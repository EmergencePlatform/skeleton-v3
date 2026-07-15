<?php

namespace Emergence\People\Groups;

use ActiveRecord;
use Emergence\People\Person;

class GroupMember extends ActiveRecord
{
    // ActiveRecord configuration
    public static $tableName = 'group_members';

    public static $fields = [
        'GroupID' => [
            'type' => 'integer'
            ,'unsigned' => true
        ]
        ,'PersonID' => [
            'type' => 'integer'
            ,'unsigned' => true
        ]
        ,'Role' => [
            'type' => 'enum'
            ,'values' => ['Member', 'Administrator', 'Owner', 'Founder']
        ]
        ,'Rank' => [
            'type' => 'integer'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Title' => [
            'type' => 'string'
            ,'notnull' => false
        ]
        ,'Joined' => [
            'type' => 'timestamp'
            ,'default' => null
        ]
        ,'Expires' => [
            'type' => 'timestamp'
            ,'notnull' => false
        ]
    ];

    public static $relationships = [
        'Person' => [
            'type' => 'one-one'
            ,'class' => Person::class
        ]
        ,'Group' => [
            'type' => 'one-one'
            ,'class' => \Emergence\People\Groups\Group::class
        ]
    ];

    public static $indexes = [
        'GroupPerson' => [
            'fields' => ['GroupID', 'PersonID']
            ,'unique' => true
        ]
    ];

    public static $dynamicFields = [
        'Group'
    ];

    public function save($deep = true)
    {
        if (!$this->Joined) {
            $this->Joined = time();
        }

        // call parent
        parent::save($deep);
    }
}