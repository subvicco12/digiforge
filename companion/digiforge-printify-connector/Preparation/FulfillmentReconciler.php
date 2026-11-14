<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
final class FulfillmentReconciler {
 public static function compare(ShipmentReadModel $before,ShipmentReadModel $after):array{$a=$before->jsonSerialize();$b=$after->jsonSerialize();if($a['scope_fingerprint']!==$b['scope_fingerprint']||$a['order_identity']!==$b['order_identity'])throw new \InvalidArgumentException('Same exact scoped order required.');$d=$b['observed_at']<$a['observed_at']?'OUT_OF_ORDER':($a['order_version']!==$b['order_version']||$a['product_versions']!==$b['product_versions']?'STALE_VERSION':($a['content_hash']===$b['content_hash']?'DUPLICATE':($a['observed_at']===$b['observed_at']?'CONFLICT':'REVIEW_REQUIRED')));return ['disposition'=>$d,'order_identity'=>$b['order_identity'],'observation_hash'=>$b['content_hash'],'manual_review_required'=>true,'apply_state'=>false,'retry_permitted'=>false,'external_execution_authorized'=>false];}
}
