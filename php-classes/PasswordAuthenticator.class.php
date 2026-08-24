<?php

use Emergence\People\Person;
use Emergence\People\User;

class PasswordAuthenticator extends Authenticator
{
    // configurable settings
    public static $requestContainer = '_LOGIN';

    // brute-force / credential-spray throttle. Keyed on BOTH username and
    // client IP, counting only FAILED attempts within the window: a shared
    // NAT (a whole school behind one egress IP) produces many *successful*
    // logins but very few failures, so the per-IP failure ceiling rarely
    // trips it while still catching a sprayer (almost all failures). The
    // per-username ceiling catches a single-account brute-force regardless of
    // source. Set any to 0 to disable that dimension.
    public static $throttleWindowSeconds = 900;   // 15 minutes
    public static $throttleMaxUserFailures = 10;   // per username in the window
    public static $throttleMaxIpFailures = 50;     // per client IP; FAILURES only, so a shared-NAT school (many successes, few failures) stays clear


    public function __construct(UserSession $Session)
    {
        // require UserSession instead of Session
        parent::__construct($Session);
    }


    /**
     * Check if an authentication request exists and
     * attempt authentication if it does
     * @return bool $success
     */
    public function checkAuthentication()
    {
        if ($this->_authenticatedPerson !== null) {
            return true;
        }

        // resolve AuthRequest from PostContainer
        if (static::$requestContainer) {
            if (isset($_REQUEST[static::$requestContainer])) {
                $requestData = &$_REQUEST[static::$requestContainer];
            } else {
                $requestData = [];
            }
        } else {
            $requestData = &$_POST;
        }

        // check for authentication request
        if (isset($requestData['username']) && isset($requestData['password'])) {
            // brute-force / spray throttle: block further guesses once this
            // username or client IP has failed too many times in the window
            if ($lockSeconds = static::checkThrottle($requestData['username'])) {
                $this->respondLoginPrompt(new PasswordAuthenticationFailedException(sprintf(
                    _('Too many failed login attempts. Please wait about %d minute(s) and try again.'),
                    max(1, (int)ceil($lockSeconds / 60))
                )));
                return false;
            }

            $this->_authenticatedPerson = $this->attemptAuthentication($requestData['username'], $requestData['password']);

            if ($this->_authenticatedPerson) {
                Emergence\EventBus::fireEvent('personAuthenticate', 'Emergence/People', [
                    'Person' => $this->_authenticatedPerson,
                    'requestData' => $requestData,
                    'authenticatorClass' => static::class
                ]);

                // redirect if original request was GET
                if ($requestData['returnMethod'] != 'POST' && $_SERVER['REQUEST_METHOD'] != 'GET') {
                    Site::redirect($_SERVER['REQUEST_URI']);
                }

                return true;
            }
            $this->respondLoginPrompt(new PasswordAuthenticationFailedException(_('The username or password you entered was incorrect.')));
            return false;
        }

        return false;
    }


    /**
     * Get Person object for authenticated person
     * @return Person $AuthenticatedPerson
     */
    protected function getAuthenticatedPerson()
    {
        // check if session is already authenticated
        if ($this->_authenticatedPerson !== null) {
            return $this->_authenticatedPerson;
        }
        // check if session is already authenticated
        if ($this->_session->PersonID) {
            return Person::getByID($this->_session->PersonID);
        }
        return null;
    }


    /**
     * Attempt password authentication and retieve Person
     * @return Person $AuthenticatedPerson
     * @param object $username
     * @param object $password
     */
    protected function attemptAuthentication($username, $password)
    {
        $userClass = User::$defaultClass;
        if (!$User = $userClass::getByLogin($username, $password)) {
            static::recordFailedAttempt($username);
            return null;
        }

        static::clearFailedAttempts($username);

        $this->_session = $this->_session->changeClass(UserSession::class, [
            'PersonID' => $User->ID
        ]);

        return $User;
    }

