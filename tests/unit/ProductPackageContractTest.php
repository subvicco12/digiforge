<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\ApprovedDesignListingPackage;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/ApprovedDesignListingPackageTest.php';
if(is_file(__DIR__.'/../../includes/POD/ProductPackageSpecification.php'))require_once __DIR__.'/../../includes/POD/ProductPackageSpecification.php';
final class ProductPackageContractTest extends TestCase
{
    public function testFl03UsesItsOwnTechnicalIdentity(): void
    {
        $f=new ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');
        $d=$f->design();
        $d['record']['input']['artwork']['width_px']=2475;
        $d['record']['input']['artwork']['height_px']=1155;
        $d['version_hash']=hash('sha256',wp_json_encode($d['record'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $d['approval']['evidence_hash']=$d['version_hash'];
        $e=$f->evidence(); $e['fixture']=['fixture_id'=>'FL-03','printify_product_id'=>'6ae98d61eb5d7e24b80ca0a6','etsy_listing_id'=>null,'etsy_state'=>'NOT_TRANSFERRED'];
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);
        self::assertSame('FL-03',$p['fixture_id']);
        self::assertSame('6ae98d61eb5d7e24b80ca0a6',$p['provider']['product_id']);
        self::assertSame([],$p['provider']['variant_ids']);
        self::assertFalse($p['commercial_approved']);
    }

    public function testTextPhotoSpecificationsRemainManualAndUncertified(): void
    {
        $spec=\DigiForge\POD\ProductPackageSpecification::normalize(['native_fields'=>[
            ['layer'=>'Name','type'=>'TEXT','provider_field_id'=>null,'max_characters'=>30,'required'=>true],
            ['layer'=>'Photo','type'=>'PHOTO','provider_field_id'=>null,'max_files'=>1,'slot'=>1,'required'=>true]],
            'shipping'=>['destination'=>'US','processing_min_days'=>7,'processing_max_days'=>14,
                'source_reference'=>null,'source_sha256'=>null]],['Name','Photo']);
        self::assertSame('MANUAL_REVIEW',$spec['overflow_policy']);
        self::assertSame('MANUAL_CROP_AND_ORDER_REVIEW',$spec['photo_policy']);
        self::assertContains('NATIVE_PROVIDER_FIELD_EVIDENCE_REQUIRED',$spec['blockers']);
        self::assertFalse($spec['supplier_certified']);
    }
    public function testForeignLayerCannotBeBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\ProductPackageSpecification::normalize(['native_fields'=>[
            ['layer'=>'Foreign','type'=>'TEXT','provider_field_id'=>null,'max_characters'=>30,'required'=>true]]],['Name']);
    }
    public function testCommercialImageRegionCannotBecomeText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\ProductPackageSpecification::normalize(['native_fields'=>[
            ['layer'=>'photo','type'=>'TEXT','provider_field_id'=>null,'max_characters'=>30,'required'=>true]]],['photo'],
            [['name'=>'photo','kind'=>'image']]);
    }
}
