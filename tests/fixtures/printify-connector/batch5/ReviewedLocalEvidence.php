<?php
declare(strict_types=1);
namespace DigiForge\Security;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Listings\Validator;
use WP_Error;

/** Local, independently reviewed restrictions/assertions. Never an external execution grant. */
final class ReviewedLocalEvidence
{
    private const OBJECT = 'reviewed_local_evidence';
    private const LIMIT = 500;

    public static function propose(string $kind, string $shop, string $key, array $payload, int $effectiveAt, int $expiresAt, string $requestId, int $expectedRevision, ?callable $condition = null): array|WP_Error
    {
        if ($kind==='finance_allocation' && $condition===null) return self::error('context_required',400);
        if ($effectiveAt < 1 || $expiresAt <= $effectiveAt || $expiresAt <= time() || !self::safePayload($payload)) {
            return self::error('invalid_proposal', 400);
        }
        return self::mutate($kind, $shop, $key, $expectedRevision, $requestId, static function (?array $state) use ($payload, $effectiveAt, $expiresAt): array {
            return ['payload'=>$payload, 'effective_at'=>$effectiveAt, 'expires_at'=>$expiresAt,
                'author_id'=>get_current_user_id(), 'reviewer_id'=>0, 'status'=>'PENDING',
                'version'=>($state['version'] ?? 0)+1];
        }, 'proposed', $condition);
    }

    public static function decide(string $kind, string $shop, string $key, int $expectedRevision, string $proposalHash, string $decision, string $requestId, ?callable $condition = null): array|WP_Error
    {
        if ($kind==='finance_allocation' && $condition===null) return self::error('context_required',400);
        if (!in_array($decision, ['APPROVED','REJECTED','REVOKED'], true) || preg_match('/^[a-f0-9]{64}$/D', $proposalHash)!==1) {
            return self::error('invalid_decision',400);
        }
        return self::mutate($kind,$shop,$key,$expectedRevision,$requestId,static function (?array $state) use ($kind,$shop,$proposalHash,$decision): array|WP_Error {
            if ($state===null || !hash_equals($state['proposal_hash'],$proposalHash) || $state['author_id']===get_current_user_id()
                || ($decision==='REVOKED' ? $state['status']!=='APPROVED' : $state['status']!=='PENDING')) {
                return self::error('invalid_transition',409);
            }
            if ($decision==='APPROVED') {
                $authority=ShopAccess::authorize($shop,self::authorCap($kind),$state['author_id']);
                if (is_wp_error($authority)) { return $authority; }
                if ($kind==='finance_allocation') {
                    $authority=ShopAccess::authorize($shop,'manage_digiforge',$state['author_id']);
                    if (is_wp_error($authority)) return $authority;
                }
                if ($state['expires_at']<=time()) { return self::error('expired',409); }
            }
            $state['status']=$decision;
            $state['reviewer_id']=get_current_user_id();
            return $state;
        },strtolower($decision),$condition);
    }

    public static function read(string $kind,string $shop,string $key): array|WP_Error|null
    {
        $valid=self::identity($kind,$shop,$key);
        if (is_wp_error($valid)) { return $valid; }
        $access=ShopAccess::authorize($shop,'manage_digiforge');
        if (is_wp_error($access)) { return $access; }
        if ($kind==='finance_allocation') {
            $access=ShopAccess::authorize($shop,'manage_digiforge_finance');
            if (is_wp_error($access)) return $access;
        }
        $events=self::events($valid,false);
        if (is_wp_error($events)) { return $events; }
        return $events===[] ? null : $events[count($events)-1]['state'];
    }

    public static function approved(string $kind,string $shop,string $key): array|WP_Error
    {
        $state=self::read($kind,$shop,$key);
        if (is_wp_error($state)) { return $state; }
        if ($state===null || $state['status']!=='APPROVED' || $state['effective_at']>time() || $state['expires_at']<=time()) {
            return self::error('not_currently_approved',409);
        }
        foreach ([$state['author_id']=>self::authorCap($kind),$state['reviewer_id']=>'manage_digiforge_settings'] as $actor=>$cap) {
            $access=ShopAccess::authorize($shop,$cap,$actor);
            if (is_wp_error($access)) { return $access; }
        }
        if ($kind==='finance_allocation') {
            foreach ([$state['author_id'],$state['reviewer_id']] as $actor) {
                $access=ShopAccess::authorize($shop,'manage_digiforge',$actor);
                if (is_wp_error($access)) return $access;
            }
            $access=ShopAccess::authorize($shop,'manage_digiforge_finance',$state['reviewer_id']);
            if (is_wp_error($access)) return $access;
        }
        return $state;
    }

