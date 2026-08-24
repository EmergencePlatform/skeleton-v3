<?php
/**
 * Run a site console-command inside the live image — the PHP-8 container
 * port of gen-3's `emergence-console-run` (emergence-php-runtime
 * config/console-run.php), which the legacy Habitat runtime provided and
 * this image previously had no analog for. Needed by the e2e harness
 * (`migrations:execute --all` after fixture loads) and generally for any
 * console-commands/ script (connectors, flags, health).
 *
 *   docker exec <container> php /opt/emergence/tools/console-run.php \
 *       migrations:execute --all
 *
 * Contract (matching the gen-3 runner): the command name maps
 * `foo:bar` -> console-commands/foo/bar.php, executed with a $_COMMAND
 * faux-superglobal of SCRIPT_PATH / ARGS / LOGGER, under the `system`
 * user session when one exists.
 */

use Emergence\Console\Logger as ConsoleLogger;

$siteRoot = rtrim(getenv('SITE_ROOT') ?: '/opt/site', '/');
require "{$siteRoot}/site/vendor/autoload.php";

@mkdir('/tmp/logs', 0777, true);

Site::initialize($siteRoot, getenv('SITE_PRIMARY_HOSTNAME') ?: 'localhost', [
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'database' => getenv('SITE_DB') ?: (getenv('SITE_HANDLE') ?: 'slate'),
    ],
    'handle' => getenv('SITE_HANDLE') ?: 'slate',
    'logger' => [
        'root' => '/tmp/logs',
    ],
]);

set_time_limit(0);

if (class_exists(ConsoleLogger::class)) {
    $logger = new ConsoleLogger();
} else {
    // minimal PSR-3-shaped stdout logger so commands can run on trees that
    // predate Emergence\Console\Logger
    $logger = new class extends \Psr\Log\AbstractLogger {
        public function log($level, $message, array $context = [])
        {
            fwrite(STDOUT, strtoupper($level).': '.$message."\n");
        }
    };
}

// system-user session, matching the gen-3 runner
if (class_exists(Emergence\People\User::class)) {
    $systemUser = Emergence\People\User::getByUsername('system');

    if ($systemUser) {
        $_SESSION['User'] = $systemUser;
        $GLOBALS['Session'] = UserSession::create(['Person' => $systemUser]);
    }
}

// parse command + args from argv
array_shift($argv);
$command = array_shift($argv);
$args = implode(' ', $argv);

if (!$command) {
    fwrite(STDERR, "Usage: console-run.php <command> [args...]\n");
    exit(1);
}

$commandPath = 'console-commands/'.str_replace(':', '/', $command).'.php';
$node = Site::resolvePath($commandPath);

if (!$node) {
    fwrite(STDERR, "Command script not found: {$commandPath}\n");
    exit(1);
}

$_COMMAND = [
    'SCRIPT_PATH' => $node->RealPath,
    'ARGS' => $args,
    'LOGGER' => $logger,
];

// closure scope so the command script sees only $_COMMAND
$handler = function () use (&$_COMMAND) {
    return require $_COMMAND['SCRIPT_PATH'];
};

$handler();
