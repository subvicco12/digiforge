<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Local explicit ACL ledger. No bootstrap, HTTP, activation, or provider authority. */
final class ShopAccessRepository
{
    public function read(string $shop): array|\WP_Error
    {
        if(!ShopAccessPolicy::validShop($shop))return self::error('shop');
        global $wpdb;$wpdb->last_error='';
        $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::key($shop)));
        if($wpdb->last_error!=='')return self::error('read',503);
        if($value===null){$state=['revision'=>0,'grants'=>[]];$proof=$this->verifyLifecycle($shop,$state);return $proof instanceof \WP_Error?$proof:$state;}
        if(!is_string($value)||strlen($value)>1048576)return self::error('corrupt');
        $state=json_decode($value,true);
        if(!is_array($state)||!is_int($state['revision']??null)||$state['revision']<0||!is_array($state['grants']??null)||count($state['grants'])>500)return self::error('corrupt');
        foreach($state['grants'] as $id=>$grant){if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!ShopAccessPolicy::validRecord($grant)||!is_string($grant['role']??null)||!is_int($grant['grantor_id']??null)||!is_string($grant['authority_hash']??null)||!is_bool($grant['revoked']??null)||$grant['shop']!==$shop)return self::error('corrupt');}
        $proof=$this->verifyLifecycle($shop,$state);return $proof instanceof \WP_Error?$proof:$state;
    }
    /** Reconstruct the current snapshot from every durable lifecycle event, including revokes. */
    private function verifyLifecycle(string $shop,array $state): true|\WP_Error
    {
        global $wpdb;$wpdb->last_error='';
        // Enumerate both sides: a missing audit must not hide a retained revocation event.
        $eventKeys=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CASE WHEN JSON_VALID(option_value) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(option_value,'$.shop'))=BINARY %s ELSE false END LIMIT 1001",$wpdb->esc_like('digiforge_shop_access_event_').'%',$shop));
        if($wpdb->last_error!==''||!is_array($eventKeys))return self::error('lifecycle_event_read',503);
        if(count($eventKeys)>1000||count($eventKeys)!==$state['revision'])return self::error('lifecycle_revision');
        $wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT event_type,actor_id,object_id,context FROM '.\DigiForge\Database\Tables::audit_log()." WHERE object_type='shop_access' AND event_type IN ('shop_access_grant','shop_access_revoke') AND CASE WHEN JSON_VALID(context) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.shop'))=BINARY %s ELSE false END ORDER BY id ASC LIMIT 1001",$shop),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($rows))return self::error('lifecycle_read',503);
        if(count($rows)>1000||count($rows)!==$state['revision'])return self::error('lifecycle_revision');
        $grants=[];$revision=0;
        foreach($rows as $row){
            if(!is_string($row['context']??null)||strlen($row['context'])>16384)return self::error('lifecycle_audit');
            $event=json_decode($row['context'],true);$action=$event['action']??null;$grant=$event['grant']??null;$id=$event['grant_id']??null;$request=$event['request_id']??null;
            if(!is_array($event)||!in_array($action,['GRANT','REVOKE'],true)||($event['shop']??null)!==$shop||($event['revision']??null)!==++$revision||!is_int($event['actor_id']??null)||$event['actor_id']<1||(string)$event['actor_id']!==(string)($row['actor_id']??null)||($row['event_type']??null)!=='shop_access_'.strtolower($action)||!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||($row['object_id']??null)!==$id||!is_string($request)||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$request)||!is_int($event['created_at']??null)||!ShopAccessPolicy::validRecord($grant)||$grant['shop']!==$shop||!is_string($grant['role']??null)||!is_int($grant['grantor_id']??null)||!is_string($grant['authority_hash']??null)||!is_bool($grant['revoked']??null))return self::error('lifecycle_audit');
            $eventId=hash('sha256',$shop.':'.$request);$wpdb->last_error='';
            if(!in_array('digiforge_shop_access_event_'.$eventId,$eventKeys,true))return self::error('lifecycle_event');
            $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'digiforge_shop_access_event_'.$eventId));
            if($wpdb->last_error!=='')return self::error('lifecycle_event_read',503);
            if(!is_string($raw)||strlen($raw)>16384||json_decode($raw,true)!==$event)return self::error('lifecycle_event');
            if($action==='GRANT'){
                if(isset($grants[$id])||$id!==$eventId||$grant['revoked']||$grant['grantor_id']!==$event['actor_id'])return self::error('lifecycle_grant');
            }else{
                $prior=$grants[$id]??null;
                if(!is_array($prior)||$prior['revoked']||!$grant['revoked'])return self::error('lifecycle_revoke');
                // A revoker may use a different signed authority; the assigned subject and scope cannot change.
                foreach(['actor_id','shop','caps','expires_at','role','grantor_id'] as $field){if($grant[$field]!==$prior[$field])return self::error('lifecycle_revoke');}
            }
            $grants[$id]=$grant;
        }
        return $grants===$state['grants']?true:self::error('lifecycle_snapshot');
    }
    /** A mutable snapshot cannot substitute for the immutable grant and durable audit. */
    public function verifyGrantEvidence(string $id,array $grant,int $revision): true|\WP_Error
    {
        global $wpdb;$wpdb->last_error='';
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'digiforge_shop_access_event_'.$id));
        if($wpdb->last_error!=='')return self::error('grant_evidence_read',503);
        if(!is_string($raw)||strlen($raw)>16384)return self::error('grant_evidence');
        $event=json_decode($raw,true);
        if(!is_array($event)||($event['action']??null)!=='GRANT'||($event['grant_id']??null)!==$id||($event['shop']??null)!==$grant['shop']||($event['actor_id']??null)!==$grant['grantor_id']||($event['grant']??null)!==$grant||!is_int($event['revision']??null)||$event['revision']<1||$event['revision']>$revision||!is_int($event['created_at']??null)||!is_string($event['request_id']??null)||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$event['request_id'])||!hash_equals($id,hash('sha256',$grant['shop'].':'.$event['request_id'])))return self::error('grant_evidence');
        $wpdb->last_error='';
        $audit=$wpdb->get_var($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log()." WHERE event_type='shop_access_grant' AND object_type='shop_access' AND object_id=%s AND actor_id=%d ORDER BY id DESC LIMIT 1",$id,$grant['grantor_id']));
        if($wpdb->last_error!=='')return self::error('grant_audit_read',503);
        if(!is_string($audit)||strlen($audit)>16384||json_decode($audit,true)!==$event)return self::error('grant_audit');
        return true;
    }
    public function grant(int $subject,string $shop,string $role,array $caps,int $expiresAt,string $requestId,int $expectedRevision): array|\WP_Error
    {
        return $this->auditDenial($this->mutate($shop,$requestId,$expectedRevision,['subject'=>$subject,'role'=>$role,'caps'=>$caps,'expires_at'=>$expiresAt],null),$shop,'GRANT');
    }
    public function revoke(string $shop,string $grantId,string $requestId,int $expectedRevision): array|\WP_Error
    {
        return $this->auditDenial($this->mutate($shop,$requestId,$expectedRevision,[], $grantId),$shop,'REVOKE');
    }
    private function auditDenial(array|\WP_Error $result,string $shop,string $action): array|\WP_Error
    {
        if($result instanceof \WP_Error&&!Logger::write('shop_access_privileged_denied',['shop'=>$shop,'action'=>$action,'denial_code'=>$result->get_error_code()],'shop_access',$shop))return self::error('audit',503);
        return $result;
    }
    public static function authorityFor(int $actor,string $shop,array $caps,int $expiry): array|\WP_Error
    {
        $records=ShopAccessAuthority::records();if($records instanceof \WP_Error)return $records;
        foreach($records as $record){if($record['actor_id']!==$actor||$record['shop']!==$shop||$record['expires_at']<$expiry||$record['expires_at']<=time()||array_diff($caps,$record['caps'])!==[])continue;return ['record'=>$record,'hash'=>hash('sha256',(string)wp_json_encode($record))];}
        return self::error('authority');
    }
    private function mutate(string $shop,string $requestId,int $expectedRevision,array $input,?string $revoke): array|\WP_Error
    {
        $actor=get_current_user_id();
        if($actor<1||!ShopAccessPolicy::validShop($shop)||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$requestId)||$expectedRevision<0||!user_can($actor,'manage_digiforge_settings'))return self::error('permission');
        global $wpdb;$wpdb->last_error='';
        foreach([$wpdb->options,\DigiForge\Database\Tables::audit_log()] as $table){
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if($wpdb->last_error!==''||!is_string($engine)||strtoupper($engine)!=='INNODB')return self::error('transaction_storage',503);
        }
        if($wpdb->query('START TRANSACTION')===false||$wpdb->last_error!=='')return self::error('transaction',503);
        $fail=static function(\WP_Error $error)use($wpdb):\WP_Error{$wpdb->query('ROLLBACK');return $error;};
        $key=self::key($shop);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$key,'{"revision":0,"grants":[]}'));
        if($wpdb->last_error!=='')return $fail(self::error('write',503));
        $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$key));
        if($wpdb->last_error!=='')return $fail(self::error('lock',503));
        $state=$this->read($shop);if($state instanceof \WP_Error)return $fail($state);
        if($state['revision']!==$expectedRevision)return $fail(self::error('revision',409));
        if($revoke!==null){
            $grant=$state['grants'][$revoke]??null;if(!is_array($grant)||$grant['revoked'])return $fail(self::error('grant',409));
            $authority=self::authorityFor($actor,$shop,$grant['caps'],time()+1);
            $grant['revoked']=true;$id=$revoke;
        }else{
            $subject=$input['subject'];$role=get_role($input['role']);$user=get_userdata($subject);
            $grant=['actor_id'=>$subject,'shop'=>$shop,'caps'=>$input['caps'],'expires_at'=>$input['expires_at'],'source_approval_hash'=>str_repeat('0',64)];
            if($subject===$actor||!$user||!$role||!in_array($input['role'],$user->roles,true)||!ShopAccessPolicy::validRecord($grant)||$grant['expires_at']<=time()||count($state['grants'])>=500)return $fail(self::error('assignment'));
            foreach($grant['caps'] as $cap){if(!user_can($actor,$cap)||!user_can($subject,$cap)||!$role->has_cap($cap))return $fail(self::error('scope'));}
            $authority=self::authorityFor($actor,$shop,$grant['caps'],$grant['expires_at']);
            $id=hash('sha256',$shop.':'.$requestId);
            $grant+=['role'=>$input['role'],'grantor_id'=>$actor,'revoked'=>false];
        }
        if($authority instanceof \WP_Error)return $fail($authority);
        foreach($grant['caps'] as $cap){if(!user_can($actor,$cap))return $fail(self::error('scope'));}
        $grant['authority_hash']=$authority['hash'];$grant['source_approval_hash']=$authority['record']['source_approval_hash'];
        $event=['request_id'=>$requestId,'action'=>$revoke===null?'GRANT':'REVOKE','actor_id'=>$actor,'grant_id'=>$id,'shop'=>$shop,'revision'=>$expectedRevision+1,'grant'=>$grant,'created_at'=>time()];
        $eventKey='digiforge_shop_access_event_'.hash('sha256',$shop.':'.$requestId);
        $existingEvent=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",$eventKey));
        if($wpdb->last_error!=='')return $fail(self::error('read',503));
        if($existingEvent!==null)return $fail(self::error('replay',409));
        if($wpdb->insert($wpdb->options,['option_name'=>$eventKey,'option_value'=>wp_json_encode($event),'autoload'=>'no'])!==1)return $fail(self::error('replay_or_write',409));
        if(!Logger::write('shop_access_'.strtolower($event['action']),$event,'shop_access',$id))return $fail(self::error('audit',503));
        $state['grants'][$id]=$grant;$state['revision']++;
        if($wpdb->update($wpdb->options,['option_value'=>wp_json_encode($state)],['option_name'=>$key])!==1||$wpdb->last_error!=='')return $fail(self::error('write',503));
        if($wpdb->query('COMMIT')===false||$wpdb->last_error!=='')return self::error('commit_uncertain',503);
        return ['grant_id'=>$id,'revision'=>$state['revision'],'shop'=>$shop,'action'=>$event['action'],'external_execution_authorized'=>false];
    }
    private static function key(string $shop): string{return 'digiforge_shop_access_state_'.$shop;}
    private static function error(string $code,int $status=403): \WP_Error{return new \WP_Error('digiforge_shop_access_'.$code,'Explicit reviewed shop access is required; no automatic retry.',['status'=>$status,'retry_permitted'=>false]);}
}
