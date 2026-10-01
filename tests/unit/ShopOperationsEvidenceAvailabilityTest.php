<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ShopOperationsEvidenceAvailabilityTest extends TestCase {
 public function testSnapshotPreservesPerSourceReadFailure():void {
  require_once __DIR__.'/../../includes/Portal/ShopOperationsReadModel.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_'; public string $last_error='';
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_row(string $sql,mixed $format):?array{$this->last_error='catalog unavailable';return null;}
   public function get_results(string $sql,mixed $format):?array{$this->last_error='rows unavailable';return null;}
  };
  try {
   $data=(new \DigiForge\Portal\ShopOperationsReadModel())->snapshot('digital');
   self::assertSame('AVAILABLE',$data['query_state']['catalog']);
   self::assertSame([],$data['catalog']);
   self::assertSame('UNAVAILABLE',$data['query_state']['ai_policies']);
   self::assertSame('UNAVAILABLE',$data['query_state']['ai_usage']);
   self::assertFalse($data['external_execution_performed']);
   $pod=(new \DigiForge\Portal\ShopOperationsReadModel())->snapshot('personalized_pod');
   self::assertSame('UNAVAILABLE',$pod['query_state']['catalog']);
   self::assertSame('UNAVAILABLE',$pod['query_state']['ai_policies']);
   self::assertSame('UNAVAILABLE',$pod['query_state']['ai_usage']);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testCatalogItemsDistinguishesFailedFromLegitimateEmptyRead():void {
  require_once __DIR__.'/../../includes/Portal/ShopOperationsReadModel.php';
  require_once __DIR__.'/../../includes/Database/Tables.php';
  $previous=$GLOBALS['wpdb']??null;
  $stub=new class {
   public string $prefix='wp_'; public string $last_error='';
   public bool $fail=true;
   public function prepare(string $sql,mixed ...$args):string{return $sql;}
   public function get_results(string $sql,mixed $format):?array{if($this->fail){$this->last_error='items unavailable';return null;}$this->last_error='';return [];}
  };
  $GLOBALS['wpdb']=$stub;
  try {
   $model=new \DigiForge\Portal\ShopOperationsReadModel();
   $state=null;self::assertSame([],$model->catalogItems(1,[],50,$state));self::assertSame('UNAVAILABLE',$state);
   $stub->fail=false;$state=null;self::assertSame([],$model->catalogItems(1,[],50,$state));self::assertSame('AVAILABLE',$state);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
}
