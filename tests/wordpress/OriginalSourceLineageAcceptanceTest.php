<?php
declare(strict_types=1);
/** Blueprint p72/p74/p224/p234: real local handoffs, no generated provider evidence. */
final class OriginalSourceLineageAcceptanceTest extends WP_UnitTestCase
{
 public static function shops():array{return [['digital'],['personalized_pod'],['standard_pod'],['jewelry']];}
 /** @dataProvider shops */
 public function testReviewedResearchToImmutableVersionRetainsShopAcrossModuleBoundaries(string $shop):void
 {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  $http=[];$guard=static function($pre,$args,$url)use(&$http){$http[]=$url;return new WP_Error('offline_source_acceptance','External HTTP forbidden in local source acceptance.');};add_filter('pre_http_request',$guard,10,3);
  try{
   $research=new DigiForge\Research\Repository();$source=$research->createSource(['shop_key'=>$shop,'name'=>'Original-source local acceptance','source_type'=>'manual','environment'=>'sandbox']);self::assertNotWPError($source);self::assertSame(0,(int)$source['enabled']);
   $observation=$research->ingest(['source_id'=>$source['id'],'external_id'=>'source-contract','title'=>'Local product concept','body'=>'Synthetic test evidence, not live market research.','provenance'=>['origin'=>'offline_acceptance_fixture']]);self::assertNotWPError($observation);
   $evidence=$research->addEvidence((int)$observation['id'],['evidence_type'=>'manual_note','value'=>'Local contract fixture; not supplier or operational certification.']);self::assertNotWPError($evidence);
   $candidate=$research->createCandidate(['shop_key'=>$shop,'title'=>'Local product concept','summary'=>'Owned original-source lineage fixture','signals'=>['demand'=>80,'competition_gap'=>60,'margin'=>70,'trend'=>50,'evidence_quality'=>90]]);self::assertNotWPError($candidate);self::assertTrue($research->linkEvidence((int)$candidate['id'],(int)$evidence['id']));self::assertWPError($research->promote((int)$candidate['id']));self::assertNotWPError($research->review((int)$candidate['id'],'APPROVED','Local fixture review only.'));
   $opportunity=$research->promote((int)$candidate['id'],'local-source-promotion');self::assertNotWPError($opportunity);self::assertSame($opportunity['id'],$research->promote((int)$candidate['id'],'local-source-promotion')['id']);
   $factory=new DigiForge\ProductFactory\Repository();$family=$factory->create('product_family',['name'=>'Local family','opportunity_id'=>$opportunity['id']],'source-family-'.$shop);self::assertNotWPError($family);$product=$factory->create('product',['name'=>'Local product','product_family_id'=>$family['id']],'source-product-'.$shop);self::assertNotWPError($product);$versionInput=['product_id'=>$product['id'],'version_label'=>'local-v1','notes'=>'Original immutable fixture'];$version=$factory->create('product_version',$versionInput,'source-version-'.$shop);self::assertNotWPError($version);
   $attribution=new DigiForge\AI\MonetaryReservationRepository();self::assertNotWPError($attribution->validateAttribution($shop,['product_id'=>$product['id'],'order_id'=>0]));foreach(['digital','personalized_pod','standard_pod','jewelry']as $other)if($other!==$shop)self::assertWPError($attribution->validateAttribution($other,['product_id'=>$product['id'],'order_id'=>0]));
   $versionInput['notes']='Unapproved different material';self::assertWPError($factory->create('product_version',$versionInput,'source-version-'.$shop));self::assertSame('Original immutable fixture',$factory->find('product_version',(int)$version['id'])['notes']);
   self::assertSame([],$http);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
  }finally{remove_filter('pre_http_request',$guard,10);}
 }
}
