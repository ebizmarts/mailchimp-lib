<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 4/27/16 4:36 PM
 * @file: Mailchimp.php
 */

require_once 'Mailchimp/Lists.php';
require_once 'Mailchimp/Exceptions.php';
require_once 'Mailchimp/Root.php';


class Mailchimp
{
    public $apiKey;
    public $ch;
    public $root    = 'https://api.mailchimp.com/3.0';
    public $debug   = false;
    const POST      = 'POST';
    const GET       = 'GET';

    public function __construct($apiKey=null,$opts=array())
    {
        if(!$apiKey)
        {
            throw new Mailchimp_Error('You must provide a MailChimp API key');
        }
        $this->apiKey   = $apiKey;
        $dc             = 'us1';
        if (strstr($this->apiKey, "-")){
            list($key, $dc) = explode("-", $this->apiKey, 2);
            if (!$dc) {
                $dc = "us1";
            }
        }
        $this->root = str_replace('https://api', 'https://' . $dc . '.api', $this->root);
        $this->root = rtrim($this->root, '/') . '/';

        if (!isset($opts['timeout']) || !is_int($opts['timeout'])){
            $opts['timeout'] = 600;
        }
        if (isset($opts['debug'])){
            $this->debug = true;
        }


        $this->ch = curl_init();

        if (isset($opts['CURLOPT_FOLLOWLOCATION']) && $opts['CURLOPT_FOLLOWLOCATION'] === true) {
            curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        }

        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Ebizmart-MailChimp-PHP/3.0.0');
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_HEADER, false);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, $opts['timeout']);

        $this->lists= new Mailchimp_Lists($this);

    }
    public function call($url,$params,$method='GET')
    {
        $params['apikey'] = $this->apiKey;
        $params = json_encode($params);

        $ch = $this->ch;
        curl_setopt($ch, CURLOPT_URL, $this->root . $url . '.json');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);

        $response_body = curl_exec($ch);

        $info = curl_getinfo($ch);
        if(curl_error($ch)) {
            throw new Mailchimp_HttpError("API call to $url failed: " . curl_error($ch));
        }
        $result = json_decode($response_body, true);

        if(floor($info['http_code'] / 100) >= 4) {
            throw $this->castError($result);
        }

        return $result;
    }
    public function castError($result) {
        if ($result['status'] !== 'error' || !$result['name']) {
            throw new Mailchimp_Error('We received an unexpected error: ' . json_encode($result));
        }

        $class = (isset(self::$error_map[$result['name']])) ? self::$error_map[$result['name']] : 'Mailchimp_Error';
        return new $class($result['error'], $result['code']);
    }
}