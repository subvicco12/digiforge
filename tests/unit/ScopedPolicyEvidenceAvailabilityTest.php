<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ScopedPolicyEvidenceAvailabilityTest extends TestCase {
 public function testFailedPolicyReadsAreUnavailableNotEmptyEvidence():void {
  require_once __DIR__.'/../../includes/Portal/ScopedCapabilityPolicyRepository.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_'; public string $last_error='policy unavailable';
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_results(string $sql,mixed $format):?array{return null;}
  };
  try {
   $repo=new \DigiForge\Portal\ScopedCapabilityPolicyRepository();
   $state=null; self::assertSame([],$repo->current(100,$state)); self::assertSame('UNAVAILABLE',$state);
   $state=null; self::assertSame([],$repo->recent('', '',50,$state)); self::assertSame('UNAVAILABLE',$state);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testSuccessfulEmptyPolicyReadsRemainAvailable():void {
  require_once __DIR__.'/../../includes/Portal/ScopedCapabilityPolicyRepository.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_'; public string $last_error='';
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_results(string $sql,mixed $format):array{return [];}
  };
  try {
   $repo=new \DigiForge\Portal\ScopedCapabilityPolicyRepository();
   $state=null; self::assertSame([],$repo->current(100,$state)); self::assertSame('AVAILABLE',$state);
   $state=null; self::assertSame([],$repo->recent('', '',50,$state)); self::assertSame('AVAILABLE',$state);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
}
