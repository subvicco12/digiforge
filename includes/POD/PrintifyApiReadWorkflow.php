<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
use DigiForge\Listings\Validator;
use DigiForge\Security\{ShopAccess, ReviewedLocalEvidence, Logger};
use DigiForge\PortalAccess\Owner;
use WP_Error;

/** Exact single-request read grants. Catalog grants and mutation permits are not interchangeable. */
final class PrintifyApiReadWorkflow
{
    public const CAPABILITY = 'printify.connection.read';
    private static function access(string $shop, string $operation = ''): bool
    {
        if (!in_array($shop, ['personalized_pod','standard_pod'], true)) { return false; }
        $caps = ['manage_digiforge_connections'];
        if (in_array($operation, ['PRODUCT_READ','PRODUCT_GPSR_READ','PRODUCTS_READ','UPLOAD_READ','UPLOADS_READ'], true)) { $caps[] = 'manage_digiforge_products'; }
        if (in_array($operation, ['ORDER_READ','ORDERS_READ'], true)) { $caps[] = 'manage_digiforge_orders'; }
        if (in_array($operation, ['PROVIDERS_READ','PROVIDER_READ','BLUEPRINTS_READ','BLUEPRINT_READ','VARIANTS_READ','SHIPPING_READ'], true)) { $caps[] = 'manage_digiforge_pod'; }
        foreach ($caps as $cap) { if (!current_user_can($cap) || ShopAccess::authorize($shop, $cap) !== true) { return false; } }
        return true;
    }
    private static function error(string $reason): WP_Error
    {
        return new WP_Error('printify_api_read_' . $reason, 'Governed Printify read blocked: ' . $reason . '.', ['status'=>409,'retry_permitted'=>false]);
    }
    private function save(string $kind, array $record): array|WP_Error
    {
        $record['seal'] = hash_hmac('sha256', Validator::canonicalJson($record), wp_salt('auth'));
        $raw = Validator::canonicalJson($record);
        if (strlen($raw) > 1048576) { return self::error('record_size'); }
        $hash = hash('sha256', $raw); $key = 'digiforge_api_read_' . $kind . '_' . $hash;
        if (!add_option($key, $raw, '', false) && get_option($key) !== $raw) { return self::error('persistence'); }
        return ['hash'=>$hash,'record'=>$record];
    }
    private function load(string $kind, string $hash): array|WP_Error
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) { return self::error('identity'); }
        global $wpdb; $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM ' . $wpdb->options . ' WHERE BINARY option_name=BINARY %s', 'digiforge_api_read_' . $kind . '_' . $hash));
        if ($wpdb->last_error !== '' || !is_string($raw) || strlen($raw) > 1048576 || !hash_equals($hash, hash('sha256', $raw))) { return self::error('integrity'); }
        $record = json_decode($raw, true);
        if (!is_array($record) || !is_string($record['seal'] ?? null)) { return self::error('seal'); }
        $seal = $record['seal']; unset($record['seal']);
        if (!hash_equals($seal, hash_hmac('sha256', Validator::canonicalJson($record), wp_salt('auth'))) || !self::access((string)($record['shop_key'] ?? ''), (string)($record['operation'] ?? $record['request']['operation'] ?? ''))) { return self::error('access'); }
        return $record;
    }
    public function prepare(string $shop, int $integration, string $operation, array $input): array|WP_Error
    {
        if (!self::access($shop, $operation) || !PrintifyApiReadEvidence::supported($operation)) { return self::error('access_or_operation'); }
        $binding = PrintifyConnectionBoundary::context($shop, $integration, self::CAPABILITY);
        if (is_wp_error($binding)) { return $binding; }
        $i = (new \DigiForge\Integrations\Repository())->find($integration);
        if (is_wp_error($i) || !is_array($i) || ($i['provider'] ?? null) !== 'printify' || ($i['config']['shop_key'] ?? null) !== $shop || !is_string($i['config']['shop_id'] ?? null)) { return self::error('integration'); }
        try { $request = PrintifyApiRequest::prepare($operation, $i['config']['shop_id'], $input); } catch (\Throwable) { return self::error('contract'); }
        return $this->save('plan', ['schema'=>'printify-api-read-v1','shop_key'=>$shop,'integration_id'=>$integration,'provider_shop_id'=>$i['config']['shop_id'],'connection_scope'=>$binding,'request'=>$request,'input'=>$input,'intent_id'=>bin2hex(random_bytes(8)),'created_by'=>get_current_user_id(),'created_at'=>time()]);
    }
    /** Server-owned composition only; no route, caller grant or source hash grants execution. */
    public function prepareNative(\DigiForge\Connector\Printify\ExternalOperationIntent $intent,string $catalogGrant='',int $requestIndex=0):array|WP_Error
    {
      if(!PrintifyNativeReadSources::extended($intent->operation)||!self::access($intent->shopKey,$intent->operation)||$intent->actorId!==get_current_user_id())return self::error('native_source_scope');
      $scope=PrintifyConnectionBoundary::context($intent->shopKey,$intent->integrationId,self::CAPABILITY);if(is_wp_error($scope))return $scope;
      $sources=PrintifyNativeReadSources::describe($intent,$catalogGrant,$requestIndex,$scope);if(is_wp_error($sources))return $sources;
      try{$request=PrintifyApiRequest::prepare($intent->operation,$scope['external_shop_reference'],$intent->input);}catch(\Throwable){return self::error('native_source_contract');}
      return $this->save('plan',['schema'=>'printify-api-read-v1','shop_key'=>$intent->shopKey,'integration_id'=>$intent->integrationId,'provider_shop_id'=>$scope['external_shop_reference'],'connection_scope'=>$scope,'request'=>$request,'input'=>$intent->input,'native_sources'=>$sources,'intent_id'=>bin2hex(random_bytes(8)),'created_by'=>$intent->actorId,'created_at'=>time()]);
    }
    public function plan(string $hash):array|WP_Error { return $this->load('plan',$hash); }
    public function approvalPayload(string $hash): array|WP_Error
    {
        $plan = $this->load('plan', $hash); if (is_wp_error($plan)) { return $plan; }
        return ['purpose'=>'printify-api-read-v1','plan_hash'=>$hash,'request_fingerprint'=>$plan['request']['fingerprint'],'connection_binding_hash'=>$plan['connection_scope']['binding_hash'],'max_requests'=>3,'required_scope'=>$plan['request']['required_scope']];
    }
    private function current(string $hash, array $plan): true|WP_Error
    {
        if (Settings::safety_locked() || Settings::get('stop_all', true) !== false || Settings::get('printify_activation_authorized', false) !== true || !Settings::is_enabled('printify')) { return self::error('locked'); }
        if (!self::access($plan['shop_key'], $plan['request']['operation']) || Owner::require($plan['shop_key']) !== true) { return self::error('owner'); }
        $binding = PrintifyConnectionBoundary::runtime($plan['integration_id'], self::CAPABILITY, $plan['connection_scope'], $plan['provider_shop_id']);
        if (is_wp_error($binding)) { return $binding; }
        if(isset($plan['native_sources'])){$source=PrintifyNativeReadSources::current($plan);if(is_wp_error($source))return $source;}
        $policy = ReviewedLocalEvidence::approved('policy', $plan['shop_key'], 'printify-api-read:' . $hash);
        $expected = $this->approvalPayload($hash);
        if (is_wp_error($policy) || is_wp_error($expected) || Validator::canonicalJson($policy['payload'] ?? []) !== Validator::canonicalJson($expected)) { return self::error('approval'); }
        return true;
    }
    public function authorize(string $hash): array|WP_Error
    {
        $plan = $this->load('plan', $hash); if (is_wp_error($plan)) { return $plan; }
        $current = $this->current($hash, $plan); if (is_wp_error($current)) { return $current; }
        $policy=ReviewedLocalEvidence::approved('policy',$plan['shop_key'],'printify-api-read:'.$hash);if(is_wp_error($policy))return $policy;
        $session=\DigiForge\Connector\Printify\NativeAuthorityAdapter::session(get_current_user_id());if(is_wp_error($session))return $session;
        return $this->save('authorization', ['shop_key'=>$plan['shop_key'],'plan_hash'=>$hash,'actor'=>get_current_user_id(),'nonce'=>bin2hex(random_bytes(24)),'issued_at'=>time(),'expires_at'=>time()+300,'policy_revision'=>$policy['revision'],'policy_hash'=>$policy['proposal_hash'],'session_fingerprint'=>$session['fingerprint']]);
    }
    private function grantCurrent(string $hash,array $plan,array $auth):true|WP_Error
    {
        if(($auth['actor']??null)!==get_current_user_id()||!is_int($auth['issued_at']??null)||!is_int($auth['expires_at']??null)||$auth['issued_at']>time()||$auth['expires_at']<=$auth['issued_at']||$auth['expires_at']-$auth['issued_at']>300||$auth['expires_at']<=time())return self::error('authorization_expired');
        $session=\DigiForge\Connector\Printify\NativeAuthorityAdapter::session($auth['actor']);if(is_wp_error($session)||($auth['session_fingerprint']??null)!==$session['fingerprint'])return self::error('authorization_session');
        $current=$this->current($hash,$plan);if(is_wp_error($current))return $current;
        $policy=ReviewedLocalEvidence::approved('policy',$plan['shop_key'],'printify-api-read:'.$hash);
        if(is_wp_error($policy)||($auth['policy_revision']??null)!==$policy['revision']||($auth['policy_hash']??null)!==$policy['proposal_hash'])return self::error('authorization_revision');
        return true;
    }
    /** Atomic claim: wp add_option uses an upsert and cannot protect racing writers. */
    private function claimIndex(string $key,string $value):bool
    {
        global $wpdb;if(strlen($key)>191||preg_match('/^[a-f0-9]{64}$/D',$value)!==1||preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->options)!==1)return false;
        $wpdb->last_error='';$indexes=$wpdb->get_results('SHOW INDEX FROM '.$wpdb->options.' WHERE Non_unique=0',ARRAY_A);if($wpdb->last_error!==''||!is_array($indexes))return false;
        $groups=[];foreach($indexes as $index)$groups[$index['Key_name']][]=$index;$unique=false;
        foreach($groups as $columns)if(count($columns)===1&&$columns[0]['Column_name']==='option_name'&&($columns[0]['Sub_part']??null)===null)$unique=true;
        if(!$unique)return false;$wpdb->last_error='';$ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$wpdb->options.' (option_name,option_value,autoload) VALUES (%s,%s,%s)',$key,$value,'no'));
        if($wpdb->last_error!==''||$ok!==1)return false;wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');return true;
    }
    /** Bind an existing independently approved one-use read grant to one exact native intent.
     * This adds no approval, sender, credential store or safety exception.
     */
    public function bindNative(\DigiForge\Connector\Printify\ExternalOperationIntent $intent, string $planHash, string $authorizationHash): array|WP_Error
    {
      try{
        $durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return $durable;
        $proof=$this->nativeProof($intent,$planHash,$authorizationHash);if(is_wp_error($proof))return $proof;
        $fingerprint=self::nativeFingerprint($intent);$key='digiforge_api_read_native_intent_'.$fingerprint;
        $saved=$this->save('native_binding',['shop_key'=>$intent->shopKey,'operation'=>$intent->operation,'actor'=>$intent->actorId,'intent_fingerprint'=>$fingerprint,'session_fingerprint'=>$proof['session']['fingerprint'],'plan_hash'=>$planHash,'authorization_hash'=>$authorizationHash]);
        if(is_wp_error($saved))return $saved;
        // Immutable association: an intent cannot be rebound after any uncertainty or revocation.
        if(!$this->claimIndex('digiforge_api_read_native_authorization_'.$authorizationHash,$fingerprint))return self::error('native_authorization_already_bound');
        if(!$this->claimIndex($key,$saved['hash']))return self::error('native_intent_already_bound');
        if(!Logger::write('native_printify_read_bound',['binding_hash'=>$saved['hash'],'intent_fingerprint'=>$fingerprint],'native_read',$fingerprint))return self::error('native_binding_audit');
        $verified=$this->resolveNative($intent);return is_wp_error($verified)?$verified:['intent_fingerprint'=>$fingerprint,'binding_hash'=>$saved['hash'],'execution_authorized'=>false];
      }catch(\Throwable){return self::error('native_binding_unconfirmed');}
    }
    private static function nativeFingerprint(\DigiForge\Connector\Printify\ExternalOperationIntent $i): string
    {return hash('sha256',Validator::canonicalJson([$i->actorId,$i->shopKey,$i->integrationId,$i->operation,$i->input,$i->intentId,$i->sourceReferences]));}
    private function nativeProof(\DigiForge\Connector\Printify\ExternalOperationIntent $i,string $planHash,string $authorizationHash):array|WP_Error
    {
        $p=$this->load('plan',$planHash);$a=$this->load('authorization',$authorizationHash);$session=\DigiForge\Connector\Printify\NativeAuthorityAdapter::session($i->actorId);
        if(is_wp_error($p)||is_wp_error($a)||is_wp_error($session)||$i->sourceReferences!==($p['native_sources']['source_references']??[])||$i->actorId!==get_current_user_id()||($p['created_by']??null)!==$i->actorId||($p['shop_key']??null)!==$i->shopKey||($p['integration_id']??null)!==$i->integrationId||($p['request']['operation']??null)!==$i->operation||($a['plan_hash']??null)!==$planHash||($a['actor']??null)!==$i->actorId||!is_int($a['expires_at']??null)||$a['expires_at']<=time())return self::error('native_identity');
        try{$request=PrintifyConnectorReadContract::prepare($i->operation,$p['provider_shop_id'],$i->input);if($request['endpoint']!==$p['request']['endpoint']||($p['request']['method']??null)!=='GET')return self::error('native_request');}catch(\Throwable){return self::error('native_request');}
        $current=$this->grantCurrent($planHash,$p,$a);return is_wp_error($current)?$current:['plan'=>$p,'authorization'=>$a,'session'=>$session];
    }
    /** Server-internal references only; no route or ability exposes grant hashes. */
    public function resolveNative(\DigiForge\Connector\Printify\ExternalOperationIntent $intent):array|WP_Error
    {
        $fingerprint=self::nativeFingerprint($intent);global $wpdb;$wpdb->last_error='';
        $hash=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_api_read_native_intent_'.$fingerprint));
        if($wpdb->last_error!==''||!is_string($hash))return self::error('native_binding_missing');
        $r=$this->load('native_binding',$hash);if(is_wp_error($r))return $r;
        $wpdb->last_error='';$reverse=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_api_read_native_authorization_'.($r['authorization_hash']??'')));if($wpdb->last_error!==''||$reverse!==$fingerprint)return self::error('native_authorization_binding');
        $proof=$this->nativeProof($intent,$r['plan_hash']??'', $r['authorization_hash']??'');if(is_wp_error($proof)||($r['intent_fingerprint']??null)!==$fingerprint||($r['session_fingerprint']??null)!==$proof['session']['fingerprint']||($r['actor']??null)!==$intent->actorId)return self::error('native_binding_stale');
        $wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2','native_printify_read_bound','native_read',$fingerprint));
        if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1||json_decode($rows[0],true)!==['binding_hash'=>$hash,'intent_fingerprint'=>$fingerprint])return self::error('native_binding_audit');
        return ['plan_hash'=>$r['plan_hash'],'authorization_hash'=>$r['authorization_hash'],'request_fingerprint'=>$proof['plan']['request']['fingerprint'],'connection_binding_hash'=>$proof['plan']['connection_scope']['binding_hash']];
    }
    public function receipt(string $hash): array|WP_Error
    {
        $receipt = $this->load('receipt', $hash); if (is_wp_error($receipt)) { return $receipt; }
        global $wpdb; $wpdb->last_error = '';
        $rows = $wpdb->get_col($wpdb->prepare('SELECT context FROM ' . \DigiForge\Database\Tables::audit_log() . ' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2', 'printify_api_read_receipt', 'printify_api', $receipt['plan_hash']));
        if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) !== 1) { return self::error('receipt_audit'); }
        $anchor = json_decode($rows[0], true);
        if (!is_array($anchor) || ($anchor['receipt_sha256'] ?? null) !== $hash || ($anchor['status'] ?? null) !== $receipt['status'] || ($anchor['plan_sha256'] ?? null) !== $receipt['plan_hash']) { return self::error('receipt_audit'); }
        return $receipt;
    }
    /** Protected observation provenance, never an operational grant or native dispatch receipt.
     * Joins the original sealed authorization, consumed claim and both real producer audit anchors.
     * Expired execution authorization cannot send again; immutable observations may be read while
     * their exact original review revision remains current. No nonce or session token is returned.
     */
    public function orderSource(string $hash): array|WP_Error
    {
        return $this->readSource($hash, 'ORDER_READ', 'order_id');
    }
    /** Same protected governed-read producer join; product identity is not a business version. */
    public function productSource(string $hash): array|WP_Error
    {
        return $this->readSource($hash, 'PRODUCT_READ', 'product_id');
    }
    private function readSource(string $hash, string $operation, string $identityField): array|WP_Error
    {
        try {
            $session=\DigiForge\Connector\Printify\NativeAuthorityAdapter::session(get_current_user_id());if(is_wp_error($session))return $session;
            $r=$this->receipt($hash);if(is_wp_error($r)||($r['operation']??null)!==$operation||($r['status']??null)!=='SUCCEEDED'||($r['network_request_attempted']??null)!==true||!is_array($r['data']??null))return self::error('source_receipt');
            $p=$this->plan($r['plan_hash']);if(is_wp_error($p))return $p;
            $request=PrintifyApiRequest::prepare($operation,$p['provider_shop_id'],$p['input']);
            $expected=$this->approvalPayload($r['plan_hash']);$policy=ReviewedLocalEvidence::approved('policy',$p['shop_key'],'printify-api-read:'.$r['plan_hash']);
            if(is_wp_error($expected)||is_wp_error($policy)||Validator::canonicalJson($request)!==Validator::canonicalJson($p['request'])||Validator::canonicalJson($expected)!==Validator::canonicalJson($policy['payload'])||($r['data']['id']??null)!==($p['input'][$identityField]??null))return self::error('source_plan');
            foreach(['operation'=>'operation','endpoint_path'=>'endpoint','request_fingerprint'=>'fingerprint']as $a=>$b)if(($r[$a]??null)!==$request[$b])return self::error('source_request');
            foreach(['shop_key','integration_id','provider_shop_id']as $key)if(($r[$key]??null)!==$p[$key])return self::error('source_identity');
            global $wpdb;$wpdb->last_error='';
            $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_type,actor_id,context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type IN (%s,%s) ORDER BY id LIMIT 3','printify_api',$r['plan_hash'],'printify_api_read_reserved','printify_api_read_receipt'),ARRAY_A);
            if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==2||$rows[0]['event_type']!=='printify_api_read_reserved'||$rows[1]['event_type']!=='printify_api_read_receipt'||(int)$rows[0]['actor_id']<1||$rows[0]['actor_id']!==$rows[1]['actor_id'])return self::error('source_producer');
            $anchor=json_decode($rows[0]['context'],true);$receiptAnchor=json_decode($rows[1]['context'],true);$authHash=$anchor['authorization_sha256']??'';
            if(($anchor['plan_sha256']??null)!==$r['plan_hash']||($anchor['max_requests']??null)!==3||($receiptAnchor['receipt_sha256']??null)!==$hash)return self::error('source_reservation');
            $auth=$this->load('authorization',$authHash);if(is_wp_error($auth)||($auth['plan_hash']??null)!==$r['plan_hash']||($auth['actor']??null)!==(int)$rows[0]['actor_id']||($auth['policy_hash']??null)!==$policy['proposal_hash']||($auth['policy_revision']??null)!==$policy['revision'])return self::error('source_approval_revision');
            $wpdb->last_error='';$claim=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_api_read_claim_'.$r['plan_hash']));
            if($wpdb->last_error!==''||$claim!==$authHash)return self::error('source_claim');
            return ['receipt'=>$r,'connection_scope'=>$p['connection_scope'],'authorization_hash'=>$authHash,'approval_hash'=>$policy['proposal_hash'],'approval_revision'=>$policy['revision'],'producer_audit_id'=>(int)$rows[1]['id'],'producer_actor_id'=>(int)$rows[1]['actor_id']];
        } catch(\Throwable) {return self::error('source_unavailable');}
    }
    /** Called only by the existing controlled transport; no new HTTP client. */
    public function execute(string $hash, string $authorization, callable $sender): array|WP_Error
    {
        $durable = PrintifyProductPipeline::durableDispatchSession(); if (is_wp_error($durable)) { return $durable; }
        $plan = $this->load('plan', $hash); $auth = $this->load('authorization', $authorization);
        if (is_wp_error($plan) || is_wp_error($auth)) { return self::error('record'); }
        if (($auth['plan_hash'] ?? null) !== $hash || ($auth['actor'] ?? null) !== get_current_user_id() || ($auth['expires_at'] ?? 0) < time()) { return self::error('authorization'); }
        $current = $this->grantCurrent($hash, $plan, $auth); if (is_wp_error($current)) { return $current; }
        try { $request = PrintifyApiRequest::prepare($plan['request']['operation'], $plan['provider_shop_id'], $plan['input']); } catch (\Throwable) { return self::error('contract'); }
        if (Validator::canonicalJson($request) !== Validator::canonicalJson($plan['request']) || $request['method'] !== 'GET' || (!PrintifyApiReadEvidence::supported($request['operation'])&&!isset($plan['native_sources']))) { return self::error('read_confinement'); }
        // Claim before sending. Any interruption remains consumed, requiring a new reviewed plan.
        if (!$this->claimIndex('digiforge_api_read_claim_' . $hash, $authorization)) { return self::error('consumed'); }
        $nonce = ExecutionNonceLedger::consume($auth['nonce'], $authorization, get_current_user_id()); if (is_wp_error($nonce)) { return $nonce; }
        if (!Logger::write('printify_api_read_reserved', ['plan_sha256'=>$hash,'authorization_sha256'=>$authorization,'max_requests'=>3], 'printify_api', $hash)) { return self::error('audit'); }
        $catalogAuthority=null;
        if(($plan['native_sources']['type']??null)==='catalog'){$src=$plan['native_sources'];$catalogAuthority=(new PrintifyCatalogReadRepository())->reserveNative($src['grant_sha256'],$src['request_index'],$plan['shop_key'],$plan['integration_id'],$request['endpoint'],hash('sha256',$hash.':'.$authorization));if(is_wp_error($catalogAuthority))return $catalogAuthority;}
        $attempted = false;
        $result = (new PrintifyScopedCredentialRetriever())->consume($plan['integration_id'], function (string $token) use ($hash, $plan, $request, $sender, $auth, $catalogAuthority, &$attempted) {
            $current = $this->grantCurrent($hash, $plan, $auth); if (is_wp_error($current)) { return $current; }
            $material = PrintifyConnectionBoundary::material($plan['integration_id'], $token); if (is_wp_error($material)) { return $material; }
            $durable = PrintifyProductPipeline::durableDispatchSession(); if (is_wp_error($durable)) { return $durable; }
            $response = null;
            for ($attempt=1; $attempt<=($catalogAuthority===null?3:1); $attempt++) {
                if($attempt>1)usleep(100000*($attempt-1));
                $fresh=$this->grantCurrent($hash,$plan,$auth);if(is_wp_error($fresh))return $fresh;
                $correlation=$hash.':'.$attempt;$quota=PrintifyAccountQuota::reserve($plan['connection_scope'],$request['endpoint'],'GET',$correlation);if(is_wp_error($quota))return $quota;
                if($catalogAuthority!==null){$admitted=(new PrintifyCatalogReadRepository())->authorizeReservedAttempt($catalogAuthority,$plan['native_sources']['source']['request'],$token,true);if(is_wp_error($admitted))return $admitted;}
                $fresh=$this->grantCurrent($hash,$plan,$auth);if(is_wp_error($fresh))return $fresh;
                $attempted=true;
                try{$response=$sender('https://api.printify.com'.$request['endpoint'],['method'=>'GET','timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048577,'headers'=>['Authorization'=>'Bearer '.$token,'User-Agent'=>'DigiForge/PrintifyApi','Accept'=>'application/json']]);}catch(\Throwable){$response=new WP_Error('printify_transport_unknown');}
                $status=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);$headers=is_wp_error($response)?[]:['retry-after'=>(string)wp_remote_retrieve_header($response,'retry-after')];
                $observed=PrintifyAccountQuota::observe($plan['connection_scope'],$status,$headers,$correlation);if(is_wp_error($observed))return ['status'=>'UNKNOWN','data'=>null];
                $outcome=PrintifyApiOutcome::classify($status,$headers,$attempt,'GET');
                if($outcome['category']==='SUCCESS')break;
                // No sleeping worker or ignored cooldown: a positive Retry-After always stops this invocation.
                if(!$outcome['retry_permitted']||$outcome['category']==='RATE_LIMITED'||$headers['retry-after']!=='')return ['status'=>$outcome['category']==='DEFINITIVE_ERROR'?'FAILED':'UNKNOWN','data'=>null,'reason_code'=>$outcome['reason_code'],'retry_after'=>$outcome['bounded_retry_after']];
            }
            try {
                if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response)!==200) { return ['status'=>'UNKNOWN','data'=>null]; }
                $raw = (string)wp_remote_retrieve_body($response);
                if (strlen($raw) > 1048576) { return ['status'=>'RESPONSE_REJECTED','data'=>null]; }
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || PrintifyCatalogReadContract::containsProtectedMaterial($decoded, $token)) { return ['status'=>'RESPONSE_REJECTED','data'=>null]; }
                $data = isset($plan['native_sources'])?PrintifyNativeReadSources::project($plan,$decoded):PrintifyApiReadEvidence::project($request['operation'], $decoded, $plan['provider_shop_id'], $plan['input']);
                if($catalogAuthority!==null&&(new PrintifyCatalogReadRepository())->authorizeReservedAttempt($catalogAuthority,$plan['native_sources']['source']['request'],$token,false)!==true)return ['status'=>'UNKNOWN','data'=>null];
                if ($this->grantCurrent($hash, $plan, $auth) !== true) { return ['status'=>'UNKNOWN','data'=>null]; }
                return ['status'=>'SUCCEEDED','data'=>$data,'response_sha256'=>hash('sha256',$raw)];
            } catch (\Throwable) { return ['status'=>'UNKNOWN','data'=>null]; } finally { $token = ''; }
        }, $plan['connection_scope']);
        $status = is_wp_error($result) ? 'UNKNOWN' : ($result['status'] ?? 'UNKNOWN');
        $data = is_wp_error($result) ? null : ($result['data'] ?? null);
        $reason = is_wp_error($result) ? 'ADMISSION_OR_EVIDENCE' : ($result['reason_code'] ?? ($result['status'] ?? 'UNKNOWN'));
        $retryAfter = is_wp_error($result) ? null : ($result['retry_after'] ?? null);
        $receipt = $this->save('receipt', ['shop_key'=>$plan['shop_key'],'plan_hash'=>$hash,'integration_id'=>$plan['integration_id'],'connection_binding_hash'=>$plan['connection_scope']['binding_hash'],'operation'=>$request['operation'],'endpoint_path'=>$request['endpoint'],'request_fingerprint'=>$request['fingerprint'],'provider_shop_id'=>$plan['provider_shop_id'],'external_account_hash'=>hash('sha256',$plan['connection_scope']['external_account_reference']),'status'=>$status,'data'=>$data,'response_sha256'=>is_wp_error($result)?null:($result['response_sha256']??null),'reason_code'=>$reason,'retry_after'=>$retryAfter,'network_request_attempted'=>$attempted,'observed_at'=>gmdate('c')]);
        if (is_wp_error($receipt) || !Logger::write('printify_api_read_receipt', ['plan_sha256'=>$hash,'status'=>$status,'receipt_sha256'=>is_wp_error($receipt)?null:$receipt['hash']], 'printify_api', $hash)) { return self::error('receipt_audit'); }
        return $receipt;
    }
}
