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
        try{$row=PrintifyCatalogContract::normalizeCatalogVariant($raw);}catch(\InvalidArgumentException $e){return new WP_Error('digiforge_printify_catalog_invalid',$e->getMessage(),['status'=>400]);}
        global $wpdb;$table=Tables::pod_catalog();$existing=$this->findPhysical($row);
        $encodedAttributes=wp_json_encode($row['attributes']);$encodedShipping=wp_json_encode($row['shipping_profile']);if(!is_string($encodedAttributes)||!is_string($encodedShipping))return new WP_Error('digiforge_printify_catalog_encode','Catalog metadata could not be encoded.',['status'=>500]);
        $data=$row;$data['attributes']=$encodedAttributes;$data['shipping_profile']=$encodedShipping;$data['updated_at']=current_time('mysql',true);
        if(is_array($existing)){
            $materialChanged=$this->materialChanged($existing,$data);
            // Supplier observations must never rewind an independently reviewed lifecycle state.
            $data['state']=(string)($existing['state']??'DRAFT');
            if(!$materialChanged){
                // observed_at is freshness evidence, not a material supplier change.
                $freshness=['observed_at'=>$data['observed_at']??null,'updated_at'=>$data['updated_at']];$ok=$wpdb->update($table,$freshness,['id'=>(int)$existing['id']]);if($ok===false)return new WP_Error('digiforge_printify_catalog_update','Catalog observation timestamp could not be updated.',['status'=>500]);
                $fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',(int)$existing['id']),ARRAY_A);return (is_array($fresh)?$fresh:$existing)+['catalog_change_detected'=>false,'idempotent'=>true];
            }
            $ok=$wpdb->update($table,$data,['id'=>(int)$existing['id']]);if($ok===false)return new WP_Error('digiforge_printify_catalog_update','Catalog evidence could not be updated.',['status'=>500]);$fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',(int)$existing['id']),ARRAY_A);return (is_array($fresh)?$fresh:$data)+['catalog_change_detected'=>true,'idempotent'=>false];
        }
        $data['created_by']=get_current_user_id();$data['created_at']=current_time('mysql',true);$ok=$wpdb->insert($table,$data);
        if($ok===false){
            // A concurrent sync may have won the unique physical identity race. Refetch and
            // converge through the normal update/idempotence path instead of surfacing a false failure.
            $winner=$this->findPhysical($row);if(is_array($winner))return $this->persistVariant($raw);
            return new WP_Error('digiforge_printify_catalog_insert','Catalog evidence could not be stored.',['status'=>500]);
        }
        return $data+['id'=>(int)$wpdb->insert_id,'catalog_change_detected'=>false,'idempotent'=>false];
    }

    private function findPhysical(array $row): ?array
    {
        global $wpdb;$found=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_catalog().' WHERE provider=%s AND environment=%s AND provider_product_key=%s AND provider_variant_key=%s LIMIT 1',$row['provider'],$row['environment'],$row['provider_product_key'],$row['provider_variant_key']),ARRAY_A);return is_array($found)?$found:null;
    }

    private function materialChanged(array $existing,array $incoming): bool
    {
        foreach(['title','variant_label','attributes','currency','base_cost','shipping_profile','availability_state','source_revision'] as $field){if((string)($existing[$field]??'')!==(string)($incoming[$field]??''))return true;}return false;
    }
}
