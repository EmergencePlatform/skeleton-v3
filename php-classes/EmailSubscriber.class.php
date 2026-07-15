<?php


class EmailSubscriber extends ActiveRecord
{
    // support subclassing
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class];

    // ActiveRecord configuration
    public static $tableName = 'email_subscribers';
    public static $singularNoun = 'email_subscriber';
    public static $pluralNoun = 'email subscribers';

    public static $fields = [
        'ContextClass' => null
        ,'ContextID' => null
        ,'Name' => [
            'type' => 'string'
            ,'notnull' => false
        ]
        ,'Email' => [
            'type' => 'string'
            ,'unique' => true
            ,'notnull' => true
        ]
    ];

    public function validate($deep = true)
    {
        // call parent
        parent::validate($deep);

        $this->_validator->validate([
            'field' => 'Email'
            ,'validator' => 'email'
        ]);

        // save results
        return $this->finishValidation();
    }
}