<?php
declare(strict_types=1);

namespace DigiForge\Connector\Printify;

use DigiForge\Listings\Validator;

/** Verify a signed local-test readback. This never grants operational admission. */
final class NativeWitnessReceipt
{
    public static function verify(string $raw, array $pins, array $intent, array $record, string $account): array|\WP_Error
    {
        try {
            if (strlen($raw) > 65536 || !function_exists('sodium_crypto_sign_verify_detached')) return self::deny();
            $envelope = json_decode($raw,true,24,JSON_THROW_ON_ERROR);
            if (Validator::canonicalJson($envelope) !== $raw) return self::deny();
            if (hash_equals($pins['issuer_key'],$pins['witness_key'])) return self::deny();
            $outer = self::signed($envelope,$pins['issuer_id'],$pins['issuer_key'],'DF_INDEPENDENT_CHECKPOINT_AUTHORITY_1','issuer.response.v1');
            $inner = self::signed($outer['witness_attestation'],$pins['witness_key_id'],$pins['witness_key'],
                'DF_INDEPENDENT_RECOVERY_WITNESS_1','witness.response.v1');
            unset($outer['witness_attestation'],$outer['key_id'],$inner['key_id']);
            if ($outer !== $inner) return self::deny();

            $now = time();
            $correlation = $record['witness_correlation'];
            $request = $outer['request_bindings'];
            $body = $request['method_body'];
            $operation = $outer['result']['operation'];
            $event = $operation['admission_event'];
            $binding = $record['binding'];
            foreach (['integration_id','credential_reference','shop_id','shop_key','environment'] as $field) {
                if ($request[$field] !== $binding[$field]) return self::deny();
            }
            foreach (['operation_id','payload_fingerprint','approval_reference','approval_revision','policy_revision','product_version'] as $field) {
                if ($operation[$field] !== $intent[$field]) return self::deny();
            }
            if (NativePortContract::hash($intent) !== $correlation['intent_digest']
                || $request['method'] !== 'readOperation' || $request['message_purpose'] !== 'operation.readback.request.v1'
                || $request['version'] !== '1.0.0' || $outer['version'] !== '1.0.0'
                || $request['provider'] !== 'printify' || $request['provider_account_reference'] !== $account
                || $request['authority_id'] !== $pins['authority_id'] || $outer['authority_id'] !== $pins['authority_id']
                || $request['installation_id'] !== $pins['installation_id'] || $outer['witness_id'] !== $pins['witness_id']
                || $request['deployment_digest'] !== $pins['deployment_digest'] || $request['workload_id'] !== $pins['workload_id']
                || $outer['root_set_revision'] !== $pins['root_set_revision']
                || $body['original_request_id'] !== $correlation['request_id'] || $body['original_challenge'] !== $correlation['challenge']
                || $body['operation_id'] !== $binding['operation_id'] || $body['payload_fingerprint'] !== $binding['payload_fingerprint']
                || $body['expected_epoch'] !== $intent['expected_epoch'] || $outer['checkpoint_epoch'] !== $intent['expected_epoch']
                || $operation['checkpoint_epoch'] !== $intent['expected_epoch']
                || $body['expected_generation'] !== $intent['expected_generation'] || $outer['generation'] !== $intent['expected_generation']
                || $operation['generation'] !== $intent['expected_generation']
                || $body['retained_witness_head'] !== $pins['retained_head'] || $body['retained_witness_sequence'] !== $pins['retained_sequence']
                || $outer['result']['method'] !== 'readOperation' || $outer['result']['schema'] !== 'DF_NATIVE_AUTHORITY_RESULT_1'
                || $outer['disposition'] !== 'ADMITTED' || $outer['result']['state'] !== 'ADMITTED' || $operation['state'] !== 'ADMITTED'
                || $operation['reservation_only'] !== true || $outer['result']['external_execution_authorized'] !== false
                || !is_int($outer['issued_at']) || !is_int($outer['expires_at']) || $outer['issued_at'] > $now
                || $outer['expires_at'] <= $now || $outer['expires_at']-$outer['issued_at'] > 30
                || !is_int($outer['witness_sequence']) || $outer['witness_sequence'] < $pins['retained_sequence']
                || !is_int($event['sequence']) || $event['sequence'] <= $pins['retained_sequence'] || $event['sequence'] > $outer['witness_sequence']
                || ($outer['witness_sequence'] === $event['sequence'] && $outer['witness_head'] !== $event['head'])) return self::deny();

            $receipt = ['receipt_digest'=>hash('sha256',$raw),'allocation_id'=>$operation['allocation_id'],
                'allocation_revision'=>$operation['allocation_revision'],'epoch'=>$operation['checkpoint_epoch'],
                'generation'=>$operation['generation'],'witness_head'=>$event['head'],'witness_sequence'=>$event['sequence']];
            return self::valid($receipt) ? $receipt : self::deny();
        } catch (\Throwable) {
            return self::deny();
        }
    }

    private static function signed(array $value,string $id,string $key,string $domain,string $purpose): array
    {
        if (array_keys($value)!==['key_id','message','signature'] || $value['key_id']!==$id) throw new \InvalidArgumentException();
        $public = sodium_base642bin($key,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $signature = sodium_base642bin($value['signature'],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (!sodium_crypto_sign_verify_detached($signature,$domain."\n".$purpose."\n".Validator::canonicalJson($value['message']),$public)) {
            throw new \InvalidArgumentException();
        }
        return $value['message'];
    }

    public static function valid(array $receipt): bool
    {
        if (array_keys($receipt)!==['receipt_digest','allocation_id','allocation_revision','epoch','generation','witness_head','witness_sequence']) return false;
        foreach (['receipt_digest','epoch','witness_head'] as $field) {
            if (!is_string($receipt[$field]) || !preg_match('/^[a-f0-9]{64}$/D',$receipt[$field])) return false;
        }
        foreach (['allocation_revision','generation','witness_sequence'] as $field) {
            if (!is_int($receipt[$field]) || $receipt[$field]<1) return false;
        }
        return is_string($receipt['allocation_id']) && preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$receipt['allocation_id'])===1;
    }

    private static function deny(): \WP_Error
    {
        return NativeFailure::error('held_witness_receipt_unverified');
    }
}
