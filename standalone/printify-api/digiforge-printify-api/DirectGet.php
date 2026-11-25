<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

/** Manual Printify GET. The offline candidate permits only explicitly intercepted fixture I/O. */
final class DirectGet
{
    private const ORIGIN = 'https://api.printify.com';
    private const LIMIT = 262144;
    private const FIXTURE = 'DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE';

    public static function selected(): bool
    {
        return defined(self::FIXTURE) && constant(self::FIXTURE) === true
            && wp_get_environment_type() === 'local'
            && defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL === true
            && !defined('WP_ACCESSIBLE_HOSTS');
    }

    private static function configuredScope(array $scope): bool
    {
        if (!defined('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE') || !is_array(DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE)) {
            return false;
        }
        $configured = DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE;
        foreach (['account','shop','integration','custody'] as $field) {
            if (!is_string($configured[$field] ?? null) || $configured[$field] !== ($scope[$field] ?? null)) {
                return false;
            }
        }
        return count($configured) === 4;
    }

    public static function status(array $scope = []): string
    {
        if (!self::selected()) {
            return 'LIVE_READ_HELD';
        }
        if (!self::configuredScope($scope) || self::credential() === null || !has_filter('digiforge_printify_direct_fixture_response')) {
            return 'FIXTURE_CONFIGURATION_UNAVAILABLE';
        }
        return self::gatesOpen() ? 'SYNTHETIC_DIRECT_READY' : 'SYNTHETIC_DIRECT_HELD';
    }

    public static function gatesOpen(): bool
    {
        if (!self::selected()) {
            return false;
        }
        $gates = apply_filters('digiforge_printify_direct_fixture_gates', null);
        return is_array($gates) && ($gates['stop_all'] ?? null) === false && ($gates['external_lock'] ?? null) === false;
    }

    private static function credential(): ?string
    {
        // A separately approved production release must supply and qualify its server-side secret resolver.
        $token = defined('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT') ? DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT : null;
        return is_string($token) && preg_match('/^fixture-[a-zA-Z0-9_-]{8,80}$/D',$token) ? $token : null;
    }

    public static function execute(array $request, \Closure $reserve): array
    {
        $base = ['state'=>'BLOCKED','reason'=>'DIRECT_READ_HELD','source'=>null,'retry_permitted'=>false,
            'provider_execution_authorized'=>false];
        try {
            if (!self::selected() || !self::gatesOpen() || !has_filter('digiforge_printify_direct_fixture_response')
                || $request !== ProtectedGet::request($request['scope'],$request['operation'],$request['selection'])
                || !self::configuredScope($request['scope']) || ($pat=self::credential()) === null) {
                return $base;
            }
            if (!$reserve()) {
                return ['reason'=>'LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE'] + $base;
            }
            if (!self::gatesOpen()) {
                return $base;
            }

            $url = self::ORIGIN.$request['path'];
            $intercepted = false;
            $intercept = static function (mixed $prior,array $args,string $target) use ($url,$request,$pat,&$intercepted): mixed {
                if ($target !== $url) return $prior;
                if ($intercepted || ($args['method'] ?? null) !== 'GET' || !empty($args['body'])
                    || ($args['headers']['Authorization'] ?? null) !== 'Bearer '.$pat
                    || ($args['sslverify'] ?? null) !== true || ($args['reject_unsafe_urls'] ?? null) !== true
                    || ($args['redirection'] ?? null) !== 0) {
                    return new \WP_Error('direct_read_fixture_denied');
                }
                $intercepted = true;
                $mock = apply_filters('digiforge_printify_direct_fixture_response', null, $request);
                return is_array($mock) ? $mock : new \WP_Error('direct_read_fixture_unavailable');
            };
            add_filter('pre_http_request',$intercept,PHP_INT_MAX,3);
            try {
                $reply = wp_safe_remote_get($url,['method'=>'GET','sslverify'=>true,'reject_unsafe_urls'=>true,
                    'redirection'=>0,'timeout'=>5,'limit_response_size'=>self::LIMIT,
                    'headers'=>['Authorization'=>'Bearer '.$pat,'Accept'=>'application/json']]);
            } finally {
                remove_filter('pre_http_request',$intercept,PHP_INT_MAX);
            }

            if (!self::gatesOpen()) return $base;
            $unavailable = ['state'=>'UNAVAILABLE','reason'=>'DIRECT_READ_UNAVAILABLE'] + $base;
            if (!$intercepted || !is_array($reply)) return $unavailable;
            $status = wp_remote_retrieve_response_code($reply);
            if ($status === 429) {
                $seconds = wp_remote_retrieve_header($reply,'retry-after');
                $seconds = is_string($seconds) && preg_match('/^[0-9]{1,6}$/D',$seconds) ? min(3600,(int)$seconds) : null;
                return ['reason'=>'PROVIDER_RATE_LIMITED','retry_after_seconds'=>$seconds] + $unavailable;
            }
            if ($status === 401 || $status === 403) {
                return ['reason'=>'PROVIDER_SCOPE_OR_CREDENTIAL_DENIED'] + $unavailable;
            }
            if ($status !== 200 || !preg_match('/^application\/json(?:\s*;|$)/i',(string)wp_remote_retrieve_header($reply,'content-type'))) {
                return $unavailable;
            }
            $body = wp_remote_retrieve_body($reply);
            if (strlen($body) > self::LIMIT || str_contains($body,$pat)) return $unavailable;
            $data = ProtectedGet::project($request,$body);
            if ($request['operation'] === 'ORDER_READ') {
                $raw = json_decode($body,true,24,JSON_THROW_ON_ERROR);
                foreach ($data['shipments'] as $index=>&$shipment) {
                    $number = $raw['shipments'][$index]['number'] ?? null;
                    if (is_string($number) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$number)
                        && hash_equals($shipment['tracking_hash'],hash('sha256',$number))) {
                        $shipment['tracking_reference'] = $number;
                    }
                }
                unset($shipment);
            }
            if (str_contains(json_encode($data,JSON_THROW_ON_ERROR),$pat)) return $unavailable;
            return ['state'=>'OBSERVED','reason'=>'SYNTHETIC_DIRECT_OBSERVED','observed_at'=>time(),
                'source'=>'SYNTHETIC_DIRECT_PRINTIFY','response_sha256'=>hash('sha256',$body),'data'=>$data] + $base;
        } catch (\Throwable) {
            return ['state'=>'UNAVAILABLE','reason'=>'DIRECT_READ_UNAVAILABLE'] + $base;
        }
    }
}
