<?php

class Session extends ActiveRecord
{
    // Session configurables
    public static $cookieName;
    public static $cookieDomain;
    public static $cookiePath = '/';
    public static $cookieSecure = false;
    public static $cookieExpires = false;
    public static $cookieSameSite = 'Lax';
    public static $timeout = 3600;

    // support subclassing
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class];

    // ActiveRecord configuration
    public static $tableName = 'sessions';
    public static $singularNoun = 'session';
    public static $pluralNoun = 'sessions';

    public static $fields = [
        'ContextClass' => null,
        'ContextID' => null,
        'Handle' => [
            'unique' => true,
            'description' => 'The session token that can be used to authenticate requests'
        ],
        'LastRequest' => [
            'type' => 'timestamp',
            'default' => null,
            'description' => 'Timestamp of last requested authenticated by session'
        ],
        'LastIP' => [
            'type' => 'uint',
            'default' => null,
            'description' => 'Long integer IP that made last request'
        ],
        'CreatorID' => null
    ];


    // Session
    public static function __classLoaded()
    {
        parent::__classLoaded();

        // generate cookie name
        if (!static::$cookieName) {
            static::$cookieName = 's_'.Site::getConfig('handle');
        }

        // auto-detect cookie domain by trimming leading www. from current hostname
        if (!static::$cookieDomain && !empty($_SERVER['HTTP_HOST'])) {
            static::$cookieDomain = preg_replace('/^www\.([^.]+\.[^.]+)$/i', '$1', $_SERVER['HTTP_HOST']);
            static::$cookieDomain = preg_replace('/^([^:]+):\d+$/i', '$1', static::$cookieDomain);
        }
    }

    public static function getFromRequest($create = true)
    {
        $clientIp = Emergence\Site\Client::getAddress();

        $sessionData = [
            'LastIP' => $clientIp ? ip2long($clientIp) : null
            ,'LastRequest' => time()
        ];

        $Session = null;

        // try to load from authorization header
        if (
            !empty($_SERVER['HTTP_AUTHORIZATION'])
            && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Token ')
            && ($Session = static::getByHandle(substr($_SERVER['HTTP_AUTHORIZATION'], 6)))
        ) {
            $Session = static::updateSession($Session, $sessionData);
        }

        // try to load from POST data
        if (
            !$Session
            && !empty($_POST['_session'])
            && ($Session = static::getByHandle($_POST['_session']))
        ) {
            $Session = static::updateSession($Session, $sessionData);
        }

        if (
            !$Session
            && !empty($_POST[static::$cookieName])
            && ($Session = static::getByHandle($_POST[static::$cookieName]))
        ) {
            $Session = static::updateSession($Session, $sessionData);

            Emergence\Logger::general_warning('Deprecated use of cookieName via POST');
        }

        // try to load from GET data
        if (
            !$Session
            && !empty($_GET['_session'])
            && ($Session = static::getByHandle($_GET['_session']))
        ) {
            $Session = static::updateSession($Session, $sessionData);
        }

        if (
            !$Session
            && !empty($_GET[static::$cookieName])
            && ($Session = static::getByHandle($_GET[static::$cookieName]))
        ) {
            $Session = static::updateSession($Session, $sessionData);

            Emergence\Logger::general_warning('Deprecated use of cookieName via GET');
        }

        // try to load from cookie data
        if (
            !$Session
            && !empty($_COOKIE[static::$cookieName])
            && ($Session = static::getByHandle($_COOKIE[static::$cookieName]))
        ) {
            $Session = static::updateSession($Session, $sessionData);
        }
        // return found or create new session
        if ($Session) {
            // session found
            return $Session;
        }


        // return found or create new session
        if ($create) {
            // create an UNSAVED session: anonymous guests (crawlers included)
            // get a valid in-memory session object so nothing null-fatals, but
            // no DB row (or cookie) is written until the session actually
            // persists — i.e. on authentication. Historically every anonymous
            // request wrote a row, and with GC unmaintained the table grew
            // without bound (a legacy e-commerce cart-holding relic).
            return static::create($sessionData, false);
        }

        // no session available
        return false;
    }

    public static function updateSession(Session $Session, $sessionData)
    {
        // check timestamp
        if (static::$timeout && $Session->LastRequest < (time() - static::$timeout)) {
            $Session->terminate();

            return false;
        }

        // update session
        $Session->setFields($sessionData);
        $Session->save();

        return $Session;
    }

    public function save($deep = true)
    {
        // set handle
        if (!$this->Handle) {
            $this->Handle = HandleBehavior::generateRandomHandle($this);
        }

        // call parent
        parent::save($deep);

        // set cookie
        if (!headers_sent()) {
            $cookie = sprintf('Set-Cookie: %s=%s', static::$cookieName, $this->Handle);
            $cookie .= sprintf('; Path=%s', static::$cookiePath);

            if (static::$cookieExpires) {
                $cookie .= sprintf('; Expires=%s', date('D, d M Y H:i:s \G\M\T', time() + static::$cookieExpires));
            }

            if (static::$cookieDomain) {
                $cookie .= sprintf('; Domain=%s', static::$cookieDomain);
            }

            if (static::$cookieSameSite) {
                $cookie .= sprintf('; SameSite=%s', static::$cookieSameSite);
            }

            if (static::$cookieSecure) {
                $cookie .= '; Secure';
            }

            header($cookie);
        }
    }

    public function changeClass($className = false, $fieldValues = false, $autoSave = true)
    {
        $Record = parent::changeClass($className, $fieldValues, $autoSave);

        // an anonymous session is created unsaved (see getFromRequest); when it
        // becomes an authenticated UserSession it must persist so the login
        // sticks — parent::changeClass skips saving a phantom.
        if ($autoSave && $Record->isPhantom && $Record instanceof UserSession && $Record->PersonID) {
            $Record->save();
        }

        return $Record;
    }

    public function terminate()
    {
        setcookie(static::$cookieName, '', ['expires' => time() - 3600]);
        unset($_COOKIE[static::$cookieName]);

        $this->destroy();
    }
}
