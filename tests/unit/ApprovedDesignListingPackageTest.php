<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Listings\Validator;
use DigiForge\POD\ApprovedDesignListingPackage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/POD/ProductionTemplateContract.php';
if (is_file(__DIR__ . '/../../includes/POD/ApprovedDesignListingPackage.php')) {
    require_once __DIR__ . '/../../includes/POD/ApprovedDesignListingPackage.php';
}

final class ApprovedDesignListingPackageTest extends TestCase
{
    public function design(): array
    {
        $record = ['schema_version'=>1, 'input'=>['shop_key'=>'personalized_pod',
            'title'=>'Original design', 'sources'=>[['url'=>'https://example.org/research',
                'observed_at'=>'2026-11-02T12:00:00Z','excerpt'=>'Observed public listing']],
            'briefs'=>array_fill(0,3,['audience'=>'couples','visual_direction'=>'original','rationale'=>'test']),
            'artwork'=>['svg'=>'<svg xmlns="http://www.w3.org/2000/svg"></svg>',
                'width_px'=>2418,'height_px'=>3018,'dpi'=>300,'selected_brief'=>1,
                'native_layers'=>['Names','Date'],'rights_note'=>'Original test artwork',
                'safe_area_reviewed'=>true,'font_license_reviewed'=>true,'mockup_reviewed'=>true]],
            'created_by'=>7,'created_at'=>'2026-11-03T12:00:00Z',
            'provenance_mode'=>'MANUAL_IMPORT_UNVERIFIED','external_automation_available'=>false,
            'publishing_enabled'=>false,'order_execution_enabled'=>false];
        $hash=hash('sha256',wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $d=['version_hash'=>$hash,'record'=>$record,'approval'=>['state'=>'HUMAN_APPROVED',
            'decision'=>'APPROVE','evidence_hash'=>$hash,'approved_by'=>7,'note'=>'Reviewed',
            'decided_at'=>'2026-11-03T12:05:00Z','publishing_enabled'=>false,'order_execution_enabled'=>false],
            'supplier_geometry_verified'=>false,'print_output_verified'=>false,'release_hold'=>true];
        return $d;
    }

    public function evidence(string $fixture = 'FL-04'): array
    {
        $baselines = json_decode(file_get_contents(__DIR__ . '/../fixtures/pod-listing-packages/technical-baselines.json'), true);
        return ['shop_key'=>'personalized_pod', 'environment'=>'test', 'fixture'=>$baselines[$fixture],
            'listing'=>['title'=>'Anniversary names poster', 'description'=>'Original personalized artwork.',
                'tags'=>['anniversary gift','names poster','wedding keepsake','couple wall art','custom names','date print','love keepsake','matte poster','romantic gift','family decor','personalized art','wedding date','couple gift'],
                'category'=>null, 'attributes'=>[]],
            'template'=>null, 'native_fields'=>[], 'media'=>[],
            'economics'=>['currency'=>'USD','sale_price'=>29.0,'base_cost'=>9.0,'shipping_cost'=>null,
                'seller_fees'=>2.0,'ads_cost'=>0.0,'fx_cost'=>null,'refund_reserve'=>1.0,'tax_cost'=>null],
            'reviews'=>[]];
    }

    public function testTechnicalFixturesProduceHonestCompleteReviewArtifacts(): void
    {
        foreach (['FL-02','FL-04'] as $fixture) {
            $p = ApprovedDesignListingPackage::compile('personalized_pod', $this->design(), $this->evidence($fixture));
            self::assertSame('REVIEW_REQUIRED', $p['state']);
            self::assertSame([], $p['provider']['variant_ids']);
            self::assertContains('EXACT_PROVIDER_TEMPLATE_REQUIRED', $p['blockers']);
            self::assertContains('SHIPPING_REVIEW_REQUIRED', $p['blockers']);
            self::assertContains('PROCESSING_REVIEW_REQUIRED', $p['blockers']);
            self::assertNull($p['economics']['modeled_contribution']);
            self::assertFalse($p['external_execution_authorized']);
            self::assertTrue($p['controls']['stop_all']);
            self::assertTrue($p['controls']['release_hold']);
            self::assertFalse($p['controls']['automation']);
            self::assertSame('MANUAL', $p['controls']['order_approval']);
            self::assertSame(Validator::canonicalJson($p), ApprovedDesignListingPackage::export($p));
            self::assertSame(ApprovedDesignListingPackage::export($p), ApprovedDesignListingPackage::export(json_decode(ApprovedDesignListingPackage::export($p), true)));
        }
    }

    public function testCrossShopIsRejected(): void
    {
        $e = $this->evidence(); $e['shop_key']='foreign';
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod', $this->design(), $e);
    }

    public function testChangedApprovedAssetIsRejected(): void
    {
        $d=$this->design(); $d['record']['input']['artwork']['svg']='<svg>changed</svg>';
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$this->evidence());
    }