    protected static function throttleTableReady()
    {
        static $ready = false;

        if (!$ready) {
            \DB::nonQuery(
                'CREATE TABLE IF NOT EXISTS `login_attempts` ('
                . '`ID` int unsigned NOT NULL AUTO_INCREMENT,'
                . '`Username` varchar(255) NOT NULL,'
                . '`IP` int unsigned NULL,'
                . '`Created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`ID`),'
                . 'KEY `username_created` (`Username`,`Created`),'
                . 'KEY `ip_created` (`IP`,`Created`),'
                . 'KEY `created` (`Created`)'
                . ') ENGINE=InnoDB'
            );
            $ready = true;
        }

        return true;
    }

    // returns the number of seconds the caller should wait (0 = not throttled)
    protected static function checkThrottle($username)
    {
        static::throttleTableReady();

        $window = (int)static::$throttleWindowSeconds;
        if ($window <= 0) {
            return 0;
        }

        $ip = \Emergence\Site\Client::getAddress();
        $ipLong = $ip ? sprintf('%u', ip2long($ip)) : null;

        // evaluate each dimension separately so one over-limit key locks
        $blocked = false;

        if (static::$throttleMaxUserFailures > 0) {
            $userFailures = (int)\DB::oneValue(
                'SELECT COUNT(*) FROM `login_attempts`'
                . ' WHERE Username = "%s" AND Created > (NOW() - INTERVAL %u SECOND)',
                [\DB::escape($username), $window]
            );
            if ($userFailures >= static::$throttleMaxUserFailures) {
                $blocked = true;
            }
        }

        if (!$blocked && static::$throttleMaxIpFailures > 0 && $ipLong !== null) {
            $ipFailures = (int)\DB::oneValue(
                'SELECT COUNT(*) FROM `login_attempts`'
                . ' WHERE IP = %s AND Created > (NOW() - INTERVAL %u SECOND)',
                [$ipLong, $window]
            );
            if ($ipFailures >= static::$throttleMaxIpFailures) {
                $blocked = true;
            }
        }

        return $blocked ? $window : 0;
    }

    protected static function recordFailedAttempt($username)
    {
        static::throttleTableReady();

        $ip = \Emergence\Site\Client::getAddress();
        $ipLong = $ip ? sprintf('%u', ip2long($ip)) : null;

        \DB::nonQuery(
            'INSERT INTO `login_attempts` (Username, IP) VALUES ("%s", %s)',
            [\DB::escape($username), $ipLong === null ? 'NULL' : $ipLong]
        );

        // opportunistic cleanup of rows past the window (keeps the table small
        // without a scheduled job); cheap and indexed on Created
        \DB::nonQuery(
            'DELETE FROM `login_attempts` WHERE Created < (NOW() - INTERVAL %u SECOND)',
            [(int)static::$throttleWindowSeconds]
        );
    }

    protected static function clearFailedAttempts($username)
    {
        static::throttleTableReady();

        \DB::nonQuery(
            'DELETE FROM `login_attempts` WHERE Username = "%s"',
            [\DB::escape($username)]
        );
    }


    /**
     * Check authentication, render login form, and block access
     * @return bool $success
     * @param object $options[optional]
     */
    public function requireAuthentication()
    {
        // authentication saved in session
        if ($this->_session->PersonID) {
            return true;
        }

        // try to read password authentication
        try {
            $success = $this->checkAuthentication();
        } catch (PasswordAuthenticationFailedException $e) {
            // print login page
            $this->respondLoginPrompt($e);
            return false;
        }

        if (!$success) {
            $this->respondLoginPrompt();
        }

        return $success;
    }


    /**
     * Print login page and exit
     * @return nothing
     * @param object $AuthException[optional]
     */
    public function respondLoginPrompt($authException = false)
    {
        if (
            (!empty(Site::$pathStack[0]) && Site::$pathStack[0] == 'json')
            || (!empty($_REQUEST['format']) && $_REQUEST['format'] == 'json')
            || (!empty($_SERVER['HTTP_ACCEPT']) && $_SERVER['HTTP_ACCEPT'] == 'application/json')
        ) {
            RequestHandler::$responseMode = 'json';
        }

        header('HTTP/1.1 401 Unauthorized');

        $postVars = $_POST;
        unset($postVars[static::$requestContainer]);

        RequestHandler::respond('login/login', [
            'success' => false,
            'loginRequired' => true,
            'requestContainer' => static::$requestContainer,
            'error' => $authException ? $authException->getMessage() : false,
            'postVars' => $postVars
        ]);
    }
}
