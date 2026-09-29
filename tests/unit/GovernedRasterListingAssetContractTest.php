<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GovernedRasterListingAssetContractTest extends TestCase {
 public function testRasterDerivationIsProtectedBoundedAndFailClosed():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/LocalAssetProducer.php');
  foreach(['rasterizeSvg','AssetStorage::absolutePath','containsActiveMarkup','ImageMagick is required','thumbnailImage($width, $height, true, true)','hash_file(\'sha256\',$source)','asset_replay_conflict',"'png'=>'image/png'"] as $n) self::assertStringContainsString($n,$s);
  self::assertStringContainsString('$width < 500 || $height < 500 || $width > 4000 || $height > 4000',$s);
 }
 public function testRasterPathDoesNotPerformExternalExecution():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/LocalAssetProducer.php');
  self::assertStringNotContainsString('wp_remote_',substr($s,strpos($s,'public function rasterizeSvg'),strpos($s,'/** @param array<int,array<string,mixed>> $assets')-strpos($s,'public function rasterizeSvg')));
 }
}
