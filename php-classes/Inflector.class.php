<?php

class Inflector
{
    public static function spacifyCaps($string)
    {
        return preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $string);
    }

    public static function pluralize($noun, $count = 2)
    {
        if ($count == 1) {
            return $noun;
        }
        return $noun.'s';
    }

    public static function pluralizeRecord($Record, $count = 2)
    {
        if ($count == 1) {
            return $Record::$singularNoun;
        }
        return $Record::$pluralNoun;
    }

    public static function labelIdentifier($string)
    {
        $string = ucfirst(strtolower((string) static::spacifyCaps($string)));

        return preg_replace('/\bid(s?)\b/i', 'ID$1', $string);
    }
}
