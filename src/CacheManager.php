<?php

namespace StoneScriptPHP;

class CacheManager
{
    private static ?Cache $instance = null;
    private static ?CacheInvalidator $invalidator = null;

    public static function instance(): Cache
    {
        if (self::$instance === null) {
            self::$instance = Cache::fromEnv();
        }

        return self::$instance;
    }

    public static function invalidator(): CacheInvalidator
    {
        if (self::$invalidator === null) {
            self::$invalidator = new CacheInvalidator(self::instance());
        }

        return self::$invalidator;
    }

    public static function setInstance(Cache $cache): void
    {
        self::$instance = $cache;
        self::$invalidator = null;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$invalidator = null;
    }

    public static function configure(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
        int $database = 0,
        string $prefix = 'stonescript:',
        int $defaultTtl = 3600,
        bool $enabled = true
    ): Cache {
        $cache = new Cache(
            host: $host,
            port: $port,
            password: $password,
            database: $database,
            prefix: $prefix,
            defaultTtl: $defaultTtl,
            enabled: $enabled
        );

        self::setInstance($cache);

        return $cache;
    }
}
