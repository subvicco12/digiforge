<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;
use DigiForgePrintifyApi\SyntheticWorkspace;

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
        ],'SYNTHETIC_OBSERVED',true);
        $op('PRODUCT_UPDATE',['product_id'=>'product-1','expected_version'=>'1','title'=>'Edited fixture mug','description'=>''],
            'SYNTHETIC_OBSERVED',true);
        $op('PRODUCT_UPDATE',['product_id'=>'product-1','expected_version'=>1,'title'=>'Stale edit'],'CONFLICT');
        $op('PRODUCT_GPSR_READ',['product_id'=>'product-1','blueprint_id'=>42,'provider_id'=>7,'variant_ids'=>[9]],'supplier_certified');
        $order = [
            'external_id'=>'operator-scenario-1','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>2]],
            'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,
            'address_to'=>['first_name'=>'Synthetic','last_name'=>'Only','email'=>'workspace@example.invalid','phone'=>'',
                'country'=>'US','region'=>'CA','address1'=>'Fixture street','address2'=>'','city'=>'Fixture','zip'=>'90001'],
        ];
        $formOrder=['external_id'=>'operator-scenario-1','product_id'=>'product-1','variant_id'=>'9','quantity'=>'2','shipping_method'=>'1'];
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
        $op('FIXTURE_FULFILL',['order_id'=>'order-1'],'SYNTHETIC_OBSERVED',true);
        $op('ORDER_READ',['order_id'=>'order-1'],'tracking_hash',true);
        $op('ORDER_CREATE',array_replace($order,['external_id'=>'operator-scenario-cancel']));
        $op('LINE_ITEM_CANCEL',['order_id'=>'order-2','line_item_id'=>'line-1'],'cancellation_accepted');
        $op('ORDER_READ',['order_id'=>'order-2'],'canceled');
        $op('ORDER_CANCEL',['order_id'=>'order-2'],'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOK_CREATE',['topic'=>'order:created','url'=>'https://webhooks.example.invalid/printify'],
            'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOK_UPDATE',['webhook_id'=>'webhook-1','url'=>'https://webhooks.example.invalid/printify-updated'],
            'SYNTHETIC_OBSERVED',true);
        $op('WEBHOOKS_READ',[],'webhook-1',true);
        $op('WEBHOOK_DELETE',['webhook_id'=>'webhook-1'],'SYNTHETIC_OBSERVED',true);
        $op('IMAGE_ARCHIVE',['image_id'=>'image-1'],'CONFLICT');
        $op('PRODUCT_PUBLISH',['product_id'=>'product-1','expected_version'=>2,'publish_fields'=>['title'=>true]],'publication_pending');
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
        requireFixture(array_column($history['events'],'type')===['order:created','order:updated','product:updated'],
            'Restart lost event history or admitted duplicate event state');
        $read = $workspace->execute($history['revision'],hash('sha256','reopen-tracking'),'ORDER_READ',['order_id'=>'order-1']);
        requireFixture($read['state']==='SYNTHETIC_OBSERVED' && $read['data']['status']==='fulfilled'
            && count($read['data']['shipments'])===1 && !$read['provider_outcome_confirmed'],'Restart lost order/tracking state');
        $row = $db->query('SELECT scope_key,state_json FROM df_printify_fixture_workspace')->fetch(PDO::FETCH_ASSOC);
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
        requireFixture($other instanceof SyntheticWorkspace && $other->inspect()['revision']===0,
            'Other authorized shop inherited existing fixture state');
    } finally {
        $operator->remove_cap('digiforge_printify_review_shop_108');
    }
    requireFixture($http===0,'Fixture made an HTTP call');
    echo "Disposable synthetic workspace $phase PASS\n";
} finally {
    remove_filter('pre_http_request',$tripwire,PHP_INT_MAX);
}
