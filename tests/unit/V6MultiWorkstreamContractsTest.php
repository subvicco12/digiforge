<?php
declare(strict_types=1);

use DigiForge\AI\ShopAiPlan;
use DigiForge\Orders\FulfillmentMode;
use DigiForge\POD\EtsyPersonalizationContract;
use DigiForge\POD\MasterCatalogImportContract;
use PHPUnit\Framework\TestCase;

final class V6MultiWorkstreamContractsTest extends TestCase {
 public function testCatalogRequiresExact500UniqueGovernedRows():void {
  $rows=[];for($i=1;$i<=500;$i++)$rows[]=[$this->id($i),'W1','Family','Concept '.$i,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','US','EU','P1',''];
  $r=MasterCatalogImportContract::normalize(MasterCatalogImportContract::REQUIRED,$rows);
  self::assertSame(500,$r['row_count']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$r['fingerprint']);
 }
 public function testCatalogRejectsDuplicateIds():void {
  $rows=[];for($i=1;$i<=500;$i++)$rows[]=[$this->id($i),'W1','Family','Concept','PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','','','P1',''];
  $rows[499][0]='DG-001';$this->expectException(InvalidArgumentException::class);MasterCatalogImportContract::normalize(MasterCatalogImportContract::REQUIRED,$rows);
 }
 public function testEtsyTypedPersonalizationSupportsTextDropdownAndOneUpload():void {
  $r=EtsyPersonalizationContract::normalize([
   ['question_type'=>'text_input','question_text'=>'Enter name','required'=>false,'max_allowed_characters'=>50,'add_on_price'=>5.0],
   ['question_type'=>'dropdown','question_text'=>'Choose font','required'=>true,'options'=>[['label'=>'Arial'],['label'=>'Serif']]],
   ['question_type'=>'unlabeled_upload','question_text'=>'Upload photo','required'=>true,'max_allowed_files'=>3],
  ]);
  self::assertCount(3,$r['personalization_questions']);self::assertTrue($r['supports_multiple_personalization_questions']);self::assertSame(5.0,$r['personalization_questions'][0]['add_on_price']);
 }
 public function testEtsyTypedPersonalizationRejectsSecondUpload():void {
  $this->expectException(InvalidArgumentException::class);EtsyPersonalizationContract::normalize([
   ['question_type'=>'unlabeled_upload','question_text'=>'Upload photo','required'=>true,'max_allowed_files'=>1],
   ['question_type'=>'unlabeled_upload','question_text'=>'Upload art','required'=>true,'max_allowed_files'=>1],
  ]);
 }
 public function testShopAiPlanStopsAtQuantityOrBudgetCeiling():void {
  $p=['shop_key'=>'personalized_pod','currency'=>'INR','monthly_budget'=>1000,'stages'=>['research'=>['limit'=>100,'estimated_unit_cost'=>1],'develop'=>['limit'=>25,'estimated_unit_cost'=>10]]];
  $r=ShopAiPlan::evaluate($p,['research'=>['count'=>100,'cost'=>100],'develop'=>['count'=>5,'cost'=>950]]);
  self::assertTrue($r['quantity_ceiling_reached']);self::assertTrue($r['budget_ceiling_reached']);self::assertFalse($r['execution_allowed']);
 }
 public function testHybridFulfillmentRequiresProviderMapping():void {
  self::assertSame('hybrid',FulfillmentMode::classify(true,true));self::assertTrue(FulfillmentMode::requiresProviderMapping('hybrid'));self::assertFalse(FulfillmentMode::requiresProviderMapping('digital'));
 }
 private function id(int $n):string{return 'DG-'.str_pad((string)$n,3,'0',STR_PAD_LEFT);}
}