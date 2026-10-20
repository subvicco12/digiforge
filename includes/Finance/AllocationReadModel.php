<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Authorized read-only proposal preview; never persists allocation or financial authority. */
final class AllocationReadModel
{
    /**
     * Scope keys: shop_key, business_id, store_id, environment. All history comes from
     * current storage, never the caller. The conservative 200-declaration environment
     * bound includes every period/shop so cross-period reversals and damaged namespace
     * evidence cannot disappear through an accounting-period or decoded-scope filter.
     * An environment exceeding that bound is unavailable even for a small source stream.
     * Any unvalidatable declaration in that bounded environment also blocks the preview.
     */
    public function preview(string $sourceHash, array $scope, array $explicitLines, ?array $originalPacket = null): array|WP_Error
    {
        try {
            $keys=array_keys($scope);sort($keys);
            if ($keys !== ['business_id','environment','shop_key','store_id']
                || !is_string($scope['shop_key']) || !in_array($scope['shop_key'],DeclarationContract::SHOPS,true)
                || !is_int($scope['business_id']) || $scope['business_id'] < 1
                || !is_int($scope['store_id']) || $scope['store_id'] < 1
                || !is_string($scope['environment']) || Validator::environment($scope['environment']) !== $scope['environment']
                || preg_match('/^[a-f0-9]{64}$/D',$sourceHash) !== 1) return self::error('scope_invalid',400);
            $authorization=ShopAccess::authorize($scope['shop_key'],'manage_digiforge_finance');
            if ($authorization instanceof WP_Error) return self::denied($authorization);
            global $wpdb;
            $sql=$wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE environment=%s AND (idempotency_key LIKE %s OR metadata LIKE %s) ORDER BY id ASC LIMIT 201',
                $scope['environment'],$wpdb->esc_like('finance_decl:').'%','%"finance_declaration":%');
            $rows=$this->read($sql);if ($rows instanceof WP_Error) return $rows;
            if (count($rows) > 200) return self::error('history_truncated',503);
            $entries=[];$source=null;$repo=new Repository();
            foreach ($rows as $row) {
                $entry=$repo->declarationEvidence($row);
                if ($entry instanceof WP_Error) return self::error('source_unavailable',503);
                $entries[]=$entry;
                if ($entry['declaration']['declaration_hash'] === $sourceHash) {
                    if ($source !== null) return self::error('source_conflict',409);
                    $source=$entry;
                }
            }
            if ($source === null) return self::error('source_unavailable',409);
            foreach (['shop_key','business_id','store_id'] as $field) if ($source['declaration']['scope'][$field] !== $scope[$field]) return self::error('scope_conflict',409);
            if ($source['declaration']['environment'] !== $scope['environment']) return self::error('scope_conflict',409);
            $history=self::related($entries,$source);
            $packet=AllocationProposal::build($history,$sourceHash,$explicitLines,new AllocationStoredTargetReferences(),$originalPacket);
            // Reject source additions/revisions/removals during preview, including dependent reversals.
            $again=$this->read($sql);if ($again instanceof WP_Error) return $again;
            if (Validator::hash($rows) !== Validator::hash($again)) return self::error('source_changed',409);
            AllocationProposal::assertCurrentTargets($packet['source'],$packet['lines'],new AllocationStoredTargetReferences());
            $authorization=ShopAccess::authorize($scope['shop_key'],'manage_digiforge_finance');
            return $authorization instanceof WP_Error ? self::denied($authorization) : $packet;
        } catch (\InvalidArgumentException|\TypeError|\JsonException) {
            return self::error('not_proposable',409);
        }
    }

    /** Complete connected revision/reversal graph over independently validated stored entries. */
    private static function related(array $entries, array $source): array
    {
        $identities=[DeclarationContract::identityKey($source['declaration'])=>true];
        $hashes=[$source['declaration']['declaration_hash']=>true];$selected=[];$changed=true;
        while ($changed) {
            $changed=false;
            foreach ($entries as $entry) {
                $d=$entry['declaration'];$identity=DeclarationContract::identityKey($d);$reverse=$d['reversal_of_hash'];
                if (isset($selected[$entry['ledger_id']])) continue;
                if (!isset($identities[$identity]) && !isset($hashes[$d['declaration_hash']]) && ($reverse === '' || !isset($hashes[$reverse]))) continue;
                $selected[$entry['ledger_id']]=$entry;$identities[$identity]=true;$hashes[$d['declaration_hash']]=true;
                if ($reverse !== '') $hashes[$reverse]=true;
                $changed=true;
            }
        }
        ksort($selected,SORT_NUMERIC);
        return array_values($selected);
    }

    private function read(string $sql): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);
        return QueryEvidence::failed() || !is_array($rows) ? self::error('source_unavailable',503) : $rows;
    }

    private static function denied(WP_Error $error): WP_Error
    {
        $data=$error->get_error_data();
        return self::error('access_denied',is_array($data) && ($data['status'] ?? 403) === 503 ? 503 : 403);
    }

    private static function error(string $code, int $status): WP_Error
    {
        return new WP_Error('finance_allocation_'.$code,'A complete current authorized explicit allocation proposal is unavailable.',
            ['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN',
                'actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,
                'money_movement_authorized'=>false,'tax_filing_authorized'=>false]);
    }
}
