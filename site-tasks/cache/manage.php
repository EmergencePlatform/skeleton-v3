<?php

return [
    'title' => 'Manage application cache',
    'description' => 'Browse and selective clear application cache entries',
    'icon' => 'pencil',
    'requireAccountLevel' => 'Developer',
    'handler' => function ($taskConfig) {

        $getEntryData = (fn($entry, $includeValue = false) => [
            'hits' => $entry['num_hits'],
            'size' => $entry['mem_size'],
            'accessTime' => $entry['access_time'],
            'createTime' => $entry['creation_time'],
            'modifyTime' => $entry['mtime'],
            'value' => $includeValue ? $entry['value'] : null
        ]);

        if ($key = array_shift($taskConfig['pathStack'])) {
            $key = urldecode((string) $key);
            $entry = Cache::getIterator('/^'.preg_quote($key, '/').'$/')->current();

            if (!$entry) {
                return static::throwNotFoundError('cache entry not found');
            }

            if (array_shift($taskConfig['pathStack']) == 'delete') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    Cache::delete($key);
                    return static::respond('message', [
                        'message' => "Cleared cache key `$key`"
                    ]);
                }

                return static::respond('confirm', [
                    'question' => "Are you sure you want to delete the cache key `$key`?"
                ]);
            }

            return static::respond('entry', [
                'entryKey' => $key,
                'entry' => $getEntryData($entry, true)
            ]);
        }


        $prefixLength = strlen((string) Cache::getKeyPrefix());
        $entries = [];

        foreach (Cache::getIterator('/.*/') AS $key => $entry) {
            $key = substr((string) $key, $prefixLength);
            $entries[$key] = $getEntryData($entry);
        }

        uasort($entries, fn($a, $b) => $b['hits'] <=> $a['hits']);

        return static::respond('entries', [
            'entries' => $entries
        ]);
    }
];