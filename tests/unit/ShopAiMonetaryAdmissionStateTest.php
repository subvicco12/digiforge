<?php
declare(strict_types=1);
use DigiForge\AI\ShopAiPlan;
use PHPUnit\Framework\TestCase;
final class ShopAiMonetaryAdmissionStateTest extends TestCase {
 public function testMissingCeilingIsSeparateFromAvailableQuantityPlanning():void {
  $p=ShopAiPlan::evaluate(['shop_key'=>'digital','stages'=>['develop'=>['limit'=>5]]]);self::assertSame('MISSING_CEILING',$p['stages']['develop']['monetary_admission_state']);self::assertTrue($p['execution_allowed']);$pre=ShopAiPlan::preflight($p,'develop',1,0);self::assertTrue($pre['execution_allowed']);self::assertSame('MISSING_CEILING',$pre['monetary_admission_state']);self::assertFalse($pre['external_execution_performed']);
 }
 public function testExplicitExactZeroOverridesPositiveParentAdmissionWithoutChangingQuantity():void {
  foreach(['0','0.0','0.000000']as $zero){$p=ShopAiPlan::evaluate(['shop_key'=>'digital','budgets'=>['month'=>10],'stages'=>['develop'=>['limit'=>5,'monthly_budget'=>$zero]]]);self::assertSame('EXPLICIT_ZERO_CEILING',$p['stages']['develop']['monetary_admission_state']);self::assertTrue(ShopAiPlan::preflight($p,'develop',1,0)['execution_allowed']);}
 }
 public function testConfiguredStageOrParentCeilingStillRequiresQuote():void {
  foreach([['stages'=>['develop'=>['limit'=>5,'monthly_budget'=>'0.000001']]],['budgets'=>['month'=>5],'stages'=>['develop'=>['limit'=>5]]]]as $policy){$p=ShopAiPlan::evaluate(['shop_key'=>'digital']+$policy);self::assertSame('CEILING_CONFIGURED_QUOTE_REQUIRED',$p['stages']['develop']['monetary_admission_state']);self::assertSame('CEILING_CONFIGURED_QUOTE_REQUIRED',ShopAiPlan::preflight($p,'develop',1,0)['monetary_admission_state']);}
 }
}
