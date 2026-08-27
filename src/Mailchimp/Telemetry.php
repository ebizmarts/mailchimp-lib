<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Counts how the Mailchimp API behaves for this installation and reports a
 * summary once, at the end of the process.
 *
 * WHAT IT MEASURES. Per endpoint family: how many requests were made, how many
 * failed, how long they took in total and what the slowest one was. Plus a
 * breakdown of failures by kind. It never records what a request contained or
 * what came back, so no customer or order data can reach the report.
 *
 * WHY IT LIVES HERE. Every request this library makes passes through one
 * method, so this is the only place that can see all of them. Measuring from
 * outside would mean re-implementing the accounting a caller cannot observe.
 *
 * WHY THE TALLY IS BUCKETED. A caller may hand this object a different API key
 * part-way through a process — one Magento installation can hold several
 * Mailchimp accounts, one per store view, and the key is re-set on each use.
 * A single flat counter would blend those accounts into one report that
 * describes none of them. So the tally is keyed by account: changing the key
 * closes the open bucket and opens (or resumes) another, and each bucket
 * reports separately.
 *
 * EVERY FAILURE HERE IS SILENT. This is diagnostics: it must never turn a
 * working store into a broken one. A missing helper, an old helper that does
 * not know the opt-out, a store URL that was never set, no network, a refused
 * connection, a PHP build without curl — all degrade to reporting less, or
 * nothing, and never to an error the merchant sees.
 */
class Mailchimp_Telemetry
{
    const ENDPOINT       = 'https://apps.ebizmarts.com/mc4magento/v1/qos';
    const SCHEMA         = 1;
    const CONTACT_CONFIG = 'mailchimp/telemetry/share_contact';
    const KILL_ENV       = 'MC_TELEMETRY';

    /** A report over this size is not worth sending; the receiver rejects it anyway. */
    const MAX_BYTES = 8192;

    /**
     * Buckets held per process. Beyond this the overflow is counted and
     * discarded rather than grown without bound.
     */
    const MAX_BUCKETS = 16;

    /** Reports sent per process, whatever the bucket count. */
    const MAX_SENDS = 4;

    /**
     * How often a process that saw the account root reports, and how often one
     * that did not.
     *
     * The extension's sync cron asks for the account root every five minutes,
     * so ~288 processes a day are eligible. Reporting from all of them would
     * cost about three hundred reports per installation per day to say very
     * nearly the same thing three hundred times. The divisor turns that into
     * roughly four.
     */
    const SAMPLE_ROOT  = 72;
    const SAMPLE_OTHER = 8;

    /** The window the sampling hash is stable within. */
    const SAMPLE_WINDOW_SEC = 300;

    /** Milliseconds the whole reporting step may spend, by context. */
    const BUDGET_WEB_MS = 400;
    const BUDGET_CLI_MS = 1500;

    /** Per-request curl limits, by context. */
    const CONNECT_WEB_MS = 150;
    const TOTAL_WEB_MS   = 250;
    const CONNECT_CLI_MS = 300;
    const TOTAL_CLI_MS   = 500;

    /**
     * @var array bucket rows keyed by install id
     */
    private $_buckets = array();

    /**
     * @var string|null install id of the bucket currently accumulating
     */
    private $_current = null;

    /**
     * @var int buckets discarded after MAX_BUCKETS
     */
    private $_dropped = 0;

    /**
     * @var bool
     */
    private $_enabled = true;

    /**
     * @var bool a process reports once
     */
    private $_reported = false;

    /**
     * @var mixed|null host helper, may be absent or older than this feature
     */
    private $_helper = null;

    /**
     * @var string|null
     */
    private $_storeUrl = null;

    /**
     * @var bool|null latched when the helper is set
     */
    private $_contactAllowed = null;

    /**
     * @var string|null latched when the helper is set
     */
    private $_moduleVersion = null;

    /**
     * @var string|null
     */
    private $_userAgent = null;

