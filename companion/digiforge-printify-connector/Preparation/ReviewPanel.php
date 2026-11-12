<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
final class ReviewPanel {
 public static function render(ReviewCoordinator $w,ShopBinding $s,int $actor):string{$q=$w->queue($s,$actor);if(is_wp_error($q))return '<p>Core review evidence unavailable; no execution authorized.</p>';$h='<section><h2>Operator review — local only</h2><p>Manual review cannot enable dispatch or resolve uncertainty without evidence.</p><ul>';foreach($q['items']as $item){$stale=$item['recovery_epoch']!==$q['checkpoint_epoch']?' SNAPSHOT_STALE':'';$h.='<li>'.htmlspecialchars($item['kind'].' '.$item['operation_state'].' '.$item['review_state'].$stale,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>';}return $h.'</ul></section>';}
}
