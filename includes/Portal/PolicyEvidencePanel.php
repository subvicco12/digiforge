<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Core\Config;
use DigiForge\Database\Tables;
use DigiForge\Security\ShopAccess;
/** Bounded non-secret observation; never a policy-writing or activation surface. */
final class PolicyEvidencePanel {
 private const STAGES=['research','ai','product_development','etsy_draft','printify','gelato','etsy_publish','order_automation'];
 public static function snapshot(string $shop):array {
  $result=['query_state'=>'UNAVAILABLE','controls'=>[],'stages'=>[],'read_only'=>true,'external_execution_authorized'=>false];$shops=['digital','personalized_pod','standard_pod','jewelry'];
  if(!is_user_logged_in()||!current_user_can('manage_digiforge'))return $result;
  foreach($shop==='all'?$shops:[$shop] as $required)if(!in_array($required,$shops,true)||is_wp_error(ShopAccess::authorize($required,'manage_digiforge')))return $result;
  $keys=['stop_all','activation_authorized','automation_armed'];foreach(self::STAGES as $cap){$keys[]=$cap;$keys[]=$cap.'_activation_authorized';}
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT setting_key,setting_value,setting_type FROM '.Tables::settings().' WHERE setting_key IN ('.implode(',',array_fill(0,count($keys),'%s')).')',...$keys),ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error!=='')return $result;$values=[];
  foreach($rows as $row){$key=(string)$row['setting_key'];$value=json_decode((string)$row['setting_value'],true);if(!in_array($key,$keys,true)||array_key_exists($key,$values)||$row['setting_type']!==Config::setting_type($key)||!Config::valid_value($key,$value))return $result;$values[$key]=$value;}
  if(count($values)!==count($keys))return $result;
  $global=$values['activation_authorized']&&$values['automation_armed']&&!$values['stop_all'];$result['controls']=['stop_all'=>$values['stop_all'],'activation_authorized'=>$values['activation_authorized'],'automation_armed'=>$values['automation_armed'],'externally_locked'=>!$global];
  foreach(self::STAGES as $cap)$result['stages'][]=['capability'=>$cap,'configured'=>$values[$cap],'approved_activation'=>$values[$cap.'_activation_authorized'],'effective_enabled'=>$global&&$values[$cap]&&$values[$cap.'_activation_authorized']];
  $result['query_state']='AVAILABLE';return $result;
 }
 public static function render(string $shop):void {
  $data=self::snapshot($shop);echo '<section class="df-panel"><h2>Non-finance activation ladder and global safety ceilings</h2><p>Read-only current configuration evidence. This panel cannot authorize execution or activate any stage. Missing evidence is not a disabled-state certification.</p>';
  if($data['query_state']!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Activation policy evidence unavailable.</div></section>';return;}
  echo '<dl>';foreach($data['controls'] as $key=>$value)echo '<dt>'.esc_html($key).'</dt><dd>'.($value?'ON':'OFF').'</dd>';
  echo '</dl><p>STOP ALL and global activation/automation restrictions dominate every stage. Shop/workflow policies cannot override these ceilings. Effective ON is configuration only; provider, rights, budget and operation-specific approvals still apply.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Non-finance activation ladder"><table class="df-table"><thead><tr><th>Stage</th><th>Configured</th><th>Approved activation</th><th>Effective</th></tr></thead><tbody>';
  foreach($data['stages'] as $stage)echo '<tr><td>'.esc_html($stage['capability']).'</td><td>'.($stage['configured']?'ON':'OFF').'</td><td>'.($stage['approved_activation']?'ON':'OFF').'</td><td>'.($stage['effective_enabled']?'ON':'OFF').'</td></tr>';
  echo '</tbody></table></div><p>External execution authority: NO</p></section>';
 }
}
