<?php
declare(strict_types=1);
/** Test-only rollback for synthetic photo fixtures whose real module COMMIT/DDL escapes WP's rollback. */
trait PhotoWorkflowFixtureIsolation
{
 private array $photoFixtureFloors=[];
 private array $photoFixturePolicies=[];
 private bool $photoFixtureCaptured=false;
 private array $photoFixtureDisplaced=[];
 private function capturePhotoFixtureState():void {
  global $wpdb;$wpdb->last_error='';$policies=$wpdb->get_results('SELECT * FROM '.DigiForge\Database\Tables::shop_ai_policies(),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($policies))throw new RuntimeException('Synthetic photo policy snapshot unavailable.');$floors=[];
  $tables=[];foreach(['businesses','stores','product_programs','pod_business_mappings','orders','order_line_items','pod_mappings','personalization_schemas','personalization_submissions','pod_production_templates','pod_render_evidence','shop_ai_usage','shop_ai_policies','audit_log']as $method)$tables[]=DigiForge\Database\Tables::{$method}();$tables[]=$wpdb->options;
  foreach($tables as $table){$wpdb->last_error='';$raw=$wpdb->get_var('SELECT COALESCE(MAX('.($table===$wpdb->options?'option_id':'id').'),0) FROM '.$table);if($wpdb->last_error!==''||!is_scalar($raw)||!preg_match('/^[0-9]+$/D',(string)$raw))throw new RuntimeException('Synthetic photo fixture boundary unavailable.');$floors[$table]=(int)$raw;}
  $this->photoFixtureFloors=$floors;$this->photoFixturePolicies=$policies;$this->photoFixtureCaptured=true;
 }
 /** Displace only captured incoming fixture rows; restore their exact IDs and bytes at teardown. */
 private function isolatePhotoFixtureRows(string $table,array $where):void {
  global $wpdb;if(!$this->photoFixtureCaptured||!isset($this->photoFixtureFloors[$table])||$table===$wpdb->options||$where===[])throw new RuntimeException('Captured synthetic fixture table and exact selector required.');$clauses=[];$values=[];
  foreach($where as $column=>$value){if(!preg_match('/^[a-z_]+$/D',$column)||(!is_int($value)&&!is_string($value)))throw new RuntimeException('Typed synthetic fixture selector required.');$clauses[]='`'.$column.'`=%s';$values[]=$value;}
  $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$table.' WHERE '.implode(' AND ',$clauses).' ORDER BY id',...$values),ARRAY_A);self::assertSame('',$wpdb->last_error);self::assertIsArray($rows);
  foreach($rows as $row){$id=(int)$row['id'];self::assertGreaterThan(0,$id);self::assertLessThanOrEqual($this->photoFixtureFloors[$table],$id,'Only incoming rows at the captured boundary may be displaced.');self::assertArrayNotHasKey($id,$this->photoFixtureDisplaced[$table]??[]);$this->photoFixtureDisplaced[$table][$id]=$row;self::assertSame(1,$wpdb->delete($table,['id'=>$id],['%d']));self::assertSame('',$wpdb->last_error);}
 }
 /** Reflection-based render fixtures use these fixed synthetic identities without running their setup. */
 private function isolatePhotoFixtureIdentity():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;
  $templates=$wpdb->get_results($wpdb->prepare('SELECT fingerprint FROM '.$t::pod_production_templates().' WHERE template_id=%s','personalized_pod'),ARRAY_A);self::assertSame('',$wpdb->last_error);self::assertIsArray($templates);
  foreach($templates as $template)$this->isolatePhotoFixtureRows($t::shop_ai_usage(),['workflow'=>'production_template','model_key'=>$template['fingerprint']]);
  $orders=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.$t::orders().' WHERE external_order_reference=%s AND shop_reference=%s','personalized_pod','personalized_pod'));self::assertSame('',$wpdb->last_error);self::assertIsArray($orders);
  foreach($orders as $order){$lines=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.$t::order_line_items().' WHERE order_id=%d',(int)$order));self::assertSame('',$wpdb->last_error);self::assertIsArray($lines);foreach($lines as $line)$this->isolatePhotoFixtureRows($t::personalization_submissions(),['order_line_item_id'=>(int)$line]);$this->isolatePhotoFixtureRows($t::order_line_items(),['order_id'=>(int)$order]);}
  $this->isolatePhotoFixtureRows($t::orders(),['external_order_reference'=>'personalized_pod','shop_reference'=>'personalized_pod']);
  $this->isolatePhotoFixtureRows($t::pod_mappings(),['mapping_version'=>'personalized_pod','provider_product_key'=>'101','product_version_id'=>1]);
  $this->isolatePhotoFixtureRows($t::pod_production_templates(),['template_id'=>'personalized_pod']);
  $this->isolatePhotoFixtureRows($t::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'name','version_label'=>'1']);
  $this->isolatePhotoFixtureRows($t::businesses(),['business_key'=>'digicraftifygoods']);
 }
 protected function tearDown():void {
  global $wpdb;
  try{if($this->photoFixtureCaptured){
   // The isolated test is the only writer. Remove only IDs created after its checked boundary.
   foreach(array_reverse($this->photoFixtureFloors,true)as $table=>$floor){$wpdb->last_error='';$ok=$wpdb->query('DELETE FROM '.$table.' WHERE '.($table===$wpdb->options?'option_id':'id').'>'.(int)$floor);self::assertNotFalse($ok,'Synthetic photo fixture cleanup failed.');self::assertSame('',$wpdb->last_error);}
   foreach(array_reverse($this->photoFixtureDisplaced,true)as $table=>$rows)foreach($rows as $id=>$row){$wpdb->last_error='';self::assertSame(1,$wpdb->insert($table,$row),'Incoming fixture restoration failed.');self::assertSame('',$wpdb->last_error);$restored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);self::assertSame('',$wpdb->last_error);self::assertSame($row,$restored,'Incoming fixture IDs and every stored field must be restored exactly.');}
   foreach($this->photoFixturePolicies as $row){$id=(int)$row['id'];unset($row['id']);$wpdb->last_error='';self::assertNotFalse($wpdb->update(DigiForge\Database\Tables::shop_ai_policies(),$row,['id'=>$id]),'Synthetic photo policy restoration failed.');self::assertSame('',$wpdb->last_error);}
   self::assertNotFalse($wpdb->query('COMMIT'),'Synthetic photo cleanup COMMIT failed.');
  }}finally{parent::tearDown();}
 }
}
