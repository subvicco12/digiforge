<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

/** Manual Printify GET with a separately configured, held-by-default server-side profile. */
final class DirectGet
{
    private const ORIGIN = 'https://api.printify.com';
    private const LIMIT = 262144;
    private const FIXTURE = 'DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE';

    private static function fixture(): bool
    {
        return !defined('DIGIFORGE_PRINTIFY_MANAGED_DIR') && defined(self::FIXTURE) && constant(self::FIXTURE) === true
            && wp_get_environment_type() === 'local'
            && defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL === true
            && !defined('WP_ACCESSIBLE_HOSTS');
    }

    public static function selected(): bool
    {
        return defined('DIGIFORGE_PRINTIFY_MANAGED_DIR') || self::fixture() || DirectReadSecret::configured();
    }

    public static function synthetic(): bool
    {
        return self::fixture();
    }

    private static function configuration(): ?array
    {
        if (defined('DIGIFORGE_PRINTIFY_MANAGED_DIR')) return null;
        if (!self::fixture()) return DirectReadSecret::read();
        $scope = defined('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE') ? DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE : null;
        $token = defined('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT') ? DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT : null;
        $gates = apply_filters('digiforge_printify_direct_fixture_gates', null);
        if (!is_array($scope) || count($scope)!==4 || !is_string($token)
            || !preg_match('/^fixture-[a-zA-Z0-9_-]{8,80}$/D',$token) || !has_filter('digiforge_printify_direct_fixture_response')
            || !is_array($gates)) return null;
        return ['scope'=>$scope,'token'=>$token,'stop_all'=>$gates['stop_all'] ?? true,
            'external_lock'=>$gates['external_lock'] ?? true,'release_hold'=>false];
    }

    private static function open(?array $configuration): bool
    {
        return $configuration!==null && $configuration['stop_all']===false
            && $configuration['external_lock']===false && $configuration['release_hold']===false;
    }

    private static function configuredScope(array $configured,array $scope): bool
    {
        foreach (['account','shop','integration','custody'] as $field) {
            if (!is_string($configured[$field] ?? null) || $configured[$field]!==($scope[$field] ?? null)) return false;
        }
        return true;
    }

    public static function status(array $scope = []): string
    {
        if (defined('DIGIFORGE_PRINTIFY_MANAGED_DIR')) return 'DIRECT_READ_HELD';
        if (!self::selected()) return 'LIVE_READ_HELD';
        $config=self::configuration();
        if ($config===null || !self::configuredScope($config['scope'],$scope)) return 'DIRECT_CONFIGURATION_UNAVAILABLE';
        if (!self::open($config)) return 'DIRECT_READ_HELD';
        return self::fixture() ? 'SYNTHETIC_DIRECT_READY' : 'DIRECT_PROFILE_CONFIGURED';
    }

    public static function gatesOpen(): bool
    {
        if (self::fixture()) {
            $gates = apply_filters('digiforge_printify_direct_fixture_gates', null);
            return is_array($gates) && ($gates['stop_all'] ?? null) === false
                && ($gates['external_lock'] ?? null) === false;
        }
        return self::open(self::configuration());
    }

    private static function retryAfter(mixed $value, int $now): ?int
    {
        if (!is_string($value)) return null;
        if (preg_match('/^[0-9]{1,6}$/D',$value)) return min(3600,(int)$value);
        if (strlen($value) !== 29 || str_contains($value, "\0")) return null;
        $format = 'D, d M Y H:i:s \G\M\T';
        $date = \DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('UTC'));
        if ($date === false || $date->format($format) !== $value) return null;
        return min(3600,max(0,$date->getTimestamp()-$now));
    }

    public static function execute(array $request, \Closure $reserve): array
    {
        $base = ['state'=>'BLOCKED','reason'=>'DIRECT_READ_HELD','source'=>null,'retry_permitted'=>false,
            'provider_execution_authorized'=>false];
        try {
            $fixture = self::fixture();
            $config = self::configuration();
            if (!self::open($config)
                || $request !== ProtectedGet::request($request['scope'],$request['operation'],$request['selection'])
                || !self::configuredScope($config['scope'],$request['scope'])) {
                return $base;
            }
            $linked=$request['operation']==='LINKED_IMAGE_METADATA_READ';
            if ($linked && !ProtectedGetWorkspace::linkedArtwork($request['scope'],$request['selection'],true)) return $base;
            if (!$reserve()) {
                return ['reason'=>'LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE'] + $base;
            }
            if ($config !== self::configuration()
                || ($linked && !ProtectedGetWorkspace::linkedArtwork($request['scope'],$request['selection'],true))) return $base;
            $pat = $config['token'];

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
            if ($fixture) add_filter('pre_http_request',$intercept,PHP_INT_MAX,3);
            try {
                $reply = wp_safe_remote_get($url,['method'=>'GET','sslverify'=>true,'reject_unsafe_urls'=>true,
                    'redirection'=>0,'timeout'=>5,'limit_response_size'=>self::LIMIT,
                    'headers'=>['Authorization'=>'Bearer '.$pat,'Accept'=>'application/json']]);
            } finally {
                if ($fixture) remove_filter('pre_http_request',$intercept,PHP_INT_MAX);
            }

            if ($config !== self::configuration()
                || ($linked && !ProtectedGetWorkspace::linkedArtwork($request['scope'],$request['selection'],true))) return $base;
            $unavailable = ['state'=>'UNAVAILABLE','reason'=>'DIRECT_READ_UNAVAILABLE'] + $base;
            if (($fixture && !$intercepted) || !is_array($reply)) return $unavailable;
            $status = wp_remote_retrieve_response_code($reply);
            if ($status === 429) {
                $seconds = wp_remote_retrieve_header($reply,'retry-after');
                $seconds = self::retryAfter($seconds, time());
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
            return ['state'=>'OBSERVED','reason'=>$fixture ? 'SYNTHETIC_DIRECT_OBSERVED' : 'DIRECT_READ_OBSERVED',
                'observed_at'=>time(),'source'=>$fixture ? 'SYNTHETIC_DIRECT_PRINTIFY' : 'PRINTIFY_HTTPS_GET','response_sha256'=>hash('sha256',$body),'data'=>$data] + $base;
        } catch (\Throwable) {
            return ['state'=>'UNAVAILABLE','reason'=>'DIRECT_READ_UNAVAILABLE'] + $base;
        }
    }
}
