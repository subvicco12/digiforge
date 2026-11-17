<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Callable read-only renderer; no public route, form, retry button or mutation handler. */
final class OperatorPanel {
 public static function render(OperatorReadModel $model,array $intents):string {
  $s=$model->snapshot($intents);if(is_wp_error($s))return '<p>Read-only operation evidence unavailable. No execution is authorized.</p>';
  $escape=static fn(string $v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $html='<section><h2>Printify operation evidence</h2><p>Read only. Retry and provider execution are not authorized.</p><table><thead><tr><th>Operation</th><th>Shop</th><th>State</th><th>Diagnostics</th></tr></thead><tbody>';
  foreach($s['items']as $r)$html.='<tr><td>'.$escape($r['operation']).' '.$escape($r['operation_id']).'</td><td>'.$escape($r['shop_key']).'</td><td>'.$escape($r['state']).'</td><td>'.$escape(implode(', ',$r['reasons'])).'</td></tr>';
  return $html.'</tbody></table></section>';
 }
}