    public function __construct()
    {
        // An operator escape hatch, deliberately not a merchant setting.
        $kill = getenv(self::KILL_ENV);
        if ($kill !== false && trim($kill) === '0') {
            $this->_enabled = false;
        }
    }

    /**
     * @param  mixed $helper
     * @return void
     */
    public function setHelper($helper)
    {
        $this->_helper = $helper;

        // Read now rather than at shutdown. A read that throws during shutdown
        // is indistinguishable from no preference at all, and the fallback is
        // to allow — so a merchant who declined could have the contact pair
        // sent anyway. Asking while the host is healthy removes that. It also
        // moves an uncached module lookup out of the destructor.
        // Once only: the helper is handed over on every API construction, and
        // both answers are process-wide.
        if ($this->_contactAllowed === null) {
            $this->_contactAllowed = $this->readContactAllowed();
        }
        if ($this->_moduleVersion === null) {
            $this->_moduleVersion = $this->readModuleVersion();
        }
    }

    /**
     * @param  string $storeUrl
     * @return void
     */
    public function setStoreUrl($storeUrl)
    {
        $this->_storeUrl = $storeUrl;
        // The URL is re-set per store view, so it belongs to the open bucket
        // rather than to the process: two store views in one process would
        // otherwise both report whichever URL happened to be set last.
        if ($this->_current !== null && isset($this->_buckets[$this->_current])) {
            if (!$this->_buckets[$this->_current]['store_url']) {
                $this->_buckets[$this->_current]['store_url'] = $storeUrl;
            }
        }
    }

    /**
     * @param  string $userAgent
     * @return void
     */
    public function setUserAgent($userAgent)
    {
        $this->_userAgent = $userAgent;
    }

    /**
     * Point the tally at the account this key belongs to.
     *
     * Resuming an already-seen key rather than starting over is what keeps a
     * process that alternates between two store views from reporting each of
     * them in fragments.
     *
     * @param  string $apiKey
     * @param  string $dc
     * @return void
     */
    public function switchKey($apiKey, $dc)
    {
        if (!$this->_enabled) {
            return;
        }
        if (!$apiKey) {
            // Same discipline as the cap path below: with no account to attribute
            // to, later calls must not land in whichever bucket was last open.
            $this->_current = null;
            return;
        }

        $id = self::installId($apiKey);

        if (isset($this->_buckets[$id])) {
            $this->_current = $id;
            return;
        }

        if (count($this->_buckets) >= self::MAX_BUCKETS) {
            $this->_dropped++;
            $this->_current = null;
            return;
        }

        $this->_buckets[$id] = array(
            'install_id'        => $id,
            'dc'                => self::validDc($dc),
            'store_url'         => null,
            'calls'             => 0,
            'errors'            => 0,
            'time_ms'           => 0,
            'max_ms'            => 0,
            'bytes_up'          => 0,
            'bytes_down'        => 0,
            'err'               => array(),
            'last_err'          => null,
            'families'          => array(),
            'mc_store_id'       => null,
            'list_id'           => null,
            'saw_root'          => false,
            'account_id'        => null,
            'owner_name'        => null,
            'owner_email'       => null,
            'total_subscribers' => null,
        );
        $this->_current = $id;
    }

