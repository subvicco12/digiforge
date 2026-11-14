<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
/** Reproduce retained catalog-profile state without weakening runtime ambiguity checks. */
final class NativeApiFixtureIsolationTest extends WP_UnitTestCase {
 public static function retainedConnections():array{return [['not-json'],['{"shop_id":"12"}']];}
 /** @dataProvider retainedConnections */
 public function testRetainedConnectionIsIsolatedAndRestoredByteForByte(string $config):void {
  global $wpdb;
  PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());
  $table=DigiForge\Database\Tables::integrations();$now=gmdate('Y-m-d H:i:s');
  self::assertSame(1,$wpdb->insert($table,['provider'=>'printify','environment'=>'test','connection_key'=>'retained-identity-'.wp_generate_uuid4(),'display_name'=>'Retained synthetic identity record','status'=>'CONFIGURED','enabled'=>0,'config'=>$config,'created_at'=>$now,'updated_at'=>$now]));
  $id=(int)$wpdb->insert_id;$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id),ARRAY_A);
  $f=new PrintifyApiApprovalFixture();$failure=null;
  try{$f->boot();self::assertIsInt($f->integration);self::assertNull($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$id)));
   // A new malformed/duplicate identity introduced during the isolated test must
   // STILL trigger the real runtime guard; isolation does not disable validation.
   self::assertSame(1,$wpdb->insert($table,$before,array_fill(0,count($before),'%s')));
   self::assertWPError((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$f->integration,'printify.connection.read'));
  }catch(Throwable $e){$failure=$e->getMessage();}finally{$f->restore();}
  $after=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id),ARRAY_A);
  // boot() committed this test's sentinel; remove it durably before parent rollback.
  $wpdb->query('COMMIT');$wpdb->delete($table,['id'=>$id]);$wpdb->query('START TRANSACTION');
  self::assertSame($before,$after,'Fixture must restore every original database byte.');
  self::assertNull($failure,'Synthetic fixture must isolate pre-existing provider identity state: '.($failure??''));
 }
}
