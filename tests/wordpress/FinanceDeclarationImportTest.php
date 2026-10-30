<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\Repository;
use DigiForge\Tests\FinanceDeclarationFixtures;

require_once dirname(__DIR__) . '/unit/FinanceDeclarationFixtures.php';
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Synthetic declarations only: no provider API compatibility or financial authority. */
final class FinanceDeclarationImportTest extends WP_UnitTestCase
{
    private array $isolatedTables=[];
    private ?Closure $financeFilter=null;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();global$wpdb;
        // Keep the real InnoDB audit table visible to ShopAccess storage verification.
        // Independent temporary financial tables must not reuse an existing audit identity.
        $last=$wpdb->get_var("SELECT COALESCE(MAX(CAST(object_id AS UNSIGNED)),0) FROM ".Tables::audit_log()." WHERE object_type IN ('finance_declaration','finance_period','finance_ledger','fx_snapshot') AND object_id REGEXP '^[0-9]+$'");
        self::assertNotNull($last);$first=(int)$last+1;
        foreach([Tables::finance_ledger(),Tables::fx_snapshots(),Tables::finance_periods()]as$table){
            $temporary=$table.'_'.substr(str_replace('-','',wp_generate_uuid4()),0,12);
            $definition=$wpdb->get_var('SHOW CREATE TABLE `'.$table.'`',1);self::assertIsString($definition);
            $definition=str_replace('CREATE TABLE `'.$table.'`','CREATE TEMPORARY TABLE `'.$temporary.'`',$definition);
            $definition=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$definition).' AUTO_INCREMENT='.$first;
            self::assertNotFalse($wpdb->query($definition));$this->isolatedTables[$table]=$temporary;
        }
        $tables=$this->isolatedTables;
        // Run after deliberate fault filters so their predicates still see the production query shape.
        $this->financeFilter=static fn(string$sql):string=>str_replace(array_keys($tables),array_values($tables),$sql);
        add_filter('query',$this->financeFilter,99);
        self::assertSame('InnoDB',$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',Tables::audit_log())),'The real audit engine must remain independently visible.');
    }
    protected function tearDown():void
    {
        if($this->financeFilter!==null)remove_filter('query',$this->financeFilter,99);
        global$wpdb;foreach($this->isolatedTables as$table)$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$table);
        parent::tearDown();
    }
    public function fixture(array $overrides = []): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        global $wpdb;
        $now = current_time('mysql', true);
        $shop=$overrides['scope']['shop_key']??'personalized_pod';
        $wpdb->insert(Tables::opportunities(),['title'=>'Synthetic Finance ancestry','created_at'=>$now,'updated_at'=>$now]);$opportunity=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::research_candidates(),['title'=>'Synthetic Finance ancestry','canonical_title'=>'synthetic finance','fingerprint'=>hash('sha256',wp_generate_uuid4()),'score_version'=>'fixture','score_inputs'=>wp_json_encode(['shop_key'=>$shop]),'opportunity_id'=>$opportunity,'created_at'=>$now,'updated_at'=>$now]);
        $wpdb->insert(Tables::product_families(),['opportunity_id'=>$opportunity,'name'=>'Synthetic Finance family','created_at'=>$now,'updated_at'=>$now]);$family=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::products(),['product_family_id'=>$family,'name'=>'Synthetic Finance product','created_at'=>$now,'updated_at'=>$now]);$product=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::product_versions(),['product_id'=>$product,'version_label'=>'finance-fixture','created_at'=>$now,'updated_at'=>$now]);$version=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::businesses(), ['business_key'=>'finance-offline-'.wp_generate_uuid4(), 'display_name'=>'Offline declaration', 'status'=>'ACTIVE', 'created_at'=>$now, 'updated_at'=>$now]);
        $business = (int) $wpdb->insert_id;
        $wpdb->insert(Tables::stores(), ['business_id'=>$business, 'store_key'=>'arbitrary-owned-store-'.wp_generate_uuid4(), 'status'=>'ACTIVE', 'created_at'=>$now, 'updated_at'=>$now]);
        $store = (int) $wpdb->insert_id;
        $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$program=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::pod_mappings(),['product_version_id'=>$version,'production_plan_id'=>0,'provider'=>'printify','environment'=>'test','provider_product_key'=>'offline-product','provider_variant_key'=>'offline-variant','mapping_version'=>'finance-fixture','state'=>'DRAFT','created_at'=>$now,'updated_at'=>$now]);$mapping=(int)$wpdb->insert_id;
        $wpdb->insert(Tables::pod_business_mappings(),['business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        return FinanceDeclarationFixtures::input(array_replace_recursive(['scope'=>['business_id'=>$business, 'store_id'=>$store,
            'shop_key'=>$shop,'order_id'=>0, 'product_version_id'=>$version, 'listing_id'=>0,'provider_mapping_id'=>$mapping,'variant_ref'=>'offline-variant']], $overrides));
    }

    public function testDeclarationImportIsExactImmutableIdempotentAndUnreconciled(): void
    {
        $input = $this->fixture(['amount'=>'9999999999.9999', 'base_amount'=>'9999999999.9999']);
        $repo = new Repository();
        $row = $repo->importDeclaration($input);
        self::assertIsArray($row);
        self::assertSame('9999999999.9999', $row['amount']);
        self::assertSame('UNRECONCILED', $row['reconciliation_state']);
        self::assertSame('UNKNOWN', json_decode($row['metadata'], true)['finance_declaration']['authenticity']);
        self::assertTrue($repo->importDeclaration($input)['idempotent_replay']);
        $input['amount'] = $input['base_amount'] = '1.0000';
        $conflict = $repo->importDeclaration($input);
        self::assertWPError($conflict);
        self::assertSame('idempotency_payload_conflict', $conflict->get_error_code());
        global $wpdb;
        self::assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_ledger()));
    }

    public function testMissingAndForeignScopeNeverWritesFinanceEvidence(): void
    {
        $input = $this->fixture();
        global $wpdb;
        foreach ([['business_id'=>999999], ['store_id'=>999999], ['order_id'=>999999], ['product_version_id'=>999999], ['listing_id'=>999999], ['provider_mapping_id'=>999999]] as $scope) {
            self::assertWPError((new Repository())->importDeclaration(array_replace_recursive($input, ['scope'=>$scope])));
        }
        $order = (new DigiForge\Orders\Repository())->createOrder(['environment'=>'test','shop_reference'=>'jewelry']);
        self::assertIsArray($order);
        self::assertWPError((new Repository())->importDeclaration(array_replace_recursive($input, ['scope'=>['order_id'=>(int)$order['id']]])));
        unset($input['scope']['business_id']);
        self::assertWPError((new Repository())->importDeclaration($input));
        self::assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_ledger()));
    }

    public function testDigitalAndDeferredShopsCannotAcquireAPodFinanceBinding():void
    {
        foreach(['digital','standard_pod','jewelry']as$shop){$input=$this->fixture(['scope'=>['shop_key'=>$shop]]);self::assertWPError((new Repository())->importDeclaration($input));}
        global $wpdb;self::assertSame('0',$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
    }

    public function testFxReferenceRequiresExactPairTimeHashAndConvertedAmount(): void
    {
        $input = $this->fixture(['currency'=>'EUR', 'amount'=>'1.0000', 'base_amount'=>'1.2346']);
        $repo = new Repository();
        $fx = $repo->createFx(['environment'=>'test','base_currency'=>'EUR','quote_currency'=>'USD',
            'rate'=>'1.2345678901','as_of_at'=>'2026-10-09 12:00:00',
            'source_metadata'=>['kind'=>'DECLARATION','document_ref'=>'offline/fx-source','document_hash'=>str_repeat('b',64)]]);
        self::assertIsArray($fx);
        $input['fx_snapshot_id'] = (int) $fx['id'];
        self::assertIsArray($repo->importDeclaration($input));
        foreach ([['base_amount'=>'1.2345'], ['currency'=>'GBP'], ['occurred_at'=>'2026-10-08T12:00:00Z']] as $change) {
            self::assertWPError($repo->importDeclaration(array_replace($input,$change)));
        }
        global $wpdb;
        $wpdb->update(Tables::fx_snapshots(), ['rate'=>'9.0000000000'], ['id'=>$fx['id']]);
        self::assertWPError($repo->importDeclaration($input));
    }

    public function testRevisionAndReversalReferencesMustMatchStoredExplicitEvidence(): void
    {
        $input = $this->fixture(); $repo = new Repository();
        $first = $repo->importDeclaration($input); self::assertIsArray($first);
        $declaration = json_decode($first['metadata'], true)['finance_declaration'];
        $revision = array_replace($input, ['revision'=>2, 'previous_revision_hash'=>$declaration['declaration_hash'], 'amount'=>'12.0000', 'base_amount'=>'12.0000']);
        self::assertIsArray($repo->importDeclaration($revision));
        self::assertWPError($repo->importDeclaration(array_replace($revision,['previous_revision_hash'=>str_repeat('b',64)])));
        self::assertWPError($repo->importDeclaration(array_replace($input,['external_transaction_id'=>'offline-bad-reversal','reversal_of_hash'=>str_repeat('c',64), 'amount'=>'-10.0000','base_amount'=>'-10.0000'])));
        // Full reversal of the currently declared revision; partial refunds use their own REFUND declaration.
        $current = DeclarationContract::normalize($revision);
        $reversal = array_replace($input,['external_transaction_id'=>'offline-reversal', 'reversal_of_hash'=>$current['declaration_hash'], 'amount'=>'-12.0000','base_amount'=>'-12.0000']);
        self::assertIsArray($repo->importDeclaration($reversal));
        self::assertWPError($repo->importDeclaration(array_replace($reversal,['external_transaction_id'=>'second-reversal','amount'=>'-11.0000','base_amount'=>'-11.0000'])));
    }

    public function testReferenceReadFailureAndLedgerWriteFailureCannotSucceed(): void
    {
        $input = $this->fixture(); global $wpdb;
        foreach (['read','write'] as $mode) {
            $filter = static function(string $sql) use ($mode): string {
                if ($mode === 'read' && str_starts_with($sql, 'SELECT * FROM ' . Tables::stores())) return 'SELECT * FROM missing_finance_scope_fixture';
                if ($mode === 'write' && str_starts_with($sql, 'INSERT INTO `' . Tables::finance_ledger() . '`')) return str_replace(Tables::finance_ledger(),'missing_finance_declaration_fixture',$sql);
                return $sql;
            };
            $previous = $wpdb->suppress_errors(true); add_filter('query',$filter);
            try { self::assertWPError((new Repository())->importDeclaration($input)); }
            finally { remove_filter('query',$filter); $wpdb->suppress_errors($previous); }
        }
        self::assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::finance_ledger()));
    }

    public function testLegacyPeriodsCannotDoubleCountOrCertifyNormalizedTransactionRevisions():void
    {
        $input=$this->fixture();$repo=new Repository();
        $first=$repo->importDeclaration($input);self::assertIsArray($first);
        $declaration=json_decode($first['metadata'],true)['finance_declaration'];
        self::assertIsArray($repo->importDeclaration(array_replace($input,['revision'=>2,'previous_revision_hash'=>$declaration['declaration_hash'],'amount'=>'12.0000','base_amount'=>'12.0000'])));
        $period=$repo->calculatePeriod(['environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD']);
        self::assertWPError($period);
        self::assertSame('declaration_report_required',$period->get_error_code());
        global $wpdb;self::assertSame('0',$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_periods()));
    }

    public function testExactFxDeclarationBindsAllTenStoredDecimalPlaces():void
    {
        $input=$this->fixture(['currency'=>'EUR','amount'=>'0.0001','base_amount'=>'99999.9999']);$repo=new Repository();
        $fx=$repo->createFx(['environment'=>'test','base_currency'=>'EUR','quote_currency'=>'USD','rate'=>'999999999.1234567890',
            'as_of_at'=>'2026-10-09 12:00:00','source_metadata'=>['kind'=>'DECLARATION','document_ref'=>'offline/exact-fx-source','document_hash'=>str_repeat('b',64)]]);
        self::assertIsArray($fx);self::assertSame('999999999.1234567890',$fx['rate']);
        $input['fx_snapshot_id']=(int)$fx['id'];$row=$repo->importDeclaration($input);self::assertIsArray($row);
        global $wpdb;$wpdb->update(Tables::fx_snapshots(),['rate'=>'999999999.1234567891'],['id'=>$fx['id']]);
        self::assertWPError($repo->declarationEvidence($row));
        self::assertWPError($repo->importDeclaration($input));
    }

    public function testDeclarationInsertReadbackMustReturnItsMatchingImmutableEvidence():void
    {
        $input=$this->fixture();global $wpdb;$table=Tables::finance_ledger();
        $auditBefore=$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='finance_declaration_created'");
        foreach(['empty','changed']as$mode){
            $copy=array_replace($input,['external_transaction_id'=>'readback-'.$mode]);
            $filter=static function(string$sql)use($table,$mode):string{
                if(!str_starts_with($sql,'SELECT * FROM '.$table.' WHERE id='))return$sql;
                return$mode==='empty'?'SELECT * FROM '.$table.' WHERE id=0':'SELECT id, environment, source_type, source_id, entry_type, currency, 999 AS amount, base_currency, base_amount, effective_date, metadata, canonical_hash, reconciliation_state, idempotency_key, created_by, created_at, updated_at FROM '.$table.' ORDER BY id DESC LIMIT 1';
            };
            add_filter('query',$filter);
            try{self::assertWPError((new Repository())->importDeclaration($copy));}
            finally{remove_filter('query',$filter);}
        }
        // Failed readback cannot leave either an admitted declaration or its success audit.
        self::assertSame('0',$wpdb->get_var('SELECT COUNT(*) FROM '.$table));
        self::assertSame($auditBefore,$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='finance_declaration_created'"));
    }
    public function testDeclaredFxRequiresALocatableSourceReference():void
    {
        $this->fixture();$result=(new Repository())->createFx(['environment'=>'test','base_currency'=>'EUR','quote_currency'=>'USD',
            'rate'=>'1.2345678901','as_of_at'=>'2026-10-09 12:00:00','source_metadata'=>['kind'=>'DECLARATION','document_hash'=>str_repeat('b',64)]]);
        self::assertWPError($result);
    }

    public function testReadOnlyReportSelectsActiveScopedRevisionWithoutFinancialAuthority():void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);
        $one=json_decode($first['metadata'],true)['finance_declaration'];
        self::assertIsArray($repo->importDeclaration(array_replace($input,['revision'=>2,'previous_revision_hash'=>$one['declaration_hash'],'amount'=>'12.1111','base_amount'=>'12.1111'])));
        $model=new DigiForge\Finance\DeclarationReadModel();$report=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('PROVISIONAL',$report['state']);self::assertSame('12.1111',$report['totals']['gross_revenue']);self::assertSame('100.0000',$report['totals']['margin_percent']);
        self::assertSame(json_decode($first['metadata'],true)['product_id'],$report['groups']['product'][0]['dimension_id']);
        self::assertCount(1,$report['gst_review']['rows']);self::assertSame('ROW_ARRAYS_NOT_CSV',$report['gst_review']['format']);
        self::assertSame('UNKNOWN',$report['source_completeness']);self::assertFalse($report['period_approval_authorized']);self::assertFalse($report['tax_filing_authorized']);
        self::assertSame('NO_DECLARATIONS',$model->snapshot('digital','test','2026-10-01','2026-10-31','USD')['state']);
        self::assertSame('NO_DECLARATIONS',$model->snapshot('personalized_pod','sandbox','2026-10-01','2026-10-31','USD')['state']);
        self::assertSame('BLOCKED',$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','EUR')['state']);
    }

    public function testReportRechecksRevokedDeletedAndAmbiguousCurrentOwnershipWithoutRewritingHistory():void
    {
        $input=$this->fixture();$row=(new Repository())->importDeclaration($input);self::assertIsArray($row);
        global$wpdb;$owner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d',$input['scope']['provider_mapping_id']),ARRAY_A);
        $ledger=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE id=%d',$row['id']),ARRAY_A);
        foreach(['revoked','deleted','ambiguous']as$mode){
            if($mode==='revoked')$wpdb->update(Tables::pod_business_mappings(),['state'=>'REVOKED'],['id'=>$owner['id']]);
            if($mode==='deleted')$wpdb->delete(Tables::pod_business_mappings(),['id'=>$owner['id']]);
            if($mode==='ambiguous'){$extra=$owner;unset($extra['id']);$extra['product_version_id']=0;$wpdb->insert(Tables::pod_business_mappings(),$extra);}
            $report=(new DigiForge\Finance\DeclarationReadModel())->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
            self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);self::assertSame([],$report['gst_review']['rows']);
            $wpdb->delete(Tables::pod_business_mappings(),['provider_mapping_id'=>$input['scope']['provider_mapping_id']]);$wpdb->insert(Tables::pod_business_mappings(),$owner);
        }
        self::assertSame($ledger,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE id=%d',$row['id']),ARRAY_A));
        self::assertSame('1',$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
    }

    public function testUnavailableOrTruncatedReportNeverExportsFalseZeroEvidence():void
    {
        $input=$this->fixture();$repo=new Repository();self::assertIsArray($repo->importDeclaration($input));
        self::assertIsArray($repo->importDeclaration(array_replace($input,['external_transaction_id'=>'second-independent-declaration'])));
        $model=new DigiForge\Finance\DeclarationReadModel();$truncated=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD',1);
        self::assertTrue($truncated['truncated']);self::assertSame('BLOCKED',$truncated['state']);self::assertNull($truncated['totals']);
        $filter=static fn(string$sql):string=>str_contains($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=')?'SELECT * FROM missing_finance_report_fixture':$sql;
        global$wpdb;$previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$report=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');self::assertSame('UNAVAILABLE',$report['query_state']);self::assertNull($report['totals']);self::assertSame([],$report['gst_review']['rows']);}
        finally{remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
    }
    /** @dataProvider corruptDeclarationCases */
    public function testCorruptSoleDeclarationMetadataCannotDisappearAsAnEmptyReport(string$field,string$tampered):void
    {
        $input=$this->fixture();$row=(new Repository())->importDeclaration($input);self::assertIsArray($row);
        global$wpdb;self::assertSame('1',$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
        self::assertSame(1,$wpdb->update(Tables::finance_ledger(),[$field=>$tampered],['id'=>$row['id']]));
        $stored=$wpdb->get_row($wpdb->prepare('SELECT '.$field.' FROM '.Tables::finance_ledger().' WHERE id=%d',$row['id']),ARRAY_A);
        self::assertIsArray($stored);self::assertSame($tampered,$stored[$field]);
        $report=(new DigiForge\Finance\DeclarationReadModel())->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);
    }
    public static function corruptDeclarationCases():array
    {return[['metadata','{"tampered":"typed declaration removed"}'],['idempotency_key',''],['idempotency_key','changed-key']];}
    public function testCrossPeriodReversalLoadsItsExactOriginalAndLaterDriftBlocksTheReport():void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);$original=json_decode($first['metadata'],true)['finance_declaration'];
        $reversal=array_replace($input,['external_transaction_id'=>'november-reversal','reversal_of_hash'=>$original['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000','occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z']);
        self::assertIsArray($repo->importDeclaration($reversal));$model=new DigiForge\Finance\DeclarationReadModel();
        $report=$model->snapshot('personalized_pod','test','2026-11-01','2026-11-30','USD');self::assertSame('PROVISIONAL',$report['state']);self::assertSame('-10.0000',$report['totals']['gross_revenue']);self::assertCount(1,$report['gst_review']['rows']);
        self::assertIsArray($repo->importDeclaration(array_replace($input,['revision'=>2,'previous_revision_hash'=>$original['declaration_hash'],'amount'=>'12.0000','base_amount'=>'12.0000'])));
        self::assertSame('BLOCKED',$model->snapshot('personalized_pod','test','2026-11-01','2026-11-30','USD')['state']);
    }
    public function testForwardCrossPeriodReversalKeepsPeriodAmountsButDuplicateInverseBlocksTheOriginalPeriod():void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);
        $original=json_decode($first['metadata'],true)['finance_declaration'];
        $inverse=array_replace($input,['external_transaction_id'=>'forward-november-inverse','reversal_of_hash'=>$original['declaration_hash'],
            'amount'=>'-10.0000','base_amount'=>'-10.0000','occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z']);
        self::assertIsArray($repo->importDeclaration($inverse));$model=new DigiForge\Finance\DeclarationReadModel();
        $october=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('PROVISIONAL',$october['state']);self::assertSame('REVERSED',$october['matching']['state']);
        self::assertSame('10.0000',$october['totals']['gross_revenue']);self::assertCount(1,$october['gst_review']['rows']);
        self::assertSame($original['declaration_hash'],$october['gst_review']['rows'][0]['declaration_hash']);
        $november=$model->snapshot('personalized_pod','test','2026-11-01','2026-11-30','USD');
        self::assertSame('-10.0000',$november['totals']['gross_revenue']);self::assertCount(1,$november['gst_review']['rows']);
        self::assertIsArray($repo->importDeclaration(array_replace($inverse,['external_transaction_id'=>'second-forward-inverse'])));
        $blocked=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('BLOCKED',$blocked['state']);self::assertSame('CONFLICT',$blocked['matching']['state']);
        self::assertNull($blocked['totals']);self::assertSame([],$blocked['gst_review']['rows']);
    }
    public function testForwardCrossPeriodInverseCannotHideItsSupersededOriginalConflict():void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);
        $original=json_decode($first['metadata'],true)['finance_declaration'];
        self::assertIsArray($repo->importDeclaration(array_replace($input,['external_transaction_id'=>'forward-revision-inverse',
            'reversal_of_hash'=>$original['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000',
            'occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z'])));
        self::assertIsArray($repo->importDeclaration(array_replace($input,['revision'=>2,'previous_revision_hash'=>$original['declaration_hash'],
            'amount'=>'12.0000','base_amount'=>'12.0000'])));
        $report=(new DigiForge\Finance\DeclarationReadModel())->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('BLOCKED',$report['state']);self::assertSame('CONFLICT',$report['matching']['state']);self::assertNull($report['totals']);
    }
    /** @dataProvider reportSourceRaceCases */
    public function testReportFinalSourceReadRejectsConcurrentAdditionDeletionAndReadFailure(string $change):void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);
        $original=json_decode($first['metadata'],true)['finance_declaration'];
        $inverse=array_replace($input,['external_transaction_id'=>'racing-forward-inverse','reversal_of_hash'=>$original['declaration_hash'],
            'amount'=>'-10.0000','base_amount'=>'-10.0000','occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z']);
        global $wpdb;$reads=0;$mutated=false;
        $filter=static function(string $sql) use ($change,$inverse,$first,$repo,$wpdb,&$reads,&$mutated):string {
            if (str_starts_with($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=')&&++$reads===2) {
                $mutated=true;
                if ($change==='addition') self::assertIsArray($repo->importDeclaration($inverse));
                elseif ($change==='deletion') self::assertSame(1,$wpdb->delete(Tables::finance_ledger(),['id'=>$first['id']]));
                else return 'SELECT * FROM missing_finance_final_graph_fixture';
            }
            return $sql;
        };
        $previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try {
            $report=(new DigiForge\Finance\DeclarationReadModel())->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
            self::assertTrue($mutated);self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);
            self::assertSame([],$report['gst_review']['rows']);if($change==='failure')self::assertSame('UNAVAILABLE',$report['query_state']);
        } finally {remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
    }
    public static function reportSourceRaceCases():array {return [['addition'],['deletion'],['failure']];}
    public function testOffPeriodEnvironmentOverflowBlocksASeeminglySmallReport():void
    {
        $input=$this->fixture();$repo=new Repository();$first=$repo->importDeclaration($input);self::assertIsArray($first);
        for($i=0;$i<200;++$i)self::assertIsArray($repo->importDeclaration(array_replace($input,['external_transaction_id'=>'off-period-overflow-'.$i,
            'occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z'])));
        $report=(new DigiForge\Finance\DeclarationReadModel())->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('BLOCKED',$report['state']);self::assertTrue($report['truncated']);self::assertNull($report['totals']);
    }
    /** @dataProvider finalReferenceChanges */
    public function testFinalLedgerReadCannotHideChangedOwnershipOrFxReferences(string $change):void
    {
        $input=$this->fixture();$repo=new Repository();global $wpdb;
        if(str_starts_with($change,'fx_')){
            $fx=$repo->createFx(['environment'=>'test','base_currency'=>'EUR','quote_currency'=>'USD','rate'=>'1.2345678901','as_of_at'=>'2026-10-09 12:00:00',
                'source_metadata'=>['kind'=>'DECLARATION','document_ref'=>'synthetic/final-reference-fx','document_hash'=>str_repeat('b',64)]]);
            self::assertIsArray($fx);$input=array_replace($input,['currency'=>'EUR','base_amount'=>'12.3457','fx_snapshot_id'=>(int)$fx['id']]);
        }
        $row=$repo->importDeclaration($input);self::assertIsArray($row);
        $model=new DigiForge\Finance\DeclarationReadModel();
        self::assertSame('PROVISIONAL',$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD')['state']);
        $owner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d',$input['scope']['provider_mapping_id']),ARRAY_A);
        self::assertIsArray($owner);$reads=0;$changed=false;
        $filter=static function(string $sql)use($change,$input,$owner,$wpdb,&$reads,&$changed):string{
            if(str_starts_with($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=')&&++$reads===2){
                if($change==='revoked')self::assertSame(1,$wpdb->update(Tables::pod_business_mappings(),['state'=>'REVOKED'],['id'=>$owner['id']]));
                elseif($change==='deleted')self::assertSame(1,$wpdb->delete(Tables::pod_business_mappings(),['id'=>$owner['id']]));
                elseif($change==='reassigned')self::assertSame(1,$wpdb->update(Tables::pod_business_mappings(),['store_id'=>(int)$owner['store_id']+999],['id'=>$owner['id']]));
                elseif($change==='fx_deleted')self::assertSame(1,$wpdb->delete(Tables::fx_snapshots(),['id'=>$input['fx_snapshot_id']]));
                elseif($change==='fx_changed')self::assertSame(1,$wpdb->update(Tables::fx_snapshots(),['rate'=>'1.1000000000'],['id'=>$input['fx_snapshot_id']]));
                $changed=true;
            }
            if($changed&&$change==='reference_read_failure'&&str_starts_with($sql,'SELECT * FROM '.Tables::product_versions().' WHERE id='))return 'SELECT * FROM missing_final_reference_fixture';
            return $sql;
        };
        $previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$report=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');}
        finally{remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
        self::assertTrue($changed,'The dependency change must occur after the initial reference validation.');
        self::assertSame($row,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE id=%d',$row['id']),ARRAY_A));
        self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);self::assertSame([],$report['gst_review']['rows']);
        foreach(['money_movement_authorized','period_approval_authorized','external_execution_authorized']as$key)self::assertFalse($report[$key]);
    }
    public static function finalReferenceChanges():array
    {return [['revoked'],['deleted'],['reassigned'],['fx_deleted'],['fx_changed'],['reference_read_failure']];}
    public function testCurrentScopedReviewExportPreservesPrecisionWithoutCertifyingTax():void
    {
        $input=$this->fixture();self::assertIsArray((new Repository())->importDeclaration($input));
        $model=new DigiForge\Finance\DeclarationReadModel();self::assertTrue(method_exists($model,'reviewExport'),'A source-validated review package is missing.');
        $one=$model->reviewExport('personalized_pod','test','2026-10-01','2026-10-31','USD');self::assertIsArray($one);
        self::assertSame(hash('sha256',$one['bytes']),$one['sha256']);self::assertSame($one,$model->reviewExport('personalized_pod','test','2026-10-01','2026-10-31','USD'));
        $body=json_decode($one['bytes'],true,32,JSON_THROW_ON_ERROR);self::assertSame('HUMAN_REVIEW_ONLY',$body['purpose']);self::assertFalse($body['filing_authorized']);
        self::assertSame('UNKNOWN',$body['source_completeness']);self::assertSame('10.0000',$body['rows'][0]['amount']);self::assertArrayNotHasKey('source_document_ref',$body['rows'][0]);
        self::assertWPError($model->reviewExport('digital','test','2026-10-01','2026-10-31','USD'));
        global $wpdb;$wpdb->update(Tables::pod_business_mappings(),['state'=>'REVOKED'],['store_id'=>$input['scope']['store_id']]);
        self::assertWPError($model->reviewExport('personalized_pod','test','2026-10-01','2026-10-31','USD'));
    }
    public function testReviewExportPortalRequiresCurrentFourShopAuthorityAndNonce():void
    {
        $input=$this->fixture();self::assertIsArray((new Repository())->importDeclaration($input));
        self::assertTrue(method_exists(DigiForge\Portal\FinanceDeclarationPanel::class,'exportBrowser'),'Authenticated review export is missing.');
        ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        $post=['action'=>'digiforge_finance_review_export','_wpnonce'=>wp_create_nonce('digiforge_finance_review_export'),'shop_key'=>'personalized_pod','environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];
        self::assertIsArray(DigiForge\Portal\FinanceDeclarationPanel::exportBrowser($post,'personalized_pod'));
        foreach([['_wpnonce'=>'invalid'],['shop_key'=>'digital'],['filing_authorized'=>'true'],['base_currency'=>'usd']]as$change)self::assertWPError(DigiForge\Portal\FinanceDeclarationPanel::exportBrowser(array_replace($post,$change),'personalized_pod'));
        ShopAccessFixture::reset();self::assertWPError(DigiForge\Portal\FinanceDeclarationPanel::exportBrowser($post,'personalized_pod'));
    }
    public function testReviewPackageVerificationRejectsTamperingAndStaleRevisions():void
    {
        $input=$this->fixture();$repo=new Repository();self::assertIsArray($repo->importDeclaration($input));$m=new DigiForge\Finance\DeclarationReadModel();
        self::assertTrue(method_exists($m,'verifyReviewExport'),'Current-version review export verification is missing.');
        $p=$m->reviewExport('personalized_pod','test','2026-10-01','2026-10-31','USD');self::assertIsArray($p);
        self::assertTrue($m->verifyReviewExport($p,'personalized_pod','test','2026-10-01','2026-10-31','USD'));
        $changed=$p;$changed['bytes'].=" ";$changed['sha256']=hash('sha256',$changed['bytes']);self::assertWPError($m->verifyReviewExport($changed,'personalized_pod','test','2026-10-01','2026-10-31','USD'));
        self::assertWPError($m->verifyReviewExport($p+['certified'=>true],'personalized_pod','test','2026-10-01','2026-10-31','USD'));
        $revision=array_replace($input,['revision'=>2,'previous_revision_hash'=>DeclarationContract::normalize($input)['declaration_hash'],'amount'=>'11.0000','base_amount'=>'11.0000']);self::assertIsArray($repo->importDeclaration($revision));
        self::assertWPError($m->verifyReviewExport($p,'personalized_pod','test','2026-10-01','2026-10-31','USD'));
    }
    public function testActualPortalPackageRoundTripsAndRetainsSelectedScope():void
    {
        $input=$this->fixture();self::assertIsArray((new Repository())->importDeclaration($input));ShopAccessFixture::reset();
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        $post=['action'=>'digiforge_finance_review_export','_wpnonce'=>wp_create_nonce('digiforge_finance_review_export'),'shop_key'=>'personalized_pod','environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];
        $query=['df_finance_start'=>'2026-10-01','df_finance_end'=>'2026-10-31','df_finance_environment'=>'test','df_finance_currency'=>'USD'];
        $oldPost=$_POST;$oldServer=$_SERVER;$_POST=wp_slash($post);$_SERVER['REQUEST_METHOD']='POST';ob_start();
        try{DigiForge\Portal\FinanceDeclarationPanel::render('personalized_pod','finance',$query,'http://example.org/portal/');$html=ob_get_contents();}finally{ob_end_clean();$_POST=$oldPost;$_SERVER=$oldServer;}
        self::assertSame(1,preg_match('/<textarea readonly[^>]*>(.*?)<\/textarea>/s',$html,$m));
        $copied=json_decode(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,32,JSON_THROW_ON_ERROR);
        self::assertTrue((new DigiForge\Finance\DeclarationReadModel())->verifyReviewExport($copied,'personalized_pod','test','2026-10-01','2026-10-31','USD'));
        self::assertStringContainsString('df_finance_start=2026-10-01',$html);self::assertStringContainsString('df_finance_end=2026-10-31',$html);
    }
    public function testRevokedPortalCapabilityDuringExportCannotReturnBytes():void
    {
        $input=$this->fixture();self::assertIsArray((new Repository())->importDeclaration($input));ShopAccessFixture::reset();
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        $post=['action'=>'digiforge_finance_review_export','_wpnonce'=>wp_create_nonce('digiforge_finance_review_export'),'shop_key'=>'personalized_pod','environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];$hit=false;
        $filter=static function(string $sql)use(&$hit):string{if(!$hit&&str_starts_with($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=')){$hit=true;wp_get_current_user()->add_cap('manage_digiforge',false);}return $sql;};
        add_filter('query',$filter);try{$result=DigiForge\Portal\FinanceDeclarationPanel::exportBrowser($post,'personalized_pod');}finally{remove_filter('query',$filter);}
        self::assertTrue($hit);self::assertWPError($result);
    }
    public function testCsvInspectionCannotRecognizePodRevenueOrRepairRevokedProvisionalLineage():void
    {
        $input=$this->fixture();$repo=new Repository();global $wpdb;
        $csv=FinanceDeclarationFixtures::csvInspection($input['scope']);
        $inspect=DigiForge\Finance\SourceAdmission::preview('printify',$csv['documents'],$csv['context'],$csv['context'],time(),'csv_structure');self::assertIsArray($inspect);
        self::assertWPError($repo->importDeclaration($inspect));self::assertSame('0',$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
        $row=$repo->importDeclaration($input);self::assertIsArray($row);
        $model=new DigiForge\Finance\DeclarationReadModel();$before=$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD');
        self::assertSame('PROVISIONAL',$before['state']);self::assertSame('UNKNOWN',$before['source_completeness']);
        $again=DigiForge\Finance\SourceAdmission::preview('printify',$csv['documents'],$csv['context'],$csv['context'],time(),'csv_structure');
        self::assertSame($inspect,$again);self::assertFalse($again['period_approval_authorized']);
        self::assertSame($before,$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD'));
        self::assertSame(1,$wpdb->update(Tables::pod_business_mappings(),['state'=>'REVOKED'],['provider_mapping_id'=>$input['scope']['provider_mapping_id']]));
        self::assertSame('BLOCKED',$model->snapshot('personalized_pod','test','2026-10-01','2026-10-31','USD')['state']);self::assertWPError($repo->importDeclaration($input));
        self::assertSame($row,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE id=%d',$row['id']),ARRAY_A));
    }

}
