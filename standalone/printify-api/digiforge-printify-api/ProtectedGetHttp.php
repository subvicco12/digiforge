<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

/** HTTP wire contract exercised through a short-circuited WordPress fixture. */
final class ProtectedGetHttp
{
    private const URL = 'https://protected-get.example.invalid/read';
    private const LIMIT = 524288;

    public static function exchange(array $request, string $correlation): ?array
    {
        if (!defined('DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE') || DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE !== true
            || wp_get_environment_type() !== 'local' || !defined('WP_HTTP_BLOCK_EXTERNAL')
            || WP_HTTP_BLOCK_EXTERNAL !== true || defined('WP_ACCESSIBLE_HOSTS')
            || !preg_match('/^[a-f0-9]{32}$/D',$correlation)) {
            return null;
        }

        $wire = ['request'=>$request,'correlation'=>$correlation];
        try {
            $body = json_encode($wire, JSON_THROW_ON_ERROR);
            if (strlen($body) > 32768) {
                return null;
            }
            $intercepted = false;
            $intercept = static function (mixed $prior, array $args, string $url) use ($wire,$body,&$intercepted): mixed {
                if ($url !== self::URL) {
                    return $prior;
                }
                if ($intercepted || ($args['body'] ?? null) !== $body
                    || ($args['method'] ?? null) !== 'POST' || ($args['redirection'] ?? null) !== 0
                    || ($args['sslverify'] ?? null) !== true || ($args['reject_unsafe_urls'] ?? null) !== true) {
                    return new \WP_Error('protected_get_fixture_held');
                }
                $intercepted = true;
                // Always stop before name resolution or a transport. The harness owns the response.
                $result = apply_filters('digiforge_printify_protected_get_http_fixture', null, $wire);
                return is_array($result) ? $result : new \WP_Error('protected_get_fixture_unavailable');
            };
            add_filter('pre_http_request', $intercept, PHP_INT_MAX, 3);
            try {
                $reply = wp_safe_remote_post(self::URL, [
                    'method'=>'POST','redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,
                    'timeout'=>5,'limit_response_size'=>self::LIMIT,
                    'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],'body'=>$body,
                ]);
            } finally {
                remove_filter('pre_http_request', $intercept, PHP_INT_MAX);
            }
            if (!$intercepted || !is_array($reply) || wp_remote_retrieve_response_code($reply) !== 200
                || !preg_match('/^application\/json(?:\s*;|$)/i', wp_remote_retrieve_header($reply,'content-type'))) {
                return null;
            }
            $payload = wp_remote_retrieve_body($reply);
            if (strlen($payload) > self::LIMIT) {
                return null;
            }
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
