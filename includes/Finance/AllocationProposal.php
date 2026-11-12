<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use InvalidArgumentException;

/** Pure exact explicit allocation proposal. No persistence, review authority or financial finality. */
final class AllocationProposal
{
    private const TARGET_KEYS = ['shop_key','business_id','store_id','order_id','order_line_item_id',
        'product_id','product_version_id','listing_id','provider_mapping_id','variant_ref','environment','currency'];

    /**
     * History must be complete, current, repository-reference-validated declaration evidence,
     * including related reversal histories. Hashes provide integrity only, never authenticity
     * or review. Callers cannot substitute input arrays for the independent references adapter.
     */
    public static function build(array $history, string $sourceHash, array $explicitLines,
        AllocationTargetReferences $references, ?array $originalPacket = null): array
    {
        self::digest($sourceHash);
        if (!array_is_list($history) || $history === [] || count($history) > 200) self::fail('Complete bounded source history required.');
        $ids=[];
        foreach ($history as &$entry) {
            if (!is_array($entry) || !is_int($entry['ledger_id'] ?? null) || $entry['ledger_id'] < 1
                || isset($ids[$entry['ledger_id']]) || !is_array($entry['declaration'] ?? null)) self::fail('Unique stored source identities required.');
            $ids[$entry['ledger_id']]=true;
            $entry['declaration']=DeclarationContract::fromStored($entry['declaration']);
        }
        unset($entry);
        // No accounting period/finality inference: only contiguous current immutable revision matching.
        $matching=DeclarationMatcher::match($history,'9999-12-31');
        if (in_array($matching['state'],['CONFLICT','MISSING','UNKNOWN'],true)) self::fail('Current complete compatible source history required.');
        $current=null;
        foreach ($matching['active'] as $entry) if ($entry['declaration']['declaration_hash'] === $sourceHash) $current=$entry;
        if ($current === null) self::fail('Source is missing or superseded.');
        $source=$current['declaration'];
        if ($source['reversal_of_hash'] === '') {
            if ($originalPacket !== null) self::fail('Ordinary allocation cannot claim a reversal packet.');
            foreach ($matching['rows'] as $row) if ($row['declaration_hash'] === $sourceHash && in_array('REVERSED',$row['statuses'],true)) self::fail('Reversed source cannot receive a new allocation.');
        }
        $lines=self::lines($source,$explicitLines,$references);
        $originalHash='';
        if ($source['reversal_of_hash'] !== '') {
            if ($originalPacket === null) self::fail('Explicit original allocation packet required for reversal.');
            $originalSource=null;
            foreach ($matching['active'] as $entry) if ($entry['declaration']['declaration_hash'] === $source['reversal_of_hash']) $originalSource=$entry;
            if ($originalSource === null || $originalSource['declaration']['reversal_of_hash'] !== '') self::fail('Current non-reversal original source required.');
            $originalLines=$originalPacket['lines'] ?? null;
            if (!is_array($originalLines) || !array_is_list($originalLines)) self::fail('Typed original allocation lines required.');
            $originalInputs=[];
            foreach ($originalLines as $line) {
                if (!is_array($line)) self::fail('Typed original allocation line required.');
                self::keys($line,['target','reference_hash','amount','base_amount']);
                $originalInputs[]=['target'=>$line['target'],'amount'=>$line['amount'],'base_amount'=>$line['base_amount']];
            }
            $resolvedOriginal=self::lines($originalSource['declaration'],$originalInputs,$references);
            $expected=self::packet($originalSource,$resolvedOriginal,'');
            if (Validator::canonicalJson($originalPacket) !== Validator::canonicalJson($expected)) self::fail('Original packet changed or current target lineage is stale.');
            if (count($lines) !== count($resolvedOriginal)) self::fail('Reversal must preserve every exact original target.');
            foreach ($lines as $i=>$line) {
                $original=$resolvedOriginal[$i];
                if (Validator::canonicalJson($line['target']) !== Validator::canonicalJson($original['target'])
                    || $line['reference_hash'] !== $original['reference_hash']
                    || $line['amount'] !== ExactDecimal::negate($original['amount'])
                    || $line['base_amount'] !== ExactDecimal::negate($original['base_amount'])) self::fail('Reversal must negate the exact original line allocation.');
            }
            $originalHash=$expected['proposal_hash'];
            self::assertCurrentTargets($originalSource['declaration'],$resolvedOriginal,$references);
        }
        self::assertCurrentTargets($source,$lines,$references);
        return self::packet($current,$lines,$originalHash);
    }

    /** Final bounded snapshot sweep only; never approves arithmetic, financial finality or execution. */
    public static function assertCurrentTargets(array $source, array $lines, AllocationTargetReferences $references): void
    {
        $source=DeclarationContract::fromStored($source);
        if (!array_is_list($lines) || $lines === [] || count($lines) > 200) self::fail('Bounded normalized target snapshots required.');
        foreach ($lines as $line) {
            if (!is_array($line) || !is_array($line['target'] ?? null)) self::fail('Normalized target snapshot required.');
            self::keys($line,['target','reference_hash','amount','base_amount']);
            $target=self::target($line['target']);self::digest($line['reference_hash']);
            $resolved=$references->resolve($source,$target);
            self::keys($resolved,['target','reference_hash']);self::digest($resolved['reference_hash']);
            if (!is_array($resolved['target']) || Validator::canonicalJson(self::target($resolved['target'])) !== Validator::canonicalJson($target)
                || !hash_equals($line['reference_hash'],$resolved['reference_hash'])) self::fail('Current target lineage changed during proposal preparation.');
        }
    }

