<?php
declare(strict_types=1);
use DigiForgePrintifyApi\{Runtime,ProtectedGet,SyntheticWorkspacePanel};
$root=getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket=getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
$phase=$argv[1]??null;
if (!is_string($root)||!str_starts_with($root,'/tmp/df-printify-')||!is_string($socket)
    || !in_array($phase,['write','claim','reopen'],true)) throw new RuntimeException('Fixture required');
require $root.'/wp-load.php';
if (DB_HOST!=='localhost:'.$socket||DB_NAME!=='printify_fixture'||wp_get_environment_type()!=='local') throw new RuntimeException('Socket fixture required');
wp_set_current_user(1);
define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY',true);
add_filter('pre_http_request',static fn()=>throw new RuntimeException('External request'),PHP_INT_MAX);
global $wpdb;
if ($phase==='write') {
    $wpdb->query('CREATE TABLE df_printify_fixture_workspace (
        scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        revision int unsigned NOT NULL,state_json mediumblob NOT NULL,seal char(64) NOT NULL) ENGINE=InnoDB');
}
$workspace=Runtime::syntheticWorkspace();
if (!$workspace) throw new RuntimeException('Workspace unavailable');
require_once WP_PLUGIN_DIR.'/digiforge-printify-api/ProtectedGet.php';
$check=static function(bool $ok,string $name): void {if (!$ok) throw new RuntimeException($name);};
$send=static function(string $op,array $input,bool $preview=false,?string $fault=null,array $edit=[],bool $create=false) use($workspace): array {
    $rev=$workspace->inspect()['revision'];
    return $workspace->execute($rev,hash('sha256','batch2-'.$rev),$op,$input,$preview,$fault,$edit,$create);
};
$review=static fn(array $proposal,string $decision,?string $fault=null): array=>$send('FIXTURE_PROPOSAL_REVIEW',
    ['proposal_id'=>$proposal['id'],'proposal_revision'=>$proposal['revision'],'decision'=>$decision],false,$fault);
