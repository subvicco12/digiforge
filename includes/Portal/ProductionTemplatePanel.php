<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Database\Tables;
use DigiForge\POD\ProductionTemplateContract;
use DigiForge\POD\TransformationEngineContract;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
/** Local immutable candidates and configured engine capabilities; never certification or execution. */
final class ProductionTemplatePanel
{
 public function snapshot(string $shop,int $limit=50):array {
  $out=['query_state'=>'UNAVAILABLE','items'=>[],'external_execution_authorized'=>false,'supplier_certified'=>false];
  if(!is_user_logged_in()||!current_user_can('manage_digiforge_pod')||!ShopAccessPolicy::validShop($shop)||is_wp_error(ShopAccess::authorize($shop,'manage_digiforge_pod')))return $out;
  global $wpdb;$limit=max(1,min(100,$limit));$usage=Tables::shop_ai_usage();$wpdb->last_error='';
  $rows=$wpdb->get_results($wpdb->prepare('SELECT t.* FROM '.Tables::pod_production_templates().' t WHERE EXISTS (SELECT 1 FROM '.$usage." u WHERE BINARY u.model_key=BINARY t.fingerprint AND u.workflow='production_template' AND u.stage='template_prepare' AND BINARY u.shop_key=BINARY %s) AND NOT EXISTS (SELECT 1 FROM ".$usage." x WHERE BINARY x.model_key=BINARY t.fingerprint AND x.workflow='production_template' AND x.stage='template_prepare' AND BINARY x.shop_key<>BINARY %s) ORDER BY t.id DESC LIMIT %d",$shop,$shop,$limit),ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error!=='')return $out;
  $items=[];foreach($rows as $row){try{$row['variant_ids']=json_decode((string)$row['variant_ids'],true,64,JSON_THROW_ON_ERROR);$row['print_areas']=json_decode((string)$row['print_areas'],true,64,JSON_THROW_ON_ERROR);$canonical=ProductionTemplateContract::normalize($row);if(!hash_equals((string)$row['fingerprint'],$canonical['fingerprint']))return $out;}catch(\Throwable){return $out;}$items[]=$canonical;}
  $out['items']=$items;$out['query_state']='AVAILABLE';return $out;
 }
 public static function render(string $shop):void {
  $data=(new self())->snapshot($shop);echo '<section class="df-panel"><h2>Production templates and engine capabilities</h2><p>Shop-owned immutable candidates. Local status and configured capabilities do not certify supplier geometry, artistic quality, licensed fixtures or catalog activation. External execution authority: NO.</p>';
  if($data['query_state']!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Production template evidence unavailable. No verified empty queue or certification is inferred.</div></section>';return;}
  echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Production template evidence"><table class="df-table"><thead><tr><th>Template / version</th><th>Local state</th><th>Supplier / blueprint / provider</th><th>Engine</th><th>SHA-256</th></tr></thead><tbody>';
  foreach($data['items'] as $row)echo '<tr><td>'.esc_html($row['template_id'].' @ '.$row['template_version']).'</td><td>'.esc_html($row['template_status']).'</td><td>'.esc_html($row['supplier'].' / '.$row['provider_blueprint_id'].' / '.$row['provider_id']).'</td><td>'.esc_html($row['personalization_engine']).'</td><td>'.esc_html($row['fingerprint']).'</td></tr>';
  echo '</tbody></table></div><p>Draft candidates are not supplier-certified production artwork.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Configured transformation engine capabilities"><table class="df-table"><thead><tr><th>Engine</th><th>Contract</th><th>Configured adapter</th><th>Certification</th><th>Catalog blocker</th></tr></thead><tbody>';
  foreach(TransformationEngineContract::capabilities() as $engine=>$cap)echo '<tr><td>'.esc_html($engine).'</td><td>'.esc_html($cap['contract_version']).'</td><td>'.esc_html($cap['adapter']).'</td><td>'.esc_html($cap['certification']).'</td><td>'.esc_html($cap['catalog_blocker']).'</td></tr>';
  echo '</tbody></table></div><p>VINTAGE_PORTRAIT catalog activation remains disabled.</p></section>';
 }
}
