<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi\Gateway;

use DigiForgePrintifyApi\ProtectedGet;

/** Offline reference with a single-host, lock-serialized admission journal. */
final class FixtureReadGateway
{
    private const MAX_ADMISSIONS = 10;

    public function __construct(
        private readonly string $verificationKey,
        private readonly \Closure $policy,
        private readonly \Closure $fixture,
        private readonly string $journal
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
                || !sodium_crypto_sign_verify_detached(hex2bin($signature), ProtectedGet::digest($grant), $this->verificationKey)) {
                return $denied;
            }
            $admission = $this->admit($grant);
            if ($admission === 'DENIED') {
                return $denied;
            }
            if ($admission !== 'ADMITTED') {
                return ['state'=>'UNAVAILABLE','reason'=>$admission] + ProtectedGet::denied();
            }
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

    public function exchange(array $request, array $grant, string $signature, int $now, string $replyKey): array
    {
        $receipt = $this->read($request, $grant, $signature, $now);
        $receipt = ['schema'=>ProtectedGet::REPLY_VERSION,'request_digest'=>ProtectedGet::digest($request),
            'grant_id'=>$grant['id'] ?? null,'issued_at'=>$now] + $receipt;
        return ['receipt'=>$receipt, 'signature'=>bin2hex(sodium_crypto_sign_detached(
            ProtectedGet::digest($receipt), $replyKey))];
    }

    private function admit(array $grant): string
    {
        $lock = @fopen($this->journal.'.lock', 'c+b');
        if ($lock === false) {
            return 'ADMISSION_STORE_UNAVAILABLE';
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return 'ADMISSION_STORE_BUSY';
            }
            $raw = is_file($this->journal) ? @file_get_contents($this->journal, false, null, 0, 4097) : false;
            if ($raw === false) {
                return 'ADMISSION_STORE_UNAVAILABLE';
            }
            $entries = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
            if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_ADMISSIONS) {
                return 'ADMISSION_STORE_UNAVAILABLE';
            }
            foreach ($entries as $id) {
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                    return 'ADMISSION_STORE_UNAVAILABLE';
                }
            }
            if (!$this->allowed($grant) || in_array($grant['id'], $entries, true)) {
                return 'DENIED';
            }
            if (count($entries) >= self::MAX_ADMISSIONS) {
                return 'FIXTURE_QUOTA_EXHAUSTED';
            }
            $entries[] = $grant['id'];
            $next = json_encode($entries, JSON_THROW_ON_ERROR);
            $temp = $this->journal.'.'.bin2hex(random_bytes(12));
            $file = @fopen($temp, 'x+b');
            if ($file === false) {
                return 'ADMISSION_STORE_UNAVAILABLE';
            }
            try {
                if (!chmod($temp, 0600) || fwrite($file, $next) !== strlen($next)
                    || !fflush($file) || !fsync($file) || !$this->allowed($grant)
                    || !rename($temp, $this->journal)) {
                    return 'ADMISSION_STORE_UNAVAILABLE';
                }
                return 'ADMITTED';
            } finally {
                fclose($file);
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        } finally {
            fclose($lock);
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
