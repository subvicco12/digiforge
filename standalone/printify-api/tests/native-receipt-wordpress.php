<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{CredentialReference,NativeAuthorityAdapter,NativeBridge,NativeHeldJournal,NativeHeldJournalSchema,ShopBinding};
use DigiForgePrintifyApi\Runtime;

$root=getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket=getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
if (!is_string($root)||!str_starts_with($root,'/tmp/df-printify-')
    || !is_string($socket)||!str_starts_with($socket,'/tmp/df-printify-')) {
    throw new RuntimeException('Disposable fixture required');
}
define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('digiforge-test-key-',2));
define('DIGIFORGE_PRINTIFY_OFFLINE_SHOP','12');
$_SERVER['REQUEST_METHOD']='GET';
require $root.'/wp-load.php';
if (DB_HOST!=='localhost:'.$socket || DB_NAME!=='printify_fixture' || wp_get_environment_type()!=='local'
    || !WP_HTTP_BLOCK_EXTERNAL) throw new RuntimeException('Native fixture requires isolated WordPress');
require_once ABSPATH.'wp-admin/includes/plugin.php';
$core=WP_PLUGIN_DIR.'/digiforge';
foreach (['MutationCoreInterface','MutationInspectionCoreInterface','PublishingCoreInterface',
    'WorkflowCoreInterface','OperationsCoreInterface','NativeCorePortInterface'] as $interface) {
    require "$core/tests/fixtures/native-printify-companion/$interface.php";
}
require "$core/tests/wordpress/fixtures/PrintifyApiApprovalFixture.php";
require "$core/tests/support/NativePrintifyContractFixture.php";
$check=static function(bool $ok,string $reason):void {if (!$ok) throw new RuntimeException($reason);};
add_filter('pre_http_request',static fn()=>throw new RuntimeException('Provider HTTP denied'),PHP_INT_MAX);
add_filter('pre_wp_mail',static fn()=>true);
$check(activate_plugin('digiforge/digiforge.php')===null,'Pinned Core activation failed');
$fixture=new PrintifyApiApprovalFixture();
$fixture->boot();
wp_get_current_user()->add_cap('digiforge_printify_review_shop_12');
$fixture->setting('stop_all',true);
$fixture->setting('automation_armed',false);
$fixture->setting('activation_authorized',false);
$fixture->setting('printify_activation_authorized',false);
$checked=PrintifyApiApprovalFixture::checked(...);
$v=$checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$fixture->integration,'printify.connection.read'));
$scope=new ShopBinding(2,'personalized_pod',$fixture->integration,'12',$v['environment'],$v['review_revision'],
    new CredentialReference($fixture->integration),$v['binding_hash'],$v['expires_at']);
$account=$checked(NativeAuthorityAdapter::scope($scope,1))['scope']['external_account_reference'];
define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT',$account);
global $wpdb;
foreach (NativeHeldJournalSchema::statements() as $sql) {
    $checked($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));
}
$binding=array_replace(NativePrintifyContractFixture::binding(),[
    'operation_id'=>hash('sha256',wp_generate_uuid4()),'scope_fingerprint'=>$scope->fingerprint(),
    'integration_id'=>$scope->integrationId,'credential_reference'=>$scope->integrationId,
    'provider_shop_id'=>'12','environment'=>'test','authorization_revision'=>$scope->revision,
]);
$key=hash('sha256','fixture:'.$binding['operation_id']);
$journal=new NativeHeldJournal();
$checked($journal->register($binding,$key));
$checked($journal->transition($binding['operation_id'],'personalized_pod',1,1,'FENCE'));
$checked($journal->transition($binding['operation_id'],'personalized_pod',1,2,'UNKNOWN'));
$entry=$checked($journal->read($binding['operation_id'],'personalized_pod',1));
$request=NativePrintifyContractFixture::mutation('RECEIPT_READ');
$request['binding']=$binding;
$request['context']=array_replace($request['context'],array_intersect_key($binding,$request['context']));
$request['duplicate_key']=$key;
$request['data']=['receipt_reference'=>$entry['event_hash']];
$check(Runtime::permitsFixtureAccount('12',$account),'Standalone fixture account or shop capability unavailable');
$check(DigiForge\Connector\Printify\NativePortContract::valid('mutation_request',$request),'Invalid native fixture request');
$prepared=Runtime::prepareNativeMutation('12',$account,$request);
$check($prepared['state']==='UNKNOWN' && $prepared['reason']==='NATIVE_HELD_OUTCOME_UNKNOWN'
    && $prepared['journal_revision']===$entry['record']['revision']
    && !$prepared['provider_execution_authorized'] && !$prepared['provider_receipt_verified'] && !$prepared['retry_permitted'],
    'Standalone did not preserve Core UNKNOWN: '.json_encode($prepared));
foreach ([
    ['duplicate_key'=>str_repeat('f',64)],
    ['data'=>['receipt_reference'=>str_repeat('f',64)]],
    ['binding'=>array_replace($binding,['payload_fingerprint'=>str_repeat('f',64)])],
] as $wrong) {
    $check(Runtime::prepareNativeMutation('12',$account,$wrong+$request)['state']==='DENIED','Substituted receipt accepted');
}
$port=$checked(NativeBridge::connect('1.0.0'));
$claim=$port->exchange(['action'=>'CLAIM','data'=>[]]+$request);
$check(is_wp_error($claim)&&$claim->get_error_code()==='printify_native_journal_and_independent_checkpoint_required',
    'Native claim failed to hold');
WP_Session_Tokens::get_instance(1)->destroy(wp_get_session_token());
$check(Runtime::prepareNativeMutation('12',$account,$request)['state']==='DENIED','Destroyed session admitted native receipt read');
echo "Pinned Core receipt read PASS: UNKNOWN, duplicate-key/event/payload substitution, missing independent claim floor, revoked session\n";
