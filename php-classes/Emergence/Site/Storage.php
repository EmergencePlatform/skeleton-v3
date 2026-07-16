<?php

namespace Emergence\Site;

use Site;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as LocalAdapter;

class Storage
{
    /**
     * Per-bucket filesystem configs consumed by getFilesystem(), keyed by
     * bucket id, in the shape accepted by
     * \Emergence\Storage\FilesystemFactory::createFromConfig():
     *
     *     Storage::$filesystemConfigs['media'] = [
     *         'driver' => 'gcs',
     *         'bucket' => 'my-site-media',
     *     ];
     *
     * Sites can populate this from php-config (this class's .config.php /
     * .config.d) or equivalently via the 'storage' site config passed to
     * Site::initialize():
     *
     *     'storage' => ['filesystems' => ['media' => [...]]]
     *
     * Any bucket without a config gets the legacy local filesystem rooted
     * at getLocalStorageRoot()/{bucketId}.
     */
    public static $filesystemConfigs = [];

    protected static $filesystems;

    /**
     * Get the root path for all local storage
     *
     * @return string $localRootStoragePath
     */
    public static function getLocalStorageRoot()
    {
        $siteStorageConfig = Site::getConfig('storage');

        if ($siteStorageConfig && !empty($siteStorageConfig['local_root'])) {
            return $siteStorageConfig['local_root'];
        }

        return Site::$rootPath.'/site-data';
    }

    /**
     * Get the filesystem config declared for the given bucket id, or null
     *
     * @param string $bucketId
     *
     * @return array|null
     */
    public static function getFilesystemConfig($bucketId)
    {
        if (isset(static::$filesystemConfigs[$bucketId])) {
            return static::$filesystemConfigs[$bucketId];
        }

        $siteStorageConfig = Site::getConfig('storage');

        return $siteStorageConfig['filesystems'][$bucketId] ?? null;
    }

    /**
     * Whether the given bucket id is backed by remote storage rather than
     * a directly-accessible local filesystem path
     *
     * @param string $bucketId
     *
     * @return bool
     */
    public static function isRemote($bucketId)
    {
        $config = static::getFilesystemConfig($bucketId);

        return $config !== null && ($config['driver'] ?? 'local') !== 'local';
    }

    /**
     * Register filesystem for given bucket id
     *
     * @param string $bucketId
     */
    public static function registerFilesystem($bucketId, FilesystemInterface $fs)
    {
        static::$filesystems[$bucketId] = $fs;
    }

    /**
     * Get registered or configured filesystem for given bucket id, falling
     * back to default local storage
     *
     * @param string $bucketId
     *
     * @return FilesystemInterface
     */
    public static function getFilesystem($bucketId)
    {
        if (empty(static::$filesystems[$bucketId])) {
            $config = static::getFilesystemConfig($bucketId);

            // build from declared config via emergence/php-core's factory
            // when available (class_exists keeps this a soft dependency so
            // sites without a declared config never need the factory)
            $factoryClass = 'Emergence\\Storage\\FilesystemFactory';

            if ($config !== null && class_exists($factoryClass)) {
                static::$filesystems[$bucketId] = call_user_func(
                    [$factoryClass, 'createFromConfig'],
                    array_merge(
                        ['root' => static::getLocalStorageRoot().'/'.$bucketId],
                        $config
                    )
                );
            } else {
                $adapter = new LocalAdapter(static::getLocalStorageRoot().'/'.$bucketId);
                static::$filesystems[$bucketId] = new Filesystem($adapter);
            }
        }

        return static::$filesystems[$bucketId];
    }
}
