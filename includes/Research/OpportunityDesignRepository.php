<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\POD\ApprovalRecord;
use WP_Error;

/** Append-only local versions. Hashes bind source bytes, artwork, timestamps and actor. */
final class OpportunityDesignRepository
{
    public function save(array $input): array|WP_Error
    {
        $access=$this->access($input['shop_key'] ?? null);
        if ($access instanceof WP_Error) return $access;
        try { $input=OpportunityDesignContract::normalize($input); } catch (\InvalidArgumentException $e) { return self::error($e->getMessage()); }
        $record=['schema_version'=>1,'input'=>$input,'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'provenance_mode'=>'MANUAL_IMPORT_UNVERIFIED','external_automation_available'=>false,'publishing_enabled'=>false,'order_execution_enabled'=>false];
        $raw=wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || strlen($raw)>400000) return self::error('Encoded version exceeds the local evidence limit.');
        $hash=hash('sha256',$raw);
        $name=$this->name($hash);
        if (!add_option($name,$raw,'',false)) return self::error('Version already exists or persistence failed; inspect the exact hash before retry.');
        return $this->read('personalized_pod',$hash);
    }

    public function read(string $shop,string $hash): array|WP_Error
    {
        $access=$this->access($shop);if ($access instanceof WP_Error) return $access;
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) return self::error('Exact version hash required.');
        $raw=$this->stored($this->name($hash));
        if (!is_string($raw) || strlen($raw)>400000 || !hash_equals($hash,hash('sha256',$raw))) return self::error('Version evidence unavailable or corrupt.');
        $record=json_decode($raw,true);
        if (!is_array($record) || ($record['input']['shop_key'] ?? null)!==$shop || !is_int($record['created_by'] ?? null) || $record['created_by']<1) return self::error('Shop or owner evidence conflict.');
        $approvalRaw=$this->stored($this->name($hash).'_decision');
        if ($approvalRaw instanceof WP_Error) return $approvalRaw;
        $approval=$approvalRaw===null?null:json_decode($approvalRaw,true);
        if ($approvalRaw!==null && (!is_array($approval) || ($approval['evidence_hash'] ?? null)!==$hash || ($approval['publishing_enabled'] ?? null)!==false || ($approval['order_execution_enabled'] ?? null)!==false || !is_int($approval['approved_by'] ?? null) || $approval['approved_by']<1 || !in_array($approval['state'] ?? null,['HUMAN_APPROVED','HUMAN_REJECTED'],true))) return self::error('Decision evidence unavailable or corrupt.');
        return ['version_hash'=>$hash,'record'=>$record,'approval'=>$approval,'supplier_geometry_verified'=>false,'print_output_verified'=>false,'release_hold'=>true];
    }

    public function decide(string $shop,string $hash,string $decision): array|WP_Error
    {
        $version=$this->read($shop,$hash);if ($version instanceof WP_Error) return $version;
        if ($version['approval']!==null) return self::error('A decision is immutable; import an edited version for another review.');
        $approval=ApprovalRecord::decide(['state'=>'READY_FOR_HUMAN_APPROVAL','evidence_hash'=>$hash,'publishing_enabled'=>false,'order_execution_enabled'=>false],$decision,get_current_user_id(),'Local artwork evidence only; supplier and release gates remain pending.');
        if ($approval instanceof WP_Error) return $approval;
        $raw=wp_json_encode($approval);
        if (!is_string($raw) || !add_option($this->name($hash).'_decision',$raw,'',false)) return self::error('Decision conflict or persistence failure; no automatic retry.');
        $result=$this->read($shop,$hash);
        if ($result instanceof WP_Error || $result['approval']!==$approval) return self::error('Decision confirmation unavailable; reconcile before retry.');
        return $result;
    }

    private function access(mixed $shop): true|WP_Error
    {
        if ($shop!=='personalized_pod' || get_current_user_id()<1 || !current_user_can('manage_digiforge_pod') || !current_user_can('manage_digiforge_research')) return self::error('Authenticated Personalized POD research/artwork reviewer required.',403);
        return true;
    }
    private function name(string $hash): string { return 'digiforge_opportunity_personalized_pod_'.$hash; }
    /** Read durable state, including missing decisions, without trusting the option cache. */
    private function stored(string $name): string|WP_Error|null
    {
        global $wpdb;
        $wpdb->last_error='';
        $raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$name));
        if ($wpdb->last_error!=='' || ($raw!==null && !is_string($raw))) return self::error('Database evidence unavailable.',503);
        return $raw;
    }
    private static function error(string $message,int $status=409): WP_Error { return new WP_Error('opportunity_design_denied',$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]); }
}
