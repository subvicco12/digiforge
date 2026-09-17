<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

/** Persists normalized shared Printify catalog evidence without touching active templates. */
final class PrintifyCatalogPersistence
{
    public function persistVariant(array $raw): array|WP_Error
    {
        try { $row=PrintifyCatalogContract::normalizeCatalogVariant($raw); }
        catch(\InvalidArgumentException $e){ return new WP_Error('digiforge_printify_catalog_invalid',$e->getMessage(),['status'=>400]); }
        global $wpdb;
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_catalog().' WHERE provider=%s AND environment=%s AND provider_product_key=%s AND provider_variant_key=%s LIMIT 1',$row['provider'],$row['environment'],$row['provider_product_key'],$row['provider_variant_key']),ARRAY_A);
        $encodedAttributes=wp_json_encode($row['attributes']); $encodedShipping=wp_json_encode($row['shipping_profile']);
        if(!is_string($encodedAttributes)||!is_string($encodedShipping)) return new WP_Error('digiforge_printify_catalog_encode','Catalog metadata could not be encoded.',['status'=>500]);
        $data=$row; $data['attributes']=$encodedAttributes; $data['shipping_profile']=$encodedShipping; $data['updated_at']=current_time('mysql',true);
        if(is_array($existing)){
            $changed=$this->changed($existing,$data);
            if(!$changed) return $existing+['catalog_change_detected'=>false,'idempotent'=>true];
            // Catalog evidence may evolve. We update the shared DRAFT catalog row only;
            // validated production templates are deliberately outside this write path.
            $ok=$wpdb->update(Tables::pod_catalog(),$data,['id'=>(int)$existing['id']]);
            if($ok===false) return new WP_Error('digiforge_printify_catalog_update','Catalog evidence could not be updated.',['status'=>500]);
            $fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_catalog().' WHERE id=%d',(int)$existing['id']),ARRAY_A);
            return (is_array($fresh)?$fresh:$data)+['catalog_change_detected'=>true,'idempotent'=>false];
        }
        $data['created_by']=get_current_user_id(); $data['created_at']=current_time('mysql',true);
        $ok=$wpdb->insert(Tables::pod_catalog(),$data);
        if($ok===false) return new WP_Error('digiforge_printify_catalog_insert','Catalog evidence could not be stored.',['status'=>500]);
        return $data+['id'=>(int)$wpdb->insert_id,'catalog_change_detected'=>false,'idempotent'=>false];
    }

    private function changed(array $existing,array $incoming): bool
    {
        foreach(['title','variant_label','attributes','currency','base_cost','shipping_profile','availability_state','source_revision','observed_at'] as $field){
            if((string)($existing[$field]??'')!==(string)($incoming[$field]??'')) return true;
        }
        return false;
    }
}
