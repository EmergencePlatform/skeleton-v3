<?php



class ContactSubmission extends ActiveRecord
{
    // support subclassing
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class];

    // ActiveRecord configuration
    public static $tableName = 'contact_submissions';
    public static $singularNoun = 'contact submission';
    public static $pluralNoun = 'contact submissions';


    public static $fields = [
       'ContextClass' => null
       ,'ContextID' => null
       ,'Subform' => [
           'type' => 'string'
           ,'notnull' => false
       ]
       ,'Data' => 'serialized'
    ];
}
