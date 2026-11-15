<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Listings\EtsyOrderEventRepository;
use DigiForge\Security\ShopAccessPolicy;

/** Read-only signed-receipt inspection. No carrier lookup, retry or fulfillment mutation. */
final class OrderTrackingInspectionPanel
{
    public function render(string $shop):void
    {
        echo '<section id="df-order-tracking" class="df-panel"><h2>Order-specific shipment receipts</h2>';
        if(!ShopAccessPolicy::validShop($shop)){echo '<p>Select one concrete shop to inspect an exact stored order.</p></section>';return;}
        $raw=$_GET['df_tracking_order']??null;
        $valid=is_string($raw)&&preg_match('/^[1-9][0-9]{0,18}$/D',$raw)&&filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false;
        echo '<form method="get" action="'.esc_url(OperatorReturnUrl::current()).'"><input type="hidden" name="df_view" value="fulfillment"><input type="hidden" name="df_shop" value="'.esc_attr($shop).'"><label>Stored order ID <input type="number" min="1" step="1" name="df_tracking_order" value="'.esc_attr($valid?$raw:'').'" required></label> <button class="df-button" type="submit">Inspect signed receipts</button></form>';
        echo '<p>Signed Etsy event metadata, not live carrier tracking or authoritative fulfillment state. This inspection never submits an order or retries a webhook.</p>';
        if($raw===null){echo '<p>Look up an older order directly; the recent shop-wide receipt window does not limit this search.</p></section>';return;}
        if(!$valid){echo '<p role="alert">An exact positive stored order ID is required.</p></section>';return;}
        $projection=(new EtsyOrderEventRepository())->forOrder($shop,(int)$raw,50);
        if($projection['query_state']!=='AVAILABLE'){
            echo '<div role="alert" class="df-notice df-notice-error">Order receipt evidence unavailable. Current order scope, signed receipt provenance and durable intake completion must all match. No successful shipment or empty receipt history is inferred. Inspect the existing intake evidence before any separate manual reconciliation; retry remains prohibited.</div></section>';return;
        }
        $order=$projection['order'];
        echo '<p>Order #'.esc_html((string)$order['id']).' · '.esc_html($order['external_order_reference']).' · stored local order state '.esc_html($order['state']).'. Latest 50 signed event receipts for this exact current order.</p>';
        if($projection['items']===[])echo '<p>No signed receipts in this order window. This does not establish that an order is unshipped or undelivered.</p>';
        else {
            echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Exact order shipment receipts"><table class="df-table"><thead><tr><th>Event / receipt</th><th>Received</th><th>Carrier / fingerprint</th><th>Durable completion</th><th>Provenance</th></tr></thead><tbody>';
            foreach($projection['items'] as $row){
                echo '<tr><td>'.esc_html($row['event_type']).'<br>'.esc_html($row['event_id']).'</td><td>'.esc_html($row['received_at']).'</td><td>'.esc_html($row['carrier']).'<br><code>'.esc_html($row['tracking_present']?$row['tracking_fingerprint']:'No tracking fingerprint supplied').'</code></td><td>Matching intake ledgers: '.esc_html($row['completion_state']).'</td><td>Signed body <code>'.esc_html($row['body_sha256']).'</code><br>Normalized evidence <code>'.esc_html($row['evidence_hash']).'</code><br>Matched completion result <code>'.esc_html($row['result_hash']).'</code></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p>Read only. External execution authority: NO. Retry authority: NO. Carrier delivery and native fulfillment remain unverified by these receipts.</p></section>';
    }
}
