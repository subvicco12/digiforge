<?php
declare(strict_types=1);
require_once __DIR__.'/ExternalDocumentListingIntegrityTest.php';

/** Actual local editing/admission/approval/listing services; no Word/Docs/provider acceptance. */
final class DigitalEditedDocumentJourneyTest extends WP_UnitTestCase
{
    public function testEditedSourceGetsIndependentVersionAndCannotBorrowPriorApproval():void
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        $author=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($author);
        ShopAccessFixture::authorizeActor($author,['digital'],['manage_digiforge_products','manage_digiforge_listings']);
        $calls=0;$deny=static function()use(&$calls){$calls++;return new WP_Error('offline_journey_only');};add_filter('pre_http_request',$deny);
        $files=[];
        try{
            $fixture=new ExternalDocumentListingIntegrityTest();
            $base=(new ReflectionMethod($fixture,'fixture'))->invoke($fixture);
            $files=(new ReflectionProperty($fixture,'inputs'))->getValue($fixture);
            $old=(new DigiForge\ProductFactory\Repository())->find('product_version',(int)$base['version']['id']);self::assertNotWPError($old);$oldHashes=[];
            foreach(['source','export'] as $kind)$oldHashes[$kind]=hash_file('sha256',$base['admitted'][$kind]['absolute_path']);
            $source=tempnam(sys_get_temp_dir(),'df-edited-source-');$pdf=tempnam(sys_get_temp_dir(),'df-edited-export-');$files[]=$source;$files[]=$pdf;
            self::assertTrue(copy($base['admitted']['source']['absolute_path'],$source));
            $zip=new ZipArchive();self::assertTrue($zip->open($source));$xml=$zip->getFromName('word/document.xml');self::assertIsString($xml);
            self::assertSame(1,substr_count($xml,'Exact source'));self::assertTrue($zip->addFromString('word/document.xml',str_replace('Exact source','Other source',$xml)));self::assertTrue($zip->close());
            // Equal-length fictional edit preserves the fixture PDF's xref and stream offsets.
            $bytes=file_get_contents($base['admitted']['export']['absolute_path']);self::assertSame(1,substr_count($bytes,'Exact source'));
            file_put_contents($pdf,str_replace('Exact source','Other source',$bytes));
            $request=$base['admitted']['binding'];unset($request['actor_id']);
            $request+=['source_path'=>$source,'export_path'=>$pdf,'source_sha256'=>hash_file('sha256',$source),'export_sha256'=>hash_file('sha256',$pdf)];
            $admission=new DigiForge\ProductFactory\ExternalDocumentAdmission();
            $conflict=$admission->admit($request);self::assertWPError($conflict);self::assertSame('digiforge_external_document_replay_conflict',$conflict->get_error_code());
            $products=new DigiForge\ProductFactory\Repository();
            $version=$products->create('product_version',['product_id'=>$old['product_id'],'version_label'=>'edited-'.wp_generate_uuid4(),'notes'=>$old['notes']]);self::assertNotWPError($version);
            $production=new DigiForge\Production\Repository();
            $spec=$production->createSpec(['product_version_id'=>$version['id'],'asset_key'=>'edited-document','asset_type'=>'digital_document','format'=>'pdf']);self::assertNotWPError($spec);
            $request['product_version_id']=(int)$version['id'];$request['asset_spec_id']=(int)$spec['id'];$request['operation_key']='edited-'.wp_generate_uuid4();
            $admitted=$admission->admit($request);self::assertNotWPError($admitted);self::assertSame($admitted,$admission->admit($request));
            $revision=$production->addRevision($admitted['revision_input']);self::assertNotWPError($revision);
            self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$admitted['evidence']+['binding'=>$admitted['binding']]]));
            self::assertNotWPError($production->transition('revision',(int)$revision['id'],'QA_PASSED'));
            $plan=$production->createPlan(['product_version_id'=>$version['id'],'plan_key'=>'edited-document','version_label'=>'1','channel'=>'digital','production_type'=>'external_document']);self::assertNotWPError($plan);
            self::assertTrue($production->linkAsset((int)$plan['id'],(int)$spec['id']));
            foreach(['VALIDATED','REVIEW_REQUIRED'] as $state)self::assertNotWPError($production->transition('plan',(int)$plan['id'],$state));
            $bundle=$production->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'edited-document','version_label'=>'1']);self::assertNotWPError($bundle);
            foreach(['VALIDATED','REVIEW_REQUIRED'] as $state)self::assertNotWPError($production->transition('bundle',(int)$bundle['id'],$state));
            $review=new DigiForge\ProductFactory\ProductReview();self::assertWPError($review->decide((int)$version['id'],'APPROVED','Must not borrow v1 approval.'));
            self::assertSame('DRAFT',$products->find('product_version',(int)$version['id'])['state']);
            foreach(['specification_match','spelling_text_quality','ip_trademark_risk','prohibited_content','link_qr_integrity','marketing_product_consistency','mockup_production_separation'] as $check)
                self::assertNotWPError($production->addQa(['target_type'=>'plan','target_id'=>$plan['id'],'check_type'=>'semantic_'.$check,'status'=>'PASS','details'=>['synthetic_only'=>true,'not_commercial_evidence'=>true]]));
            $reviewer=self::factory()->user->create(['role'=>'administrator']);
            ShopAccessFixture::authorizeActor($reviewer,['digital'],['manage_digiforge_products','manage_digiforge_listings']);wp_set_current_user($reviewer);
            $approved=$review->decide((int)$version['id'],'APPROVED','Independent synthetic fixture decision only.');self::assertNotWPError($approved);
            $key='edited-listing-'.wp_generate_uuid4();$factory=new DigiForge\Listings\ListingFactory();
            $listing=$factory->prepare((int)$version['id'],$key);self::assertNotWPError($listing);
            self::assertSame('digital',$listing['listing']['shop_reference']);self::assertSame('REVIEW_REQUIRED',$listing['listing']['state']);
            self::assertFalse($listing['draft_package_created']);self::assertFalse($listing['etsy_api_invoked']);
            self::assertSame($listing['listing_id'],$factory->prepare((int)$version['id'],$key)['listing_id']);
            self::assertSame($old,$products->find('product_version',(int)$old['id']));
            foreach(['source','export'] as $kind)self::assertSame($oldHashes[$kind],hash_file('sha256',$base['admitted'][$kind]['absolute_path']));
            self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
        }finally{remove_filter('pre_http_request',$deny);foreach($files as $path)if(is_file($path))unlink($path);}
    }
}
