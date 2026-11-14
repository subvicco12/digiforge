<?php

declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Immutable local research snapshots in non-autoloaded options; no schema migration. */
final class ProductIntelligenceRepository
{
    public const PREFIX='digiforge_pod_intelligence_v1_';

    public function import(array $input): array|WP_Error
    {
        try {$packet=ProductIntelligence::validate($input);$json=json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
        catch (\Throwable) {return new WP_Error('pod_intelligence_invalid','Invalid or inconsistent research packet.',['status'=>400]);}
        $id=hash('sha256',$json);
        // add_option is insert-only, unique by option_name. Never overwrite originals.
        $created=add_option(self::PREFIX.$id,$json,'',false);
        $readback=$this->read($id);if(is_wp_error($readback))return $readback;
        if (!\DigiForge\Security\Logger::write('pod_intelligence_import',['snapshot_id'=>$id,'shop_key'=>'personalized_pod','replayed'=>!$created,'external_execution_authorized'=>false],'pod_research',$id)) return new WP_Error('pod_intelligence_audit_unavailable','Snapshot retained but import audit could not be confirmed. Reconcile before further action.',['status'=>503]);
        return ['snapshot_id'=>$id,'replayed'=>!$created,'production_authority'=>false,'external_execution_authorized'=>false];
    }

    public function read(string $id): array|WP_Error
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return $this->unavailable();
        $wpdb->last_error='';
        $raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s LIMIT 1',self::PREFIX.$id));if(class_exists(\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::class))\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::observeOption(self::PREFIX.$id,$raw);
        if (!empty($wpdb->last_error) || !is_string($raw) || !hash_equals($id,hash('sha256',$raw))) return $this->unavailable();
        try {$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input))return $this->unavailable();return ProductIntelligence::validate($input);}
        catch (\Throwable) {return $this->unavailable();}
    }

    public function recent(): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';
        $rows=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 11',$wpdb->esc_like(self::PREFIX).'%'));
        if (!is_array($rows)||!empty($wpdb->last_error))return $this->unavailable();
        $items=[];
        foreach (array_slice($rows,0,10) as $name) {
            $id=substr($name,strlen(self::PREFIX));$packet=$this->read($id);
            if(is_wp_error($packet))return $packet;
            $items[]=['snapshot_id'=>$id,'concepts'=>count($packet['sections']['concepts']['rows'])];
        }
        return ['items'=>$items,'more_versions'=>count($rows)>10];
    }

    private function unavailable(): WP_Error
    {
        return new WP_Error('pod_intelligence_unavailable','Research snapshot unavailable or integrity verification failed. No readiness inferred.',['status'=>503]);
    }
}
