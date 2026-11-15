<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/VersionedDateNativePackageFixture.php';
require_once __DIR__.'/fixtures/Shop2BoundPackageFixture.php';
use DigiForge\Database\Tables;
use DigiForge\Research\{ArtworkPersonalizationRepository as Proofs, ArtworkPersonalizationIntake as Intake};
use DigiForge\POD\OfflineDateDisplayProof as Policy;
final class VersionedDateHeldPackageTest extends WP_UnitTestCase {
 use VersionedDateNativePackageFixture,Shop2BoundPackageFixture;
 private PrintifyApiApprovalFixture $fixture;private array $rows=[];
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->rows[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach($this->rows as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $row)$wpdb->insert($t,$row,array_fill(0,count($row),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public static function families():array{return [['mug'],['poster']];}
 private function prepared(string $family):array {
  $p=$this->approvedDateNativePackage($family,true);
  $repo=new DigiForge\POD\ProductPackageRepository();$v=PrintifyApiApprovalFixture::checked($repo->read('personalized_pod',$p['package']));$r=$v['record'];$factory=$r['commercial_design_metadata']['design_hash'];
  $fields=[];foreach($r['evidence']['specification']['native_fields']as $f)$fields[]=['key'=>$f['layer'],'type'=>$f['layer']==='date'?'date':'text','required'=>true,'max_length'=>$f['max_characters']];
  $r['evidence']['native_fields']=array_map(static fn($f)=>['question_type'=>'text_input','question_text'=>$f['key'],'required'=>true,'max_allowed_characters'=>$f['max_length']],$fields);
  $proofs=new Proofs();$legacy=PrintifyApiApprovalFixture::checked($proofs->define('personalized_pod',$factory,$fields));
  $binding=['schema'=>'DF_NATIVE_ARTWORK_BINDING_1','shop_key'=>'personalized_pod','design_factory_hash'=>$factory,'supplier_workflow_hash'=>$p['workflow'],'artwork_version_hash'=>$r['artwork_version_hash']];
  [$scope,$mapping,$pv,$owner,$spec]=$this->boundProduct($binding,$family,$r['package']['provider']['product_id'],(string)$p['variant_id']);
  $ctx=['shop_key'=>'personalized_pod','supplier_workflow_hash'=>$p['workflow'],'workflow_hash'=>$r['commercial_design_metadata']['commercial_hash'],'factory_hash'=>$factory,'business_integration_id'=>(string)$scope->integrationId,'business_mapping_id'=>(string)$mapping,'business_asset_spec_id'=>(string)$spec];
  return ['context'=>$ctx,'evidence'=>$r['evidence'],'schema'=>$legacy,'fields'=>$fields];
 }
 private function selected(array $f,string $schema):array {
  $r=new Proofs();$p=PrintifyApiApprovalFixture::checked($r->sample('personalized_pod',$schema,['first_name'=>'Élodie','second_name'=>'José','date'=>'2024-02-29']));
  $p=PrintifyApiApprovalFixture::checked($r->decide('personalized_pod',$p['hash'],'APPROVE','SYNTHETIC local review only; native fit not approved'));
  PrintifyApiApprovalFixture::checked((new Intake())->selectReviewed('personalized_pod',$f['context']['supplier_workflow_hash'],$f['context']['factory_hash'],$p['hash']));return $p;
 }
 private function packageFor(array $f,array $proof):array {
  $ctx=$f['context']+['personalization_hash'=>$proof['hash']];
  $p=$this->call($ctx+['operation'=>'package_prepare','evidence_mode'=>'import','evidence_json'=>wp_json_encode($f['evidence'])]);
  $this->call($ctx+['operation'=>'package_decide','package_hash'=>$p['package_hash'],'decision'=>'APPROVE','note'=>'SYNTHETIC held review only','confirm'=>'1']);
  return $ctx+['package_hash'=>$p['package_hash'],'confirm'=>'1'];
 }
 /** @dataProvider families */
 public function testPolicySelectionRequiresFreshHeldDecisionAndBindsExactDisplayBytes(string $family):void {
  $http=0;$deny=static function()use(&$http){++$http;throw new RuntimeException('External HTTP forbidden');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{
   $f=$this->prepared($family);$legacy=$this->selected($f,$f['schema']['hash']);$old=$this->packageFor($f,$legacy);$panel=new DigiForge\Portal\Shop2ProductWorkflowPanel();
   self::assertIsArray($panel->process($old+['operation'=>'package_export']));
   $s=PrintifyApiApprovalFixture::checked((new Proofs())->define('personalized_pod',$f['context']['factory_hash'],$f['fields'],$f['schema']['hash'],Policy::POLICY));
   $proof=$this->selected($f,$s['hash']);$result=$proof['record']['data']['result'];
   self::assertSame('2024-02-29',$result['samples']['date']);self::assertSame(10,$result['fields'][2]['max_length']);self::assertSame($family==='mug'?18:24,$result['date_display']['policy']['native_date_cap']);self::assertSame('February 29, 2024',$result['date_display']['samples']['date']);
   self::assertWPError($panel->process($old+['operation'=>'package_export']),'Changed policy selection must invalidate old package');
   $post=$this->packageFor($f,$proof);self::assertNotSame($old['package_hash'],$post['package_hash']);
   $out=$this->call($post+['operation'=>'package_export']);$raw=$out['package_export'];$json=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$ref=$json['proof_business_binding']['personalization_intake'];
   self::assertSame($proof['hash'],$ref['versions']['proof_hash']);self::assertSame($result['proof_sha256'],$ref['proof']['proof_sha256']);self::assertFalse($json['external_execution_authorized']);self::assertTrue($json['release_hold']);self::assertFalse($json['proof_business_binding']['native_typography_verified']);self::assertFalse($json['proof_business_binding']['supplier_certified']);self::assertStringNotContainsString('Élodie',$raw);
   $bundle=$this->call($post+['operation'=>'package_bundle']);$file=tempnam(sys_get_temp_dir(),'date-held-');file_put_contents($file,$bundle['package_bundle']);$zip=new ZipArchive();try{self::assertTrue($zip->open($file,ZipArchive::CHECKCONS));self::assertSame($raw,$zip->getFromName('held-package.json'));self::assertSame($result['proof_sha256'],hash('sha256',$zip->getFromName('proof/local-sample.svg')));self::assertStringContainsString('February 29, 2024',$zip->getFromName('proof/local-sample.svg'));}finally{$zip->close();unlink($file);}
   global $wpdb;$key='digiforge_shop2_personalization_version_'.$proof['hash'];$original=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));
   foreach(['policy_sha256','display_inputs_sha256','receipt_sha256']as $hash){$record=$proof['record'];$record['data']['result']['date_display'][$hash]=str_repeat('f',64);try{$wpdb->update($wpdb->options,['option_value'=>DigiForge\Research\CommercialResearchContract::json($record)],['option_name'=>$key],['%s'],['%s']);self::assertWPError($panel->process($post+['operation'=>'package_export']),$hash);self::assertWPError($panel->process($post+['operation'=>'package_bundle']),$hash);}finally{$wpdb->update($wpdb->options,['option_value'=>$original],['option_name'=>$key],['%s'],['%s']);}}
   self::assertSame($raw,$this->call($post+['operation'=>'package_export'])['package_export']);self::assertSame(0,$http);
  }finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
}
