<?php
declare(strict_types=1);
/** Cross-namespace fixture proof only; never changes production limiter behavior. */
final class NativeFixtureLimiterIsolationAcceptance extends WP_UnitTestCase {
 public function testIndependentFixtureNamespaceCannotContendForAnotherFixturesLocalRateLock():void {
  $key='digiforge_portal_rate_'.hash_hmac('sha256','email_send_global',wp_salt('nonce'));$lock='df_rate_'.substr(hash('sha256',$key),0,48);$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));
  try{self::assertFalse(DigiForge\PortalAccess\Limiter::reserve('email_send_global',1));$failure=DigiForge\PortalAccess\EmailAuthentication::process(['email'=>'limiter-probe@example.invalid']);self::assertWPError($failure);self::assertSame('digiforge_portal_email_verification',$failure->get_error_code());self::assertSame(429,$failure->get_error_data()['status']);$io=[];putenv('DF_LIMITER_PROBE_BOOTSTRAP='.(defined('DF_NATIVE_FIXTURE_BOOTSTRAP')?DF_NATIVE_FIXTURE_BOOTSTRAP:'/private/core10-bootstrap.php'));$process=proc_open([PHP_BINARY,'/companionrepo/tests/fixtures/printify-connector/sprint/limiter-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$io);self::assertIsResource($process);fclose($io[0]);$out=stream_get_contents($io[1]);$err=stream_get_contents($io[2]);fclose($io[1]);fclose($io[2]);self::assertSame(0,proc_close($process),'Isolated child fixture must bootstrap.');self::assertSame('',trim($err));self::assertSame('ADMITTED',trim($out),'Independent fixture namespace must not share local rate-lock identity.');}
  finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();putenv('DF_LIMITER_PROBE_BOOTSTRAP');}
  self::assertTrue(DigiForge\PortalAccess\Limiter::reserve('email_send_global',1));
 }
}
