<?php

class SearchRequestHandler extends RequestHandler
{
    public static $searchClasses = [];
    public static $useBoolean = true;

    public static $userResponseModes = [
        'application/json' => 'json'
        ,'text/csv' => 'csv'
    ];

    public static function __classLoaded()
    {
        uasort(static::$searchClasses, function ($a, $b) {
            $a = empty($a['weight']) ? 0 : $a['weight'];
            $b = empty($b['weight']) ? 0 : $b['weight'];
            return $a <=> $b;
        });
    }

    public static function handleRequest()
    {
        if (static::peekPath() == 'json') {
            static::$responseMode = static::shiftPath();
        }

        return static::handleSearchRequest();
    }

    public static function handleSearchRequest()
    {
        if (empty($_REQUEST['q'])) {
            return static::throwError('You did not supply any search terms');
        }

        if (!empty($_REQUEST['tag']) && !$Tag = Tag::getByHandle($_REQUEST['tag'])) {
            return static::throwNotFoundError('Tag does not exist');
        }

        if (empty(static::$searchClasses)) {
            return static::throwError('No search classes configured for this site');
        }

        $searchResults = [];
        $totalResults = 0;
        /*

        // Extra feature. Specify which classes to search for in Request parameter 'searchClasses'

        if(!empty($_REQUEST['searchClasses']))
        {
            $classes = explode(',', $_REQUEST['searchClasses']);
            foreach(static::$searchClasses AS $className => $options)
            {
                if(!in_array($className,$classes))
                    unset(static::$searchClasses[$className]);
            }

        }
        */
        foreach (static::$searchClasses AS $className => $options) {
            if (is_string($options)) {
                $className = $options;
                $options = [];
            }

            $options = array_merge([
                'className' => $className
                ,'fields' => ['Title']
                ,'conditions' => []
            ], $options);

            if (empty($options['fields'])) {
                continue;
            }

            // parse fields
            $columns = [
                'fulltext' => []
                ,'like' => []
                ,'exact' => []
                ,'sql' => []
            ];
            foreach ($options['fields'] AS $field) {
                // transform string-only
                if (is_string($field)) {
                    $field = [
                        'field' => $field
                    ];
                }

                // apply defaults
                $field = array_merge([
                    'method' => 'fulltext'
                ], $field);

                // sort conditions
                $columns[$field['method']][] = $field['method'] == 'sql' ? $field['sql'] : $className::getColumnName($field['field']);
            }

            // add match conditions
            $query = $_REQUEST['q'];
            $escapedQuery = DB::escape($query);
            $matchConditions = [];

            if ($columns['fulltext']) {
                $matchConditions[] = sprintf('MATCH (`%s`) AGAINST ("%s" %s)', implode('`,`', $columns['fulltext']), $escapedQuery, static::$useBoolean ? 'IN BOOLEAN MODE' : '');
            }

            if ($columns['like']) {
                $matchConditions[] =
                    '('
                    .implode(') OR (', array_map(fn($column) => sprintf('`%s` LIKE "%%%s%%"', $column, $escapedQuery), $columns['like']))
                    .')';
            }

            if ($columns['exact']) {
                $matchConditions[] =
                    '('
                    .implode(') OR (', array_map(fn($column) => sprintf('`%s` = "%s"', $column, $escapedQuery), $columns['exact']))
                    .')';
            }

            if ($columns['sql']) {
                $matchConditions[] =
                    '('
                    .implode(') OR (', array_map(fn($sql) => is_callable($sql) ? call_user_func($sql, $query) : sprintf($sql, $escapedQuery), $columns['sql']))
                    .')';
            }


            $options['conditions'][] = implode(' OR ', $matchConditions);

            $tableAlias = $className::getTableAlias();
            try {
                if (isset($Tag)) {
                    $results = DB::allRecords(
                        'SELECT %s.*'
                        .' FROM `tag_items` t'
                        .' INNER JOIN `%s` p ON (p.ID = t.`ContextID`)'
                        .' WHERE t.`TagID` = %u AND t.`ContextClass` = "%s"'
                        .' AND (%s)'
                        , [
                            $tableAlias,
                            $className::$tableName,
                            $tableAlias,
                            $Tag->ID,
                            $className,
                            implode(') AND (', $className::mapConditions($options['conditions']))
                        ]
                    );
                } else {
                    $results = DB::allRecords(
                        'SELECT * FROM `%s` %s WHERE (%s)'
                        , [
                            $className::$tableName,
                            $tableAlias,
                            implode(') AND (', $className::mapConditions($options['conditions']))
                        ]
                    );
                }
            } catch (TableNotFoundException) {
                $results = [];
            }

            $classResults = count($results);
            $totalResults += $classResults;

            $searchResults[$className] = $classResults !== 0 ? ActiveRecord::instantiateRecords($results) : [];
        }

        //DebugLog::dumpLog();

        static::respond('search', [
            'data' => $searchResults
            ,'totalResults' => $totalResults
        ]);
    }
}