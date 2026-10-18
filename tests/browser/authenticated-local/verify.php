<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_file($dir.'/disposable.marker'))throw new RuntimeException('Disposable fixture required.');
require $dir.'/core/wp-load.php';
$before=json_decode(file_get_contents($dir.'/before.json'),true,32,JSON_THROW_ON_ERROR);global $wpdb;
foreach($before as $table=>$sha){if(!str_starts_with($table,$wpdb->prefix.'digiforge_')||!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Invalid snapshot scope.');$wpdb->last_error='';$rows=$wpdb->get_results('SELECT * FROM `'.$table.'` ORDER BY 1',ARRAY_A);if($wpdb->last_error!==''||hash('sha256',wp_json_encode($rows))!==$sha)throw new RuntimeException('Business evidence changed during browser journey.');}
if(!DigiForge\Core\Settings::safety_locked()||DigiForge\Core\Settings::get('stop_all')!==true||DigiForge\Core\Settings::get('automation_armed')!==false)throw new RuntimeException('Safety posture changed.');
if(is_file($dir.'/violations.jsonl')&&filesize($dir.'/violations.jsonl')>0)throw new RuntimeException('Forbidden request or mutation attempted.');
echo json_encode(['business_tables_unchanged'=>count($before),'stop_all'=>true,'external_locked'=>true,'automation_armed'=>false,'external_requests'=>0,'business_row_mutations'=>0,'fixture_bootstrap_ddl_statements'=>is_file($dir.'/bootstrap-ddl.jsonl')?count(file($dir.'/bootstrap-ddl.jsonl')):0])."\n";
