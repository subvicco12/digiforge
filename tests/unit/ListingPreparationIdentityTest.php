<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingPreparationIdentityTest extends TestCase {
 public function testQuotaIdentityBindsEveryMaterialLocalInputWithoutInventingAiCost():void {
  require_once dirname(__DIR__,2).'/includes/Listings/ListingPreparationQuotaRepository.php';
  $args=['digital','sandbox',3,4,5,'approved-bundle-sha','listing-key',null];
  $hash=DigiForge\Listings\ListingPreparationQuotaRepository::fingerprint(...$args);
  foreach([1=>'production',2=>13,3=>14,4=>15,5=>'changed-bundle',6=>'other-key',7=>['run_id'=>'batch','started_at'=>'2026-10-12 00:00:00']] as $index=>$value){$changed=$args;$changed[$index]=$value;self::assertNotSame($hash,DigiForge\Listings\ListingPreparationQuotaRepository::fingerprint(...$changed));}
  self::assertSame($hash,DigiForge\Listings\ListingPreparationQuotaRepository::fingerprint(...$args));
 }
}
