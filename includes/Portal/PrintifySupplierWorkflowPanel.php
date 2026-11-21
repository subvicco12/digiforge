<?php
declare(strict_types=1);
namespace DigiForge\Portal;
/** Renders local supplier evidence alongside the existing approved factory workflows. */
final class PrintifySupplierWorkflowPanel {
 public function render(array $packet,string $snapshot,string $baseUrl):void {
  if(!ProductIntelligencePanel::canRead())return;
  echo '<details><summary>Supplier-to-product workflow readiness</summary><p>Select exact catalog identities to review retained evidence. This does not create a product, certify a template, or generate personalization or mockups.</p><form method="get" action="'.esc_url($baseUrl).'"><input type="hidden" name="df_view" value="pod_personalized"><input type="hidden" name="df_shop" value="personalized_pod"><input type="hidden" name="df_pi_snapshot" value="'.esc_attr($snapshot).'">';
  foreach(['blueprint','provider','variant'] as $key)echo '<label>'.esc_html(ucfirst($key)).' ID <input type="number" name="df_pi_'.$key.'" min="1" max="2147483647" required></label>';
  echo '<button class="df-button" type="submit">Review supplier evidence</button></form>';
  if(isset($_GET['df_pi_variant'])){
   try{$ids=[];foreach(['blueprint','provider','variant'] as $key){$v=$_GET['df_pi_'.$key]??null;if(!is_string($v)||!preg_match('/^[1-9][0-9]{0,9}$/D',$v))throw new \InvalidArgumentException('Exact supplier identifiers required.');$ids[]=(int)$v;}$review=\DigiForge\POD\PrintifySupplierWorkflow::review($packet,...$ids);
    echo '<p>REVIEW REQUIRED · No execution authority</p><ul>';foreach($review['gaps'] as $gap)echo '<li>'.esc_html(str_replace('_',' ',$gap)).'</li>';echo '</ul><details><summary>Source-bound selection evidence</summary><pre>'.esc_html((string)wp_json_encode($review,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details><p>Use the existing approved production-template, native personalization and product-package reviews below. Supplier observations do not approve those steps.</p>';
   }catch(\InvalidArgumentException $e){echo '<p>'.esc_html($e->getMessage()).'</p>';}
  }echo '</details>';
 }
}
