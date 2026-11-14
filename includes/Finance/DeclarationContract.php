<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use InvalidArgumentException;

/** Internal declaration, never a provider payload/parser or authenticity certification. */
final class DeclarationContract
{
    public const SCHEMA = 'digiforge-finance-declaration-v1';
    public const SHOPS = ['digital', 'personalized_pod', 'standard_pod', 'jewelry'];
    public const CATEGORIES = ['REVENUE'=>'REVENUE', 'REFUND'=>'REFUND_RESERVE', 'MARKETPLACE_FEE'=>'PLATFORM_FEE',
        'PAYMENT_FEE'=>'PAYMENT_FEE', 'COGS'=>'COGS', 'SHIPPING'=>'SHIPPING_COST', 'ADS'=>'AD_SPEND',
        'AI'=>'OTHER_EXPENSE', 'TAX_COLLECTED'=>'TAX_COLLECTED', 'TAX_EXPENSE'=>'TAX_EXPENSE',
        'OTHER_INCOME'=>'OTHER_INCOME', 'OTHER_EXPENSE'=>'OTHER_EXPENSE', 'ADJUSTMENT'=>'ADJUSTMENT'];

    public static function normalize(array $input): array
    {
        Validator::structured($input);
        self::keys($input, ['schema','provider','environment','external_transaction_id','revision','previous_revision_hash',
            'category','currency','amount','base_currency','base_amount','fx_snapshot_id','occurred_at','observed_at','source','scope','reversal_of_hash']);
        if ($input['schema'] !== self::SCHEMA || !in_array($input['provider'], ['etsy','printify','gelato','ai','manual'], true)
            || !is_string($input['environment']) || Validator::environment($input['environment']) !== $input['environment']
            || !is_string($input['category']) || !isset(self::CATEGORIES[$input['category']])) throw new InvalidArgumentException('Unsupported declaration identity/category.');
        self::token($input['external_transaction_id']);
        self::identity($input['revision'], false); self::identity($input['fx_snapshot_id'], true);
        self::digest($input['previous_revision_hash'], $input['revision'] === 1);
        if ($input['revision'] === 1 && $input['previous_revision_hash'] !== '') throw new InvalidArgumentException('First revision cannot have a predecessor.');
        self::digest($input['reversal_of_hash'], true);
        foreach (['currency','base_currency'] as $key) if (!is_string($input[$key]) || preg_match('/^[A-Z]{3}$/D', $input[$key]) !== 1) throw new InvalidArgumentException('Canonical currency required.');
        $input['amount'] = ExactDecimal::money($input['amount']); $input['base_amount'] = ExactDecimal::money($input['base_amount']);
        if ($input['currency'] === $input['base_currency'] && ($input['fx_snapshot_id'] !== 0 || $input['amount'] !== $input['base_amount'])) throw new InvalidArgumentException('Same-currency declaration must preserve its exact amount.');
        if ($input['currency'] !== $input['base_currency'] && $input['fx_snapshot_id'] < 1) throw new InvalidArgumentException('Foreign currency requires an immutable FX snapshot.');
        self::timestamp($input['occurred_at']); self::timestamp($input['observed_at']);
        if ($input['observed_at'] < $input['occurred_at']) throw new InvalidArgumentException('Observation cannot precede the declared occurrence.');
        if (!is_array($input['source']) || !is_array($input['scope'])) throw new InvalidArgumentException('Typed source and scope required.');
        $input['source']=self::source($input['source']);
        self::keys($input['scope'], ['shop_key','business_id','store_id','order_id','product_version_id','listing_id','provider_mapping_id','variant_ref']);
        if (!in_array($input['scope']['shop_key'], self::SHOPS, true)) throw new InvalidArgumentException('Explicit shop scope required.');
        foreach (['business_id','store_id','order_id','product_version_id','listing_id','provider_mapping_id'] as $field) self::identity($input['scope'][$field], !in_array($field, ['business_id','store_id'], true));
        if ($input['scope']['variant_ref'] !== '') self::token($input['scope']['variant_ref']);
        if ($input['scope']['variant_ref'] !== '' && $input['scope']['provider_mapping_id'] < 1) throw new InvalidArgumentException('Variant declaration requires an explicit local mapping.');
        $input['accounting_convention'] = 'signed-bucket-v1';
        $input['authenticity'] = 'UNKNOWN';
        $input['declaration_hash'] = Validator::hash($input);
        return $input;
    }

    public static function fromStored(array $stored): array
    {
        $hash = $stored['declaration_hash'] ?? null;
        $convention = $stored['accounting_convention'] ?? null; $authenticity = $stored['authenticity'] ?? null;
        unset($stored['declaration_hash'], $stored['accounting_convention'], $stored['authenticity']);
        $normalized = self::normalize($stored);
        if (!is_string($hash) || !hash_equals($normalized['declaration_hash'], $hash) || $convention !== 'signed-bucket-v1' || $authenticity !== 'UNKNOWN') throw new InvalidArgumentException('Declaration hash/convention is invalid.');
        return $normalized;
    }

    public static function identityKey(array $declaration): string
    {
        return 'finance_decl:' . Validator::hash(['provider'=>$declaration['provider'], 'environment'=>$declaration['environment'],
            'store_id'=>$declaration['scope']['store_id'], 'external_transaction_id'=>$declaration['external_transaction_id']]) . ':';
    }

    public static function source(array $source):array
    {
        self::keys($source,['kind','document_ref','document_hash']);
        if($source['kind']!=='DECLARATION')throw new InvalidArgumentException('Only DECLARATION source evidence is supported.');
        self::token($source['document_ref']);self::digest($source['document_hash'],false);
        return $source;
    }

    public static function timestamp(mixed $value): string
    {
        if (!is_string($value)||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$value)!==1) throw new InvalidArgumentException('UTC timestamp string required.');
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || \DateTimeImmutable::getLastErrors() !== false || $time->format('Y-m-d\TH:i:s\Z') !== $value) throw new InvalidArgumentException('Canonical UTC timestamp required.');
        return $value;
    }

    private static function keys(array $input, array $expected): void
    {
        $keys = array_keys($input); sort($keys); sort($expected);
        if ($keys !== $expected) throw new InvalidArgumentException('Declaration fields must match the versioned contract exactly.');
    }
    private static function token(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D', $value) !== 1) throw new InvalidArgumentException('Bounded explicit evidence reference required.');
    }
    private static function identity(mixed $value, bool $zero): void
    {
        if (!is_int($value) || $value < ($zero ? 0 : 1)) throw new InvalidArgumentException('Explicit integer scope/revision identity required.');
    }
    private static function digest(mixed $value, bool $empty): void
    {
        if ($empty && $value === '') return;
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) throw new InvalidArgumentException('SHA-256 evidence reference required.');
    }
}
