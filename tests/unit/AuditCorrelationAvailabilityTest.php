<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AuditCorrelationAvailabilityTest extends TestCase {
 public function testFailedAuditIdentityReadIsUnavailableNotEmptyEvidence():void {
  require_once __DIR__.'/../../includes/Operations/AuditCorrelationReadModel.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_'; public string $last_error='audit unavailable';
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_results(string $sql,mixed $format):?array{return null;}
  };
  try {
   $state=null;$rows=(new \DigiForge\Operations\AuditCorrelationReadModel())->recent('', '',50,$state);
   self::assertSame('UNAVAILABLE',$state); self::assertSame([],$rows);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testSuccessfulEmptyAuditIdentityReadRemainsAvailable():void {
  require_once __DIR__.'/../../includes/Operations/AuditCorrelationReadModel.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_'; public string $last_error='';
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_results(string $sql,mixed $format):array{return [];}
  };
  try {
   $state=null;$rows=(new \DigiForge\Operations\AuditCorrelationReadModel())->recent('', '',50,$state);
   self::assertSame('AVAILABLE',$state); self::assertSame([],$rows);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testPortalLabelsUnavailableAuditIdentityEvidence():void {
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('Audit identity evidence unavailable',$portal);
  self::assertStringContainsString("$"."auditIdentityState==='AVAILABLE'",$portal);
 }
}
