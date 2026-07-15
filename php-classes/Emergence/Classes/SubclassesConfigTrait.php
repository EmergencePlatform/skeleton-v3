<?php

namespace Emergence\Classes;

trait SubclassesConfigTrait
{
	public static $rootClass;
    public static $defaultClass;
    public static $subClasses;

    public static function getStaticRootClass($boundingParentClass = self::class)
    {
        if (static::$rootClass) {
            return static::$rootClass;
        }

        // detect root class by crawling up the inheritence tree until an abstract parent is found
        $class = new \ReflectionClass(static::class);
        while ($parentClass = $class->getParentClass()) {
            
            if ($parentClass->isAbstract()) {
                return $class->getName();
            }

            $class = $parentClass;
        }
    }

    public static function getStaticDefaultClass()
    {
        if (static::$defaultClass) {
            return static::$defaultClass;
        }

        return static::getStaticRootClass();
    }

    public static function getStaticSubClasses()
    {
        if (static::$subClasses) {
            return static::$subClasses;
        }

        return array_unique([static::getStaticRootClass(), static::class]);
    }


    // instance wrappers
    public function getRootClass($boundingParentClass = self::class)
    {
        return static::getStaticRootClass($boundingParentClass);
    }

    public function getDefaultClass()
    {
        return static::getStaticDefaultClass();
    }

    public function getSubClasses()
    {
        return static::getStaticSubClasses();
    }
}
