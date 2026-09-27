<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only authoritative ownership projection for POD product/provider mappings. */
final class BusinessAttributionReadModel
{
    /** @return array<string,mixed>|null */
    public function forProductVersion(int $productVersionId):?array
    {
        global $wpdb;if($productVersionId<1)return null;
        $sql='SELECT b.business_key,s.store_key,p.program_key,m.product_version_id,m.provider_mapping_id,m.state FROM '.Tables::pod_business_mappings().' m INNER JOIN '.Tables::businesses().' b ON b.id=m.business_id INNER JOIN '.Tables::stores().' s ON s.id=m.store_id AND s.business_id=b.id INNER JOIN '.Tables::product_programs().' p ON p.id=m.product_program_id AND p.business_id=b.id AND p.store_id=s.id WHERE m.product_version_id=%d AND m.state=%s AND m.approved_by>0 AND m.approved_at IS NOT NULL AND b.status=%s AND s.status=%s AND p.status=%s ORDER BY m.id DESC LIMIT 2';
        $rows=$wpdb->get_results($wpdb->prepare($sql,$productVersionId,'APPROVED','ACTIVE','ACTIVE','ACTIVE'),ARRAY_A)?:[];
        if(count($rows)!==1)return null;
        return $rows[0]+['attribution_authoritative'=>true,'external_execution_performed'=>false];
    }
}
