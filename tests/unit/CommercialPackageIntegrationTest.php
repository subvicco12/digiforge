<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\ApprovedDesignListingPackage;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/ApprovedDesignListingPackageTest.php';require_once __DIR__.'/CommercialWorksheetTest.php';
final class CommercialPackageIntegrationTest extends TestCase {
 public function inputs():array {
  $f=new ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');$d=$f->design();$e=$f->evidence();$binding=['shop_key'=>'personalized_pod','environment'=>'test','fixture_id'=>'FL-04','product_id'=>'6ae9913d9541969415088814','artwork_sha256'=>hash('sha256',$d['record']['input']['artwork']['svg']),'template_fingerprint'=>null,'blueprint_id'=>null,'provider_id'=>null,'variant_ids'=>[]];$w=\CommercialWorksheetFixture::input($binding);$w['revenue']['item_price_minor']['value']=1899;$w['revenue']['buyer_shipping_minor']['value']=739;$e['commercial_worksheet']=$w;foreach($e['economics']as $k=>$v)if(!in_array($k,['currency','sale_price'],true))$e['economics'][$k]=null;$e['economics']['sale_price']=18.99;return [$d,$e];
 }
 public function testWorksheetIsSealedWithExactArtifactAndPriceRemainsItemOnly():void{[$d,$e]=$this->inputs();$p=ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);self::assertArrayHasKey('worksheet',$p['economics'],'Optional commercial evidence must not be silently omitted.');self::assertSame(18.99,$p['economics']['assumptions']['sale_price']);self::assertSame(7.39,$p['economics']['assumptions']['buyer_shipping']);self::assertSame(2638,$p['economics']['worksheet']['conditional']['gross_revenue_minor']);self::assertNull($p['economics']['worksheet']['actual_contribution_minor']);$e['commercial_worksheet']['costs']['base_cost_minor']['value']=600;$next=ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);self::assertNotSame($p['sha256'],$next['sha256']);self::assertFalse($next['commercial_approved']);}
 public function testDeliveredTotalCannotBeReinterpretedAsItemPrice():void{[$d,$e]=$this->inputs();$e['economics']['sale_price']=26.38;$this->expectException(\InvalidArgumentException::class);ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);}
 public function testArtworkOrProductSubstitutionCannotKeepWorksheetBinding():void{[$d,$e]=$this->inputs();$e['commercial_worksheet']['binding']['artwork_sha256']=str_repeat('e',64);$this->expectException(\InvalidArgumentException::class);ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);}
 public function testDuplicateLegacyCostsAreRejectedInsteadOfDoubleCounted():void{[$d,$e]=$this->inputs();$e['economics']['base_cost']=5;$this->expectException(\InvalidArgumentException::class);ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);}
}