    private static function mutate(string $kind,string $shop,string $key,int $revision,string $requestId,callable $change,string $action,?callable $condition=null): array|WP_Error
    {
        $stream=self::identity($kind,$shop,$key);
        if (is_wp_error($stream)) { return $stream; }
        if ($revision<0 || preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$requestId)!==1) { return self::error('invalid_request',400); }
        $caps=['manage_digiforge',$action==='proposed'?self::authorCap($kind):'manage_digiforge_settings'];
        if ($kind==='finance_allocation') $caps[]='manage_digiforge_finance';
        $caps=array_values(array_unique($caps));
        foreach ($caps as $cap) {
            $access=ShopAccess::authorize($shop,$cap);
            if (is_wp_error($access)) { return $access; }
        }
        global $wpdb;
        $option='digiforge_local_review_'.$stream;
        // Both state and its durable audit must commit together; unsupported engines fail closed.
        foreach ([$wpdb->options,Tables::audit_log()] as $table) {
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if (!is_string($engine) || strtoupper($engine)!=='INNODB' || QueryEvidence::failed()) { return self::error('transaction_unavailable',503); }
        }
        // Never implicitly commit a caller transaction. Nested work remains under
        // the caller's commit/rollback; unsupported transaction introspection denies.
        $active=$wpdb->get_var('SELECT @@session.in_transaction');
        if (QueryEvidence::failed() || !in_array((string)$active,['0','1'],true)) { return self::error('transaction_unavailable',503); }
        $nested=(string)$active==='1';
        $savepoint='df_review_'.bin2hex(random_bytes(8));
        $rollback=$nested?'ROLLBACK TO SAVEPOINT '.$savepoint:'ROLLBACK';
        $finish=$nested?'RELEASE SAVEPOINT '.$savepoint:'COMMIT';
        if ($wpdb->query($nested?'SAVEPOINT '.$savepoint:'START TRANSACTION')===false) { return self::error('transaction_unavailable',503); }
        $abort=static function(WP_Error $failure) use ($wpdb,$rollback,$nested,$savepoint,$kind,$shop,$key,$action): WP_Error {
            if ($wpdb->query($rollback)===false || ($nested && $wpdb->query('RELEASE SAVEPOINT '.$savepoint)===false)) { return self::error('rollback_uncertain',503); }
            if (!Logger::write('local_review_denied',['kind'=>$kind,'shop'=>$shop,'key'=>$key,'action'=>$action,'reason'=>$failure->get_error_code()],'reviewed_local_denial',hash('sha256',$kind.':'.$shop.':'.$key))) { return self::error('denial_audit',503); }
            return $failure;
        };
        try {
            $wpdb->last_error='';
            // Serialize admission with the existing shop grant/revocation ledger.
            $aclKey='digiforge_shop_access_state_'.$shop;
            $acl=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$aclKey));
            if(!is_string($acl)||QueryEvidence::failed()) { throw new \RuntimeException('assignment_lock'); }
            $snapshot=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$aclKey));
            if(QueryEvidence::failed()||$acl!==$snapshot) { return $abort(self::error('assignment_snapshot_stale',409)); }
            foreach($caps as $cap) {
                $access=ShopAccess::authorize($shop,$cap);
                if(is_wp_error($access)) { return $abort($access); }
            }
            $created=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$option,'[]'));
            if ($created===false) { throw new \RuntimeException('state'); }
            $events=self::events($stream,true);
            if (is_wp_error($events)) { return $abort($events); }
            $prior=$events===[]?null:$events[count($events)-1]['state'];
            if (($prior['revision']??0)!==$revision || count($events)>=self::LIMIT) { return $abort(self::error('stale_revision',409)); }
            foreach ($events as $event) { if (hash_equals($event['request_id'],$requestId)) { return $abort(self::error('replay',409)); } }
            $state=$change($prior);
            if (is_wp_error($state)) { return $abort($state); }
            // Additional context can only deny after all existing authority/state
            // checks. Finance callers must revalidate their frozen local proposal;
            // this never grants financial-period or external-operation authority.
            if ($condition!==null) {
                $context=$condition();
                if ($context!==true) return $abort($context instanceof WP_Error?$context:self::error('context_changed',409));
            }
            $state['revision']=$revision+1;
            $state['proposal_hash']=hash('sha256',self::json([$kind,$shop,$key,$state['version'],$state['author_id'],$state['payload'],$state['effective_at'],$state['expires_at']]));
            $state['external_execution_authorized']=false;
            $event=['schema'=>1,'stream'=>$stream,'kind'=>$kind,'shop'=>$shop,'key'=>$key,'action'=>$action,
                'actor_id'=>get_current_user_id(),'request_id'=>$requestId,'recorded_at'=>time(),
                'previous_hash'=>$events===[]?str_repeat('0',64):$events[count($events)-1]['hash'],'state'=>$state];
            $event['hash']=hash_hmac('sha256',self::json($event),wp_salt('auth'));
            $events[]=$event;
            $encoded=self::json($events);
            if (strlen($encoded)>1048576 || $wpdb->update($wpdb->options,['option_value'=>$encoded],['option_name'=>$option],['%s'],['%s'])!==1
                || !Logger::write('local_review_'.$action,$event,self::OBJECT,$stream)) { throw new \RuntimeException('persist'); }
            if ($wpdb->query($finish)===false) { throw new \RuntimeException('commit'); }
            wp_cache_delete($option,'options');
            return $state;
        } catch (\Throwable) {
            return $abort(self::error('persistence',503));
        }
    }

    /** Validate the complete contiguous signed stream against independently persisted audit rows. */
    private static function events(string $stream,bool $lock): array|WP_Error
    {
        global $wpdb;
        $wpdb->last_error='';
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s".($lock?' FOR UPDATE':''),'digiforge_local_review_'.$stream));
        if (QueryEvidence::failed()) { return self::error('read_unavailable',503); }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT event_type,actor_id,CASE WHEN OCTET_LENGTH(context)<=65536 THEN context ELSE NULL END AS context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s ORDER BY id ASC LIMIT 501',self::OBJECT,$stream),ARRAY_A);
        if (QueryEvidence::failed() || !is_array($rows)) { return self::error('read_unavailable',503); }
        if ($raw===null) { return $rows===[]?[]:self::error('history_mismatch',409); }
        if (!is_string($raw) || strlen($raw)>1048576) { return self::error('history_mismatch',409); }
        $events=json_decode($raw,true);
        if (!is_array($events) || !array_is_list($events) || count($events)>self::LIMIT || count($events)!==count($rows)) { return self::error('history_mismatch',409); }
        $previous=str_repeat('0',64);
        foreach ($events as $index=>$event) {
            if (!is_array($event) || !is_string($event['hash']??null)) { return self::error('history_mismatch',409); }
            $hash=$event['hash']; unset($event['hash']);
            $state=$event['state']??null;
            if (!is_array($state) || ($event['stream']??null)!==$stream || ($event['previous_hash']??null)!==$previous
                || ($state['revision']??null)!==$index+1 || !hash_equals($hash,hash_hmac('sha256',self::json($event),wp_salt('auth')))) { return self::error('history_mismatch',409); }
            $event['hash']=$hash;
            $audit=json_decode((string)$rows[$index]['context'],true);
            if (!is_array($audit) || self::json($event)!==self::json($audit) || (int)$rows[$index]['actor_id']!==$event['actor_id'] || $rows[$index]['event_type']!=='local_review_'.$event['action']) { return self::error('history_mismatch',409); }
            $previous=$hash;
        }
        return $events;
    }

    private static function identity(string $kind,string $shop,string $key): string|WP_Error
    {
        if (!in_array($kind,['ownership','policy','finance_allocation'],true) || !ShopAccessPolicy::validShop($shop) || preg_match('/^[A-Za-z0-9_:.-]{1,240}$/D',$key)!==1) { return self::error('invalid_identity',400); }
        return hash('sha256',self::json([$kind,$shop,$key]));
    }
    private static function safePayload(array $payload,int $depth=0): bool
    {
        if ($depth>12 || count($payload)>100) { return false; }
        foreach ($payload as $key=>$value) {
            if (Logger::isCredentialKey((string)$key) || (is_string($key)&&preg_match('/password|secret|token|credential|authorization|private.?key/i',$key))) { return false; }
            if (is_array($value)) { if (!self::safePayload($value,$depth+1)) { return false; } }
            elseif (!is_null($value)&&!is_bool($value)&&!is_int($value)&&!is_string($value)) { return false; }
            elseif (is_string($value)&&(strlen($value)>4096 || preg_match('//u',$value)!==1)) { return false; }
        }
        return strlen(self::json($payload))<=32768;
    }
    private static function authorCap(string $kind): string { return match($kind){'ownership'=>'manage_digiforge_connections','finance_allocation'=>'manage_digiforge_finance',default=>'manage_digiforge_settings'}; }
    private static function json(array $value): string { return Validator::canonicalJson($value); }
    private static function error(string $code,int $status): WP_Error { return new WP_Error('digiforge_local_review_'.$code,'Current independently reviewed local evidence is required.',['status'=>$status,'external_execution_authorized'=>false]); }
}
