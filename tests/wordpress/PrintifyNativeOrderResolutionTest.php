<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeApprovedPackageFixture.php';
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyProductPipeline,PrintifyControlledTransport,PrintifyConnectionBoundary,PrintifyMutationRequest,ScopedProviderConnection};
/** Entire provider flow is synthetic offline transport; production sealing/review/export checks run. */
final class PrintifyNativeOrderResolutionTest extends WP_UnitTestCase
{
 use NativeApprovedPackageFixture;
 private PrintifyApiApprovalFixture $fixture;
 private array $initialOptions=[];
 public static function set_up_before_class():void {parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());}
 protected function setUp():void {
  parent::setUp();global $wpdb;$this->initialOptions=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('digiforge_').'%'));
  $this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();
 }
 protected function tearDown():void {
  try{$this->fixture->restore();global $wpdb;$names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('digiforge_').'%'));foreach(array_diff($names,$this->initialOptions) as $name)delete_option($name);}finally{parent::tearDown();}
 }
 private function bind(string $capability):void {
  $connection=new ScopedProviderConnection();$p=PrintifyApiApprovalFixture::checked($connection->propose('personalized_pod',['integration_id'=>$this->fixture->integration,'capability'=>$capability,'source_sha256'=>hash('sha256','native-test'.$capability),'evidence_reference'=>'local://isolated/native-test','observed_at'=>gmdate('c',time()-1)],time(),time()+1200,wp_generate_uuid4(),0));
  PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($connection->decide('personalized_pod',$this->fixture->integration,$capability,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
 }
 private function productOperation(PrintifyProductPipeline $pipeline,array $package,string $operation,array $options,array $response):array {
  $plan=PrintifyApiApprovalFixture::checked($pipeline->prepare($package['workflow'],$package['package'],$this->fixture->integration,$operation,$options));
  $auth=$this->fixture->approve($pipeline,$plan,'printify-product');
  $transport=new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($response));
  $receipt=PrintifyApiApprovalFixture::checked($transport->executeProduct($plan['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$receipt['record']['status']);return $receipt;
 }
 public function testAuditedConfiguredReceiptResolvesWithoutMetadataInProviderBodyAndRevocationBlocks():void {
  $deny=static function(){throw new RuntimeException('No live HTTP allowed');};add_filter('pre_http_request',$deny);
  try{
   $this->bind('printify.product.prepare');$this->bind('PROVIDER_ORDER_SUBMIT');$package=$this->approvedNativePackage();$pipeline=new PrintifyProductPipeline();
   $read=new PrintifyApiReadWorkflow();$plan=PrintifyApiApprovalFixture::checked($read->prepare('personalized_pod',$this->fixture->integration,'PRODUCT_READ',['product_id'=>'native_p1','blueprint_id'=>5,'provider_id'=>7,'variant_ids'=>[9]]));$auth=$this->fixture->approve($read,$plan,'printify-api-read');
   $product=['id'=>'native_p1','shop_id'=>12,'blueprint_id'=>5,'print_provider_id'=>7,'variants'=>[['id'=>9,'price'=>2500,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]];
   $receipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($product)))->executeApiRead($plan['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$receipt['record']['status']);
   $adopted=PrintifyApiApprovalFixture::checked($pipeline->adoptReadReceipt($package['workflow'],$package['package'],$this->fixture->integration,$receipt['hash']));
   $options=$this->productOperation($pipeline,$package,'PERSONALIZATION_OPTIONS',['product_receipt'=>$adopted['hash']],[['field_id'=>'front_text','type'=>'textbox','label'=>'Names','text_options'=>['character_limit'=>30]]]);
   $native=['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>'offline-opaque-token'];
   $configOptions=['product_receipt'=>$adopted['hash'],'options_receipt'=>$options['hash'],'items'=>[['field_id'=>'front_text','type'=>'textbox','input'=>['text'=>'Alex and Sam']]],'photo_receipts'=>[]];
   $configured=$this->productOperation($pipeline,$package,'PERSONALIZATION_CONFIGURE',$configOptions,$native);
   $verified=PrintifyApiApprovalFixture::checked($pipeline->receipt($configured['hash']));self::assertTrue($verified['network_request_attempted']);self::assertTrue($verified['audit_required']);
   $scope=PrintifyApiApprovalFixture::checked(PrintifyConnectionBoundary::context('personalized_pod',$this->fixture->integration,'PROVIDER_ORDER_SUBMIT'));
   $order=PrintifyApiApprovalFixture::order();$order['line_items']=[['product_id'=>'native_p1','variant_id'=>9,'quantity'=>1,'personalisation'=>$native]];
   $payload=['integration_id'=>$this->fixture->integration,'shop_id'=>12,'connection_scope'=>$scope,'order'=>$order,'native_configuration_references'=>[['line_index'=>0,'configuration_receipt'=>$configured['hash']]]];
   $prepared=PrintifyApiApprovalFixture::checked(PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload));self::assertSame($order,$prepared['body']);self::assertStringNotContainsString($configured['hash'],wp_json_encode($prepared['body']));self::assertSame($configured['hash'],$prepared['reconciliation_identity']['native_configuration_references'][0]['configuration_receipt']);
   $configOptions2=$configOptions;$configOptions2['items'][0]['input']['text']='Different names';$second=$this->productOperation($pipeline,$package,'PERSONALIZATION_CONFIGURE',$configOptions2,$native);$payload2=$payload;$payload2['native_configuration_references'][0]['configuration_receipt']=$second['hash'];$prepared2=PrintifyApiApprovalFixture::checked(PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload2));self::assertSame($prepared['body'],$prepared2['body']);self::assertNotSame($prepared['request_fingerprint'],$prepared2['request_fingerprint']);
   $wrong=$payload;$wrong['connection_scope']['external_account_reference']='foreign-account';self::assertWPError(PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$wrong));
   $key=hash('sha256',DigiForge\Listings\Validator::canonicalJson(['DF-OWN-001','personalized_pod',$this->fixture->integration,'printify.product.prepare']));$state=PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::read('ownership','personalized_pod',$key));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::decide('ownership','personalized_pod',$key,$state['revision'],$state['proposal_hash'],'REVOKED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError(PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload));
  }finally{remove_filter('pre_http_request',$deny);}
 }
}
