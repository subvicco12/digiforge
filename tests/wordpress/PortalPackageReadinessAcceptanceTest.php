<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Portal\Portal;

/** Blueprint table22/r9 and r2: actual scoped evidence UI, no live or installed certification. */
final class PortalPackageReadinessAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
        wp_create_nonce('package-readiness-local');
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_package_readiness','HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());parent::tearDown();
    }
    private function render(string $view,string $shop,array $focus=[]): string
    {
        $prior=$_GET;$mutations=[];$observations=Tables::pod_provenance_integrity_evidence();
        $capture=static function(string $sql)use(&$mutations,$observations):string{
            $observationInsert=str_starts_with($sql,'INSERT INTO `'.$observations.'`');
            $observationRefresh=preg_match('/^UPDATE `'.preg_quote($observations,'/').'` SET `last_observed_at` = /',$sql)===1;
            if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql)&&!$observationInsert&&!$observationRefresh)$mutations[]=$sql;return $sql;
        };
        add_filter('query',$capture);
        try{$_GET=['df_view'=>$view,'df_shop'=>$shop]+$focus;$html=(new Portal())->render();}
        finally{$_GET=$prior;remove_filter('query',$capture);}
        self::assertSame([],$mutations,'Evidence rendering must not write business, recovery or authorization state.');return $html;
    }
    private function panel(string $html,string $label): DOMElement
    {
        $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
        try{$doc->loadHTML($html,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $nodes=(new DOMXPath($doc))->query('//section[.//h2[text()="'.$label.'"]]');self::assertSame(1,$nodes->length,'Expected exact '.$label.' panel.');return $nodes->item(0);
    }
    private function source(string $shop): array
    {
        $marker='package-ui-'.wp_generate_uuid4();$research=new DigiForge\Research\Repository();$candidate=$research->createCandidate(['shop_key'=>$shop,'title'=>$marker]);self::assertNotWPError($candidate);self::assertNotWPError($research->review((int)$candidate['id'],'APPROVED','Synthetic local source fixture.'));$opportunity=$research->promote((int)$candidate['id']);self::assertNotWPError($opportunity);
        $products=new DigiForge\ProductFactory\Repository();$family=$products->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>$marker]);self::assertNotWPError($family);$product=$products->create('product',['product_family_id'=>$family['id'],'name'=>$marker]);self::assertNotWPError($product);$version=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'local-v1']);self::assertNotWPError($version);
        $listing=(new DigiForge\Listings\Repository())->createListing(['product_version_id'=>$version['id'],'channel'=>'etsy','environment'=>'sandbox','shop_reference'=>$shop,'title'=>$marker,'description'=>'Synthetic persisted package lineage','currency'=>'USD','price_amount'=>1]);self::assertNotWPError($listing);
        // Historical unapproved package/intent records prove visibility only.
        global $wpdb;$now=current_time('mysql',true);$hash=hash('sha256',$marker);
        self::assertSame(1,$wpdb->insert(Tables::etsy_draft_packages(),['listing_id'=>$listing['id'],'package_version'=>$marker,'canonical_payload'=>'{}','payload_hash'=>$hash,'readiness'=>'{}','readiness_hash'=>$hash,'approved_by'=>0,'created_at'=>$now]));$package=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::etsy_intents(),['listing_id'=>$listing['id'],'draft_package_id'=>$package,'environment'=>'sandbox','intent_type'=>'PREPARE_DRAFT','state'=>'BLOCKED','created_at'=>$now,'updated_at'=>$now]));$intent=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert($wpdb->prefix.'digiforge_etsy_operations',['shop_reference'=>$shop,'intent_id'=>$intent,'draft_package_id'=>$package,'operation_type'=>'CREATE_DRAFT','state'=>'UNKNOWN','resource_reference'=>$marker,'idempotency_key'=>$marker,'request_fingerprint'=>$hash,'authorization_hash'=>$hash,'evidence_hash'=>$hash,'created_at'=>$now,'updated_at'=>$now]));$operation=(int)$wpdb->insert_id;
        return compact('shop','marker','listing','package','intent','operation');
    }
    private function assertRow(DOMElement $panel,int $id,array $cells,bool $present=true): void
    {
        $xpath=new DOMXPath($panel->ownerDocument);$rows=$xpath->query('.//tbody/tr[td[1][text()="'.$id.'"]]',$panel);
        self::assertSame($present?1:0,$rows->length);
        if($present)foreach($cells as $position=>$expected)self::assertSame((string)$expected,$rows->item(0)->getElementsByTagName('td')->item($position)->textContent);
    }
    public function testSelectedShopPackageIntentAndOperationRowsRetainExactListingAncestryWithoutWrites(): void
    {
        $own=$this->source('digital');$foreign=$this->source('jewelry');
        foreach([$own,$foreign]as $selected){$other=$selected['shop']==='digital'?$foreign:$own;$html=$this->render('listings',$selected['shop']);
            $packages=$this->panel($html,'Etsy Draft Packages');$this->assertRow($packages,$selected['package'],[1=>$selected['listing']['id'],2=>$selected['marker']]);$this->assertRow($packages,$other['package'],[],false);
            $intents=$this->panel($html,'Etsy Intents');$this->assertRow($intents,$selected['intent'],[1=>$selected['listing']['id'],2=>$selected['package'],3=>'sandbox',6=>'BLOCKED']);$this->assertRow($intents,$other['intent'],[],false);
            $audit=$this->render('audit',$selected['shop'],['df_etsy_operation'=>$selected['operation']]);$operations=$this->panel($audit,'Audit / Reconciliation');$xpath=new DOMXPath($operations->ownerDocument);$rows=$xpath->query('.//tr[@id="df-etsy-operation-'.$selected['operation'].'"]',$operations);self::assertSame(1,$rows->length);
            $cells=$rows->item(0)->getElementsByTagName('td');foreach([0=>'#'.$selected['operation'],1=>$selected['shop'],2=>'CREATE_DRAFT',3=>'UNKNOWN',4=>$selected['marker'],5=>'RECONCILE BEFORE ANY RETRY']as $index=>$expected)self::assertSame($expected,$cells->item($index)->textContent);
            self::assertStringNotContainsString($other['marker'],$operations->textContent);self::assertStringContainsString('Retry authorityNO',$operations->textContent);self::assertStringContainsString('External execution authorityNO',$operations->textContent);
            $foreignFocus=$this->render('audit',$selected['shop'],['df_etsy_operation'=>$other['operation']]);self::assertStringContainsString('Requested Etsy reconciliation evidence is unavailable',$foreignFocus);self::assertStringNotContainsString('id="df-etsy-operation-'.$other['operation'].'"',$foreignFocus);
            self::assertStringNotContainsString($other['marker'],$packages->textContent);self::assertStringContainsString('STOP ALL: ON',$html);
        }
    }
    public function testUnavailablePackageSourceIsExplicitlyUnavailableRatherThanEmpty(): void
    {
        $own=$this->source('digital');global $wpdb;$table=Tables::etsy_draft_packages();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$panel=$this->panel($this->render('listings','digital'),'Etsy Draft Packages');}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertStringContainsString('UNAVAILABLE',$panel->textContent);self::assertStringContainsString('Evidence unavailable. Source records could not be read',$panel->textContent);self::assertStringNotContainsString('No records found.',$panel->textContent);self::assertStringNotContainsString($own['marker'],$panel->textContent);
    }
    public function testGlobalDashboardShowsProtectedReviewRequiredAndSchemaDriftWhenRecoveryEvidenceIsMissing(): void
    {
        foreach(['digiforge_recovery_database_backup_evidence','digiforge_recovery_plugin_package_evidence','digiforge_recovery_drill_evidence']as $name)delete_option($name);
        $missingRecovery=$this->panel($this->render('dashboard','all'),'System summary');
        self::assertStringContainsString('ReadinessREVIEW_REQUIRED',$missingRecovery->textContent);self::assertStringContainsString('Schema'.DigiForge\Database\MigrationPlan::LATEST,$missingRecovery->textContent);self::assertStringContainsString('External lockLOCKED',$missingRecovery->textContent);
        $current=DigiForge\Database\MigrationPlan::LATEST-1;update_option('digiforge_db_schema_version',$current,false);
        $html=$this->render('dashboard','all');$panel=$this->panel($html,'System summary');$text=$panel->textContent;
        self::assertStringContainsString('ReadinessREVIEW_REQUIRED',$text);self::assertStringContainsString('Schema'.$current,$text);self::assertStringContainsString('External lockLOCKED',$text);self::assertStringNotContainsString('READY_LOCKED',$text);self::assertStringContainsString('STOP ALL: ON',$html);
        self::assertFalse(Settings::get('activation_authorized',false));self::assertFalse(Settings::get('automation_armed',false));
    }
    public function testGlobalDashboardReadFailureCannotClaimReadyOrEmptyHealthyQueue(): void
    {
        global $wpdb;$table=Tables::jobs();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$html=$this->render('dashboard','all');}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        $summary=$this->panel($html,'System summary');self::assertStringContainsString('ReadinessREVIEW_REQUIRED',$summary->textContent);self::assertStringContainsString('External lockLOCKED',$summary->textContent);self::assertStringNotContainsString('READY_LOCKED',$summary->textContent);
        $queue=$this->panel($html,'Global queue health');self::assertStringContainsString('Queue stateUNAVAILABLE',$queue->textContent);self::assertStringContainsString('Dead lettersUNAVAILABLE',$queue->textContent);self::assertStringNotContainsString('NO CURRENT ATTENTION',$queue->textContent);
    }
}
