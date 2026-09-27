<?php
declare(strict_types=1);

use DigiForge\AI\ShopAiPlan;
use DigiForge\Orders\FulfillmentMode;
use DigiForge\POD\PersonalizationSubmissionNormalizer;
use PHPUnit\Framework\TestCase;

final class V6PortalPersonalizationOperationsTest extends TestCase
{
    public function testAiBudgetsFailClosedAcrossRunDayAndMonth():void
    {
        $policy=['shop_key'=>'personalized_pod','currency'=>'USD','budgets'=>['run'=>2,'day'=>5,'month'=>20],'stages'=>['render'=>['limit'=>10,'estimated_unit_cost'=>0.25]]];
        $projection=ShopAiPlan::evaluate($policy,['month'=>['render'=>['count'=>2,'cost'=>1.5]],'costs'=>['run'=>2,'day'=>3,'month'=>4]]);
        self::assertTrue($projection['budget_ceiling_reached_by_period']['run']);
        self::assertFalse($projection['budget_ceiling_reached_by_period']['day']);
        self::assertFalse($projection['execution_allowed']);
        self::assertSame(16.0,$projection['budget_remaining_by_period']['month']);
    }

    public function testHybridModeRequiresProviderMapping():void
    {
        self::assertSame(FulfillmentMode::HYBRID,FulfillmentMode::classify(true,true));
        self::assertTrue(FulfillmentMode::requiresProviderMapping(FulfillmentMode::HYBRID));
        self::assertFalse(FulfillmentMode::requiresProviderMapping(FulfillmentMode::DIGITAL));
    }

    public function testTypedPersonalizationNormalizesWithoutProviderExecution():void
    {
        $questions=[
            ['question_id'=>54,'question_type'=>'text_input','question_text'=>'Enter family name','required'=>true,'max_allowed_characters'=>30],
            ['question_id'=>55,'question_type'=>'dropdown','question_text'=>'Choose style','required'=>true,'options'=>[['label'=>'Classic'],['label'=>'Modern']]],
        ];
        $result=PersonalizationSubmissionNormalizer::normalize($questions,['question_54'=>'Singha Roy','question_55'=>'Classic']);
        self::assertIsArray($result);
        self::assertCount(2,$result['answers']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$result['canonical_hash']);
        self::assertSame('text_input',$result['answers'][0]['question_type']);
    }
    public function testProjectedAiSpendRequiresApprovalBeforeCrossingCeilings():void
    {
        $projection=\DigiForge\AI\ShopAiPlan::evaluate(['shop_key'=>'personalized_pod','currency'=>'USD','budgets'=>['run'=>2,'day'=>5,'month'=>20],'stages'=>['render'=>['limit'=>10,'estimated_unit_cost'=>0.25]]],['month'=>['render'=>['count'=>8,'cost'=>1.5]],'costs'=>['run'=>1.8,'day'=>4.8,'month'=>10]]);
        $preflight=\DigiForge\AI\ShopAiPlan::preflight($projection,'render',3,0.3);
        self::assertTrue($preflight['approval_required']);
        self::assertFalse($preflight['execution_allowed']);
        self::assertContains('stage_quantity_ceiling',$preflight['reasons']);
        self::assertContains('run_budget_ceiling',$preflight['reasons']);
        self::assertContains('day_budget_ceiling',$preflight['reasons']);
        self::assertFalse($preflight['external_execution_performed']);
    }

}