$claimPath=dirname($root).'/mockup-claim.json';
if ($phase==='claim') {
    $claim=json_decode(file_get_contents($claimPath),true,8,JSON_THROW_ON_ERROR);
    $result=$workspace->execute($claim['revision'],hash('sha256','fixture-mockup-claim-'.$argv[2]),
        'FIXTURE_PROPOSAL_REVIEW',$claim['input'],false,'lost_ack');
    $check(!$result['retry_permitted'] && !$result['provider_execution_authorized'],'Claim created authority');
    if ($result['state']==='UNKNOWN') echo "MOCKUP_CLAIM_UNKNOWN\n";
    else {
        $check($result['state']==='DENIED' && in_array($result['reason'],
            ['WORKSPACE_CHANGED','STALE_REVISION_OR_WORKSPACE_LIMIT','PROFILE_OR_WORKSPACE_CHANGED','WORKSPACE_LIMIT_OR_SCOPE_CHANGED'],true),
            'Unexpected competing claim result: '.$result['reason']);
        echo "MOCKUP_COMPETING_CLAIM_DENIED\n";
    }
    return;
}
if ($phase==='reopen') {
    $unknown=array_values(array_filter($workspace->inspect()['proposals'],static fn(array $p): bool=>
        $p['operation']==='MOCKUP_SELECTION_UPDATE' && $p['state']==='UNKNOWN'));
    $check(count($unknown)===1,'Missing durable mockup UNKNOWN');
    $check($review($unknown[0],'simulate_held')['reason']==='FIXTURE_TRANSITION_APPROVAL_MISMATCH','UNKNOWN replay reached the fixture');
    $check($review($unknown[0],'reconcile_mockup')['state']==='RECONCILED_FIXTURE_TRANSITION','Exact readback did not reconcile');
    $check($review($unknown[0],'reconcile_mockup')['reason']==='FIXTURE_MOCKUP_RECONCILIATION_DENIED','Duplicate readback decision allowed');
    echo "PASS reopened UNKNOWN, denied resend, reconciled exact mockup selection and denied duplicate decision\n";
    return;
}
require_once WP_PLUGIN_DIR.'/digiforge-printify-api/SyntheticWorkspacePanel.php';
$parse=new ReflectionMethod(SyntheticWorkspacePanel::class,'fieldInput');
$fields=static fn(string $op,array $input): array=>$parse->invoke(null,'107',$op,$input);
$chunk=static fn(string $type,string $data): string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0)).$chunk('IDAT',gzcompress("\0\0\xff\0")).$chunk('IEND','');
$check($send('IMAGE_UPLOAD',$fields('IMAGE_UPLOAD',['file_name'=>'fixture.png','contents'=>base64_encode($png)]))['state']==='SYNTHETIC_OBSERVED','Fixture upload');
$product=['title'=>'Fixture product','description'=>'Synthetic','blueprint_id'=>'42','print_provider_id'=>'7','variant_id'=>'9','price'=>'1200','image_id'=>'image-1','position'=>'front'];
$productInput=$fields('PRODUCT_CREATE',$product);
$artworkProposal=$send('PRODUCT_CREATE',$productInput,true)['proposal'];
$artworkApproved=$review($artworkProposal,'approve')['proposal'];
$artworkEdit=['id'=>$artworkApproved['id'],'revision'=>$artworkApproved['revision']];
$check($send('PRODUCT_CREATE',['print_areas'=>[]]+$productInput,false,null,$artworkEdit,true)['reason']==='FIXTURE_CREATE_INTENT_MISMATCH','Modified artwork passed approval');
$check($send('PRODUCT_CREATE',$productInput,false,null,$artworkEdit,true)['state']==='FIXTURE_CREATE_RECORDED','Fixture product creation');
$order=['external_id'=>'fixture-quote-order','product_id'=>'product-1','variant_id'=>'9','quantity'=>'1','shipping_method'=>'1','destination'=>'us'];
$quote=$send('ORDER_SHIPPING_QUOTE',$fields('ORDER_SHIPPING_QUOTE',$order),true);
$check(isset($quote['proposal']['quote_binding']),'Quote not bound');
$orderInput=$fields('ORDER_CREATE',$order);
$orderProposal=$send('ORDER_CREATE',$orderInput,true)['proposal'];
$approved=$review($orderProposal,'approve')['proposal'];
$edit=['id'=>$approved['id'],'revision'=>$approved['revision']];
foreach ([
    ['shipping_method'=>4]+$orderInput,
    ['address_to'=>['country'=>'CA']+$orderInput['address_to']]+$orderInput,
    ['line_items'=>[['product_id'=>'product-1','variant_id'=>10,'quantity'=>1]]]+$orderInput,
] as $changed) $check($send('ORDER_CREATE',$changed,false,null,$edit,true)['reason']==='FIXTURE_CREATE_INTENT_MISMATCH','Changed order passed approval');
$check($send('ORDER_CREATE',$orderInput,false,null,['id'=>$approved['id'],'revision'=>1],true)['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION','Stale approval accepted');
$expiry=new ReflectionMethod($workspace,'quoteMatches');
$binding=$approved['quote_binding'];
$check(!$expiry->invoke(null,$binding,$binding['intent_sha256'],$binding['quoted_at']+300),'Expired quote accepted');
$check(!$expiry->invoke(null,['amount_minor'=>$binding['amount_minor']+1]+$binding,$binding['intent_sha256'],$binding['quoted_at']),
    'A transplanted quote amount retained authorization');
$created=$send('ORDER_CREATE',$orderInput,false,null,$edit,true);
$creation = $created['proposal']['creation_receipt'];
$check(($creation['quote_id']??null)===$quote['proposal']['id'] && $creation['quote_amount_minor'] === $binding['amount_minor']
    && $creation['quote_method']==='standard' && $creation['quote_projection_sha256']===$binding['projection_sha256'],
    'Order receipt lost authenticated quote identity, method or amount');
$check($send('ORDER_CREATE',$orderInput,false,null,$edit,true)['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION','Repeated order claim accepted');
echo "PASS bound quote, stale approval, changed artwork, variants, destination and shipping, and duplicate order claim\n";
foreach (['LINE_ITEM_CANCEL'=>['order_id'=>'order-1','line_item_id'=>'line-1'],
    'ORDER_CANCEL'=>['order_id'=>'order-1']] as $operation=>$input) {
    $proposal=$send($operation,$input,true)['proposal'];
    $check(isset($proposal['cancellation_expected_sha256']),'Cancellation did not bind exact readback');
    $held=$review($proposal,'approve')['proposal'];
    $lost=$review($held,'simulate_held','lost_ack');
    $check($lost['state']==='UNKNOWN' && !$lost['retry_permitted'],'Lost cancellation acknowledgment was treated as success');
    $check($review($lost['proposal'],'simulate_held')['reason']==='FIXTURE_TRANSITION_APPROVAL_MISMATCH','UNKNOWN cancellation replayed');
    $check($send('ORDER_CANCEL',['order_id'=>'order-1'],true)['reason']==='FIXTURE_CANCELLATION_UNKNOWN'
        && $send($operation,$input)['reason']==='FIXTURE_CANCELLATION_UNKNOWN','New cancellation bypassed UNKNOWN quarantine');
    $check($review($lost['proposal'],'reconcile_cancellation')['state']==='RECONCILED_FIXTURE_TRANSITION','Exact cancellation could not reconcile');
    $check($review($lost['proposal'],'reconcile_cancellation')['reason']==='FIXTURE_CANCELLATION_RECONCILIATION_DENIED','Duplicate cancellation reconciliation admitted');
}
echo "PASS lost line and whole-order cancellation acknowledgment, exact readback and replay denial\n";
$amendment=['product_id'=>'product-1','expected_version'=>1,'title'=>'Fixture product revised'];
$productDraft=$send('PRODUCT_UPDATE',$amendment,true)['proposal'];
$check(isset($productDraft['product_expected_sha256']),'Product update preview did not bind exact readback');
$productApproved=$review($productDraft,'approve')['proposal'];
$productUpdated=$review($productApproved,'simulate_held');
$updateReceipt=$productUpdated['proposal']['transition_receipt'] ?? [];
$check($productUpdated['state']==='RECONCILED_FIXTURE_TRANSITION'
    && ($updateReceipt['resource_id']??null)==='product-1'
    && !hash_equals($updateReceipt['before_sha256'],$updateReceipt['after_sha256']),
    'Approved product update did not produce exact versioned readback');
$check($review($productApproved,'simulate_held')['reason']==='FIXTURE_TRANSITION_APPROVAL_MISMATCH','Product update was replayed');
$check($send('PRODUCT_UPDATE',$amendment,true)['state']!=='SYNTHETIC_OBSERVED','Stale product revision admitted');
echo "PASS held product update, exact readback, stale revision and duplicate denial\n";
$selection=['groups'=>[['variant_ids'=>[9],'mockup_ids'=>['product-1_back']]]];
$request=['product_id'=>'product-1','expected_version'=>2,'selection'=>$selection,'selection_sha256'=>ProtectedGet::digest($selection)];
$check($send('MOCKUP_SELECTION_UPDATE',$request)['state']==='DENIED','Unapproved PATCH admitted');
$foreign=['groups'=>[['variant_ids'=>[9],'mockup_ids'=>['foreign_mockup']]]];
foreach ([
    ['expected_version'=>3]+$request,
    ['selection_sha256'=>str_repeat('f',64)]+$request,
    ['selection'=>$foreign,'selection_sha256'=>ProtectedGet::digest($foreign)]+$request,
] as $invalid) $check($send('MOCKUP_SELECTION_UPDATE',$invalid,true)['state']!=='SYNTHETIC_OBSERVED','Invalid replacement preflight succeeded');
$draft=$send('MOCKUP_SELECTION_UPDATE',$request,true)['proposal'];
$check($draft['state']==='PENDING_APPROVAL' && isset($draft['mockup_expected_sha256']),'No exact mockup preview');
$approved=$review($draft,'approve')['proposal'];
$generic=$send('FIXTURE_PROPOSAL_REVIEW',['proposal_id'=>$approved['id'],'proposal_revision'=>$approved['revision'],
    'decision'=>'reconcile','receipt_id'=>str_repeat('a',64)]);
$check($generic['reason']==='PROPOSAL_TRANSITION_DENIED','Generic receipt bypassed exact mockup readback');
$claim=['revision'=>$workspace->inspect()['revision'],'input'=>[
    'proposal_id'=>$approved['id'],'proposal_revision'=>$approved['revision'],'decision'=>'simulate_held',
]];
file_put_contents($claimPath,json_encode($claim,JSON_THROW_ON_ERROR));
echo "PASS complete mockup replacement preflight, stale revision, foreign mockup denial and held approval\n";
