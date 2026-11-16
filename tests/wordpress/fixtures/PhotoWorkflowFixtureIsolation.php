<?php
declare(strict_types=1);
/** Test-only rollback for synthetic photo fixtures whose real module COMMIT/DDL escapes WP's rollback. */
trait PhotoWorkflowFixtureIsolation
{
 private array $photoFixtureFloors=[];
 private array $photoFixturePolicies=[];
 private bool $photoFixtureCaptured=false;
 private function capturePhotoFixtureState():void {
  global $wpdb;$wpdb->last_error='';$policies=$wpdb->get_results('SELECT * FROM '.DigiForge\Database\Tables::shop_ai_policies(),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($policies))throw new RuntimeException('Synthetic photo policy snapshot unavailable.');$floors=[];
  $tables=[];foreach(['businesses','stores','product_programs','pod_business_mappings','orders','order_line_items','pod_mappings','personalization_schemas','personalization_submissions','pod_production_templates','pod_render_evidence','shop_ai_usage','shop_ai_policies','audit_log']as $method)$tables[]=DigiForge\Database\Tables::{$method}();$tables[]=$wpdb->options;
  foreach($tables as $table){$wpdb->last_error='';$raw=$wpdb->get_var('SELECT COALESCE(MAX('.($table===$wpdb->options?'option_id':'id').'),0) FROM '.$table);if($wpdb->last_error!==''||!is_scalar($raw)||!preg_match('/^[0-9]+$/D',(string)$raw))throw new RuntimeException('Synthetic photo fixture boundary unavailable.');$floors[$table]=(int)$raw;}
  $this->photoFixtureFloors=$floors;$this->photoFixturePolicies=$policies;$this->photoFixtureCaptured=true;
 }
 protected function tearDown():void {
  global $wpdb;
  try{if($this->photoFixtureCaptured){
   // The isolated test is the only writer. Remove only IDs created after its checked boundary.
   foreach(array_reverse($this->photoFixtureFloors,true)as $table=>$floor){$wpdb->last_error='';$ok=$wpdb->query('DELETE FROM '.$table.' WHERE '.($table===$wpdb->options?'option_id':'id').'>'.(int)$floor);self::assertNotFalse($ok,'Synthetic photo fixture cleanup failed.');self::assertSame('',$wpdb->last_error);}
   foreach($this->photoFixturePolicies as $row){$id=(int)$row['id'];unset($row['id']);$wpdb->last_error='';self::assertNotFalse($wpdb->update(DigiForge\Database\Tables::shop_ai_policies(),$row,['id'=>$id]),'Synthetic photo policy restoration failed.');self::assertSame('',$wpdb->last_error);}
   self::assertNotFalse($wpdb->query('COMMIT'),'Synthetic photo cleanup COMMIT failed.');
  }}finally{parent::tearDown();}
 }
}
