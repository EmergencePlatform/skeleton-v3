<?php
/**
 * Recreate the MySQL FULLTEXT indexes the search feature needs.
 *
 * SearchRequestHandler runs `MATCH(cols) AGAINST(...)`, which errors 1191
 * ("Can't find FULLTEXT index matching the column list") unless a FULLTEXT
 * index covering exactly those columns exists. Seeding a site's DB from a
 * mysqldump (VFS host -> Cloud SQL) drops the source's MyISAM FULLTEXT
 * indexes — they don't survive the ENGINE=MyISAM->InnoDB rewrite / import —
 * so a freshly-seeded site 500s on /search until they're restored.
 *
 * This derives the required indexes from the live SearchRequestHandler config
 * (so it stays correct as that config changes per site) and creates any that
 * are missing. Idempotent: safe to run after every seed / data cutover, and a
 * no-op once the indexes exist. Run inside the site image against the target
 * DB, e.g.:
 *
 *   docker run --rm -e SITE_HANDLE -e SITE_DB -e DB_HOST -e DB_USER -e DB_PASS \
 *     <image> php /opt/emergence/tools/sync-search-indexes.php
 *
 * or as a one-off Cloud Run job / `gcloud sql import` of its printed SQL.
 */

$siteRoot = rtrim(getenv('SITE_ROOT') ?: '/opt/site', '/');
require "{$siteRoot}/site/vendor/autoload.php";

Site::initialize($siteRoot, getenv('SITE_PRIMARY_HOSTNAME') ?: 'localhost', [
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'database' => getenv('SITE_DB') ?: (getenv('SITE_HANDLE') ?: 'slate'),
    ],
    'handle' => getenv('SITE_HANDLE') ?: 'slate',
]);

// Some SearchRequestHandler config.d fragments gate on the current session
// (e.g. people.php: `$GLOBALS['Session']->hasAccountLevel('User')`), which
// doesn't exist in this bare CLI context and would hang/fatal on config load.
// A no-privilege stub lets every fragment load cleanly — the account-level
// branches only vary NON-fulltext conditions, so they don't affect which
// fulltext indexes we derive.
if (empty($GLOBALS['Session'])) {
    $GLOBALS['Session'] = new class {
        public function hasAccountLevel($level)
        {
            return false;
        }
        public function __call($name, $args)
        {
            return null;
        }
    };
}

// touching the class loads its config.d, populating $searchClasses
class_exists('SearchRequestHandler');

// group required fulltext column-sets by table (MATCH needs an index whose
// column list exactly equals the MATCHed set; the handler MATCHes each class's
// fulltext-method fields together, so index that exact set)
$required = [];
foreach (SearchRequestHandler::$searchClasses as $className => $options) {
    $cols = [];
    foreach ($options['fields'] as $field) {
        if (is_string($field)) {
            $field = ['field' => $field];
        }
        if (($field['method'] ?? 'fulltext') !== 'fulltext') {
            continue; // only fulltext-method fields go through MATCH
        }
        $cols[] = $className::getColumnName($field['field']);
    }
    if (!$cols) {
        continue;
    }
    $table = $className::$tableName;
    $required[$table][implode(',', $cols)] = $cols;
}

$created = 0;
$existing = 0;
$failed = 0;

foreach ($required as $table => $colSets) {
    foreach ($colSets as $colList => $cols) {
        $have = DB::allRecords(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols'
            .' FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME = "%s" AND INDEX_TYPE = "FULLTEXT"'
            .' GROUP BY INDEX_NAME',
            [DB::escape($table)]
        );

        $match = false;
        foreach ($have as $idx) {
            if (strcasecmp($idx['cols'], $colList) === 0) {
                $match = true;
                break;
            }
        }

        if ($match) {
            fwrite(STDERR, "ok       {$table} FULLTEXT({$colList})\n");
            $existing++;
            continue;
        }

        $sql = sprintf('ALTER TABLE `%s` ADD FULLTEXT INDEX `ft_search` (`%s`)', $table, implode('`,`', $cols));
        try {
            DB::nonQuery($sql);
            fwrite(STDERR, "created  {$table} FULLTEXT({$colList})\n");
            $created++;
        } catch (Exception $e) {
            fwrite(STDERR, "FAILED   {$table} FULLTEXT({$colList}): {$e->getMessage()}\n");
            $failed++;
        }
    }
}

fwrite(STDERR, sprintf("done: %d created, %d already present, %d failed\n", $created, $existing, $failed));
exit($failed > 0 ? 1 : 0);
