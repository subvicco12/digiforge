<?php
declare(strict_types=1);
require_once __DIR__.'/Support/TemporaryUsageIdentity.php';

final class DeepResearchAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    private function selected(string $shop='digital', bool $approved=true): array
    {
        $repo=new DigiForge\Research\Repository();
        $source=$repo->createSource(['shop_key'=>$shop,'name'=>'Offline selected source','environment'=>'sandbox']);
        self::assertNotWPError($source);
        $observation=$repo->ingest(['source_id'=>$source['id'],'title'=>'Stored selected observation','body'=>'Synthetic market note; no real supplier certification.']);
        self::assertNotWPError($observation);
        $evidence=$repo->addEvidence((int)$observation['id'],['value'=>'Selected demand evidence '.wp_generate_uuid4(),'provenance'=>['origin'=>'offline_fixture']]);
        self::assertNotWPError($evidence);
        $candidate=$repo->createCandidate(['shop_key'=>$shop,'title'=>'Selected concept '.wp_generate_uuid4(),'summary'=>'Stored product context only.']);
        self::assertNotWPError($candidate);
        self::assertTrue($repo->linkEvidence((int)$candidate['id'],(int)$evidence['id']));
        if($approved)self::assertNotWPError($repo->review((int)$candidate['id'],'APPROVED'));
        return ['candidate'=>$candidate,'evidence'=>$evidence];
    }

    public function testDeepBriefUsesApprovedSameShopStoredCandidateAndActualLinkedEvidence(): void
    {
        $selected=$this->selected();
        $brief=(new DigiForge\Launch\ExecutionEngine())->deepResearchBrief((int)$selected['candidate']['id'],'digital');
        self::assertIsString($brief);
        self::assertStringContainsString($selected['candidate']['title'],$brief);
        self::assertStringContainsString($selected['evidence']['value'],$brief);
        foreach(['demand','competition','provider feasibility','uncertainty'] as $dimension)self::assertStringContainsString($dimension,$brief);
    }

    public function testDeepBriefRejectsUnreviewedOrCrossShopSelection(): void
    {
        $engine=new DigiForge\Launch\ExecutionEngine();
        $pending=$this->selected('digital',false);
        self::assertWPError($engine->deepResearchBrief((int)$pending['candidate']['id'],'digital'));
        $approved=$this->selected();
        self::assertWPError($engine->deepResearchBrief((int)$approved['candidate']['id'],'goods'));
    }

    public function testUnavailableLinkedEvidenceCannotBecomeAnEmptyDeepResearchBrief(): void
    {
        $selected=$this->selected();
        $table=DigiForge\Database\Tables::research_candidate_evidence();
        $filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table.' ')?'SELECT * FROM digiforge_missing_deep_evidence':$sql;
        add_filter('query',$filter);
        try{self::assertWPError((new DigiForge\Launch\ExecutionEngine())->deepResearchBrief((int)$selected['candidate']['id'],'digital'));}
        finally{remove_filter('query',$filter);}
    }

    public function testDanglingLinkedEvidenceCannotDisappearBehindOtherValidSources(): void
    {
        $selected=$this->selected();global $wpdb;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::research_candidate_evidence(),['candidate_id'=>$selected['candidate']['id'],'evidence_id'=>2147483647,'created_at'=>current_time('mysql',true)]));
        self::assertWPError((new DigiForge\Launch\ExecutionEngine())->deepResearchBrief((int)$selected['candidate']['id'],'digital'));
    }

    public function testProtectedDeepResearchCannotReserveOrCallAProvider(): void
    {
        global $wpdb;$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::shop_ai_usage()." WHERE stage='shortlist'");
        $selected=$this->selected();$calls=0;
        $filter=static function()use(&$calls){$calls++;return new WP_Error('offline_forbidden','No provider execution.');};
        add_filter('pre_http_request',$filter);
        try{$result=(new DigiForge\Launch\ExecutionEngine())->research(['shop'=>'digital','phase'=>'deep_research','selected_candidate_id'=>(int)$selected['candidate']['id']],'locked-deep-'.wp_generate_uuid4());}
        finally{remove_filter('pre_http_request',$filter);}
        self::assertWPError($result);self::assertSame(0,$calls);
        global $wpdb;self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::shop_ai_usage()." WHERE stage='shortlist'"));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
    public function testDeepAnalysisUsesIndependentShortlistQuotaAndPersistsProviderEvidence(): void
    {
        $isolated=[];$scope=null;try {
        $selected=$this->selected();
        // Custom plugin tables survive WordPress core test installation. Keep
        // this positive monetary fixture independent of unreconciled old runs.
        global $wpdb;$tables=DigiForge\Database\Tables::class;
        $nextId=TemporaryUsageIdentity::next($this);
        foreach([$tables::shop_ai_usage(),$tables::shop_ai_policies()]as $base){$isolated[$base]=$base.'_'.substr(str_replace('-','',wp_generate_uuid4()),0,12);self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$isolated[$base].' LIKE '.$base));}
        self::assertNotFalse($wpdb->query('ALTER TABLE '.$isolated[$tables::shop_ai_usage()].' AUTO_INCREMENT = '.$nextId));
        $scope=static fn(string $sql):string=>str_replace(array_keys($isolated),array_values($isolated),$sql);add_filter('query',$scope,1);
        DigiForge\Core\Settings::set('research',true);
        DigiForge\Core\Settings::set('ai',true);
        self::assertTrue(DigiForge\Core\Settings::activateResearch());
        self::assertTrue(DigiForge\Core\Settings::activateAi());
        $governance=new DigiForge\AI\ShopAiGovernanceRepository();
        self::assertNotWPError($governance->savePolicy(['shop_key'=>'digital','currency'=>'USD','stages'=>['research'=>['limit'=>500],'shortlist'=>['limit'=>500]]]));
        $existing=$governance->evaluate('digital');self::assertNotWPError($existing);$deepBefore=(int)$existing['stages']['shortlist']['used'];
        self::assertNotWPError($governance->savePolicy(['shop_key'=>'digital','currency'=>'USD','stages'=>['research'=>['limit'=>(int)$existing['stages']['research']['used']+1],'shortlist'=>['limit'=>$deepBefore+1,'monthly_budget'=>'1']]]));
        self::assertNotWPError($governance->reserveGeneration('digital','research','used-trend-'.wp_generate_uuid4()));
        global $wpdb;$tables=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
        $wpdb->update($tables::integrations(),['enabled'=>0],['provider'=>'ai']);
        self::assertSame(1,$wpdb->insert($tables::integrations(),['provider'=>'ai','environment'=>'production','connection_key'=>'deep-fixture-'.wp_generate_uuid4(),'display_name'=>'OFFLINE DEEP FIXTURE','status'=>'CONFIGURED','enabled'=>1,'config'=>'{}','created_at'=>$now,'updated_at'=>$now]));
        $integration=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert($tables::integration_secrets(),['integration_id'=>$integration,'secret_name'=>'api_key','ciphertext'=>DigiForge\Integrations\CredentialVault::encrypt('offline-fixture',DigiForge\Integrations\Repository::secretContext($integration,'api_key')),'fingerprint'=>'fixture','created_at'=>$now,'updated_at'=>$now]));
        $payload=['title'=>'Deep analyzed concept '.wp_generate_uuid4(),'summary'=>'Synthetic selected-candidate analysis, not commercial certification.','signals'=>['demand'=>80,'competition_gap'=>60,'margin'=>50,'trend'=>40,'evidence_quality'=>70],
            'analysis'=>['demand_evidence'=>'Synthetic demand evidence','competition_saturation'=>'Synthetic competition evidence','provider_feasibility'=>'Supplier route remains unverified','confidence_uncertainty'=>'Synthetic fixture only'],
            'observations'=>[['title'=>'Synthetic follow-up','body'=>'Offline evidence only','evidence'=>'No authentic provider claim','source_url'=>'https://example.invalid/deep-fixture']]];
        $engine=new DigiForge\Launch\ExecutionEngine();$brief=$engine->deepResearchBrief((int)$selected['candidate']['id'],'digital');self::assertIsString($brief);$contract=DigiForge\Launch\OpenAIClient::generationContract($brief,4000,false,true);
        $quote=['provider'=>'openai','quote_id'=>'deep-reviewed-'.wp_generate_uuid4(),'model'=>$contract['model'],'request_sha256'=>$contract['request_sha256'],'currency'=>'USD','maximum_charge'=>'0.1','inclusive_all_charges'=>true,'valid_from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'valid_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)];self::assertNotWPError((new DigiForge\AI\MonetaryReservationRepository())->reviewQuote('digital',(string)wp_json_encode($quote),'CONFIRM_PROVIDER_QUOTE'));
        $body=['id'=>'resp_'.str_replace('-','',wp_generate_uuid4()),'status'=>'completed','model'=>$contract['model'],'output_text'=>wp_json_encode($payload),'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]];
        $calls=0;$captured='';
        $filter=static function($pre,array $args)use(&$calls,&$captured,$body){$calls++;$captured=(string)$args['body'];return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];};
        add_filter('pre_http_request',$filter,10,2);
        try{
            $engine=new DigiForge\Launch\ExecutionEngine();
            $input=['shop'=>'digital','phase'=>'deep_research','selected_candidate_id'=>(int)$selected['candidate']['id']];
            $result=$engine->research($input,'deep-first-'.wp_generate_uuid4());
            self::assertNotWPError($result);
            self::assertSame('PENDING',$result['candidate']['review_status']);
            self::assertSame(1,$calls);
            self::assertSame(30,$result['ai']['usage']['total_tokens']);
            $usageId=(int)$wpdb->get_var('SELECT id FROM '.$tables::shop_ai_usage()." WHERE shop_key='digital' AND stage='shortlist' ORDER BY id DESC LIMIT 1");
            $receipt=(new DigiForge\AI\ProviderEvidenceRepository())->snapshot($usageId,'digital');
            self::assertSame($contract['model'],$receipt['metering']['model']);
            self::assertSame('research',$receipt['metering']['workflow']);
            self::assertSame('shortlist',$receipt['metering']['task']);
            self::assertSame(30,$receipt['metering']['usage']['total_tokens']);
            self::assertStringContainsString($selected['evidence']['value'],$captured);
            self::assertSame((string)($deepBefore+1),(string)$wpdb->get_var('SELECT SUM(quantity) FROM '.$tables::shop_ai_usage()." WHERE shop_key='digital' AND stage='shortlist'"));
            self::assertNotNull($wpdb->get_var($wpdb->prepare('SELECT id FROM '.$tables::research_evidence().' WHERE evidence_type=%s AND value=%s','provider_feasibility','Supplier route remains unverified')));
            self::assertWPError($engine->research($input,'deep-denied-'.wp_generate_uuid4()));
            self::assertSame(1,$calls,'Independent deep-analysis cap must deny a second actual provider attempt.');
        }finally{remove_filter('pre_http_request',$filter,10);DigiForge\Core\Settings::protectProduction();}
        }finally{DigiForge\Core\Settings::protectProduction();if($scope!==null)remove_filter('query',$scope,1);foreach($isolated as $table)$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$table);}
    }

}
