<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PortalActionAcceptanceFixture.php';

use DigiForge\AI\ShopAiGovernanceRepository;
use DigiForge\AI\ShopAiPlan;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;

/** table22r4/r5/r15: synthetic local quantity governance, never provider or monetary authority. */
final class PortalQuantityPolicyAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
        wp_create_nonce('quantity-policy-fixture');$this->http=function(){++$this->httpCalls;return new WP_Error('offline_quantity','Provider HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));self::assertTrue(Settings::safety_locked());parent::tearDown();
    }
    private function saved(string $shop): array
    {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s',$shop,'production'),ARRAY_A);self::assertIsArray($row);self::assertSame(hash('sha256',$row['policy']),$row['policy_hash']);return $row;
    }
    private function policy(string $shop,int $limit): void
    {
        PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>$shop,'currency'=>'USD','stage_develop_limit'=>(string)$limit,'stage_develop_unit_cost'=>'0','stage_research_limit'=>(string)($limit+1)]);
        $row=$this->saved($shop);$p=json_decode($row['policy'],true);self::assertSame($shop,$row['shop_key']);self::assertSame('production',$row['environment']);self::assertSame($limit,$p['stages']['develop']['limit']);self::assertSame($limit+1,$p['stages']['research']['limit']);
    }
    private function render(string $shop): string
    {
        $prior=$_GET;try{$_GET=['df_view'=>'ai_budget','df_shop'=>$shop];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$prior;}
    }
    private function runContext(): array{return ['run_id'=>'private-fixture-run-'.wp_generate_uuid4(),'started_at'=>gmdate('Y-m-d H:i:s')];}
    private function fingerprint(array $run): string{return hash('sha256',$run['run_id']."\0".$run['started_at']);}
    public function testFourShopNativeQuantityPoliciesRoundTripExactLimitsAndKeepForeignPoliciesUnchanged(): void
    {
        $limits=['digital'=>3,'personalized_pod'=>5,'standard_pod'=>7,'jewelry'=>9];foreach($limits as $shop=>$limit)$this->policy($shop,$limit);$before=[];foreach($limits as $shop=>$limit)$before[$shop]=$this->saved($shop);
        foreach($limits as $shop=>$limit){$html=$this->render($shop);self::assertStringContainsString('name="shop_key" value="'.$shop.'"',$html);self::assertStringContainsString('name="stage_develop_limit" value="'.$limit.'"',$html);self::assertStringContainsString('name="stage_research_limit" value="'.($limit+1).'"',$html);self::assertStringContainsString('Saving ceilings does not run AI',$html);}
        $this->policy('digital',11);foreach(['personalized_pod','standard_pod','jewelry']as $shop)self::assertSame($before[$shop],$this->saved($shop));
    }
    public function testActualZeroCostQuantityReservationsConsumeLastSlotsAndRejectOverrunWithoutChangingForeignShop(): void
    {
        global $wpdb;$month=gmdate('Y-m-01 00:00:00');$used=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND stage=%s AND occurred_at>=%s','digital','develop',$month));$this->policy('digital',$used+2);
        $repo=new ShopAiGovernanceRepository();$run=$this->runContext();$before=$repo->evaluate('digital','production',$run);self::assertNotWPError($before);$plan=ShopAiPlan::preflight($before,'develop',3,0.0);self::assertContains('stage_quantity_ceiling',$plan['reasons']);
        $foreignBefore=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s','jewelry'));
        foreach([1,2]as $n){$reservation=$repo->reserveGeneration('digital','develop','quantity-'.wp_generate_uuid4(),$run);self::assertNotWPError($reservation);self::assertSame('digital',$reservation['shop_key']);self::assertSame($run['run_id'],$reservation['run_id']);self::assertSame(1,(int)$reservation['quantity']);}
        $blocked=$repo->reserveGeneration('digital','develop','overrun-'.wp_generate_uuid4(),$run);self::assertWPError($blocked);self::assertSame('ai_generation_budget_blocked',$blocked->get_error_code());$after=$repo->evaluate('digital','production',$run);self::assertNotWPError($after);self::assertSame(0,$after['stages']['develop']['remaining']);self::assertSame($used+2,$after['stages']['develop']['used']);
        self::assertSame(2,(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(quantity) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND run_id=%s','digital',$run['run_id'])));self::assertSame($foreignBefore,(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s','jewelry')));
    }
    public function testActualRunQuantitiesRemainDistinctSelectedAndPrivateInPortal(): void
    {
        $runs=[$this->runContext(),$this->runContext(),$this->runContext()];$repo=new ShopAiGovernanceRepository();
        foreach([['digital',$runs[0],2],['digital',$runs[1],1],['jewelry',$runs[2],1]]as [$shop,$run,$quantity]){self::assertNotWPError($repo->recordUsage(['shop_key'=>$shop,'workflow'=>'product_factory','stage'=>'develop','quantity'=>$quantity,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD','run_context'=>$run],'run-quantity-'.wp_generate_uuid4()));}
        $html=$this->render('digital');self::assertStringContainsString($this->fingerprint($runs[0]),$html);self::assertStringContainsString($this->fingerprint($runs[1]),$html);self::assertStringNotContainsString($this->fingerprint($runs[2]),$html);foreach($runs as $run)self::assertStringNotContainsString($run['run_id'],$html);
        $projection=(new DigiForge\Portal\RunQuantityReadModel())->snapshot('digital');self::assertSame('AVAILABLE',$projection['query_state']);self::assertFalse($projection['external_execution_authorized']);$items=array_column($projection['items'],null,'run_fingerprint');self::assertSame(2,$items[$this->fingerprint($runs[0])]['quantity']);self::assertSame(1,$items[$this->fingerprint($runs[1])]['quantity']);self::assertArrayNotHasKey($this->fingerprint($runs[2]),$items);
    }
    public function testSameRunIdWithDistinctStartedAtKeepsExactContextFingerprints(): void
    {
        $one=$this->runContext();$two=$one;$two['started_at']=gmdate('Y-m-d H:i:s',time()-120);$repo=new ShopAiGovernanceRepository();
        foreach([[$one,1],[$two,2]]as [$run,$quantity])self::assertNotWPError($repo->recordUsage(['shop_key'=>'digital','workflow'=>'product_factory','stage'=>'develop','quantity'=>$quantity,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD','run_context'=>$run],'context-quantity-'.wp_generate_uuid4()));
        $projection=(new DigiForge\Portal\RunQuantityReadModel())->snapshot('digital');self::assertSame('AVAILABLE',$projection['query_state']);$items=array_column($projection['items'],null,'run_fingerprint');self::assertSame(1,$items[$this->fingerprint($one)]['quantity']);self::assertSame(2,$items[$this->fingerprint($two)]['quantity']);self::assertSame($one['started_at'],$items[$this->fingerprint($one)]['started_at']);self::assertSame($two['started_at'],$items[$this->fingerprint($two)]['started_at']);
    }
    public function testUsageWithoutExplicitRunContextRemainsUnknownInsteadOfFabricatedIdentity(): void
    {
        global $wpdb;$before=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage()." WHERE shop_key=%s AND (run_id='' OR run_started_at IS NULL)",'digital'));
        self::assertNotWPError((new ShopAiGovernanceRepository())->recordUsage(['shop_key'=>'digital','workflow'=>'product_factory','stage'=>'develop','quantity'=>4,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD'],'unknown-run-'.wp_generate_uuid4()));
        $projection=(new DigiForge\Portal\RunQuantityReadModel())->snapshot('digital');self::assertSame('AVAILABLE',$projection['query_state']);self::assertSame($before+4,$projection['unattributed_quantity']);foreach($projection['items']as $item)self::assertNotSame(hash('sha256',"\0"),$item['run_fingerprint']);
        $html=$this->render('digital');self::assertStringContainsString('Run identity UNKNOWN',$html);self::assertStringContainsString('Unattributed quantity',$html);
    }
    public function testSqlResultIsBoundedWhileUnknownQuantityRemainsComplete(): void
    {
        global $wpdb;$repo=new ShopAiGovernanceRepository();$before=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage()." WHERE shop_key=%s AND (run_id='' OR run_started_at IS NULL)",'digital'));
        foreach([1,2,3]as $n)self::assertNotWPError($repo->recordUsage(['shop_key'=>'digital','workflow'=>'product_factory','stage'=>'develop','quantity'=>$n,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD','run_context'=>$this->runContext()],'bounded-run-'.wp_generate_uuid4()));
        self::assertNotWPError($repo->recordUsage(['shop_key'=>'digital','workflow'=>'product_factory','stage'=>'develop','quantity'=>5,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>'USD'],'bounded-unknown-'.wp_generate_uuid4()));
        $projection=(new DigiForge\Portal\RunQuantityReadModel())->snapshot('digital',1);self::assertSame('AVAILABLE',$projection['query_state']);self::assertCount(1,$projection['items']);self::assertTrue($projection['truncated']);self::assertSame($before+5,$projection['unattributed_quantity']);self::assertSame(2,$wpdb->num_rows,'Only limit+1 grouped records may be fetched into PHP.');
    }
    public function testFailedPolicyReadBlocksEditingAndCannotReplaceUnknownPolicy(): void
    {
        $this->policy('digital',3);$before=$this->saved('digital');global $wpdb;$table=Tables::shop_ai_policies();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$html=$this->render('digital');self::assertStringContainsString('AI policy or cost evidence unavailable',$html);self::assertStringNotContainsString('name="stage_develop_limit"',$html);PortalActionAcceptanceFixture::invoke('SAVE_AI_POLICY_ACTION',['shop_key'=>'digital','currency'=>'USD','stage_develop_limit'=>'99'],true);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        self::assertSame($before,$this->saved('digital'));
    }
    public function testUnavailableRunReadIsNotAnEmptyZeroWindowAndUnassignedActorCannotReadIt(): void
    {
        global $wpdb;$table=Tables::shop_ai_usage();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$html=$this->render('digital');self::assertStringContainsString('Run quantity evidence unavailable',$html);self::assertStringNotContainsString('No attributed run quantities',$html);}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$projection=(new DigiForge\Portal\RunQuantityReadModel())->snapshot('digital');self::assertSame('UNAVAILABLE',$projection['query_state']);self::assertSame([],$projection['items']);
    }
}
