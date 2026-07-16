<?php

namespace Emergence\Classes;

trait StackedConfigTrait
{
    protected static $stackedConfigs = [];

    protected static function initStackedConfig($propertyName)
    {
        $className = static::class;

        // merge fields from first ancestor up
        $classes = class_parents($className);
        array_unshift($classes, $className);

        $config = [];
        while ($class = array_pop($classes)) {
            // as of PHP 8.1, get_class_vars() returns the *declared default*
            // of a static property instead of its current value (5.6–8.0
            // returned the live value), which hid every runtime mutation made
            // by __classLoaded()/config layers; read live statics via
            // reflection instead
            $classVars = (new \ReflectionClass($class))->getStaticProperties() + get_class_vars($class);
            if (!empty($classVars[$propertyName])) {
                $config = array_merge($config, $classVars[$propertyName]);
            }
        }

        // apply property-specific initialization
        $initMethodName = 'init'.ucfirst((string) $propertyName);
        if (method_exists($className, $initMethodName)) {
            return call_user_func([$className, $initMethodName], $config);
        }

        return $config;
    }

    public static function &getStackedConfig($propertyName, $key = null)
    {
        $className = static::class;

        if (!isset(static::$stackedConfigs[$className][$propertyName])) {
            static::$stackedConfigs[$className][$propertyName] = static::initStackedConfig($propertyName);
        }

        if ($key) {
            if (array_key_exists($key, static::$stackedConfigs[$className][$propertyName])) {
                return static::$stackedConfigs[$className][$propertyName][$key];
            }
            return null;
        }
        return static::$stackedConfigs[$className][$propertyName];
    }

    public static function aggregateStackedConfig($propertyName, array $classes)
    {
        $config = [];

        foreach ($classes as $class) {
            $config = array_merge($config, $class::getStackedConfig($propertyName));
        }

        return $config;
    }
}
