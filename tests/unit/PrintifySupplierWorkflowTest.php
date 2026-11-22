<?php
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{ProductIntelligence,PrintifySupplierWorkflow};
require_once __DIR__.'/../../includes/POD/ProductIntelligence.php';
require_once __DIR__.'/../../includes/POD/PrintifySupplierWorkflow.php';
final class PrintifySupplierWorkflowTest extends TestCase {
 private function packet():array{
  $p=['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'source','name'=>'retained.json','sha256'=>str_repeat('a',64),'modified_at'=>'2026-11-06T00:00:00Z']],'sections'=>[]];
  foreach(['concepts'=>[['V2 ID'=>'DG2-001','Source DG ID'=>'DG-001','Physical Product'=>'PP-001']], 'variants'=>[['Blueprint ID'=>'5','Provider ID'=>'7','Variant ID'=>'9','Title'=>'Mug','Production Cost'=>null]], 'print_areas'=>[['Blueprint ID'=>'5','Provider ID'=>'7','Variant ID'=>'9','Width PX'=>'1200','Height PX'=>'800','DPI'=>null]]] as $key=>$rows)$p['sections'][$key]=['source_id'=>'source','sheet'=>$key,'rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)];return $p;
 }
 public function testBindsExactVariantAndPreservesUnknowns():void{$r=PrintifySupplierWorkflow::review($this->packet(),5,7,9);self::assertFalse($r['production_authority']);self::assertNull($r['production_cost']);self::assertSame('1200',$r['evidence']['print_areas'][0]['facts']['Width PX']);self::assertNull($r['evidence']['print_areas'][0]['facts']['DPI']);self::assertSame(str_repeat('a',64),$r['evidence']['print_areas'][0]['source_sha256']);self::assertContains('SHIPPING_EVIDENCE_MISSING',$r['gaps']);self::assertContains('APPROVED_NATIVE_PERSONALIZATION_REQUIRED',$r['gaps']);}
 public function testCrossProviderSelectionCannotBorrowEvidence():void{$this->expectException(\InvalidArgumentException::class);PrintifySupplierWorkflow::review($this->packet(),5,8,9);}
 public function testTamperedOrForeignPacketIsRejected():void{$p=$this->packet();$p['shop_key']='digital';$this->expectException(\InvalidArgumentException::class);PrintifySupplierWorkflow::review($p,5,7,9);}
 public function testInvalidIdentifierIsRejected():void{$this->expectException(\InvalidArgumentException::class);PrintifySupplierWorkflow::review($this->packet(),0,7,9);}
 public function testConflictingObservationsAreNotSilentlyMerged():void{$p=$this->packet();$p['sections']['variants']['rows'][]=['Blueprint ID'=>'5','Provider ID'=>'7','Variant ID'=>'9','Title'=>'Changed'];$p['sections']['variants']['rows_sha256']=ProductIntelligence::rowsHash($p['sections']['variants']['rows']);$r=PrintifySupplierWorkflow::review($p,5,7,9);self::assertContains('VARIANT_OBSERVATIONS_REQUIRE_RECONCILIATION',$r['gaps']);self::assertCount(2,$r['evidence']['variants']);}
 public function testServiceIndexDoesNotCertifyDestinationRates():void{$p=$this->packet();$rows=[['Blueprint ID'=>'5','Provider ID'=>'7','Service'=>'standard','Evidence'=>'SERVICE_INDEX_ONLY']];$p['sections']['shipping']=['source_id'=>'source','sheet'=>'shipping','rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)];$r=PrintifySupplierWorkflow::review($p,5,7,9);self::assertContains('DESTINATION_RATES_MISSING',$r['gaps']);self::assertNull($r['destination_shipping_rate']);}
}