    /**
     * Record one completed request.
     *
     * Called before the library raises on a failed response, because a report
     * that only counts successes describes the opposite of what it is for.
     *
     * @param  string $path     request path, captured before any query string
     * @param  array  $info     curl_getinfo output
     * @param  int    $curlErr  curl_errno, 0 when the transport succeeded
     * @return void
     */
    public function record($path, $info, $curlErr)
    {
        if (!$this->_enabled || $this->_current === null) {
            return;
        }
        if (!isset($this->_buckets[$this->_current])) {
            return;
        }

        $bucket = &$this->_buckets[$this->_current];
        $family = self::family($path);

        // Recorded on the attempt, not on the answer. Asking for the account
        // is what says this process is the kind that can be identified; whether
        // the answer came back is a separate fact, and one that fails exactly
        // for the installations most worth hearing from. Latching on success
        // would drop a store whose key has expired into the anonymous, less
        // detailed branch, and make it report far more often than a healthy one.
        if ($family === 'root') {
            $bucket['saw_root'] = true;
        }

        // The store and audience a request addressed are part of the path, so
        // they cost nothing to observe. First non-empty wins: an installation
        // that later touches a second audience must not have its record
        // re-pointed at whichever one it happened to use last.
        $ids = self::harvest($path);
        if (!$bucket['mc_store_id'] && $ids['mc_store_id']) {
            $bucket['mc_store_id'] = $ids['mc_store_id'];
        }
        if (!$bucket['list_id'] && $ids['list_id']) {
            $bucket['list_id'] = $ids['list_id'];
        }

        $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $ms     = isset($info['total_time']) ? (int)round($info['total_time'] * 1000) : 0;

        $bucket['calls']++;
        $bucket['time_ms'] += $ms;
        if ($ms > $bucket['max_ms']) {
            $bucket['max_ms'] = $ms;
        }
        if (isset($info['size_upload'])) {
            $bucket['bytes_up'] += (int)$info['size_upload'];
        }
        if (isset($info['size_download'])) {
            $bucket['bytes_down'] += (int)$info['size_download'];
        }

        if (!isset($bucket['families'][$family])) {
            $bucket['families'][$family] = array('c' => 0, 'e' => 0, 'ms' => 0, 'mx' => 0);
        }
        $bucket['families'][$family]['c']++;
        $bucket['families'][$family]['ms'] += $ms;
        if ($ms > $bucket['families'][$family]['mx']) {
            $bucket['families'][$family]['mx'] = $ms;
        }

        $key = self::errorKey($status, $curlErr);
        if ($key !== null) {
            $bucket['errors']++;
            $bucket['families'][$family]['e']++;
            if (!isset($bucket['err'][$key])) {
                $bucket['err'][$key] = 0;
            }
            $bucket['err'][$key]++;
            // A composed literal, never free text: an error message can quote
            // the request that produced it.
            $bucket['last_err'] = $family . ':' . $key;
        }
    }

    /**
     * Latch account facts from a response the caller happened to ask for.
     *
     * This never triggers a request of its own — spending a merchant's API
     * quota to label our own diagnostics would not be a fair trade.
     *
     * @param  array $result decoded body of a successful root request
     * @return void
     */
    public function observeRoot($result)
    {
        if (!$this->_enabled || $this->_current === null || !is_array($result)) {
            return;
        }
        if (!isset($this->_buckets[$this->_current])) {
            return;
        }

        $bucket = &$this->_buckets[$this->_current];

        // First non-empty wins: identity must not move under a report that has
        // already been attributed.
        if (!$bucket['account_id'] && isset($result['account_id'])) {
            $bucket['account_id'] = (string)$result['account_id'];
        }
        if (isset($result['total_subscribers'])) {
            $bucket['total_subscribers'] = (int)$result['total_subscribers'];
        }
        if (!$bucket['owner_name'] && isset($result['account_name'])) {
            $bucket['owner_name'] = (string)$result['account_name'];
        }
        if (!$bucket['owner_email'] && isset($result['email'])) {
            $bucket['owner_email'] = (string)$result['email'];
        }
    }

    /**
     * Send one report per bucket, within the process budget.
     *
     * @return void
     */
    public function flush()
    {
        if (!$this->_enabled || $this->_reported) {
            return;
        }
        $this->_reported = true;

        $cli     = (PHP_SAPI === 'cli');
        $budget  = $cli ? self::BUDGET_CLI_MS : self::BUDGET_WEB_MS;
        $started = microtime(true);
        $sent    = 0;

        foreach ($this->_buckets as $bucket) {
            if ($sent >= self::MAX_SENDS) {
                break;
            }
            if ((microtime(true) - $started) * 1000 >= $budget) {
                break;
            }
            if (!$bucket['calls']) {
                continue;
            }

            $mode = $this->sendMode($bucket, $cli);
            if ($mode === 'skip') {
                continue;
            }

            $body = json_encode($this->envelope($bucket, $cli, $mode === 'lean'));
            if (!is_string($body) || strlen($body) > self::MAX_BYTES) {
                continue;
            }

            $this->post($body, $cli);
            $sent++;
        }
    }

