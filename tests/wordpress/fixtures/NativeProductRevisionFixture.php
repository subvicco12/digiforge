<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{NativePortContract, NativeSourceTransaction, ShopBinding};

/** Disposable revision producer. The runtime neither installs nor reads this table. */
final class NativeProductRevisionFixture
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix.'digiforge_native_revision_fixture';
    }

    public static function migration(): string
    {
        return 'CREATE TABLE '.self::table().' (
            product_scope char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision bigint unsigned NOT NULL,
            source_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (product_scope)
        ) ENGINE=InnoDB';
    }

    private static function key(ShopBinding $scope, int $product): string
    {
        return NativePortContract::hash(['DF_NATIVE_PRODUCT_REVISION_FIXTURE_1',$scope->shopKey,
            $scope->integrationId,$scope->providerShopId,$scope->environment,$product]);
    }

    /** A reviewed source can advance its scoped counter once for the expected predecessor. */
    public static function advance(ShopBinding $scope, int $product, int $expected, string $source): bool
    {
        global $wpdb;
        $connection = NativeSourceTransaction::id();
        if (is_wp_error($connection) || $product < 1 || $expected < 0 || $expected === PHP_INT_MAX
            || !preg_match('/^[a-f0-9]{64}$/D',$source)) return false;
        $database = $wpdb;
        $table = self::table();
        $key = self::key($scope,$product);
        $query = $expected === 0
            ? $wpdb->prepare("INSERT IGNORE INTO $table (product_scope,revision,source_hash) VALUES (%s,1,%s)",$key,$source)
            : $wpdb->prepare("UPDATE $table SET revision=revision+1,source_hash=%s WHERE product_scope=%s AND revision=%d AND source_hash<>%s",
                $source,$key,$expected,$source);
        $rows = $wpdb->query($query);
        return $rows === 1 && !is_wp_error(NativeSourceTransaction::verify($database,$connection));
    }

    /** Lock the latest revision and bind it to the reviewed source in the caller's transaction. */
    public static function lockCurrent(ShopBinding $scope, int $product, int $expected, string $source): bool
    {
        global $wpdb;
        $connection = NativeSourceTransaction::id();
        if (is_wp_error($connection) || $product < 1 || $expected < 1
            || !preg_match('/^[a-f0-9]{64}$/D',$source)) return false;
        $database = $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT revision,source_hash FROM $table WHERE product_scope=%s FOR UPDATE",
            self::key($scope,$product)),ARRAY_A);
        return $wpdb->last_error === '' && is_array($row) && (string)$row['revision'] === (string)$expected
            && hash_equals($row['source_hash'],$source) && !is_wp_error(NativeSourceTransaction::verify($database,$connection));
    }
}
