<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 4/27/16 4:45 PM
 * @file: Error.php
 */
class Mailchimp_Error extends Exception
{
    /**
     * @var string
     */
    protected $url;
    /**
     * @var string
     */
    protected $title;
    /**
     * @var string
     */
    protected $detail;
    /**
     * @var string
     */
    protected $method;
    /**
     * @var array
     */
    protected $errors;
    /**
     * @var string
     */
    protected $instance;
    protected $helper;
    protected $storeURL;

    /**
     * @var string
     */
    protected $params;

    public function __construct($url,$method='',$params='',$title='',$detail='',$errors=null, $instance = null, $helper=null, $storeURL=null)
    {
        $titleComplete = $title . " for Api Call: " . $url;
        parent::__construct($titleComplete . " - " . $detail);
        $this->url = $url;
        $this->title = $title;
        $this->detail = $detail;
        $this->method = $method;
        $this->errors = $errors;
        $this->params = $params;
        $this->instance = $instance;
        $this->helper = $helper;
        $this->storeURL = $storeURL;
    }
    public function getFriendlyMessage()
    {
        $error = [];
        $error['title'] = $this->title . " for Api Call: [" . $this->url. "] using method [".$this->method."]";
        $error['detail'] = $this->detail;
        if(is_array($this->errors)) {
            foreach ($this->errors as $errors) {
                $field = array_key_exists('field', $errors) ? $errors['field'] : '';
                $message = array_key_exists('message', $errors) ? $errors['message'] : '';
                $error['field'][$field] = $message;
            }
        }
        if(is_array($this->params)) {
            if(count($this->params)) {
                $error['params'][]=$this->params;
            }
        } else {
            $error['params'][] = $this->params;
        }
        if ($this->instance) {
            $error['instance'] = $this->instance;
        }
        $errors = [];
        if ($this->storeURL) {
            $errors['storeURL'] = $this->storeURL;
        }
        if ($this->helper && method_exists($this->helper, 'getGmtDate')) {
            $errors['time'] = $this->helper->getGmtDate();
        }
        $errors['error'] = $error;
        // Guarded on the method, not only on the helper. Composer cannot
        // express this pairing: the module requires a minimum library version,
        // a library cannot require a minimum module version, and the module's
        // constraint is a floor with no ceiling -- so `composer update` on an
        // old installation pairs whatever module is on disk with the newest
        // library. `saveNotification()` arrived in the module on 2024-12-03
        // (103.4.65) and this call landed the day after, which has made every
        // module below that version fatal here ever since.
        //
        // On the error path, which is the worst place to fatal: the call is
        // reached because Mailchimp answered 4xx, so an install that cannot
        // listen loses the message explaining the failure AND the request that
        // was carrying it. A host that has no way to be notified is not an
        // error to raise; it is a host to leave alone.
        if ($this->helper && method_exists($this->helper, 'saveNotification')) {
            $this->helper->saveNotification($errors);
        }
        return $errors;
    }
    public function getUrl()
    {
        return $this->url;
    }
    public function getTitle()
    {
        return$this->title;
    }
    public function getDetail()
    {
        return $this->detail;
    }
    public function getMethod()
    {
        return $this->method;
    }
    public function getErrors()
    {
        return $this->errors;
    }
}
