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

    // returns the number of seconds the caller should wait (0 = not throttled).
    // getCount is TableNotFound-safe (returns 0), so the very first check
    // before the table exists reads as "no failures" — the table is created
    // on demand by the first recorded failure (ActiveRecord create-on-error).
    protected static function checkThrottle($username)
    {
        $window = (int)static::$throttleWindowSeconds;
        if ($window <= 0) {
            return 0;
        }

        $recentInWindow = sprintf('Created > (NOW() - INTERVAL %u SECOND)', $window);

        if (static::$throttleMaxUserFailures > 0) {
            $userFailures = \Emergence\People\LoginAttempt::getCount([
                'Username' => $username,
                $recentInWindow,
            ]);
            if ($userFailures >= static::$throttleMaxUserFailures) {
                return $window;
            }
        }

        if (static::$throttleMaxIpFailures > 0 && ($ipLong = static::clientIpLong()) !== null) {
            $ipFailures = \Emergence\People\LoginAttempt::getCount([
                'IP' => $ipLong,
                $recentInWindow,
            ]);
            if ($ipFailures >= static::$throttleMaxIpFailures) {
                return $window;
            }
        }

        return 0;
    }

    protected static function recordFailedAttempt($username)
    {
        // create-with-save; ActiveRecord creates login_attempts on first
        // insert if it does not yet exist (TableNotFound -> getCreateTable)
        \Emergence\People\LoginAttempt::create([
            'Username' => $username,
            'IP' => static::clientIpLong(),
        ], true);
    }

    protected static function clearFailedAttempts($username)
    {
        foreach (\Emergence\People\LoginAttempt::getAllByField('Username', $username) as $attempt) {
            $attempt->destroy();
        }
    }

    protected static function clientIpLong()
    {
        $ip = \Emergence\Site\Client::getAddress();

        return $ip ? sprintf('%u', ip2long($ip)) : null;
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
