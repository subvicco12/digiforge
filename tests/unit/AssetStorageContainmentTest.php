<?php
declare(strict_types=1);

use DigiForge\ProductFactory\AssetStorage;
use PHPUnit\Framework\TestCase;

final class AssetStorageContainmentTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCanonicalPlatformPathsRetainContainmentAndRejectSiblingTraversal(): void
    {
        if (!function_exists('trailingslashit')) eval('function trailingslashit(string $p):string{return rtrim($p,"/\\\\")."/";}');
        if (!function_exists('wp_upload_dir')) eval('function wp_upload_dir():array{return ["basedir"=>$GLOBALS["containment_uploads"]];}');
        require_once dirname(__DIR__,2).'/includes/ProductFactory/AssetStorage.php';
        $base=sys_get_temp_dir().'/df-containment-'.bin2hex(random_bytes(8));
        $GLOBALS['containment_uploads']=$base;
        $root=$base.'/digiforge/product-factory';$sibling=$base.'/digiforge/product-factory-sibling';
        mkdir($root,0700,true);mkdir($sibling,0700);
        file_put_contents($root.'/inside.txt','inside');file_put_contents($sibling.'/outside.txt','outside');
        try {
            self::assertSame(realpath($root.'/inside.txt'),AssetStorage::absolutePath('digiforge/product-factory/inside.txt'));
            self::assertNull(AssetStorage::absolutePath('digiforge/product-factory/../product-factory-sibling/outside.txt'));
            self::assertNull(AssetStorage::absolutePath('digiforge/product-factory-sibling/outside.txt'));
            self::assertNull(AssetStorage::absolutePath('digiforge/product-factory/missing.txt'));
            self::assertNull(AssetStorage::absolutePath('digiforge/product-factory/'));
        } finally {
            unlink($root.'/inside.txt');unlink($sibling.'/outside.txt');rmdir($root);rmdir($sibling);rmdir($base.'/digiforge');rmdir($base);
        }
    }
}
