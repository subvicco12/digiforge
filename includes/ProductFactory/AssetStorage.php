<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/** Protects U3 generated assets from direct public web access. */
final class AssetStorage
{
    public const RELATIVE_ROOT = 'digiforge/product-factory';

    public static function ensureProtectedRoot(): bool
    {
        $uploads = wp_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir'])) {
            return false;
        }
        $root = trailingslashit((string) $uploads['basedir']) . self::RELATIVE_ROOT;
        if (! wp_mkdir_p($root)) {
            return false;
        }

        $index = trailingslashit($root) . 'index.php';
        if (! is_file($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
        }

        $htaccess = trailingslashit($root) . '.htaccess';
        $rules = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (! is_file($htaccess) || (string) @file_get_contents($htaccess) !== $rules) {
            @file_put_contents($htaccess, $rules, LOCK_EX);
        }
        return is_file($index) && is_file($htaccess);
    }

    public static function absolutePath(string $storageReference): ?string
    {
        if (! str_starts_with($storageReference, self::RELATIVE_ROOT . '/')) {
            return null;
        }
        $uploads = wp_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }
        $root = realpath(trailingslashit((string) $uploads['basedir']) . self::RELATIVE_ROOT);
        $path = realpath(trailingslashit((string) $uploads['basedir']) . ltrim($storageReference, '/'));
        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }
        $prefix = trailingslashit($root);
        return str_starts_with($path, $prefix) ? $path : null;
    }
}
