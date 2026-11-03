<?php
declare(strict_types=1);

final class AiWorkflowBudgetAcceptanceTest extends WP_UnitTestCase
{
    private string $shop;
    private string $requestHash;

    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        $this->shop='workflow-budget-'.wp_rand();$this->requestHash=hash('sha256','offline request '.wp_rand());
    }

    private function policy(string $limit, string $parent='0'): array|WP_Error
    {
        return (new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$this->shop,'currency'=>'USD','budgets'=>['month'=>$parent],
            'stages'=>['research'=>['limit'=>10,'monthly_budget'=>$limit],'develop'=>['limit'=>10,'monthly_budget'=>'5']]]);
    }

    private function contract(string $amount='0.600000'): array
    {
        $quote=['provider'=>'openai','quote_id'=>'wf-'.wp_generate_uuid4(),'model'=>'fixture-model','request_sha256'=>$this->requestHash,'currency'=>'USD',
            'maximum_charge'=>$amount,'inclusive_all_charges'=>true,'valid_from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'valid_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)];
        self::assertNotWPError((new DigiForge\AI\MonetaryReservationRepository())->reviewQuote($this->shop,(string)wp_json_encode($quote),'CONFIRM_PROVIDER_QUOTE'));
        return ['quote_id'=>$quote['quote_id'],'model'=>$quote['model'],'request_sha256'=>$quote['request_sha256']];
    }

    public function testPendingAuthoritativeHoldExhaustsOnlyConfiguredWorkflowBudget(): void
    {
        self::assertNotWPError($this->policy('0.600000'));
        $contract=$this->contract();$repo=new DigiForge\AI\ShopAiGovernanceRepository();
        self::assertNotWPError($repo->reserveGeneration($this->shop,'research','research-one',$run=null,$contract));
        $blocked=$repo->reserveGeneration($this->shop,'research','research-two',null,$contract);
        self::assertWPError($blocked);self::assertSame('ai_money_workflow_budget_ceiling',$blocked->get_error_code());
        self::assertNotWPError($repo->reserveGeneration($this->shop,'develop','develop-one',null,$contract));
    }

    public function testConfiguredZeroIsNoSpendingAndCannotUseAnEstimatedUnitCost(): void
    {
        self::assertNotWPError($this->policy('0'));
        $repo=new DigiForge\AI\ShopAiGovernanceRepository();
        self::assertWPError($repo->reserveGeneration($this->shop,'research','zero-without-evidence'));
        $denied=$repo->reserveGeneration($this->shop,'research','zero-with-quote',null,$this->contract());
        self::assertWPError($denied);self::assertSame('ai_money_workflow_budget_ceiling',$denied->get_error_code());
    }

    public function testWorkflowLimitCannotOverrideAnExhaustedParentShopBudget(): void
    {
        self::assertNotWPError($this->policy('5','0.600000'));
        $repo=new DigiForge\AI\ShopAiGovernanceRepository();$contract=$this->contract();
        self::assertNotWPError($repo->reserveGeneration($this->shop,'develop','parent-one',null,$contract));
        $denied=$repo->reserveGeneration($this->shop,'research','parent-two',null,$contract);
        self::assertWPError($denied);self::assertSame('ai_money_budget_ceiling',$denied->get_error_code());
    }

    public function testUnboundedHistoryFromAnotherWorkflowDoesNotConsumeAnIndependentOnlyBudget(): void
    {
        self::assertNotWPError($this->policy('0.600000'));
        $repo=new DigiForge\AI\ShopAiGovernanceRepository();
        self::assertNotWPError($repo->recordUsage(['shop_key'=>$this->shop,'stage'=>'develop','currency'=>'USD'],'generation-other-unbounded'));
        self::assertNotWPError($repo->reserveGeneration($this->shop,'research','own-bounded',null,$this->contract()));
    }

    public function testWorkflowAccountingReadFailureAndConcurrentShopLockBlockReservation(): void
    {
        self::assertNotWPError($this->policy('0.600000'));$contract=$this->contract();$repo=new DigiForge\AI\ShopAiGovernanceRepository();
        global $wpdb;$table=DigiForge\Database\Tables::shop_ai_usage();
        $filter=static fn(string $sql):string=>str_contains($sql,'SELECT * FROM '.$table.' WHERE shop_key=')?'SELECT * FROM digiforge_missing_workflow_exposure':$sql;
        add_filter('query',$filter);try{self::assertWPError($repo->reserveGeneration($this->shop,'research','query-failure',null,$contract));}finally{remove_filter('query',$filter);}
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock=$repo::generationLockName($this->shop);
        self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));
        try{self::assertWPError($repo->reserveGeneration($this->shop,'research','lock-failure',null,$contract));}
        finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();}
        self::assertNotWPError($repo->reserveGeneration($this->shop,'research','after-release',null,$contract));
    }

    public function testWorkflowBudgetRequiresAuthorizedReviewAndAnExactNonnegativeDecimal(): void
    {
        foreach(['-1','NaN','0.0000001',1.25] as $invalid){
            $result=(new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$this->shop,'stages'=>['research'=>['limit'=>10,'monthly_budget'=>$invalid]]]);
            self::assertWPError($result);
        }
        wp_set_current_user(0);self::assertWPError($this->policy('1'));
    }
}
