<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
final class WorkflowPanel {
 public static function render(WorkflowCoordinator $w,ShopBinding $s,int $actor):string {$r=$w->snapshot($s,$actor);if(is_wp_error($r))return '<p>Workflow evidence unavailable. No execution authorized.</p>';$h='<section><h2>Workflow reconciliation</h2><p>Manual review required. No provider execution or retry.</p><ul>';foreach($r['items']as $row)$h.='<li>'.htmlspecialchars($row['kind'].' '.$row['disposition'].' '.$row['reason'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>';return $h.'</ul></section>';}
}
