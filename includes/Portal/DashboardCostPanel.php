<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\AI\CostKpiReadModel;
use DigiForge\Security\ShopAccess;
/** Selected-shop existing accounting evidence only; no new finance calculations. */
final class DashboardCostPanel {
 public static function snapshot(string $shop):array {
  $result=['query_state'=>'UNAVAILABLE','totals'=>null,'commercial'=>null,'read_only'=>true,'external_execution_authorized'=>false];
  if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true)||!is_user_logged_in()||!current_user_can('manage_digiforge')||!current_user_can('manage_digiforge_ai'))return $result;
  foreach(['manage_digiforge','manage_digiforge_ai'] as $cap)if(is_wp_error(ShopAccess::authorize($shop,$cap)))return $result;
  $data=(new CostKpiReadModel())->snapshot($shop);
  if(($data['query_state']??'UNAVAILABLE')!=='AVAILABLE'||!is_string($data['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$data['currency'])||(int)($data['quantity']??0)<1)return $result;
  foreach(['estimated_cost','actual_cost']as $key)if(!is_numeric($data[$key]??null)||!is_finite((float)$data[$key])||(float)$data[$key]<0)return $result;
  $result['query_state']='AVAILABLE';$result['totals']=['shop'=>$shop,'currency'=>$data['currency'],'quantity'=>$data['quantity'],'estimated_cost'=>$data['estimated_cost'],'actual_cost'=>$data['actual_cost']];$result['commercial']=(array)$data['commercial_kpis'];return $result;
 }
 public static function render(string $shop):void {
  $data=self::snapshot($shop);echo '<section class="df-panel"><h2>Selected-shop AI spend evidence</h2><p>Existing stored usage totals across all retained periods, separate from the reviewed commercial cohort and its displayed UTC period. Estimated reservations and recorded amounts are not provider invoices or reconciled financial profit. External execution authority: NO.</p>';
  if($data['query_state']!=='AVAILABLE'){echo '<p role="status" class="df-notice">Selected-shop spend evidence unavailable. Select one assigned shop with AI visibility and existing single-currency usage; missing evidence is not verified zero spend.</p></section>';return;}
  $totals=$data['totals'];echo '<dl><dt>Shop</dt><dd>'.esc_html($totals['shop']).'</dd><dt>Currency</dt><dd>'.esc_html($totals['currency']).'</dd><dt>Recorded usage quantity</dt><dd>'.esc_html((string)$totals['quantity']).'</dd><dt>Estimated stored spend</dt><dd>'.esc_html(number_format((float)$totals['estimated_cost'],6,'.','')).'</dd><dt>Recorded actual stored spend</dt><dd>'.esc_html(number_format((float)$totals['actual_cost'],6,'.','')).'</dd></dl><p>Recorded zero can exclude pending or missing provider charges. Reviewed costs require their separate retained evidence below.</p></section>';
  CommercialKpiView::render($data['commercial']);
 }
}
