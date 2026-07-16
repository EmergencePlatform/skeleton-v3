<?php

namespace Emergence\CMS;

class PagesRequestHandler extends AbstractRequestHandler
{
    // RecordsRequestHandler config
    public static $recordClass = \Emergence\CMS\Page::class;
    public static $browseConditions = [
        'Class' => \Emergence\CMS\Page::class
    ];

    protected static function throwRecordNotFoundError($handle, $message = 'Record not found')
    {
        return static::respond('pageNotFound', [
            'success' => false
            ,'pageHandle' => $handle
        ]);
    }
}
