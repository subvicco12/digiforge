<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

/** Reads a private JSON file with no lookup in WordPress settings or in the database. */
final class DirectReadSecret
{
    private const LIMIT = 4096;

    public static function configured(): bool
    {
        return defined('DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE');
    }

    public static function read(): ?array
    {
        if (!self::configured()) return null;
        $path = DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE;
        if (!is_string($path) || !str_starts_with($path,'/') || str_contains($path,"\0")) return null;
        clearstatcache(true);
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (!is_string($documentRoot) || !str_starts_with($documentRoot,'/')) return null;
        $web = realpath($documentRoot);
        $wp = realpath(ABSPATH);
        if ($web === false || $wp === false || realpath($path) !== $path
            || self::within($path,$web) || self::within($path,$wp)) return null;
        $before = @lstat($path);
        if (!self::safe($before)) return null;
        $file = @fopen($path,'rb');
        if ($file === false) return null;
        try {
            $opened = fstat($file);
            if (!self::safe($opened) || $before['ino']!==$opened['ino'] || $before['dev']!==$opened['dev']) return null;
            $raw = stream_get_contents($file,self::LIMIT+1);
            if (!is_string($raw) || strlen($raw)>self::LIMIT || strlen($raw)!==$opened['size']) return null;
            clearstatcache(true);
            $current = @lstat($path);
            if (!self::safe($current) || array_intersect_key($opened,array_flip(['ino','dev','size','mtime','ctime']))
                !== array_intersect_key($current,array_flip(['ino','dev','size','mtime','ctime']))) return null;
            $config = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($config) || count($config)!==8 || ($config['schema'] ?? null)!==1
                || ($config['state'] ?? null)!=='approved-read-only'
                || !is_int($config['expires_at'] ?? null) || $config['expires_at']<=time()
                || !is_string($config['token'] ?? null) || !preg_match('/^[A-Za-z0-9._~-]{16,512}$/D',$config['token'])
                || !is_array($config['scope'] ?? null) || count($config['scope'])!==4) return null;
            foreach (['stop_all','external_lock','release_hold'] as $gate) {
                if (!is_bool($config[$gate] ?? null)) return null;
            }
            foreach (['shop'=>'/^[1-9][0-9]{0,18}$/D','account'=>'/^[A-Za-z0-9_-]{1,100}$/D',
                'integration'=>'/^[A-Za-z0-9_-]{1,100}$/D','custody'=>'/^custody-ref:[A-Za-z0-9_-]{1,88}$/D'] as $key=>$pattern) {
                if (!is_string($config['scope'][$key] ?? null) || !preg_match($pattern,$config['scope'][$key])) return null;
            }
            return $config;
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($file);
        }
    }

    private static function within(string $path,string $root): bool
    {
        return $path===$root || str_starts_with($path,rtrim($root,'/').'/');
    }

    private static function safe(array|false $stat): bool
    {
        return $stat!==false && ($stat['mode'] & 0170000)===0100000
            && ($stat['mode'] & 0077)===0 && $stat['nlink']===1 && $stat['size']<=self::LIMIT
            && (!function_exists('posix_geteuid') || $stat['uid']===posix_geteuid());
    }
}