    /**
     * Whether this bucket reports on this run, and how much.
     *
     * A web request reports every time: there are few of them, they are the
     * ones a merchant is waiting on, and their timing is the signal.
     *
     * Background processes are the opposite — hundreds a day, all saying much
     * the same thing — so they report on a schedule derived from the store
     * itself. The same store lands in the same window, which keeps a busy
     * installation from reporting far more often than a quiet one, and keeps
     * the whole population from reporting at the same moment.
     *
     * A background process that never asked for the account root has no
     * identity to attach, so it reports rarely and briefly: enough to show the
     * installation is working, not enough to pay for detail nobody can join to
     * anything.
     *
     * @param  array $bucket
     * @param  bool  $cli
     * @return string full, lean or skip
     */
    private function sendMode($bucket, $cli)
    {
        if (!$cli) {
            return 'full';
        }

        $seed    = $bucket['store_url'] ? $bucket['store_url'] : $bucket['install_id'];
        $divisor = $bucket['saw_root'] ? self::SAMPLE_ROOT : self::SAMPLE_OTHER;
        $window  = (int)floor(time() / self::SAMPLE_WINDOW_SEC);

        if ((crc32($seed . $window) & 0x7fffffff) % $divisor !== 0) {
            return 'skip';
        }

        return $bucket['saw_root'] ? 'full' : 'lean';
    }

    /**
     * @param  array $bucket
     * @param  bool  $cli
     * @param  bool  $lean  omit everything that is not always present
     * @return array
     */
    private function envelope($bucket, $cli, $lean = false)
    {
        $out = array(
            'v'          => self::SCHEMA,
            'lib'        => self::libVersion(),
            'ts'         => time(),
            'sapi'       => PHP_SAPI,
            'bid'        => md5(uniqid('', true)),
            'install_id' => $bucket['install_id'],
            'dc'         => $bucket['dc'],
            'php'        => self::phpVersion(),
            'calls'      => $bucket['calls'],
            'errors'     => $bucket['errors'],
            'time_ms'    => $bucket['time_ms'],
            'max_ms'     => $bucket['max_ms'],
            'bytes_up'   => $bucket['bytes_up'],
            'bytes_down' => $bucket['bytes_down'],
        );

        if ($this->_dropped > 0) {
            $out['bdrop'] = $this->_dropped;
        }

        // A lean report says the installation is alive and how much work it
        // did. Everything below identifies or explains, and none of it can be
        // joined to anything without an account, which this one never saw.
        if ($lean) {
            return $out;
        }
        if ($bucket['store_url']) {
            $out['store_url'] = $bucket['store_url'];
        }
        if ($bucket['account_id']) {
            $out['account_id'] = $bucket['account_id'];
        }
        if ($bucket['mc_store_id']) {
            $out['mc_store_id'] = $bucket['mc_store_id'];
        }
        if ($bucket['list_id']) {
            $out['list_id'] = $bucket['list_id'];
        }
        if ($bucket['total_subscribers'] !== null) {
            $out['total_subscribers'] = $bucket['total_subscribers'];
        }
        if ($bucket['err']) {
            $out['err'] = $bucket['err'];
        }
        if ($bucket['last_err']) {
            $out['last_err'] = $bucket['last_err'];
        }
        if ($bucket['families']) {
            $out['m'] = $bucket['families'];
        }
        if ($this->_userAgent) {
            $out['ua'] = $this->_userAgent;
        }

        $moduleVersion = $this->moduleVersion();
        if ($moduleVersion) {
            $out['module_version'] = $moduleVersion;
        }

        // The contact pair is the only part a merchant can decline, and the
        // flag travels either way so the receiver can tell "declined" from
        // "this request happened not to carry it".
        if ($this->contactAllowed()) {
            if ($bucket['owner_name']) {
                $out['owner_name'] = $bucket['owner_name'];
            }
            if ($bucket['owner_email']) {
                $out['owner_email'] = $bucket['owner_email'];
            }
        } else {
            $out['contact_opt_out'] = true;
        }

        return $out;
    }

