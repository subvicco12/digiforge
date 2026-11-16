<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Orders\PaidIntakeEvidence;

/** Stored origin ledger inspection; no retry, approval or execution action. */
final class OrderIntakeEvidencePanel
{
    public function render(string $shop,int $orderId):void
    {
        $service=new PaidIntakeEvidence();$view=$service->inspect($shop,$orderId);
        if(is_wp_error($view)){self::unavailable();return;}
        $copy=[
            'COMPLETE'=>'The stored intake ledgers agree on completion. Separate buyer review and production authorization remain required.',
            'FAILED'=>'The stored intake ledgers report failure. Keep the order held and investigate its persisted evidence.',
            'UNCERTAIN'=>'Intake completion is uncertain. Keep the order held; investigate the event and persistence records before any separately authorized recovery.',
        ];
        $state=$view['intake_state']??null;$actor=$view['actor_id']??null;$hash=$view['snapshot_hash']??null;
        if(!is_string($state)||!isset($copy[$state])||!is_int($actor)||$actor<1||!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||($view['correlation_basis']??null)!=='STORED_ORDER_KEY'||($view['payload_order_binding']??null)!=='NOT_RECORDED'){self::unavailable();return;}
        $order=$view['order'];
        // Prepare all escaped output before final admission: escaping filters may
        // revoke the actor or change the stored source while HTML is prepared.
        $html='<section id="df-order-intake" class="df-panel"><h3>Stored intake records</h3><p><strong>'.esc_html($state).'</strong> · '.esc_html($copy[$state]).'</p>';
        $html.='<p>Order #'.esc_html((string)$order['id']).' · '.esc_html($order['external_order_reference']).' · stored order state '.esc_html($order['state']).'</p>';
        $html.='<dl><dt>Origin event recorded on the order</dt><dd>'.esc_html($view['event_id']).'</dd><dt>Event ledger</dt><dd>'.esc_html($view['receipt_status']).'</dd><dt>Deduplication ledger</dt><dd>'.esc_html($view['dedup_status']??'MISSING').'</dd><dt>Recorded body fingerprint</dt><dd><code>'.esc_html($view['body_sha256']).'</code></dd></dl>';
        if($state==='COMPLETE')$html.='<p>Matching completion fingerprint <code>'.esc_html($view['result_hash']).'</code></p>';
        $html.='<p>This view correlates the origin event using the stored order key. Matching the signed payload to this order and verifying payment are not established by this view. No retry, approval or production action is authorized here.</p></section>';
        $confirmed=$service->inspect($shop,$orderId);
        if(is_wp_error($confirmed)||get_current_user_id()!==$actor||($confirmed['actor_id']??null)!==$actor||!is_string($confirmed['snapshot_hash']??null)||!hash_equals($hash,$confirmed['snapshot_hash'])){self::unavailable();return;}
        echo $html;
    }
    private static function unavailable():void
    {
        echo '<section id="df-order-intake" class="df-panel"><h3>Stored intake records</h3><p role="alert">Intake evidence unavailable. Current order scope and retained event records must agree; no empty history, completed intake or retry authority is inferred.</p></section>';
    }
}
