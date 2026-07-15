<?php

namespace Emergence\CMS\Item;

use Emergence\People\Person;

abstract class AbstractItem extends \VersionedRecord
{
    // ActiveRecord configuration
    public static $tableName = 'content_items';
    public static $singularNoun = 'content item';
    public static $pluralNoun = 'content items';

    // required for shared-table subclassing support
    public static $rootClass = self::class;
    public static $defaultClass = \Emergence\CMS\Item\Text::class;
    public static $subClasses = [
        \Emergence\CMS\Item\Album::class
        ,\Emergence\CMS\Item\Embed::class
        ,\Emergence\CMS\Item\Media::class
        ,\Emergence\CMS\Item\RichText::class
        ,\Emergence\CMS\Item\Text::class
        ,\Emergence\CMS\Item\Markdown::class
    ];

    public static $fields = [
        'Title' => [
            'notnull' => false
            ,'blankisnull' => true
        ]
        ,'ContentID' => [
            'type'  => 'integer'
            ,'unsigned' => true
            ,'index' => true
        ]
        ,'AuthorID' => [
            'type'  =>  'integer'
            ,'unsigned' => true
        ]
        ,'Status' => [
            'type' => 'enum'
            ,'values' => ['Draft','Published','Hidden','Deleted']
            ,'default' => 'Published'
        ]
        ,'Order' => [
            'type' => 'integer'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Data' => 'json'
    ];

    public static $relationships = [
        'Author'    =>  [
            'type'  =>  'one-one'
            ,'class' => Person::class
        ]
        ,'Content' =>   [
            'type'  =>  'one-one'
            ,'class' => \Emergence\CMS\AbstractContent::class
        ]
    ];

    public static $validators = [
        'Content' => 'require-relationship'
    ];

    public function validate($deep = true)
    {
        // call parent
        parent::validate();

        // save results
        return $this->finishValidation();
    }

    public function save($deep = true)
    {
        // set author
        if (!$this->AuthorID && $_SESSION !== [] && !empty($_SESSION['User'])) {
            $this->Author = $_SESSION['User'];
        }

        // call parent
        parent::save($deep);
    }


    abstract public function renderBody();
}