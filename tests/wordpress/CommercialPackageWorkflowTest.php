<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/../unit/ApprovedDesignListingPackageTest.php';
require_once __DIR__.'/../fixtures/product-packages/CommercialWorksheetFixture.php';

/** Synthetic service-issued local decisions only; never owner/provider/commercial authority. */
final class CommercialPackageWorkflowTest extends WP_UnitTestCase {
 public function testBothFamilyCostVersionsRequireNewExactDecisionAndKeepPriorSeal():void {
  $http=0;$deny=static function()use(&$http){++$http;throw new RuntimeException('No external requests');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{foreach(['FL-03','FL-04']as $id){
   $actor=self::factory()->user->create();$u=new WP_User($actor);$u->add_cap('manage_digiforge_pod');$u->add_cap('manage_digiforge_research');wp_set_current_user($actor);DigiForge\Core\Activator::activate();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($actor,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);DigiForge\Core\Settings::protectProduction();
   $f=new DigiForge\Tests\ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');$in=$f->design()['record']['input'];foreach($in['briefs']as $i=>&$brief)$brief['visual_direction']='Synthetic commercial fixture direction '.$i;unset($brief);[$w,$h]=$id==='FL-04'?[2418,3018]:[2475,1155];$in['artwork']['width_px']=$w;$in['artwork']['height_px']=$h;$in['artwork']['svg']='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'"><text>Synthetic technical fixture only</text></svg>';
   $art=new DigiForge\Research\OpportunityDesignRepository();$v=$art->save($in);self::assertIsArray($v);$a=$art->decide('personalized_pod',$v['version_hash'],'APPROVE');self::assertIsArray($a);
   $e=json_decode(file_get_contents(__DIR__.'/../fixtures/product-packages/'.strtolower($id).'.json'),true);$e['economics']['sale_price']=$id==='FL-03'?17.99:18.99;
   $binding=['shop_key'=>'personalized_pod','environment'=>'test','fixture_id'=>$id,'product_id'=>$e['fixture']['printify_product_id'],'artwork_sha256'=>hash('sha256',$in['artwork']['svg']),'template_fingerprint'=>null,'blueprint_id'=>null,'provider_id'=>null,'variant_ids'=>[]];$e['commercial_worksheet']=CommercialWorksheetFixture::input($binding);$e['commercial_worksheet']['revenue']['item_price_minor']['value']=$id==='FL-03'?1799:1899;$e['commercial_worksheet']['revenue']['buyer_shipping_minor']['value']=$id==='FL-03'?729:739;
   $repo=new DigiForge\POD\ProductPackageRepository();$p=$repo->prepare('personalized_pod',$a['version_hash'],$e);self::assertIsArray($p);self::assertNull($p['decision']);self::assertWPError($repo->export('personalized_pod',$p['hash']));self::assertIsArray($repo->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC held review only'));
   $before=$repo->export('personalized_pod',$p['hash']);self::assertIsString($before);$out=json_decode($before,true);self::assertNull($out['package']['economics']['worksheet']['actual_contribution_minor']);self::assertSame($e['economics']['sale_price'],$out['package']['economics']['assumptions']['sale_price']);
   $e['commercial_worksheet']['costs']['base_cost_minor']['value']++;$next=$repo->prepare('personalized_pod',$a['version_hash'],$e,$p['hash']);self::assertIsArray($next);self::assertNotSame($p['hash'],$next['hash']);self::assertNull($next['decision']);self::assertWPError($repo->export('personalized_pod',$next['hash']));self::assertSame($before,$repo->export('personalized_pod',$p['hash']));
   $e['commercial_worksheet']['binding']['product_id']='foreign-product';self::assertWPError($repo->prepare('personalized_pod',$a['version_hash'],$e));self::assertWPError($repo->read('digital',$p['hash']));$u->add_cap('manage_digiforge_pod',false);self::assertWPError($repo->read('personalized_pod',$p['hash']));
  }self::assertSame(0,$http);}finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
}
