<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\Connector\Printify\{NativeBridge,NativePortContract,ShopBinding,CredentialReference,ExternalOperationIntent};

require_once __DIR__.'/../support/NativePrintifyContractFixture.php';

final class NativePrintifyCorePortTest extends TestCase
{
 public function testContractBytesAndClosedGrammar():void {
  self::assertSame('6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434',NativePortContract::digest());
  self::assertSame('DF_NATIVE_CORE_PORT_1',NativePortContract::specification()['contract']);
  self::assertFalse(NativePortContract::valid('recovery_request',['action'=>'REARM']));
  self::assertFalse(NativePortContract::valid('recovery_request',['action'=>'INSPECT','url'=>'https://example.invalid']));
  self::assertFalse(NativePortContract::valid('json_object',['object'=>new stdClass()]));
  self::assertTrue(NativePortContract::valid('recovery_request',['action'=>'INSPECT']));
 }
 public function testFactoryVersionAndExactTypedComposite():void {
  self::assertInstanceOf(WP_Error::class,NativeBridge::connect('2.0.0'));
  $p=NativeBridge::connect('1.0.0');self::assertInstanceOf(DigiForgePrintifyConnector\Preparation\NativeCorePortInterface::class,$p);
  foreach(['version','inspectionVersion','publicationVersion','workflowVersion','operationsVersion'] as $method)self::assertSame('1.0.0',$p->$method());
 }
 public function testAbsentAndIncompatibleCompanionsDenyWithoutFatalOrEagerImplementation():void {
  foreach(['absent','incompatible','wrong-return','return-reference']as $mode){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/discovery-child.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);self::assertSame("DENIED_WITHOUT_LOADING_NATIVE_IMPLEMENTATION\n",$out);}
 }
 public function testNoUnbuiltSourceIdentityOrExecutableCapability():void {
  $p=NativeBridge::connect('1.0.0');
  $this->expectException(RuntimeException::class);$p->contractManifest();
 }
 public function testCallerHashesCannotCreateDurableOrRecoveryEvidence():void {
  $p=NativeBridge::connect('1.0.0');$scope=self::scope();$called=0;
  foreach(['AUTHORIZE','REGISTER','CLAIM','RECOVER','CANCEL','RECEIPT_READ','RECONCILE','OUTCOME'] as $a){
   $q=NativePrintifyContractFixture::mutation($a);$r=$p->exchange($q);self::assertInstanceOf(WP_Error::class,$r);self::assertFalse($r->get_error_data()['external_execution_authorized']);self::assertFalse($r->get_error_data()['retry_permitted']);
  }
  foreach([
   fn()=>$p->inspect(NativePrintifyContractFixture::binding()),
   fn()=>$p->authorizePublication(NativePrintifyContractFixture::binding(),[]),
   fn()=>$p->allocation($scope,1,['order_identity'=>str_repeat('f',64),'order_version'=>1]),
   fn()=>$p->recovery($scope,1,['action'=>'INSPECT']),
   fn()=>$p->withWebhookSecret($scope,function()use(&$called){$called++;return []; }),
   fn()=>$p->recordWorkflow([]),fn()=>$p->recordFulfillment([]),fn()=>$p->decideReview([]),
   fn()=>$p->inspectWorkflows($scope,1,20),fn()=>$p->reviewQueue($scope,1,20),fn()=>$p->inspectFulfillment($scope,1,str_repeat('a',64)),
  ] as $call)self::assertInstanceOf(WP_Error::class,$call());
  self::assertSame(0,$called);
 }
 public function testMalformedFixedGetCannotBecomeHttpProxy():void {
  foreach(['PRODUCT_PUBLISH','https://example.invalid','ORDER_SUBMIT']as $op){try{new ExternalOperationIntent(1,'personalized_pod',3,$op,[],str_repeat('a',32));self::fail('Mutation/URL accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public static function scope():ShopBinding{return new ShopBinding(2,'personalized_pod',3,'28930593','test',1,new CredentialReference(3),str_repeat('b',64),time()+60);}
}
