<?php
/**
 * Front controller for the Track A PHP 8 live-site prototype.
 *
 * PHP 8 analog of infra/archive/image/web/index.php (the working PHP 5
 * boot-php-core-in-a-container reference): nginx routes every request here
 * with SITE_ROOT passed as a fastcgi param, and emergence-php-core's
 * Site::initialize() roots the framework filesystem at {SITE_ROOT}/site —
 * the composed flat tree produced by infra/live/build/compose-site.sh.
 *
 * Differences from the archive controller:
 *  - single-site image (one composed skeleton-v3+slate tree baked at
 *    /opt/site/site), so SITE_ROOT/SITE_HANDLE just default sensibly
 *  - php-core lives at /opt/php-core (plain composer install in the image
 *    build), not a Habitat package path
 *  - PHP 8 syntax is fair game
 */

$siteRoot = rtrim($_SERVER['SITE_ROOT'] ?? getenv('SITE_ROOT') ?: '/opt/site', '/');
$siteHandle = $_SERVER['SITE_HANDLE'] ?? getenv('SITE_HANDLE') ?: 'slate';
$siteDb = $_SERVER['SITE_DB'] ?? getenv('SITE_DB') ?: $siteHandle;

if (!is_dir("{$siteRoot}/site")) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "live runtime misconfigured: {$siteRoot}/site not found\n";
    exit(1);
}

// emergence/php-core + deps, Composer-installed into the projected tree at
// image build (php-core is a Composer dependency, not a hologit holosource).
require "{$siteRoot}/site/vendor/autoload.php";

// scratch roots — the crash logger and local-storage layers write here but
// never create the directories themselves (same caveat as the archive image)
@mkdir('/tmp/logs', 0777, true);
@mkdir("/tmp/data/{$siteHandle}", 0777, true);

Site::$debug = filter_var(getenv('SITE_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
Site::$production = !Site::$debug;

$hostname = empty($_SERVER['HTTP_HOST'])
    ? 'localhost'
    : parse_url('http://'.$_SERVER['HTTP_HOST'], PHP_URL_HOST);

Site::initialize($siteRoot, $hostname, [
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'database' => $siteDb,
    ],

    'handle' => $siteHandle,
    'label' => getenv('SITE_LABEL') ?: 'Slate (skeleton-v3 PHP 8 prototype)',
    'primary_hostname' => $hostname,
    'hostnames' => [],

    'logger' => [
        'dump' => Site::$debug,
        'root' => '/tmp/logs',
    ],

    'storage' => [
        // scratch space only in the prototype; production needs GCS-backed
        // media (see infra/live/README.md gap list)
        'local_root' => "/tmp/data/{$siteHandle}",
    ],
]);

Site::handleRequest();
