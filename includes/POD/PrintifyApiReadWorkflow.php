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
    private static function access(string $shop): bool
    {
        return in_array($shop, ['personalized_pod','standard_pod'], true) && current_user_can('manage_digiforge_connections') && ShopAccess::authorize($shop, 'manage_digiforge_connections') === true;
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
        if (!hash_equals($seal, hash_hmac('sha256', Validator::canonicalJson($record), wp_salt('auth'))) || !self::access((string)($record['shop_key'] ?? ''))) { return self::error('access'); }
        return $record;
    }
    public function prepare(string $shop, int $integration, string $operation, array $input): array|WP_Error
    {
        if (!self::access($shop) || !PrintifyApiReadEvidence::supported($operation)) { return self::error('access_or_operation'); }
        $binding = PrintifyConnectionBoundary::context($shop, $integration, self::CAPABILITY);
        if (is_wp_error($binding)) { return $binding; }
        $i = (new \DigiForge\Integrations\Repository())->find($integration);
        if (is_wp_error($i) || !is_array($i) || ($i['provider'] ?? null) !== 'printify' || ($i['config']['shop_key'] ?? null) !== $shop || !is_string($i['config']['shop_id'] ?? null)) { return self::error('integration'); }
        try { $request = PrintifyApiRequest::prepare($operation, $i['config']['shop_id'], $input); } catch (\Throwable) { return self::error('contract'); }
        return $this->save('plan', ['schema'=>'printify-api-read-v1','shop_key'=>$shop,'integration_id'=>$integration,'provider_shop_id'=>$i['config']['shop_id'],'connection_scope'=>$binding,'request'=>$request,'input'=>$input,'created_by'=>get_current_user_id(),'created_at'=>time()]);
    }
    public function approvalPayload(string $hash): array|WP_Error
    {
        $plan = $this->load('plan', $hash); if (is_wp_error($plan)) { return $plan; }
        return ['purpose'=>'printify-api-read-v1','plan_hash'=>$hash,'request_fingerprint'=>$plan['request']['fingerprint'],'connection_binding_hash'=>$plan['connection_scope']['binding_hash'],'max_requests'=>1,'required_scope'=>$plan['request']['required_scope']];
    }
    private function current(string $hash, array $plan): true|WP_Error
    {
        if (Settings::safety_locked() || Settings::get('stop_all', true) !== false || Settings::get('printify_activation_authorized', false) !== true || !Settings::is_enabled('printify')) { return self::error('locked'); }
        if (!self::access($plan['shop_key']) || Owner::require($plan['shop_key']) !== true) { return self::error('owner'); }
        $binding = PrintifyConnectionBoundary::runtime($plan['integration_id'], self::CAPABILITY, $plan['connection_scope'], $plan['provider_shop_id']);
        if (is_wp_error($binding)) { return $binding; }
        $policy = ReviewedLocalEvidence::approved('policy', $plan['shop_key'], 'printify-api-read:' . $hash);
        $expected = $this->approvalPayload($hash);
        if (is_wp_error($policy) || is_wp_error($expected) || Validator::canonicalJson($policy['payload'] ?? null) !== Validator::canonicalJson($expected)) { return self::error('approval'); }
        return true;
    }
    public function authorize(string $hash): array|WP_Error
    {
        $plan = $this->load('plan', $hash); if (is_wp_error($plan)) { return $plan; }
        $current = $this->current($hash, $plan); if (is_wp_error($current)) { return $current; }
        return $this->save('authorization', ['shop_key'=>$plan['shop_key'],'plan_hash'=>$hash,'actor'=>get_current_user_id(),'nonce'=>bin2hex(random_bytes(24)),'expires_at'=>time()+300]);
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
    /** Called only by the existing controlled transport; no new HTTP client. */
    public function execute(string $hash, string $authorization, callable $sender): array|WP_Error
    {
        $durable = PrintifyProductPipeline::durableDispatchSession(); if (is_wp_error($durable)) { return $durable; }
        $plan = $this->load('plan', $hash); $auth = $this->load('authorization', $authorization);
        if (is_wp_error($plan) || is_wp_error($auth)) { return self::error('record'); }
        if (($auth['plan_hash'] ?? null) !== $hash || ($auth['actor'] ?? null) !== get_current_user_id() || ($auth['expires_at'] ?? 0) < time()) { return self::error('authorization'); }
        $current = $this->current($hash, $plan); if (is_wp_error($current)) { return $current; }
        try { $request = PrintifyApiRequest::prepare($plan['request']['operation'], $plan['provider_shop_id'], $plan['input']); } catch (\Throwable) { return self::error('contract'); }
        if (Validator::canonicalJson($request) !== Validator::canonicalJson($plan['request']) || $request['method'] !== 'GET' || !PrintifyApiReadEvidence::supported($request['operation'])) { return self::error('read_confinement'); }
        // Claim before sending. Any interruption remains consumed, requiring a new reviewed plan.
        if (!add_option('digiforge_api_read_claim_' . $hash, $authorization, '', false)) { return self::error('consumed'); }
        $nonce = ExecutionNonceLedger::consume($auth['nonce'], $authorization, get_current_user_id()); if (is_wp_error($nonce)) { return $nonce; }
        $account = hash('sha256', (string)$plan['connection_scope']['external_account_reference'] . '|' . $plan['connection_scope']['business_id']);
        $slot = false;
        for ($n = 0; $n < 10; $n++) { if (add_option('digiforge_api_read_rate_' . $account . '_' . intdiv(time(), 60) . '_' . $n, time(), '', false)) { $slot = true; break; } }
        if (!$slot || !Logger::write('printify_api_read_reserved', ['plan_sha256'=>$hash,'authorization_sha256'=>$authorization,'max_requests'=>1], 'printify_api', $hash)) { return self::error('rate_or_audit'); }
        $attempted = false;
        $result = (new PrintifyScopedCredentialRetriever())->consume($plan['integration_id'], function (string $token) use ($hash, $plan, $request, $sender, &$attempted) {
            $current = $this->current($hash, $plan); if (is_wp_error($current)) { return $current; }
            $material = PrintifyConnectionBoundary::material($plan['integration_id'], $token); if (is_wp_error($material)) { return $material; }
            $durable = PrintifyProductPipeline::durableDispatchSession(); if (is_wp_error($durable)) { return $durable; }
            $attempted = true;
            try { $response = $sender('https://api.printify.com' . $request['endpoint'], ['method'=>'GET','timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048576,'headers'=>['Authorization'=>'Bearer ' . $token,'User-Agent'=>'DigiForge/PrintifyApi','Accept'=>'application/json']]);
                if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) { return ['status'=>'UNKNOWN','data'=>null]; }
                $raw = (string)wp_remote_retrieve_body($response);
                if (strlen($raw) > 1048576) { return ['status'=>'RESPONSE_REJECTED','data'=>null]; }
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || PrintifyCatalogReadContract::containsProtectedMaterial($decoded, $token)) { return ['status'=>'RESPONSE_REJECTED','data'=>null]; }
                $data = PrintifyApiReadEvidence::project($request['operation'], $decoded, $plan['provider_shop_id'], $plan['input']);
                if ($this->current($hash, $plan) !== true) { return ['status'=>'UNKNOWN','data'=>null]; }
                return ['status'=>'SUCCEEDED','data'=>$data,'response_sha256'=>hash('sha256',$raw)];
            } catch (\Throwable) { return ['status'=>'UNKNOWN','data'=>null]; } finally { $token = ''; }
        }, $plan['connection_scope']);
        $status = is_wp_error($result) ? 'UNKNOWN' : ($result['status'] ?? 'UNKNOWN');
        $data = is_wp_error($result) ? null : ($result['data'] ?? null);
        $receipt = $this->save('receipt', ['shop_key'=>$plan['shop_key'],'plan_hash'=>$hash,'integration_id'=>$plan['integration_id'],'connection_binding_hash'=>$plan['connection_scope']['binding_hash'],'operation'=>$request['operation'],'status'=>$status,'data'=>$data,'network_request_attempted'=>$attempted,'observed_at'=>gmdate('c')]);
        if (is_wp_error($receipt) || !Logger::write('printify_api_read_receipt', ['plan_sha256'=>$hash,'status'=>$status,'receipt_sha256'=>is_wp_error($receipt)?null:$receipt['hash']], 'printify_api', $hash)) { return self::error('receipt_audit'); }
        return $receipt;
    }
}
