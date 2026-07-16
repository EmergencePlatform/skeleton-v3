<?php

use Emergence\People\Person;

return [
    'title' => 'People',
    'description' => 'Each row represents an individual person tracked in the database',
    'filename' => 'people',
    'headers' => [
        'PersonID' => 'Person ID',
        'FirstName' => 'First Name',
        'LastName' => 'Last Name'
    ],
    'readQuery' => fn (array $input) => [],
    'buildRows' => function (array $query = [], array $config = []) {

        // build rows
        try {
            $result = DB::query(
                '
                    SELECT Person.*
                      FROM `%s` Person
                     ORDER BY LastName, FirstName
                ',
                [
                    Person::$tableName
                ]
            );

            while ($record = $result->fetch_assoc()) {
                $Person = Person::instantiateRecord($record);

                yield [
                    'PersonID' => $Person->ID,
                    'FirstName' => $Person->FirstName,
                    'LastName' => $Person->LastName
                ];
            }
        } finally {
            unset($record);
            unset($Person);

            if ($result) {
                $result->free();
            }
            unset($result);
        }
    }
];