    private static function lines(array $source, array $inputs, AllocationTargetReferences $references): array
    {
        Validator::structured($inputs);
        if (!array_is_list($inputs) || $inputs === [] || count($inputs) > 200) self::fail('One to 200 explicit allocation lines required.');
        $lines=[];$seen=[];$amount='0.0000';$base='0.0000';
        foreach ($inputs as $input) {
            if (!is_array($input)) self::fail('Typed explicit allocation line required.');
            self::keys($input,['target','amount','base_amount']);
            if (!is_array($input['target'])) self::fail('Exact target tuple required.');
            $target=self::target($input['target']);
            foreach (['shop_key','business_id','store_id'] as $key) if ($target[$key] !== $source['scope'][$key]) self::fail('Target business/store/shop differs from source.');
            foreach (['order_id','product_version_id','listing_id','provider_mapping_id','variant_ref'] as $key) {
                $value=$source['scope'][$key];
                if ($value !== 0 && $value !== '' && $target[$key] !== $value) self::fail('Target expands an explicit source restriction.');
            }
            if ($target['environment'] !== $source['environment'] || $target['currency'] !== $source['currency']) self::fail('Target environment/currency differs from source.');
            // An order line may occur only once even if a caller changes another tuple field.
            $identity=(string)$target['order_line_item_id'];
            if (isset($seen[$identity])) self::fail('Duplicate allocation target line.');
            $seen[$identity]=true;
            $resolved=$references->resolve($source,$target);
            self::keys($resolved,['target','reference_hash']);
            if (!is_array($resolved['target']) || Validator::canonicalJson(self::target($resolved['target'])) !== Validator::canonicalJson($target)) self::fail('Current stored target lineage conflicts.');
            self::digest($resolved['reference_hash']);
            $lineAmount=ExactDecimal::money($input['amount']);$lineBase=ExactDecimal::money($input['base_amount']);
            self::sign($lineAmount,$source['amount']);self::sign($lineBase,$source['base_amount']);
            if ($source['currency'] === $source['base_currency'] && $lineAmount !== $lineBase) self::fail('Same-currency allocation must preserve exact line amounts.');
            $amount=ExactDecimal::add($amount,$lineAmount);$base=ExactDecimal::add($base,$lineBase);
            $lines[]=['target'=>$target,'reference_hash'=>$resolved['reference_hash'],'amount'=>$lineAmount,'base_amount'=>$lineBase];
        }
        if ($amount !== $source['amount'] || $base !== $source['base_amount']) self::fail('Explicit allocations must exactly reconcile both source amounts; no remainder is allocated.');
        usort($lines,static fn(array $a,array $b): int => $a['target']['order_line_item_id'] <=> $b['target']['order_line_item_id']);
        return $lines;
    }

    private static function target(array $target): array
    {
        self::keys($target,self::TARGET_KEYS);
        foreach (['business_id','store_id','order_id','order_line_item_id','product_id','product_version_id','listing_id','provider_mapping_id'] as $field) {
            if (!is_int($target[$field]) || $target[$field] < ($field === 'provider_mapping_id' ? 0 : 1)) self::fail('Explicit target integer identities required.');
        }
        if (!in_array($target['shop_key'],DeclarationContract::SHOPS,true)
            || !is_string($target['environment']) || Validator::environment($target['environment']) !== $target['environment']
            || !is_string($target['currency']) || preg_match('/^[A-Z]{3}$/D',$target['currency']) !== 1
            || !is_string($target['variant_ref']) || ($target['variant_ref'] !== '' && (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D',$target['variant_ref']) !== 1 || $target['provider_mapping_id'] < 1))) self::fail('Canonical target scope/currency/variant required.');
        return array_replace(array_fill_keys(self::TARGET_KEYS,null),$target);
    }

    private static function packet(array $entry, array $lines, string $originalHash): array
    {
        $source=$entry['declaration'];
        $packet=['schema'=>'digiforge-finance-allocation-proposal-v1','method'=>'EXPLICIT_AMOUNTS_ONLY',
            'source_ledger_id'=>$entry['ledger_id'],'source'=>$source,'lines'=>$lines,
            'allocated_amount'=>$source['amount'],'allocated_base_amount'=>$source['base_amount'],
            'reversal_of_proposal_hash'=>$originalHash,'state'=>'PROVISIONAL',
            'reconciliation_state'=>$originalHash === '' ? 'EXPLICIT_SUM_MATCH' : 'EXPLICIT_REVERSAL_PROPOSAL',
            'review_status'=>'UNREVIEWED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN',
            'actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,
            'money_movement_authorized'=>false,'tax_filing_authorized'=>false];
        $packet['proposal_hash']=Validator::hash($packet);
        $packet['idempotency_key']='finance_alloc:'.$packet['proposal_hash'];
        return $packet;
    }

    private static function sign(string $amount, string $source): void
    {
        if ($amount !== '0.0000' && ($source === '0.0000' || ($amount[0] === '-') !== ($source[0] === '-'))) self::fail('Line amounts must preserve the signed source bucket; mixed offsets are not inferred.');
    }

    private static function keys(array $input, array $expected): void
    {
        $actual=array_keys($input);sort($actual);sort($expected);
        if ($actual !== $expected) self::fail('Fields must exactly match the versioned allocation contract.');
    }

    private static function digest(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D',$value) !== 1) self::fail('SHA-256 evidence identity required.');
    }

    private static function fail(string $message): never { throw new InvalidArgumentException($message); }
}