    public function testUnapprovedDesignIsRejectedEvenWithRecomputedHash(): void
    {
        $d=$this->design(); $d['approval']['approved_by']=0;
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$this->evidence());
    }

    public function testCredentialMetadataCannotEnterExport(): void
    {
        $e=$this->evidence(); $e['api_key']='do-not-export';
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$e);
    }

    public function testPackageMutationCannotBeExported(): void
    {
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$this->evidence());
        $p['listing']['title']='changed after review';
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::export($p);
    }

    public function testConditionalCostsRemainExplicitAndNotRealizedProfit(): void
    {
        $e=$this->evidence();
        foreach (['shipping_cost'=>4.0,'fx_cost'=>1.0,'tax_cost'=>0.0] as $k=>$v) $e['economics'][$k]=$v;
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$e);
        self::assertSame(12.0,$p['economics']['modeled_contribution']);
        self::assertSame('CONDITIONAL_ESTIMATE_NOT_REALIZED_PROFIT',$p['economics']['classification']);
        self::assertFalse($p['handoff']['transfer_authorized']);
        self::assertContains('OWNER_PACKAGE_APPROVAL_REQUIRED',$p['blockers']);
    }

    public function testTemplateGeometryMismatchFailsClosed(): void
    {
        $e=$this->evidence();
        // Deliberately synthetic test IDs: never used as FL-04 provider facts.
        $e['template']=['template_id'=>'synthetic-test-only','template_version'=>1,'supplier'=>'printify',
            'provider_blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3],
            'print_areas'=>[['position'=>'front','decoration_method'=>'print','width_px'=>1,'height_px'=>1]],
            'personalization_pipeline'=>'PRINTIFY_NATIVE','personalization_engine'=>'NATIVE','template_status'=>'DRAFT'];
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$e);
    }

    /** @dataProvider invalidEvidence */
    public function testUnsafeOrIncompleteEvidenceIsRejected(string $case): void
    {
        $e=$this->evidence(); $d=$this->design();
        switch ($case) {
            case 'environment': $e['environment']='foreign'; break;
            case 'product': $e['fixture']['printify_product_id']='foreign-product'; break;
            case 'tags': $e['listing']['tags'][1]=$e['listing']['tags'][0]; break;
            case 'html': $e['listing']['title']='<script>bad</script>'; break;
            case 'nan': $e['economics']['sale_price']=NAN; break;
            case 'negative': $e['economics']['shipping_cost']=-1; break;
            case 'traversal': $e['media']=[['reference'=>'media/../secret','sha256'=>str_repeat('a',64)]]; break;
            case 'native': $e['native_fields']=[['question_type'=>'remote_execution']]; break;
            case 'media_mismatch': $e['media']=[['reference'=>'media/mockup.png','sha256'=>str_repeat('a',64),'design_sha256'=>str_repeat('b',64)]]; break;
            case 'fixture_id_array': $e['fixture']['fixture_id']=[]; break;
            case 'native_scalar': $e['native_fields']='malformed'; break;
            case 'economics_scalar': $e['economics']='malformed'; break;
            case 'fixture_scalar': $e['fixture']='malformed'; break;
        }
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);
    }

    public static function invalidEvidence(): array
    {
        return array_map(static fn($s)=>[$s], ['environment','product','tags','html','nan','negative','traversal','native','media_mismatch','fixture_id_array','native_scalar','economics_scalar','fixture_scalar']);
    }

    public function testRejectedApprovalCannotBeRelabeledByHash(): void
    {
        $d=$this->design(); $d['approval']['state']='HUMAN_REJECTED';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Current server-resolved Lane A approval is required.');
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$this->evidence());
    }

    public function testMissingSourceEvidenceFailsEvenWhenRecordHashIsRecomputed(): void
    {
        $d=$this->design(); $d['record']['input']['sources']=[];
        $d['version_hash']=hash('sha256',wp_json_encode($d['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $d['approval']['evidence_hash']=$d['version_hash'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Design source provenance is required.');
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$this->evidence());
    }

    public function testUnknownEconomicsIsAnExplicitBlocker(): void
    {
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$this->evidence());
        self::assertContains('ECONOMICS_COST_EVIDENCE_REQUIRED',$p['blockers']);
        self::assertSame(['shipping_cost','fx_cost','tax_cost'],$p['economics']['unknown_costs']);
    }

    public function testObjectMetadataCannotBypassCredentialScanner(): void
    {
        $e=$this->evidence(); $e['listing']['attributes']=(object)['api_key'=>'secret'];
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$e);
    }

    public function testSourceQueryCannotExportCredentials(): void
    {
        $d=$this->design(); $d['record']['input']['sources'][0]['url']='https://example.org/source?key=private';
        $d['version_hash']=hash('sha256',wp_json_encode($d['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $d['approval']['evidence_hash']=$d['version_hash'];
        $this->expectException(\InvalidArgumentException::class);
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$this->evidence());
    }

    public function testMatchingDimensionsDoNotBindAnUnrelatedTemplateToFixture(): void
    {
        $e=$this->evidence();
        $e['template']=['template_id'=>'synthetic-test-only','template_version'=>1,'supplier'=>'printify',
            'provider_blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3],
            'print_areas'=>[['position'=>'front','decoration_method'=>'print','width_px'=>2418,'height_px'=>3018]],
            'personalization_pipeline'=>'PRINTIFY_NATIVE','personalization_engine'=>'NATIVE','template_status'=>'DRAFT'];
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$this->design(),$e);
        self::assertContains('PRODUCT_TEMPLATE_BINDING_REQUIRED',$p['blockers']);
        self::assertContains('EXACT_PROVIDER_TEMPLATE_REQUIRED',$p['blockers']);
        self::assertSame('UNVERIFIED_CANDIDATE',$p['provider']['identity_state']);
    }

    public function testOversizedCombinedPackageIsRejectedBeforeExport(): void
    {
        $d=$this->design();
        $d['record']['input']['artwork']['svg']='<svg><!--'.str_repeat('x',55000).'--></svg>';
        $d['version_hash']=hash('sha256',wp_json_encode($d['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $d['approval']['evidence_hash']=$d['version_hash'];
        $e=$this->evidence(); $e['listing']['description']=str_repeat('y',15000);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Structured field exceeds size limit.');
        ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);
    }

    public function testFullyPopulatedSyntheticEvidenceStillCannotAuthorizeLiveWork(): void
    {
        $e=$this->evidence(); $d=$this->design();
        // Synthetic unit data only, explicitly not an actual FL-04 variant/source receipt.
        require_once __DIR__.'/../fixtures/PrintifyGeometryFixture.php';
        $e['template']=\PrintifyGeometryFixture::candidate(\PrintifyGeometryFixture::catalog(1,2,3,2418,3018),'synthetic-test-only',2418,3018);
        $e['template']['personalization_pipeline']='PRINTIFY_NATIVE';$e['template']['personalization_engine']='NATIVE';unset($e['template']['fingerprint']);
        $template=\DigiForge\POD\ProductionTemplateContract::normalize($e['template']);
        $assetHash=hash('sha256',$d['record']['input']['artwork']['svg']);
        $e['provider_binding']=['shop_key'=>'personalized_pod','environment'=>'test',
            'product_id'=>$e['fixture']['printify_product_id'],'template_fingerprint'=>$template['fingerprint'],
            'source_reference'=>'synthetic/receipt.json','source_sha256'=>hash('sha256','synthetic receipt'),
            'observed_at'=>'2026-11-03T12:00:00Z','sku'=>'SYNTHETIC-TEST-ONLY'];
        foreach (['Names','Date','Message'] as $label) {
            $e['native_fields'][]=['question_type'=>'text_input','question_text'=>$label,'required'=>true,'max_allowed_characters'=>30];
        }
        for ($i=0;$i<5;$i++) {
            $e['media'][]=['reference'=>'synthetic/mockup-'.$i.'.png','sha256'=>hash('sha256','synthetic mockup '.$i),'design_sha256'=>$assetHash];
        }
        $e['parity']=['design_sha256'=>$assetHash,'template_fingerprint'=>$template['fingerprint'],'preview_production_match'=>true];
        $e['reviews']=array_fill_keys(['safe_area','bleed','font_license','mockup_rights','native_mapping','overflow','choice_routing','shipping','processing','manual_price'],true);
        foreach (['shipping_cost'=>4.0,'fx_cost'=>1.0,'tax_cost'=>0.0] as $k=>$v) $e['economics'][$k]=$v;
        $e['listing']['category']='synthetic-test-only'; $e['listing']['attributes']=['finish'=>'matte'];
        $p=ApprovedDesignListingPackage::compile('personalized_pod',$d,$e);
        self::assertSame(3,count($p['native_personalization']['etsy_questions']['personalization_questions']));
        self::assertSame(5,count($p['media']));
        self::assertSame($template['fingerprint'],$p['provider']['template']['fingerprint']);
        self::assertSame('SOURCE_LINKED_REQUIRES_SUPPLIER_REVIEW',$p['provider']['identity_state']);
        self::assertSame(['OWNER_PACKAGE_APPROVAL_REQUIRED','SUPPLIER_CERTIFICATION_REQUIRED',
            'BUYER_PREVIEW_ACCEPTANCE_REQUIRED','LIVE_HANDOFF_SEPARATE_AUTHORITY_REQUIRED'],$p['blockers']);
        self::assertFalse($p['supplier_certified']);
        self::assertFalse($p['commercial_approved']);
        self::assertFalse($p['external_execution_authorized']);
        self::assertSame($d,$p['source_approval']);
    }
}
