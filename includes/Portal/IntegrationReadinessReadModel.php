<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Database\Tables;
/** Read-only integration readiness projection. It never performs connectivity tests or exposes credentials. */
final class IntegrationReadinessReadModel {
 public function snapshot():array {
  global $wpdb;$rows=$wpdb->get_results('SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at FROM '.Tables::integrations().' ORDER BY id ASC',ARRAY_A);$rows=is_array($rows)?$rows:[];
  $counts=['total'=>count($rows),'enabled'=>0,'connected'=>0,'attention'=>0];
  foreach($rows as &$r){$enabled=!empty($r['enabled']);$connected=strtoupper((string)($r['status']??''))==='CONNECTED';if($enabled)$counts['enabled']++;if($connected)$counts['connected']++;if($enabled&&!$connected)$counts['attention']++;$r['read_only']=true;$r['connectivity_test_performed']=false;$r['credentials_exposed']=false;$r['external_execution_authorized']=false;}unset($r);
  return ['counts'=>$counts,'items'=>$rows,'read_only'=>true,'connectivity_test_performed'=>false,'credentials_exposed'=>false,'external_execution_authorized'=>false];
 }
}