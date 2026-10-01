<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only readiness for a governed Master 500 v2 ingestion. Never promotion authority. */
final class MasterCatalogV2IngestionReadModel
{
    public function snapshot(): array
    {
        global $wpdb;
        $wpdb->last_error='';
        $parent=$wpdb->get_row($wpdb->prepare(
            'SELECT id,catalog_key,version_label,source_sha256,source_state,row_count,fingerprint,production_authority FROM '.Tables::catalog_versions().' WHERE catalog_key=%s AND source_sha256=%s AND source_state=%s ORDER BY id DESC LIMIT 1',
            PersonalizedCatalogReference::CATALOG_KEY,
            PersonalizedCatalogReference::SOURCE_SHA256,
            'IMMUTABLE_REFERENCE'
        ),ARRAY_A);
        if(!is_array($parent)||!empty($wpdb->last_error)){
            return $this->blocked('PARENT_EVIDENCE_UNAVAILABLE');
        }
        $checks=[
            'parent_catalog_key'=>hash_equals(PersonalizedCatalogReference::CATALOG_KEY,(string)($parent['catalog_key']??'')),
            'parent_source_sha'=>hash_equals(PersonalizedCatalogReference::SOURCE_SHA256,(string)($parent['source_sha256']??'')),
            'parent_state'=>(string)($parent['source_state']??'')==='IMMUTABLE_REFERENCE',
            'parent_rows'=>(int)($parent['row_count']??0)===PersonalizedCatalogReference::LISTING_COUNT,
            'parent_fingerprint'=>preg_match('/^[a-f0-9]{64}$/',(string)($parent['fingerprint']??''))===1,
            'parent_non_authorizing'=>(int)($parent['production_authority']??1)===0,
        ];
        $ready=!in_array(false,$checks,true);
        if($ready){
            $wpdb->last_error='';
            $existing=$wpdb->get_row($wpdb->prepare(
                'SELECT id,source_sha256,source_state,parent_version_id,migration_metadata,row_count,fingerprint,production_authority FROM '.Tables::catalog_versions().' WHERE catalog_key=%s ORDER BY id DESC LIMIT 1',
                MasterCatalogV2Reference::CATALOG_KEY
            ),ARRAY_A);
            if(!empty($wpdb->last_error))return $this->blocked('V2_EVIDENCE_UNAVAILABLE');
            if(is_array($existing)){
                $exact=hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,(string)($existing['source_sha256']??''))
                    &&(string)($existing['source_state']??'')==='MIGRATION_CANDIDATE'
                    &&(int)($existing['parent_version_id']??0)===(int)$parent['id']
                    &&(int)($existing['row_count']??0)===MasterCatalogV2Reference::ROW_COUNT
                    &&preg_match('/^[a-f0-9]{64}$/',(string)($existing['fingerprint']??''))===1
                    &&(int)($existing['production_authority']??1)===0
                    &&$this->migrationEvidenceMatches((string)($existing['migration_metadata']??''),(int)$parent['id'],(string)($existing['fingerprint']??''));
                return [
                    'query_state'=>'AVAILABLE',
                    'ingestion_readiness'=>$exact?'ALREADY_PERSISTED':'BLOCKED',
                    'blocker'=>$exact?'':'EXISTING_V2_EVIDENCE_CONFLICT',
                    'parent_version_id'=>(int)$parent['id'],
                    'v2_version_id'=>(int)($existing['id']??0),
                    'v2_source_sha256'=>MasterCatalogV2Reference::SOURCE_SHA256,
                    'expected_rows'=>MasterCatalogV2Reference::ROW_COUNT,
                    'checks'=>$checks+['existing_v2_exact'=>$exact],
                    'production_authority'=>false,'promotion_authorized'=>false,'external_execution_authorized'=>false,
                ];
            }
        }
        return [
            'query_state'=>'AVAILABLE',
            'ingestion_readiness'=>$ready?'READY_FOR_VALIDATED_INPUT':'BLOCKED',
            'blocker'=>$ready?'':'PARENT_EVIDENCE_INVALID',
            'parent_version_id'=>(int)($parent['id']??0),
            'parent_version_label'=>(string)($parent['version_label']??''),
            'v2_catalog_key'=>MasterCatalogV2Reference::CATALOG_KEY,
            'v2_source_file'=>MasterCatalogV2Reference::SOURCE_FILE,
            'v2_source_sha256'=>MasterCatalogV2Reference::SOURCE_SHA256,
            'expected_rows'=>MasterCatalogV2Reference::ROW_COUNT,
            'checks'=>$checks,
            'production_authority'=>false,
            'promotion_authorized'=>false,
            'external_execution_authorized'=>false,
        ];
    }

    private function migrationEvidenceMatches(string $json,int $parentVersionId,string $fingerprint):bool
    {
        $meta=json_decode($json,true);
        return is_array($meta)
            &&(string)($meta['source_state']??'')==='MIGRATION_CANDIDATE'
            &&hash_equals(PersonalizedCatalogReference::CATALOG_KEY,(string)($meta['parent_catalog_key']??''))
            &&(int)($meta['parent_version_id']??0)===$parentVersionId
            &&hash_equals(MasterCatalogV2Reference::SOURCE_FILE,(string)($meta['source_file']??''))
            &&hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,(string)($meta['source_sha256']??''))
            &&preg_match('/^[a-f0-9]{64}$/',(string)($meta['fingerprint']??''))===1
            &&hash_equals($fingerprint,(string)($meta['fingerprint']??''))
            &&empty($meta['production_authority'])
            &&empty($meta['promotion_authorized']);
    }

    private function blocked(string $blocker):array
    {
        return ['query_state'=>'UNAVAILABLE','ingestion_readiness'=>'BLOCKED','blocker'=>$blocker,'parent_version_id'=>0,'checks'=>[],'production_authority'=>false,'promotion_authorized'=>false,'external_execution_authorized'=>false];
    }
}
