<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Security\ShopAccessPolicy;

/** Scoped GET inspection; buttons navigate to local evidence, never dispatch. */
final class OrderHeldPreparationPanel
{
    public function render(string $shop): void
    {
        if(!isset($_GET['df_order_id']))return;
        echo '<section class="df-panel"><h2>Order-specific held preparation</h2>';
        if(!ShopAccessPolicy::validShop($shop)){echo '<p>Select one concrete shop to inspect its order evidence.</p></section>';return;}
        $raw=$_GET['df_order_id'];
        if(!is_string($raw)||!preg_match('/^[1-9][0-9]{0,9}$/D',$raw)){echo '<p role="alert">An exact stored order ID is required.</p></section>';return;}
        (new OrderHoldWorkflow())->render($shop,(int)$raw);
        (new OrderIntakeEvidencePanel())->render($shop,(int)$raw);
        $service=new HeldFulfillmentPreparation();$source=$service->forOrder($shop,(int)$raw);
        if(is_wp_error($source)){echo '<div role="alert" class="df-notice df-notice-error">'.esc_html($source->get_error_message()).'</div>';
            $intent=$_GET['df_intent_id']??null;if(is_string($intent)&&preg_match('/^[1-9][0-9]{0,9}$/D',$intent)===1&&(int)$intent<=2147483647)(new OrderHeldJobWorkflow())->render($shop,(int)$raw,(int)$intent);
            echo '</section>';return;}
        $order=$source['order'];
        echo '<p>Order #'.esc_html((string)$order['id']).' · '.esc_html((string)$order['external_order_reference']).' · '.esc_html((string)$order['environment'].' / '.(string)$order['state']).'</p>';
        echo '<p>Current readiness: '.(!empty($source['readiness']['ready'])?'READY FOR LOCAL REVIEW':'BLOCKED').' · Reconciliation: '.(!empty($source['reconciliation']['reconciled'])?'MATCHED LOCAL LINES':'REVIEW REQUIRED').'. Production release remains held.</p>';
        if($order['environment']==='production')echo '<p><a class="df-button df-button-compact" href="'.esc_url(add_query_arg(['df_view'=>'fulfillment','df_shop'=>$shop,'df_tracking_order'=>(int)$order['id']],OperatorReturnUrl::current()).'#df-order-tracking').'">Inspect this order’s shipment receipts</a></p>';
        echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Order line identities"><table class="df-table"><thead><tr><th>Line</th><th>Listing / version</th><th>Mapping / provider</th><th>Quantity</th><th>Evidence state</th></tr></thead><tbody>';
        foreach($source['lines'] as $line)echo '<tr><td>#'.esc_html((string)$line['id']).'</td><td>#'.esc_html((string)$line['listing_id']).' / #'.esc_html((string)$line['product_version_id']).'</td><td>#'.esc_html((string)$line['provider_mapping_id']).' / '.esc_html((string)($line['provider']??'UNAVAILABLE')).'</td><td>'.esc_html((string)$line['quantity']).'</td><td>'.esc_html((string)$line['validation_status'].' / '.(string)($line['mapping_state']??'MISSING')).'</td></tr>';
        echo '</tbody></table></div><h3>Stored personalization reviews</h3>';
        if($source['personalization']===[])echo '<p>No stored personalization submissions for these lines.</p>';
        foreach($source['personalization'] as $row)echo '<p>Submission #'.esc_html((string)$row['id']).' · line #'.esc_html((string)$row['order_line_item_id']).' · '.esc_html((string)$row['review_status']).' · <code>'.esc_html((string)$row['payload_hash']).'</code></p>';
        echo '<h3>Stored buyer render reviews</h3>';
        if($source['renders']===[])echo '<p>Buyer-specific render evidence is missing. Artifact preparation and separate visual approval are still required.</p>';
        foreach($source['renders'] as $row)echo '<p>Render #'.esc_html((string)$row['id']).' · mapping #'.esc_html((string)$row['provider_mapping_id']).' · '.esc_html((string)$row['template_key'].' / '.(string)$row['template_version'].' / '.(string)$row['review_status']).'. Actual artifacts require their existing certification check before production review.</p>';
        foreach($source['renders'] as $row)echo '<p><a class="df-button df-button-compact" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>$shop,'df_order_id'=>(int)$order['id'],'df_render_id'=>(int)$row['id']],OperatorReturnUrl::current())).'">Inspect stored render #'.esc_html((string)$row['id']).'</a></p>';
        if(isset($_GET['df_render_id'])){
            $renderId=$_GET['df_render_id'];$packageId=$_GET['df_package_id']??null;
            if(!is_string($renderId)||preg_match('/^[1-9][0-9]{0,9}$/D',$renderId)!==1||(int)$renderId>2147483647||($packageId!==null&&(!is_string($packageId)||preg_match('/^[1-9][0-9]{0,9}$/D',$packageId)!==1||(int)$packageId>2147483647)))echo '<p role="alert">Exact stored render and optional package IDs are required for inspection.</p>';
            else (new OrderArtifactInspection())->render($shop,(int)$order['id'],(int)$renderId,$packageId===null?null:(int)$packageId);
        }
        (new OrderLocalWorkflow())->renderForms($shop,$source);
        echo '<h3>Stored fulfillment plans and intents</h3>';
        foreach($source['plans'] as $row)echo '<p>Plan #'.esc_html((string)$row['id']).' · '.esc_html((string)$row['provider'].' / '.(string)$row['state']).'</p>';
        if($source['intents']===[])echo '<p>No stored preparation intent exists. Create and review the local fulfillment plan through the existing order workflow before preparing an intent.</p>';
        foreach($source['intents'] as $row){
            echo '<p>Intent #'.esc_html((string)$row['id']).' · plan #'.esc_html((string)$row['fulfillment_plan_id']).' · '.esc_html((string)$row['intent_type'].' / '.(string)$row['state']);
            if($row['state']==='BLOCKED'&&in_array($row['intent_type'],['PREPARE_ORDER','PREPARE_FULFILLMENT'],true))echo ' <a class="df-button df-button-compact" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>$shop,'df_order_id'=>(int)$order['id'],'df_intent_id'=>(int)$row['id']],OperatorReturnUrl::current())).'">Inspect held preparation</a>';
            echo '</p>';
        }
        if(isset($_GET['df_intent_id'])){
            $id=$_GET['df_intent_id'];$prepared=is_string($id)&&preg_match('/^[1-9][0-9]{0,9}$/D',$id)?$service->prepare($shop,(int)$order['id'],(int)$id):new \WP_Error('held_intent_id','An exact stored intent ID is required.');
            if(is_wp_error($prepared))echo '<div role="alert" class="df-notice df-notice-error">Held preparation blocked: '.esc_html($prepared->get_error_message()).'</div>';
            else echo '<div class="df-notice"><strong>'.esc_html($prepared['state']).'</strong><p>Stored plan #'.esc_html((string)$prepared['fulfillment_plan_id']).' and current mapped lines prepared for local review.</p><p>Evidence <code>'.esc_html($prepared['current_evidence_hash']).'</code></p></div>';
            if(is_string($id)&&preg_match('/^[1-9][0-9]{0,9}$/D',$id)===1&&(int)$id<=2147483647)(new OrderHeldJobWorkflow())->render($shop,(int)$order['id'],(int)$id);
        }
        echo '<p>Release hold: YES. Adapter invoked: NO. External execution authority: NO. Buyer artifact certification, visual approval and the existing separate production authorization remain required.</p></section>';
    }
}
