<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi\Gateway;

use DigiForgePrintifyApi\ProtectedGet;

/** Offline reference. The process-local quota and replay state are fixture-only. */
final class FixtureReadGateway
{
    private const MAX_ADMISSIONS = 10;
    private array $consumed = [];

    public function __construct(
        private readonly string $verificationKey,
        private readonly \Closure $policy,
        private readonly \Closure $fixture
    ) {}

    public function read(array $request, array $grant, string $signature, int $now): array
    {
        $denied = ['state'=>'DENIED','reason'=>'INVALID_OR_HELD_GRANT'] + ProtectedGet::denied();
        try {
            $expected = ProtectedGet::request($request['scope'] ?? [], $request['operation'] ?? '', $request['selection'] ?? []);
            $digest = ProtectedGet::digest($expected);
            if ($request !== $expected || count($grant) !== 7
                || ($grant['issuer'] ?? null) !== 'fixture-custodian'
                || ($grant['audience'] ?? null) !== ProtectedGet::VERSION
                || ($grant['request_digest'] ?? null) !== $digest
                || !is_int($grant['issued_at'] ?? null) || !is_int($grant['expires_at'] ?? null)
                || $grant['issued_at'] > $now || $grant['expires_at'] <= $now
                || $grant['expires_at'] - $grant['issued_at'] > 60
                || !is_int($grant['policy_revision'] ?? null) || $grant['policy_revision'] < 1
                || !is_string($grant['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $grant['id'])
                || !preg_match('/^[a-f0-9]{128}$/D', $signature)
                || !sodium_crypto_sign_verify_detached(hex2bin($signature), ProtectedGet::digest($grant), $this->verificationKey)
                || isset($this->consumed[$grant['id']]) || !$this->allowed($grant)) {
                return $denied;
            }
            if (count($this->consumed) >= self::MAX_ADMISSIONS) {
                return ['state'=>'UNAVAILABLE','reason'=>'FIXTURE_QUOTA_EXHAUSTED'] + ProtectedGet::denied();
            }
            $this->consumed[$grant['id']] = true;
            $result = ($this->fixture)($expected['scope'], 'GET', $expected['path']);
            if (!$this->allowed($grant)) {
                return $denied;
            }
            if (($result['status'] ?? null) !== 200 || !is_string($result['body'] ?? null)) {
                return ['state'=>'UNAVAILABLE','reason'=>($result['status'] ?? null) === 429 ? 'RATE_LIMITED' : 'FIXTURE_UNAVAILABLE']
                    + ProtectedGet::denied();
            }
            $data = ProtectedGet::project($expected, $result['body']);
            return ['state'=>'OBSERVED','reason'=>null,'source'=>'SYNTHETIC_PRINTIFY','observed_at'=>$now,
                'request_digest'=>$digest,'grant_id'=>$grant['id'],'response_sha256'=>hash('sha256', $result['body']),
                'data'=>$data] + ProtectedGet::denied();
        } catch (\Throwable) {
            return ['state'=>'UNAVAILABLE','reason'=>'INVALID_OR_UNAVAILABLE_EVIDENCE'] + ProtectedGet::denied();
        }
    }

    private function allowed(array $grant): bool
    {
        $policy = ($this->policy)();
        return ($policy['stop_all'] ?? null) === false && ($policy['external_lock'] ?? null) === false
            && ($policy['release_hold'] ?? null) === false && ($policy['revision'] ?? null) === $grant['policy_revision']
            && is_array($policy['revoked'] ?? null) && array_is_list($policy['revoked'])
            && !in_array($grant['id'], $policy['revoked'], true);
    }
}
