<?php

/**
 * Mobile Detect
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 */
class MobileDetector
{
    protected static $_instance;

    protected $accept;
    protected $userAgent;
    protected $isMobile = false;
    protected $isAndroid;
    protected $isAndroidtablet;
    protected $isIphone;
    protected $isIpad;
    protected $isBlackberry;
    protected $isBlackberrytablet;
    protected $isOpera;
    protected $isPalm;
    protected $isWindows;
    protected $isWindowsphone;
    protected $isGeneric;
    protected $devices = [
        "android" => "android.*mobile",
        "androidtablet" => "android(?!.*mobile)",
        "blackberry" => "blackberry",
        "blackberrytablet" => "rim tablet os",
        "iphone" => "(iphone|ipod)",
        "ipad" => "(ipad)",
        "palm" => "(avantgo|blazer|elaine|hiptop|palm|plucker|xiino)",
        "windows" => "windows ce; (iemobile|ppc|smartphone)",
        "windowsphone" => "windows phone os",
        "generic" => "(kindle|mobile|mmp|midp|pocket|psp|symbian|smartphone|treo|up.browser|up.link|vodafone|wap|opera mini)"
    ];

    public function __construct()
    {
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'];
        $this->accept = $_SERVER['HTTP_ACCEPT'];

        if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
            $this->isMobile = true;
        } elseif (strpos((string) $this->accept, 'text/vnd.wap.wml') > 0 || strpos((string) $this->accept, 'application/vnd.wap.xhtml+xml') > 0) {
            $this->isMobile = true;
        } else {
            foreach ($this->devices as $device => $regexp) {
                if ($this->_isDevice($device)) {
                    $this->isMobile = true;
                }
            }
        }
    }

    /**
     * Overloads isAndroid() | isAndroidtablet() | isIphone() | isIpad() | isBlackberry() | isBlackberrytablet() | isPalm() | isWindowsphone() | isWindows() | isGeneric() through isDevice()
     *
     * @param string $name
     * @param array $arguments
     * @return bool
     */
    public function __call($name, $arguments)
    {
        $device = substr($name, 2);
        if ($name == "is".ucfirst($device) && array_key_exists(strtolower($device), $this->devices)) {
            return $this->_isDevice($device);
        }
        trigger_error("Method $name not defined", E_USER_WARNING);
    }

    /**
     * Returns true if any type of mobile device detected, including special ones
     * @return bool
     */
    protected function _isMobile()
    {
        return $this->isMobile;
    }

    protected function _isDevice($device)
    {
        $var = "is".ucfirst((string) $device);
        $return = $this->$var ?? (bool) preg_match("/".$this->devices[strtolower((string) $device)]."/i", (string) $this->userAgent);
        if ($device != 'generic' && $return == true) {
            $this->isGeneric = false;
        }

        return $return;
    }

    public static function getInstance()
    {
        if (!isset(static::$_instance)) {
            static::$_instance = new static();
        }

        return static::$_instance;
    }

    public static function __callStatic($name, $arguments)
    {
        return static::getInstance()->__call($name, $arguments);
    }

    public static function isMobile()
    {
        return static::getInstance()->_isMobile();
    }

    public static function isDevice()
    {
        return static::getInstance()->_isDevice($device);
    }
}
