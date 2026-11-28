<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify {
 function is_wp_error(mixed $value): bool { return $value instanceof \WP_Error; }
}
namespace {
 if (!class_exists('WP_Error')) { class WP_Error { public function __construct(...$args) {} } }
require_once __DIR__.'/../../includes/Connector/Printify/NativeProtectedOptionDependencies.php';
require_once __DIR__.'/../../includes/Connector/Printify/NativeFailure.php';
use DigiForge\Connector\Printify\NativeProtectedOptionDependencies as Roots;
use PHPUnit\Framework\TestCase;
final class NativeProtectedOptionCaptureTest extends TestCase {
 public function testCaptureRetainsOnlyExactHashesAndMissingPointerEvidence():void {
  $key='digiforge_product_package_'.str_repeat('a',64);$raw='synthetic-private-artwork-bytes';
  $r=Roots::capture(static function()use($key,$raw){Roots::observeOption($key,$raw);Roots::observeOption($key.'_decision',null);return ['ok'=>true];});
  self::assertIsArray($r);self::assertSame(['ok'=>true],$r['value']);self::assertSame(['exists'=>true,'sha256'=>hash('sha256',$raw)],$r['dependencies'][$key]);self::assertSame(['exists'=>false,'sha256'=>null],$r['dependencies'][$key.'_decision']);self::assertStringNotContainsString($raw,json_encode($r));
 }
 public function testConflictingRepeatedObservationCannotBindEarlierBytesToLaterBytes():void {
  $r=Roots::capture(static function(){Roots::observeOption('digiforge_pp_plan_a','earlier');Roots::observeOption('digiforge_pp_plan_a','later');return [];});self::assertInstanceOf(WP_Error::class,$r);
 }
 public function testMissingGetterInstrumentationCannotProduceAnEmptyValidManifest():void {
  self::assertInstanceOf(WP_Error::class,Roots::capture(static fn()=>['looks_valid'=>true]));
 }
 public function testSecretAndNonBusinessKeysAreRejectedEvenWithNoRawSecret():void {
  foreach(['digiforge_pp_plan_secret','wp_user_roles','session_tokens']as $key){self::assertInstanceOf(WP_Error::class,Roots::capture(static function()use($key){Roots::observeOption($key,null);return [];}));}
 }
 public function testNestedCaptureCannotHideAChangedDependencyAndFramesAlwaysClear():void {
  $key='digiforge_pp_plan_a';$r=Roots::capture(static function()use($key){Roots::observeOption($key,'first');Roots::capture(static function()use($key){Roots::observeOption($key,'second');return [];});return [];});self::assertInstanceOf(WP_Error::class,$r);
  self::assertInstanceOf(WP_Error::class,Roots::capture(static function(){throw new RuntimeException('synthetic');}));
  $r=Roots::capture(static function()use($key){Roots::observeOption($key,'fresh');return [];});self::assertIsArray($r);self::assertSame(hash('sha256','fresh'),$r['dependencies'][$key]['sha256']);
 }
 public function testOversizedRawObservationIsDeniedAndDoesNotPoisonTheNextCapture():void {
  $raw=str_repeat('x',67108865);$key='digiforge_pp_plan_a';
  self::assertInstanceOf(WP_Error::class,Roots::capture(static function()use($key,$raw){Roots::observeOption($key,$raw);return [];}));unset($raw);
  $next=Roots::capture(static function()use($key){Roots::observeOption($key,'bounded');return [];});self::assertIsArray($next);
 }
 public function testTooManyUniqueRootsAreDenied():void {
  self::assertInstanceOf(WP_Error::class,Roots::capture(static function(){for($i=0;$i<97;$i++)Roots::observeOption('digiforge_pp_plan_'.$i,'bounded');return [];}));
 }

}

}
