<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;
use DigiForgePrintifyApi\SyntheticWorkspace;
use DigiForgePrintifyApi\SyntheticWebhookReceiver;

if (!defined('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY')) {
    define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
}
define('DIGIFORGE_PRINTIFY_SYNTHETIC_SHOPS',[108=>'fixture-other-account']);
if ($phase === 'write') {
    $db->exec('CREATE TABLE df_printify_fixture_workspace (
        scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        revision int unsigned NOT NULL,
        state_json mediumblob NOT NULL,
        seal char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
    ) ENGINE=InnoDB');
}
$workspace = Runtime::syntheticWorkspace();
requireFixture($workspace instanceof SyntheticWorkspace, 'Scoped fixture workspace unavailable');
$http = 0;
$tripwire = static function () use (&$http): never {
    ++$http;
    throw new RuntimeException('Fixture HTTP forbidden');
};
add_filter('pre_http_request',$tripwire,PHP_INT_MAX);
try {
    if ($phase === 'write') {
        $post = static function (string $operation, array $input, int $revision, string $id, ?string $nonce = null, bool $fields = false): string {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'df_workspace_shop'=>'107','df_workspace_operation'=>$operation,'df_workspace_input'=>wp_slash(json_encode((object)$input,JSON_THROW_ON_ERROR)),
                'df_workspace_revision'=>(string)$revision,'df_workspace_id'=>$id,
                'df_workspace_nonce'=>$nonce??wp_create_nonce('df_printify_synthetic_workspace_v1:107'),
            ];
            if ($fields) {
                unset($_POST['df_workspace_input']);
                $_POST['df_workspace_fields_present'] = '1';
                $_POST['df_workspace_fields'] = wp_slash($input);
            }
            ob_start();
            Runtime::panel();
            $html = (string)ob_get_clean();
            $_POST = [];
            unset($_SERVER['REQUEST_METHOD']);
            return $html;
        };
        $op = static function (string $operation, array $input = [], string $marker = 'SYNTHETIC_OBSERVED', bool $fields = false) use ($post,$workspace): void {
            $revision = $workspace->inspect()['revision'];
            $html = $post($operation,$input,$revision,hash('sha256',$operation.':'.$revision),null,$fields);
            requireFixture(str_contains($html,$marker) && $workspace->inspect()['revision']===$revision+1,
                'Operator operation did not produce expected result: '.$operation.' '.$marker);
        };
        requireFixture(str_contains($post('SHOPS_READ',[],0,str_repeat('a',64),'0000000000'),'INVALID_FORM_OR_SESSION'),
            'Workspace admitted invalid nonce');
        $op('SHOPS_READ');
        $op('VARIANTS_READ',['blueprint_id'=>'42','provider_id'=>'7'],'SYNTHETIC_OBSERVED',true);
        $chunk = static fn(string $t,string $d):string=>pack('N',strlen($d)).$t.$d.hash('crc32b',$t.$d,true);
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0))
            .$chunk('IDAT',gzcompress("\0\0\xff\0")).$chunk('IEND','');
        $op('IMAGE_UPLOAD',['file_name'=>'operator.png','contents'=>base64_encode($png)],'SYNTHETIC_OBSERVED',true);
        $op('PRODUCT_CREATE',[
            'title'=>'Operator fixture mug','description'=>'Local scenario','blueprint_id'=>'42','print_provider_id'=>'7',
            'variant_id'=>'9','price'=>'1234','image_id'=>'image-1','position'=>'front',
            'variants'=>[['variant_id'=>'10','price'=>'1500']],
            'placements'=>[
                ['variant_id'=>'9','image_id'=>'image-1','position'=>'back','x'=>'0.45','y'=>'0.5','scale'=>'0.8','angle'=>'10'],
                ['variant_id'=>'10','image_id'=>'image-1','position'=>'front','x'=>'0.5','y'=>'0.5','scale'=>'0.9','angle'=>'0'],
            ],
        ],'SYNTHETIC_OBSERVED',true);
        $edit=[
            'product_id'=>'product-1','expected_version'=>'1','title'=>'Edited fixture mug','description'=>'',
            'variant_id'=>'9','price'=>'1300','variants'=>[['variant_id'=>'10','price'=>'1550']],
            'placements'=>[
                ['variant_id'=>'9','image_id'=>'image-1','position'=>'front','x'=>'0.5','y'=>'0.5','scale'=>'1','angle'=>'0'],
                ['variant_id'=>'9','image_id'=>'image-1','position'=>'back','x'=>'0.45','y'=>'0.5','scale'=>'0.8','angle'=>'15'],
                ['variant_id'=>'10','image_id'=>'image-1','position'=>'front','x'=>'0.5','y'=>'0.5','scale'=>'0.9','angle'=>'0'],
            ],
        ];
        $op('PRODUCT_UPDATE',$edit,'SYNTHETIC_OBSERVED',true);
        $invalidEdit=$edit;
        $invalidEdit['expected_version']='2';
        $invalidEdit['placements'][0]['x']='2';
        $revision=$workspace->inspect()['revision'];
        $html=$post('PRODUCT_UPDATE',$invalidEdit,$revision,hash('sha256','bad-placement'),null,true);
        requireFixture(str_contains($html,'INVALID_OPERATION_FIELD') && $workspace->inspect()['revision']===$revision,
            'Invalid geometry consumed a write revision');
        $op('PRODUCT_UPDATE',['product_id'=>'product-1','expected_version'=>1,'title'=>'Stale edit'],'CONFLICT');
        $op('PRODUCT_GPSR_READ',['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9,10]],'supplier_certified');
        $order = [
            'external_id'=>'operator-scenario-1','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>2]],
            'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,
            'address_to'=>['first_name'=>'Synthetic','last_name'=>'Only','email'=>'workspace@example.invalid','phone'=>'',
                'country'=>'US','region'=>'CA','address1'=>'Fixture street','address2'=>'','city'=>'Fixture','zip'=>'90001'],
        ];
        $formOrder=['external_id'=>'operator-scenario-1','product_id'=>'product-1','variant_id'=>'9','quantity'=>'2','shipping_method'=>'1',
            'line_items'=>[['product_id'=>'product-1','variant_id'=>'10','quantity'=>'1']]];
        $productRead=$workspace->execute($workspace->inspect()['revision'],hash('sha256','multi-placement-read'),
            'PRODUCT_READ',['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9,10]]);
        requireFixture($productRead['state']==='SYNTHETIC_OBSERVED' && count($productRead['data']['print_areas'])===2
            && count($productRead['data']['print_areas'][0]['placeholders'])===2
            && $productRead['data']['print_areas'][0]['placeholders'][1]['images'][0]['angle']===15,
            'Multi-placement product edit was not persisted');
        $productTwo=$edit;
        unset($productTwo['product_id'],$productTwo['expected_version']);
        $productTwo['blueprint_id']='42';
        $productTwo['print_provider_id']='7';
        $productTwo['title']='Second fixture product';
        $productTwo['variant_id']='10';
        $productTwo['description']='Synthetic alternate product';
        $productTwo['image_id']='image-1';
        $productTwo['position']='front';
        unset($productTwo['placements']);
        $productTwo['variants']=[];
        $op('PRODUCT_CREATE',$productTwo,'SYNTHETIC_OBSERVED',true);
        $art=['field_id'=>'fixture-artwork','type'=>'image','input'=>['image_url'=>'https://images.example.invalid/image-1.png']];
        $item = ['field_id'=>'fixture-inscription','type'=>'textbox','input'=>['text'=>'Fixture inscription']];
        $op('PERSONALIZATION_OPTIONS',['product_id'=>'product-1'],'fixture-inscription');
        $op('PERSONALIZATION_CONFIGURE',['product_id'=>'product-1','variant_id'=>9,'items'=>[$item],
            'require_all_personalization_fields'=>true],'fixture-config-');
        $op('PREVIEW_CREATE',['product_id'=>'product-1','external_id'=>'operator-preview','variant_ids'=>[9,10],
            'personalization'=>[$item,$art],'format'=>'png'],'task-product-1-1');
        $op('PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-1'],'pending');
        $previewEvent=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa1','type'=>'personalization-preview-task:processed',
            'created_at'=>'2026-11-19 10:05:00+00:00','resource'=>['id'=>'task-product-1-1',
                'type'=>'personalization-preview-task','data'=>['shop_id'=>107,'task_id'=>'task-product-1-1',
                    'status'=>'failed','preview_count'=>0,'mockups'=>[]]]];
        $op('FIXTURE_WEBHOOK_EVENT',$previewEvent);
        $op('FIXTURE_WEBHOOK_EVENT',$previewEvent,'DUPLICATE_EVENT');
        $op('PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-1'],'failed',true);
        $op('PREVIEW_CREATE',['product_id'=>'product-1','external_id'=>'render-preview','variant_ids'=>[9,10],
            'personalization'=>[$item,$art],'format'=>'png'],'task-product-1-2');
        $op('FIXTURE_PREVIEW_COMPLETE',['product_id'=>'product-1','task_id'=>'task-product-1-2']);
        $op('PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-2'],'synthetic_svg_sha256');


        $revision = $workspace->inspect()['revision'];
        $invalid = $post('ORDER_CREATE',array_replace($formOrder,['quantity'=>'two']),$revision,hash('sha256','invalid-quantity'),null,true);
        requireFixture(str_contains($invalid,'INVALID_OPERATION_FIELD') && str_contains($invalid,'quantity')
            && $workspace->inspect()['revision']===$revision,'Invalid quantity did not produce a field error');
        $op('ORDER_SHIPPING_QUOTE',$formOrder,'SYNTHETIC_OBSERVED',true);
        $op('ORDER_CREATE',$formOrder,'SYNTHETIC_OBSERVED',true);
        $event=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbf5','type'=>'order:created','created_at'=>'2026-11-19 10:00:00+00:00',
            'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107]],
        ];
        $op('FIXTURE_WEBHOOK_EVENT',$event);
        $op('FIXTURE_WEBHOOK_EVENT',$event,'DUPLICATE_EVENT');
        $conflicting=$event;
        $conflicting['created_at']='2026-11-19 10:01:00+00:00';
        $op('FIXTURE_WEBHOOK_EVENT',$conflicting,'EVENT_ID_CONFLICT');
        $event['resource']['data']['shop_id']=108;
        $op('FIXTURE_WEBHOOK_EVENT',$event,'EVENT_CONTRACT_OR_SCOPE');
        $op('FIXTURE_PRODUCTION_CONFIRM',['order_id'=>'order-1'],'SYNTHETIC_OBSERVED',true);
        $statusEvent=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbf6','type'=>'order:updated','created_at'=>'2026-11-19 10:01:00+00:00',
            'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107,'status'=>'in-production']],
        ];
        $op('FIXTURE_WEBHOOK_EVENT',$statusEvent);
        $productEvent=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbf7','type'=>'product:updated','created_at'=>'2026-11-19 10:02:00+00:00',
            'resource'=>['id'=>'product-1','type'=>'product','data'=>['shop_id'=>107]],
        ];
        $op('FIXTURE_WEBHOOK_EVENT',$productEvent);
        $productionEvent=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbf8','type'=>'order:sent-to-production','created_at'=>'2026-11-19 10:03:00+00:00',
            'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107]],
        ];
        $op('FIXTURE_WEBHOOK_EVENT',$productionEvent);
        $op('FIXTURE_FULFILL',['order_id'=>'order-1'],'SYNTHETIC_OBSERVED',true);
        $carrier=['code'=>'USPS','tracking_number'=>'SYNTHETIC-order-1','tracking_url'=>'https://tracking.example.invalid/order-1'];
        $shipment=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbf9','type'=>'order:shipment:created','created_at'=>'2026-11-19 10:04:00+00:00',
            'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107,'shipped_at'=>'2026-11-19 10:04:00+00:00',
                'carrier'=>$carrier,'skus'=>['fixture-sku-9','fixture-sku-10'],'line_item_ids'=>['line-1','line-2']]],
        ];
        $wrongShipment=$shipment;$wrongShipment['resource']['data']['line_item_ids']=['line-999'];
        $op('FIXTURE_WEBHOOK_EVENT',$wrongShipment,'EVENT_SHIPMENT_LINE_CONFLICT');
        $op('FIXTURE_WEBHOOK_EVENT',$shipment);
        $delivery=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbfa','type'=>'order:shipment:delivered','created_at'=>'2026-11-19 10:05:00+00:00',
            'resource'=>['id'=>'order-1','type'=>'order','data'=>['shop_id'=>107,'delivered_at'=>'2026-11-19 10:05:00+00:00',
                'carrier'=>$carrier,'skus'=>['fixture-sku-9','fixture-sku-10']]],
        ];
        $op('FIXTURE_WEBHOOK_EVENT',$delivery);
        $op('FIXTURE_WEBHOOK_EVENT',$delivery,'DUPLICATE_EVENT');
        $staleDelivery=$delivery;
        $staleDelivery['id']='653b6be8-2ff7-4ab5-a7a6-6889a8b3aaab';
        $staleDelivery['resource']['data']['delivered_at']='2026-11-19 10:04:00+00:00';
        $op('FIXTURE_WEBHOOK_EVENT',$staleDelivery,'EVENT_DELIVERY_CONFLICT');


        $op('ORDER_READ',['order_id'=>'order-1'],'tracking_hash',true);
        $support=['order_id'=>'order-1','reason'=>'incorrect_item','description'=>'Fixture only',
            'line_items'=>[['line_item_id'=>'line-1','quantity'=>1]]];
        $op('SUPPORT_REFUND',$support,'request_acknowledged');
        $op('SUPPORT_REFUND',$support,'CONFLICT');
        $op('SUPPORT_REPRINT',$support,'request_acknowledged');
        $op('SUPPORT_LIST',['order_id'=>'order-1'],'support-order-1-1');
        $op('SUPPORT_DETAIL',['order_id'=>'order-1','support_request_id'=>'support-order-1-1'],'unresolved');
        $op('ORDER_CREATE',array_replace($order,['external_id'=>'operator-scenario-cancel']));
        $op('SUPPORT_ADDRESS_CHANGE',['order_id'=>'order-2','first_name'=>'Fixture','last_name'=>'Buyer',
            'address1'=>'Disposable avenue','city'=>'Fixture','country'=>'US','zip'=>'90001'],'support_request_created');
        $addressChange=['order_id'=>'order-2','first_name'=>'Fixture','last_name'=>'Buyer',
            'address1'=>'Synthetic lane','city'=>'Fixture','country'=>'US','zip'=>'90001'];
        $op('ORDER_ADDRESS_CHANGE',$addressChange,'address_updated');
        $op('ORDER_ADDRESS_CHANGE',$addressChange,'CONFLICT');
        $op('LINE_ITEM_CANCEL',['order_id'=>'order-2','line_item_id'=>'line-1'],'cancellation_accepted');
        $lineEvent=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa2','type'=>'order:line-item:canceled',
            'created_at'=>'2026-11-19 10:05:00+00:00','resource'=>['id'=>'order-2','type'=>'order',
                'data'=>['shop_id'=>107,'line_item_id'=>'line-1','status'=>'canceled','status_details'=>null]]];
        $op('FIXTURE_WEBHOOK_EVENT',$lineEvent);
        $lineEvent['id']='653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa3';
        $lineEvent['resource']['data']['line_item_id']='line-999';
        $op('FIXTURE_WEBHOOK_EVENT',$lineEvent,'EVENT_CANCELLATION_CONFLICT');
        $op('ORDER_READ',['order_id'=>'order-2'],'canceled');
        $op('ORDER_CANCEL',['order_id'=>'order-2'],'SYNTHETIC_OBSERVED',true);
        $express=array_intersect_key($order,array_flip(['external_id','line_items','address_to']));
        $express['external_id']='operator-scenario-express';
        $express['line_items'][]=['product_id'=>'product-2','variant_id'=>10,'quantity'=>1];
        $quote=$workspace->execute($workspace->inspect()['revision'],hash('sha256','mixed-shipping'),
            'ORDER_SHIPPING_QUOTE',['order'=>$express+['shipping_method'=>3,'is_printify_express'=>true,
                'is_economy_shipping'=>false,'send_shipping_notification'=>false]]);
        requireFixture(($quote['data']['rates_minor']['printify_express']??null)===1400,'Mixed quote disagrees with groups');

        $op('ORDER_EXPRESS_CREATE',['external_id'=>'operator-scenario-express','product_id'=>'product-1',
            'variant_id'=>'9','quantity'=>'2','line_items'=>[['product_id'=>'product-2','variant_id'=>'10','quantity'=>'1']]],
            'ordinary',true);
        $op('ORDER_EXPRESS_CREATE',$express,'CONFLICT');
        $op('FIXTURE_PRODUCTION_CONFIRM',['order_id'=>'order-3']);
        $op('FIXTURE_FULFILL',['order_id'=>'order-3']);
        $op('ORDER_CANCEL',['order_id'=>'order-4']);
        $op('ORDER_READ',['order_id'=>'order-4'],'canceled');


        $op('WEBHOOK_CREATE',['topic'=>'order:created','url'=>'https://webhooks.example.invalid/printify'],
            'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOK_UPDATE',['webhook_id'=>'webhook-1','url'=>'https://webhooks.example.invalid/printify-updated'],
            'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOKS_READ',[],'webhook-1',true);
        $op('WEBHOOK_SIMULATE',['webhook_id'=>'webhook-1'],'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOK_SIMULATE',['webhook_id'=>'webhook-1'],'DUPLICATE_EVENT');
        $op('WEBHOOK_SIMULATE',['webhook_id'=>'webhook-98'],'NOT_FOUND');

        $op('WEBHOOK_DELETE',['webhook_id'=>'webhook-1'],'SYNTHETIC_OBSERVED',true);
        $op('IMAGE_ARCHIVE',['image_id'=>'image-1'],'CONFLICT');
        $op('PRODUCT_PUBLISH',['product_id'=>'product-1','expected_version'=>2,'publish_fields'=>['title'=>true]],'publication_pending');
        $publishEvent=[
            'id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3bbfb','type'=>'product:publish:started','created_at'=>'2026-11-19 10:06:00+00:00',
            'resource'=>['id'=>'product-1','type'=>'product','data'=>['shop_id'=>107,'action'=>'create','out_of_stock_publishing'=>0,
                'publish_details'=>['title'=>true,'description'=>false,'images'=>false,'variants'=>false,'tags'=>false,
                    'key_features'=>false,'shipping_template'=>false]]],
        ];
        $wrongPublish=$publishEvent;
        $wrongPublish['resource']['data']['publish_details']['images']=true;
        $op('FIXTURE_WEBHOOK_EVENT',$wrongPublish,'EVENT_PUBLICATION_CONFLICT');
        $op('FIXTURE_WEBHOOK_EVENT',$publishEvent);
        $deleteEvent=$publishEvent;
        $deleteEvent['id']='653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa4';
        $deleteEvent['resource']['data']=['action'=>'delete'];
        $op('FIXTURE_WEBHOOK_EVENT',$deleteEvent);
        $supportEvent=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa5','type'=>'support-request:updated',
            'created_at'=>'2026-11-19 10:07:00+00:00','resource'=>['id'=>'fixture-provider-support',
                'type'=>'support-request','data'=>['shop_id'=>107,'order_id'=>'order-1','type'=>'order_refund',
                    'status'=>'resolved','status_details'=>null,'outcome'=>'positive']]];
        $op('FIXTURE_WEBHOOK_EVENT',$supportEvent);
        $op('SUPPORT_DETAIL',['order_id'=>'order-1','support_request_id'=>'fixture-provider-support'],'positive');
        $supportEvent['id']='653b6be8-2ff7-4ab5-a7a6-6889a8b3aaa6';
        $supportEvent['resource']['data']['status']='pending';
        $supportEvent['resource']['data']['outcome']='unresolved';
        $op('FIXTURE_WEBHOOK_EVENT',$supportEvent,'EVENT_RESOURCE_UNAVAILABLE');

        $op('PRODUCT_PUBLISH_SUCCEEDED',['product_id'=>'product-1','expected_version'=>3,
            'external'=>['id'=>'fixture-listing','handle'=>'https://products.example.invalid/fixture-listing']],'PUBLISHED');
        $op('PRODUCT_UNPUBLISH_NOTIFY',['product_id'=>'product-1','expected_version'=>4],'UNPUBLISHED');
        $op('PRODUCT_PUBLISH',['product_id'=>'product-1','expected_version'=>5,'publish_fields'=>['title'=>true]],'publication_pending');
        $op('PRODUCT_PUBLISH_FAILED',['product_id'=>'product-1','expected_version'=>6,'reason'=>'CHANNEL_REJECTED'],'FAILED');
        $op('CATALOG_SHIPPING_V1_READ',['blueprint_id'=>42,'provider_id'=>7],'profiles');
        foreach (['personalization-preview-task:processed','order:shipment:created','order:line-item:canceled',
            'support-request:updated'] as $index=>$topic) {
            $op('WEBHOOK_CREATE',['topic'=>$topic,'url'=>'https://webhooks.example.invalid/printify']);
            $hook='webhook-'.($index+2);
            $op('WEBHOOK_SIMULATE',['webhook_id'=>$hook]);
            $op('WEBHOOK_SIMULATE',['webhook_id'=>$hook],'DUPLICATE_EVENT');
        }


        $revision=$workspace->inspect()['revision'];
        $id=hash('sha256','lost-commit');
        // Simulate a commit whose response is lost on the WordPress database connection.
        $inject=static function(string $sql) use ($db):string {
            if (!str_starts_with($sql,'UPDATE df_printify_fixture_workspace SET revision=')) return $sql;
            $db->exec($sql);
            return 'SELECT * FROM df_missing_fixture_ack';
        };
        global $wpdb;
        $prior=$wpdb->suppress_errors(true);
        add_filter('query',$inject);
        try {
            $lost=$workspace->execute($revision,$id,'ORDERS_READ',[]);
            requireFixture($lost['state']==='UNKNOWN' && !$lost['retry_permitted'],'Lost database acknowledgement promoted');
        } finally {
            remove_filter('query',$inject);
            $wpdb->suppress_errors($prior);
        }
        requireFixture($workspace->execute($revision+1,$id,'ORDERS_READ',[])['reason']==='OPERATION_ALREADY_RECORDED',
            'Lost operation identity became reusable');
        requireFixture($workspace->execute($revision,hash('sha256','stale-form'),'ORDERS_READ',[])['reason']==='STALE_REVISION_OR_WORKSPACE_LIMIT',
            'Stale form overwrote workspace');
        // Interleave two independent database connections at the exact CAS.
        $expected=$workspace->inspect()['revision'];
        $winner=null;
        $otherDb=new wpdb('root','','printify_fixture','localhost:'.$socket);
        $competitor=new SyntheticWorkspace($otherDb,'107','fixture-account');
        $race=static function(string $sql) use (&$winner,$competitor,$expected):string {
            if ($winner!==null || !str_starts_with($sql,'UPDATE df_printify_fixture_workspace SET revision=')) return $sql;
            $winner=false;
            $winner=$competitor->execute($expected,hash('sha256','race-winner'),'ORDERS_READ',[]);
            return $sql;
        };
        add_filter('query',$race);
        try {
            $loser=$workspace->execute($expected,hash('sha256','race-loser'),'PRODUCTS_READ',[]);
        } finally {
            remove_filter('query',$race);
        }
        requireFixture($winner['state']==='SYNTHETIC_OBSERVED' && $loser['reason']==='WORKSPACE_CHANGED'
            && $workspace->inspect()['revision']===$expected+1,'Competing updates did not produce one winner');
        requireFixture(!str_contains((string)$db->query('SELECT state_json FROM df_printify_fixture_workspace')->fetchColumn(),
            'workspace@example.invalid'),'Durable state retained address data');
    } else {
        $history = $workspace->inspect();
        requireFixture(($history['audit'][0]['actor_id']??null)===1 && isset($history['audit'][0]['occurred_at']),
            'Restart lost operator audit attribution');
        requireFixture(array_column($history['events'],'type')===['personalization-preview-task:processed','order:created','order:updated','product:updated',
            'order:sent-to-production','order:shipment:created','order:shipment:delivered','order:line-item:canceled','order:created','product:publish:started','product:publish:started','support-request:updated',
            'personalization-preview-task:processed','order:shipment:created','order:line-item:canceled','support-request:updated'],
            'Restart lost event history or admitted duplicate event state');
        $read = $workspace->execute($history['revision'],hash('sha256','reopen-tracking'),'ORDER_READ',['order_id'=>'order-1']);
        requireFixture($read['state']==='SYNTHETIC_OBSERVED' && $read['data']['status']==='fulfilled'
            && count($read['data']['shipments'])===1 && $read['data']['shipments'][0]['delivered_at']==='2026-11-19T10:05:00Z'
            && count($read['data']['line_items'])===2 && !$read['provider_outcome_confirmed'],'Restart lost order/tracking state');
        $select=$db->prepare('SELECT scope_key,state_json FROM df_printify_fixture_workspace WHERE scope_key=?');
        $select->execute([hash('sha256',"107\nfixture-account")]);
        $row=$select->fetch(PDO::FETCH_ASSOC);
        $tamper=$db->prepare('UPDATE df_printify_fixture_workspace SET state_json=? WHERE scope_key=?');
        $tamper->execute(['{}',$row['scope_key']]);
        try {
            $workspace->inspect();
            throw new LogicException('Malformed persisted state admitted');
        } catch (RuntimeException) {
            // Integrity or shape must deny stored state.
        } finally {
            $tamper->execute([$row['state_json'],$row['scope_key']]);
        }
        $expressRead=$workspace->execute($workspace->inspect()['revision'],hash('sha256','reopen-express'),
            'ORDER_READ',['order_id'=>'order-3']);
        requireFixture($expressRead['state']==='SYNTHETIC_OBSERVED'
            && $expressRead['data']['status']==='fulfilled' && $expressRead['data']['line_items'][0]['status']==='fulfilled'
            && $expressRead['data']['shipping_cost']===900,'Restart lost Express fixture fulfillment or cost');
        $reopenPreview=$workspace->execute($workspace->inspect()['revision'],hash('sha256','reopen-svg'),
            'PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-2']);
        $svg=base64_decode($reopenPreview['data']['synthetic_svg']??'',true);
        requireFixture(is_string($svg) && str_contains($svg,'data:image/png;base64,')
            && str_contains($svg,'Fixture inscription') && !$reopenPreview['provider_outcome_confirmed'],
            'Restart lost rendered fixture artifact or misattributed its origin');
        $raster=$reopenPreview['data']['synthetic_raster']??null;
        requireFixture(function_exists('imagepng')
            ? ($raster['mime']??null)==='image/png'
                && str_starts_with(base64_decode($raster['base64'],true),"\x89PNG\r\n\x1a\n")
            : $raster===null,'PNG availability or portable SVG fallback was misreported');

        $supportAfterRestart=$workspace->execute($workspace->inspect()['revision'],hash('sha256','reopen-support'),
            'SUPPORT_LIST',['order_id'=>'order-1']);
        requireFixture($supportAfterRestart['state']==='SYNTHETIC_OBSERVED'
            && count($supportAfterRestart['data']['support_requests'])===3
            && $supportAfterRestart['data']['support_requests'][0]['outcome']==='unresolved',
            'Restart lost pending support evidence');
        $previewAfterRestart=$workspace->execute($workspace->inspect()['revision'],hash('sha256','reopen-preview'),
            'PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-1']);
        requireFixture($previewAfterRestart['state']==='SYNTHETIC_OBSERVED'
            && $previewAfterRestart['data']['status']==='failed','Restart lost terminal preview evidence');
    }
    wp_set_current_user(0);
    requireFixture(Runtime::syntheticWorkspace()===null,'Anonymous workspace access');
    wp_set_current_user(1);
    try {
        new SyntheticWorkspace($wpdb,'108','fixture-account');
        throw new LogicException('Cross-shop store admission');
    } catch (InvalidArgumentException) {
        // Exact shop and capability required.
    }
    requireFixture(Runtime::syntheticWorkspace('108')===null,'Unassigned shop was visible');
    $operator=wp_get_current_user();
    $operator->add_cap('digiforge_printify_review_shop_108');
    try {
        $other=Runtime::syntheticWorkspace('108');
        requireFixture($other instanceof SyntheticWorkspace,'Other fixture unavailable');
        if ($phase==='write') {
            requireFixture($other->inspect()['revision']===0,'Other shop inherited fixture state');
            $otherRun=static function(string $op,array $input=[]) use ($other):array {
                $rev=$other->inspect()['revision'];
                $result=$other->execute($rev,hash('sha256','other:'.$rev),$op,$input);
                requireFixture($result['state']==='SYNTHETIC_OBSERVED','Other fixture operation failed: '.$op);
                return $result;
            };
            $otherRun('IMAGE_UPLOAD',['file_name'=>'other.png','contents'=>base64_encode($png)]);
            $otherRun('PRODUCT_CREATE',['title'=>'Other fixture product','description'=>'Other scope','blueprint_id'=>42,
                'print_provider_id'=>7,'variants'=>[['id'=>9,'price'=>1200,'is_enabled'=>true]],'print_areas'=>[
                    ['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[
                        ['id'=>'image-1','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0],
                    ]]]],
                ]]);
            $otherRun('PREVIEW_CREATE',['product_id'=>'product-1','external_id'=>'other-jpeg','variant_ids'=>[9],
                'personalization'=>[$item,$art],'format'=>'jpeg']);
            $otherRun('FIXTURE_PREVIEW_COMPLETE',['product_id'=>'product-1','task_id'=>'task-product-1-1']);
            $jpeg=$otherRun('PREVIEW_READ',['product_id'=>'product-1','task_id'=>'task-product-1-1'])['data']['synthetic_raster']??null;
            requireFixture(function_exists('imagejpeg')
                ? ($jpeg['mime']??null)==='image/jpeg' && str_starts_with(base64_decode($jpeg['base64'],true),"\xff\xd8")
                : $jpeg===null,'JPEG availability or portable SVG fallback was misreported');
            $productEvent=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3aadd','type'=>'product:created',
                'created_at'=>'2026-11-19 10:00:00+00:00',
                'resource'=>['id'=>'product-1','type'=>'product','data'=>['shop_id'=>108]]];
            $wire=SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($productEvent));
            $wireAttempt=static function(string $wire,string $expected) use ($other):void {
                $rev=$other->inspect()['revision'];
                $result=$other->execute($rev,hash('sha256','wire:'.$rev),'FIXTURE_WEBHOOK_HTTP',['wire'=>$wire]);
                requireFixture($result['reason']===$expected,'Unexpected HTTP fixture result: '.$expected);
            };
            $wireAttempt(str_replace('sha256=','sha256= ',$wire),'EVENT_FIXTURE_SIGNATURE');
            $wireAttempt($wire,'NONE');
            $wireAttempt($wire,'DUPLICATE_EVENT');
            $productEvent['created_at']='2026-11-19 10:01:00+00:00';
            $wireAttempt(SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($productEvent)),'EVENT_ID_CONFLICT');
            $productEvent['resource']['data']['shop_id']=107;
            $wireAttempt(SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($productEvent)),
                'EVENT_CONTRACT_OR_SCOPE');
            $otherRun('PRODUCT_DELETE',['product_id'=>'product-1','expected_version'=>1]);
            $productEvent['type']='product:deleted';
            $productEvent['id']='653b6be8-2ff7-4ab5-a7a6-6889a8b3aade';
            $productEvent['resource']['data']['shop_id']=108;
            $wireAttempt(SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($productEvent)),'NONE');
            $disconnect=$otherRun('SHOP_DISCONNECT');
            requireFixture($disconnect['state']==='SYNTHETIC_OBSERVED','Synthetic disconnect failed');
            $event=['id'=>'653b6be8-2ff7-4ab5-a7a6-6889a8b3aadf','type'=>'shop:disconnected',
                'created_at'=>'2026-11-19 10:00:00+00:00','resource'=>['id'=>108,'type'=>'shop','data'=>null]];
            $receipt=$other->execute($other->inspect()['revision'],hash('sha256','disconnect-event'),'FIXTURE_WEBHOOK_HTTP',
                ['wire'=>SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($event))]);
            requireFixture($receipt['state']==='SYNTHETIC_OBSERVED','Disconnect event missing');
        } else {
            requireFixture(array_column($other->inspect()['events'],'type')===['product:created','product:deleted','shop:disconnected'],
                'Disconnect observation did not survive restart');
        }
    } finally {
        $operator->remove_cap('digiforge_printify_review_shop_108');
    }
    requireFixture($http===0,'Fixture made an HTTP call');
    echo "Disposable synthetic workspace $phase PASS\n";
} finally {
    remove_filter('pre_http_request',$tripwire,PHP_INT_MAX);
}
