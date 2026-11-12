<?php
declare(strict_types=1);
/** Local test-only durable session; never changes production transaction guards. */
trait DurableCatalogFixture {
 private array $catalogSnapshots=[];
 private function snapshotCatalogSession():void {
  global $wpdb;
  $tables=[DigiForge\Database\Tables::settings(),DigiForge\Database\Tables::audit_log(),DigiForge\Database\Tables::integrations(),DigiForge\Database\Tables::integration_secrets(),DigiForge\Database\Tables::businesses(),DigiForge\Database\Tables::stores(),DigiForge\Database\Tables::product_programs(),DigiForge\Database\ProviderConnectionSchema::table(),$wpdb->options,$wpdb->users,$wpdb->usermeta];
  foreach($tables as $table){$rows=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);self::assertIsArray($rows);self::assertSame('',$wpdb->last_error);$this->catalogSnapshots[$table]=$rows;}
 }
 private function durableCatalogSession():void {
  global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));
 }
 protected function tearDown():void {
  global $wpdb;
  $wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  foreach($this->catalogSnapshots as $table=>$rows){self::assertNotFalse($wpdb->query('DELETE FROM '.$table));foreach($rows as $row)self::assertNotFalse($wpdb->insert($table,$row));}
  wp_cache_flush();$wpdb->query('SET autocommit=0');$wpdb->query('START TRANSACTION');parent::tearDown();
 }
}