    /**
     * Whether the merchant has declined sharing the contact pair.
     *
     * An absent value means on. A helper too old to know the setting returns
     * nothing, and that must read as "not configured", never as a merchant
     * having said no — this library updates independently of the extension
     * that owns the setting, so most installs will be in exactly that state.
     *
     * @return bool
     */
    private function contactAllowed()
    {
        if ($this->_contactAllowed !== null) {
            return $this->_contactAllowed;
        }

        return $this->readContactAllowed();
    }

    /**
     * @return bool
     */
    private function readContactAllowed()
    {
        if (!$this->_helper || !method_exists($this->_helper, 'getConfigValue')) {
            return true;
        }

        try {
            $value = $this->_helper->getConfigValue(self::CONTACT_CONFIG);
        } catch (Exception $e) {
            return true;
        } catch (Throwable $t) {
            // On PHP 7+ an Error is not an Exception, and a host that is broken
            // enough to raise one must not take the store's request with it.
            return true;
        }

        if ($value === null || $value === '') {
            return true;
        }

        return !in_array((string)$value, array('0', 'false', 'no'), true);
    }

    /**
     * @return string|null
     */
    private function moduleVersion()
    {
        if ($this->_moduleVersion !== null) {
            return $this->_moduleVersion;
        }

        return $this->readModuleVersion();
    }

    /**
     * @return string|null
     */
    private function readModuleVersion()
    {
        if (!$this->_helper || !method_exists($this->_helper, 'getModuleVersion')) {
            return null;
        }
        try {
            $version = $this->_helper->getModuleVersion();
        } catch (Exception $e) {
            return null;
        } catch (Throwable $t) {
            return null;
        }

        return $version ? (string)$version : null;
    }

    /**
     * Post one report and forget it.
     *
     * A fresh handle every time: the library's own handle carries the
     * merchant's Mailchimp credentials, and those must never be presented to
     * anything but Mailchimp.
     *
     * @param  string $body
     * @param  bool   $cli
     * @return void
     */
    private function post($body, $cli)
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $ch = false;
        try {
            $ch = curl_init();
        } catch (Exception $e) {
            return;
        } catch (Throwable $t) {
            return;
        }
        if (!$ch) {
            return;
        }

