<?php

use Emergence\People\Person;

abstract class Token extends ActiveRecord
{
    public static $expirationHours = 48;
    public static $emailTemplate = 'token';

    public static $tableName = 'tokens';
    public static $collectionRoute = '/tokens';

    // support subclassing
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class, 'PasswordToken'];


    public static $fields = [
        'Handle' => [
            'type' => 'string'
            ,'unique' => true
        ]
        ,'Expires' => [
            'type' => 'timestamp'
            ,'notnull' => false
        ]
        ,'Used' => [
            'type' => 'timestamp'
            ,'notnull' => false
        ]
    ];


    public static $relationships = [
        'Creator' => [
            'type' => 'one-one'
            ,'class' => Person::class
            ,'local' => 'CreatorID'
        ]
    ];

    public function handleRequest($data)
    {
        // do nothing
    }

    public function getValue($name)
    {
        switch ($name) {
            case 'isExpired':
                return ($this->Expires < time());
            case 'isUsed':
                return $this->Used == true;
            default:
                return parent::getValue($name);
        }
    }

    public function save($deep = true)
    {
        // set handle
        if (!$this->Handle) {
            $this->Handle = HandleBehavior::generateRandomHandle($this);
        }

        if (!$this->Expires) {
            $this->Expires = time() + (3600 * static::$expirationHours);
        }

        // call parent
        parent::save($deep);
    }

    public function sendEmail($email)
    {
        return Emergence\Mailer\Mailer::sendFromTemplate($email, static::$emailTemplate, [
            'Token' => $this
        ]);
    }
}
