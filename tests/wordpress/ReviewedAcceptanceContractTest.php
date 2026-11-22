<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\Portal\ReviewedAcceptanceContract as Contract;
final class ReviewedAcceptanceContractTest extends WP_UnitTestCase {
 private array $actors;
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$this->actors=ReviewedLocalFixture::actors('personalized_pod');}
 private function ref():array{return ['shop_key'=>'personalized_pod','reference'=>'local://synthetic/contract-evidence','sha256'=>hash('sha256','synthetic-not-certified')];}
 private function input(string $criterion):array {
  $r=$this->ref();$d=match($criterion){
   'BP-seam-panel-render'=>['fixture_refs'=>[$r],'geometry_ref'=>$r,'checks'=>['seam','panel','bleed']],
   'BP-template-qa-spelling'=>['locale'=>'en-US','text_sha256'=>hash('sha256','Approved synthetic text'),'spelling_review_ref'=>$r],
   'BP-ai-value-routing'=>['measure'=>'Synthetic measured outcome','unit'=>'synthetic units','baseline_ref'=>$r,'candidate_ref'=>$r,'benefit_review_ref'=>$r,'decision_rule_ref'=>$r,'fallback'=>'ORIGINAL'],
   default=>['numerator'=>'Reviewed synthetic events','denominator'=>'Reviewed eligible population','population'=>'Synthetic reviewed records','attribution'=>'Exact shop/product/order/workflow references','period_start'=>'2026-01-01T00:00:00Z','period_end'=>'2026-02-01T00:00:00Z','source_records'=>[$r],'formula_ref'=>null],
  };
  if(in_array($criterion,['BP-portal-17-defect-quality','BP-quality-feedback'],true))$d['severity_policy_ref']=$r;
  return ['version'=>1,'criterion'=>$criterion,'scope'=>'synthetic-scope','workflow'=>'synthetic-workflow','definition'=>$d];
 }
 public static function criteria():array{return array_map(static fn($x)=>[$x],['BP-seam-panel-render','BP-ai-value-routing','BP-template-qa-spelling','BP-portal-7-supplier-readiness','BP-portal-17-conversion','BP-portal-17-supplier-performance','BP-portal-17-product-performance','BP-portal-17-shop-performance','BP-portal-17-defect-quality','BP-quality-feedback']);}
 private function propose(array $input):array|WP_Error{return Contract::propose('personalized_pod',$input,time()-1,time()+3600,wp_generate_uuid4(),0);}
 private function approve(array $input):array {$p=$this->propose($input);self::assertNotWPError($p);wp_set_current_user($this->actors['reviewer']);$a=Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($a);wp_set_current_user($this->actors['author']);return $a;}
 /** @dataProvider criteria */
 public function testTenTypedContractsUseExistingIndependentReviewWithoutCertification(string $criterion):void {
  $input=$this->input($criterion);self::assertWPError(Contract::current('personalized_pod',$criterion,$input['scope']));$this->approve($input);$out=Contract::current('personalized_pod',$criterion,$input['scope']);self::assertNotWPError($out);self::assertSame(DigiForge\Listings\Validator::canonicalJson($input),DigiForge\Listings\Validator::canonicalJson($out['payload']));self::assertSame('UNKNOWN',$out['value_state']);foreach(['external_execution_authorized','supplier_approved','publishing_allowed','ai_execution_allowed']as $flag)self::assertFalse($out[$flag]);self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testUnknownVersionFieldsEmptyDefinitionsAndForeignSourcesReject():void {
  $input=$this->input('BP-portal-17-conversion');foreach([['version'=>2],['extra'=>true],['criterion'=>'invented'],['definition'=>[]]]as $change)self::assertWPError($this->propose(array_replace($input,$change)));
  foreach(['numerator','denominator','population','attribution']as $field){$bad=$input;$bad['definition'][$field]='';self::assertWPError($this->propose($bad));}
  $bad=$input;$bad['definition']['source_records'][0]['shop_key']='digital';self::assertWPError($this->propose($bad));$bad=$input;$bad['definition']['formula_ref']='invented formula';self::assertWPError($this->propose($bad));
 }
 public function testEvidenceReferencesAreBoundedNonSecretDeclarationsNotCalculatedScores():void {
  $input=$this->input('BP-portal-17-conversion');foreach([['reference'=>'https://example.invalid/evidence'],['reference'=>'local://synthetic/../private'],['sha256'=>'not-a-digest'],['token'=>'synthetic-only']]as $change){$bad=$input;$bad['definition']['source_records'][0]=array_replace($this->ref(),$change);self::assertWPError($this->propose($bad));}
  foreach([array_fill(0,33,$this->ref()),[$this->ref(),$this->ref()]]as $refs){$bad=$input;$bad['definition']['source_records']=$refs;self::assertWPError($this->propose($bad));}$bad=$input;$bad['definition']['score']=100;self::assertWPError($this->propose($bad));$input['definition']['formula_ref']=$this->ref();$this->approve($input);$out=Contract::current('personalized_pod',$input['criterion'],$input['scope']);self::assertNotWPError($out);self::assertSame('UNKNOWN',$out['value_state']);self::assertArrayNotHasKey('score',$out);self::assertArrayNotHasKey('rate',$out);
 }
 public function testAbsolutePeriodsAndReviewedDefectPopulationAreRequired():void {
  $input=$this->input('BP-quality-feedback');foreach(['2026-01-01 00:00:00','2026-02-30T00:00:00Z','2026-03-01T00:00:00Z']as $date){$bad=$input;$bad['definition']['period_start']=$date;self::assertWPError($this->propose($bad));}$bad=$input;unset($bad['definition']['severity_policy_ref']);self::assertWPError($this->propose($bad));$bad=$input;$bad['definition']['source_records']=[];self::assertWPError($this->propose($bad));
 }
 public function testSeamBleedAndTextContractsCannotCertifyCallerHashes():void {
  $seam=$this->input('BP-seam-panel-render');$seam['definition']['checks']=['bleed'];self::assertWPError($this->propose($seam));$text=$this->input('BP-template-qa-spelling');$this->approve($text);self::assertTrue(Contract::matchesText('personalized_pod',$text['scope'],'Approved synthetic text','en-US'));self::assertWPError(Contract::matchesText('personalized_pod',$text['scope'],'Changed text','en-US'));self::assertWPError(Contract::matchesText('personalized_pod',$text['scope'],'Approved synthetic text','en-GB'));
 }
 public function testUnvalidatedPolicyPayloadAndFutureReviewCannotBorrowAcceptanceNamespace():void {
  $input=$this->input('BP-portal-17-conversion');$key='acceptance:v1:'.$input['criterion'].':'.$input['scope'];$p=DigiForge\Security\ReviewedLocalEvidence::propose('policy','personalized_pod',$key,['version'=>999],time()-1,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($p);wp_set_current_user($this->actors['reviewer']);self::assertNotWPError(DigiForge\Security\ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));self::assertWPError(Contract::current('personalized_pod',$input['criterion'],$input['scope']));
  wp_set_current_user($this->actors['author']);$input['scope']='future';$p=Contract::propose('personalized_pod',$input,time()+600,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($p);wp_set_current_user($this->actors['reviewer']);self::assertNotWPError(Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));self::assertWPError(Contract::current('personalized_pod',$input['criterion'],$input['scope']));
 }
 public function testReviewReplayExpiryRevocationAndDeferredShopsFailClosed():void {
  $input=$this->input('BP-ai-value-routing');$p=$this->propose($input);self::assertNotWPError($p);self::assertWPError(Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));wp_set_current_user($this->actors['reviewer']);$a=Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($a);self::assertWPError(Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));self::assertNotWPError(Contract::decide('personalized_pod',$input['criterion'],$input['scope'],$a['revision'],$a['proposal_hash'],'REVOKED',wp_generate_uuid4()));self::assertWPError(Contract::current('personalized_pod',$input['criterion'],$input['scope']));foreach(['digital','standard_pod','jewelry']as $shop)self::assertWPError(Contract::current($shop,$input['criterion'],$input['scope']));self::assertWPError(Contract::propose('personalized_pod',$input,time()-100,time()-1,wp_generate_uuid4(),$a['revision']));
 }
}