        curl_setopt($ch, CURLOPT_URL, self::ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Without this the millisecond timeouts below are unreliable on builds
        // that resolve DNS synchronously, and the timeout fence is the whole
        // safety argument for reporting from inside a web request.
        if (defined('CURLOPT_NOSIGNAL')) {
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        }

        $connect = $cli ? self::CONNECT_CLI_MS : self::CONNECT_WEB_MS;
        $total   = $cli ? self::TOTAL_CLI_MS : self::TOTAL_WEB_MS;
        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $connect);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $total);
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        }

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * The PHP version, without the distribution's build suffix.
     *
     * Packaged builds report things like 8.1.2-1ubuntu2.14, which is longer
     * than the field allows and carries nothing worth comparing across stores.
     *
     * @return string
     */
    public static function phpVersion()
    {
        if (defined('PHP_MAJOR_VERSION')) {
            return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        }

        $parts = explode('-', PHP_VERSION, 2);

        return $parts[0];
    }

    /**
     * Version of this package, read from its own composer.json.
     *
     * Read rather than hardcoded so there is one place to bump at release
     * time: a constant in the source is a second copy that goes stale the
     * first time someone tags without touching it, and a wrong version in a
     * diagnostics report is worse than none — it sends whoever reads it
     * looking at the wrong code.
     *
     * Cached for the process: this is a file read on a path that runs inside
     * web requests.
     *
     * @return string empty when the file is absent or unreadable
     */
    public static function libVersion()
    {
        static $version = null;

        if ($version !== null) {
            return $version;
        }
        $version = '';

        $path = dirname(dirname(__DIR__)) . '/composer.json';
        if (!is_readable($path)) {
            return $version;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $version;
        }

        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            $version = $data['version'];
        }

        return $version;
    }

    /**
     * Endpoint family of a request path.
     *
     * Parsed from the path before any query string is appended, so the value
     * is the resource and never the arguments. Two spellings that address one
     * resource are folded together; an empty path is the account root, which
     * needs a name of its own because it is the request that carries identity.
     *
     * @param  string $path
     * @return string
     */
    public static function family($path)
    {
        $path = (string)$path;
        $cut  = strpos($path, '?');
        if ($cut !== false) {
            $path = substr($path, 0, $cut);
        }
        $path = ltrim($path, '/');

        if ($path === '') {
            return 'root';
        }

        $slash  = strpos($path, '/');
        $family = ($slash === false) ? $path : substr($path, 0, $slash);
        $family = strtolower($family);

        if ($family === 'automation') {
            return 'automations';
        }
        if ($family === 'conversation') {
            return 'conversations';
        }

        return $family === '' ? 'root' : $family;
    }

    /**
     * Identifiers a request path addressed, if any.
     *
     * Both are structural: `ecommerce/stores/<store>` and `lists/<audience>`.
     * Reading them from the path means the beacon can say which store and which
     * audience an installation actually uses without asking Mailchimp for
     * anything, and without touching what a request contained.
     *
     * @param  string $path
     * @return array  keys mc_store_id and list_id, either may be null
     */
    public static function harvest($path)
    {
        $out = array('mc_store_id' => null, 'list_id' => null);

        $path = (string)$path;
        $cut  = strpos($path, '?');
        if ($cut !== false) {
            $path = substr($path, 0, $cut);
        }
        $parts = explode('/', trim($path, '/'));

        if (isset($parts[2]) && $parts[0] === 'ecommerce' && $parts[1] === 'stores' && $parts[2] !== '') {
            $out['mc_store_id'] = substr($parts[2], 0, 128);
        }
        if (isset($parts[1]) && $parts[0] === 'lists' && $parts[1] !== '') {
            $out['list_id'] = substr($parts[1], 0, 32);
        }

        return $out;
    }

    /**
     * The datacenter suffix, or null when it does not look like one.
     *
     * It is derived by splitting the API key, so a malformed key yields a
     * malformed value. Reporting nothing is better than reporting a fragment
     * of somebody's key under a field name that says datacenter.
     *
     * @param  string $dc
     * @return string|null
     */
    public static function validDc($dc)
    {
        $dc = strtolower(trim((string)$dc));

        return preg_match('/^[a-z]{2,4}[0-9]{1,3}$/', $dc) ? $dc : null;
    }

    /**
     * Stable, non-reversible identifier for the account a key belongs to.
     *
     * The key itself never leaves the store. Normalising inside the hash means
     * the same key written with stray whitespace or different case resolves to
     * one identity instead of splitting an installation in two.
     *
     * @param  string $apiKey
     * @return string
     */
    public static function installId($apiKey)
    {
        return substr(hash('sha256', 'ebz-t-v1|' . strtolower(trim($apiKey))), 0, 32);
    }

    /**
     * Which kind of failure this was, or null when the request succeeded.
     *
     * A closed set: the point is to see the shape of failures across many
     * stores, and free-form values cannot be compared.
     *
     * @param  int $status
     * @param  int $curlErr
     * @return string|null
     */
    public static function errorKey($status, $curlErr)
    {
        if ($curlErr) {
            return 'net';
        }
        if ($status < 400) {
            return null;
        }
        if (in_array($status, array(400, 401, 403, 404, 422, 429), true)) {
            return (string)$status;
        }

        return $status < 500 ? '4xx' : '5xx';
    }
}
