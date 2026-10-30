<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\NativeFinanceEvidenceSchema as Schema;
use DigiForge\Database\QueryEvidence;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Security\Logger;
use WP_Error;

/** Offline encrypted original custody only. No HTTP, accounting, retention or deletion API. */
final class NativeEvidenceStore
{
    private static bool $writing=false;
    public const MAX_SCOPE_RECORDS=500;

    public function append(array $document,array $scope,string $supersedes=''):array|WP_Error
    {
        $access=NativeEvidenceAccess::validate($scope);
        if($access instanceof WP_Error||!Schema::ready()||self::$writing)return NativeEvidenceAccess::error();
        try{
            $context=self::context($scope);
            $stage=EtsyNativeObservationStage::preview([$document],$context,$context,time());
            $observation=$stage['documents'][0]['observation'];
            if($supersedes!==''&&preg_match('/^[a-f0-9]{64}$/D',$supersedes)!==1)throw new \RuntimeException();
            $scopeHash=self::scopeHash($scope);$hash=self::recordHash($document,$scopeHash,$supersedes);
            // Missing keys must fail even on retries, without provisioning key material.
            $probe=CredentialVault::encryptExisting('native-evidence-key-probe','finance-native:key-probe');
            if(CredentialVault::decryptExisting($probe,'finance-native:key-probe')!=='native-evidence-key-probe')throw new \RuntimeException();
        }catch(\Throwable){return NativeEvidenceAccess::error();}
        return $this->transaction($scopeHash,$scope,$access,function()use($document,$scope,$access,$observation,$supersedes,$scopeHash,$hash):array{
            global $wpdb;
            if(NativeEvidenceAccess::validate($scope)!==$access)throw new \RuntimeException();
            $existing=$this->row($hash,$scopeHash);
            if($existing!==null)return $this->decode($existing,$scope);
            // An audit without its original row is missing history, never a fresh retry.
            if($this->audits($hash)!==[])throw new \RuntimeException();
            $this->assertNoOrphanHistory();
            $wpdb->last_error='';$history=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::records().' WHERE scope_hash=%s ORDER BY id DESC LIMIT 1',$scopeHash),ARRAY_A);
            if(QueryEvidence::failed())throw new \RuntimeException();
            if(is_array($history))$this->decode($history,$scope,false);
            $state=$observation['source_identity_hashes']===[]?'OBSERVATION_ONLY':'STORED_UNVERIFIED';
            $claims=[];
            foreach($observation['source_identity_keys']as$key){
                $claim=self::claimHash($scopeHash,$key);$owner=$this->claim($claim);$claims[$claim]=$owner;
                if($owner!==null){
                    $row=$this->row($owner,$scopeHash);if($row===null||Validator::canonicalJson($this->payload($row)['scope'])!==Validator::canonicalJson($scope))throw new \RuntimeException();
                    // The complete existing history was authenticated above.
                    $state='QUARANTINED';
                }
            }
            if($supersedes!==''){
                $parent=$this->row($supersedes,$scopeHash);if($parent===null||$claims===[])throw new \RuntimeException();
                $p=$this->payload($parent);
                $parentStage=EtsyNativeObservationStage::preview([$p['document']],self::context($scope),self::context($scope),time());
                if($p['document']['native_schema']!==$document['native_schema']||$parentStage['identity_hashes']===[]
                    ||array_keys($parentStage['identity_hashes'])!==array_keys($observation['source_identity_hashes']))throw new \RuntimeException();
                // Linking a correction never approves a category, amount or replacement transaction.
                $state='UNREVIEWED_CORRECTION';
            }
            $packet=['version'=>1,'scope'=>$scope,'document'=>$document,'state'=>$state,'supersedes'=>$supersedes,'record_hash'=>$hash,'created_by'=>get_current_user_id()];
            $cipher=CredentialVault::encryptExisting(json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'finance-native:v1:'.$hash);
            $wpdb->last_error='';
            if($wpdb->insert(Schema::records(),['record_hash'=>$hash,'scope_hash'=>$scopeHash,'source_hash'=>hash('sha256',$document['raw']),'ciphertext'=>$cipher,'created_at'=>gmdate('Y-m-d H:i:s')])!==1||QueryEvidence::failed())throw new \RuntimeException();
            foreach($claims as$claim=>$owner)if($owner===null){
                $wpdb->last_error='';if($wpdb->insert(Schema::claims(),['claim_hash'=>$claim,'record_hash'=>$hash])!==1||QueryEvidence::failed())throw new \RuntimeException();
            }
            if(!Logger::write('finance_native_evidence_appended',['record_hash'=>$hash,'ciphertext_sha256'=>hash('sha256',$cipher)],'finance_native_evidence',$hash))throw new \RuntimeException();
            if(NativeEvidenceAccess::validate($scope)!==$access)throw new \RuntimeException();
            $row=$this->row($hash,$scopeHash);if($row===null)throw new \RuntimeException();
            return $this->decode($row,$scope);
        });
    }

    /** Returns only integrity/state digests; raw financial content has no public retrieval endpoint. */
    public function receipt(string $hash,array $scope):array|WP_Error
    {
        $access=NativeEvidenceAccess::validate($scope);
        if($access instanceof WP_Error||!Schema::ready()||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)return NativeEvidenceAccess::error();
        if(self::$writing)return NativeEvidenceAccess::error();
        return $this->transaction(self::scopeHash($scope),$scope,$access,function()use($hash,$scope):array{
            $row=$this->row($hash,self::scopeHash($scope));if($row===null)throw new \RuntimeException();
            return $this->decode($row,$scope);
        });
    }
    /** Compare an encrypted copy only; claims, audit, keys and restoration are not certified by a digest. */
    public function inspectBackupCopy(string $hash,array $scope,string $ciphertext):array|WP_Error
    {
        if($ciphertext===''||strlen($ciphertext)>262144)return NativeEvidenceAccess::error();
        $receipt=$this->receipt($hash,$scope);if($receipt instanceof WP_Error)return $receipt;
        if(!hash_equals($receipt['ciphertext_sha256'],hash('sha256',$ciphertext)))return NativeEvidenceAccess::error();
        return $receipt+['backup_copy_digest_matches'=>true,'restore_verified'=>false,'restore_authorized'=>false,
            'key_recovery_authorized'=>false,'retention_policy'=>'UNAPPROVED','legal_hold_policy'=>'UNAPPROVED',
            'retention_actions_authorized'=>false,'automatic_cleanup_authorized'=>false,'erasure_authorized'=>false];
    }

    private function decode(array $row,array $scope,bool $requireCurrentScope=true):array
    {
        // Reconstruct immutable first claims and complete correction ancestry from
        // authenticated originals, rather than trusting the mutable claim index.
        $this->assertNoOrphanHistory();
        global $wpdb;$scopeHash=self::scopeHash($scope);$wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Schema::records().' WHERE scope_hash=%s ORDER BY id ASC LIMIT %d FOR UPDATE',$scopeHash,self::MAX_SCOPE_RECORDS+1),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($rows)||count($rows)>self::MAX_SCOPE_RECORDS)throw new \RuntimeException();
        $seen=[];$expectedClaims=[];$selected=null;
        foreach($rows as$original){
            $packet=$this->payload($original);$storedScope=$packet['scope']??null;
            if(($packet['version']??null)!==1||!is_array($storedScope)||self::scopeHash($storedScope)!==$scopeHash
                ||!is_array($packet['document']??null)||!is_string($packet['supersedes']??null))throw new \RuntimeException();
            $doc=$packet['document'];$context=self::context($storedScope);$stage=EtsyNativeObservationStage::preview([$doc],$context,$context,time());
            $hash=$original['record_hash'];$parent=$packet['supersedes'];
            if($original['scope_hash']!==$scopeHash||self::recordHash($doc,$scopeHash,$parent)!==$hash
                ||($packet['record_hash']??null)!==$hash||hash('sha256',$doc['raw'])!==$original['source_hash'])throw new \RuntimeException();
            $proof=$this->audits($hash);
            if(count($proof)!==1||(int)$proof[0]['actor_id']!==($packet['created_by']??null)||$proof[0]['context']!==wp_json_encode(['record_hash'=>$hash,'ciphertext_sha256'=>hash('sha256',$original['ciphertext'])]))throw new \RuntimeException();
            $identities=$stage['documents'][0]['observation']['source_identity_keys'];
            $state=$identities===[]?'OBSERVATION_ONLY':'STORED_UNVERIFIED';
            foreach($identities as$key)if(isset($expectedClaims[self::claimHash($scopeHash,$key)]))$state='QUARANTINED';
            if($parent!==''){
                $ancestor=$seen[$parent]??null;
                if($ancestor===null||$identities===[]||$ancestor['identities']!==$identities||$ancestor['schema']!==$doc['native_schema']
                    ||Validator::canonicalJson($ancestor['scope'])!==Validator::canonicalJson($storedScope))throw new \RuntimeException();
                $state='UNREVIEWED_CORRECTION';
            }
            if(($packet['state']??null)!==$state)throw new \RuntimeException();
            foreach($identities as$key){$claim=self::claimHash($scopeHash,$key);if(!isset($expectedClaims[$claim]))$expectedClaims[$claim]=$hash;}
            $seen[$hash]=['identities'=>$identities,'schema'=>$doc['native_schema'],'scope'=>$storedScope];
            if($hash===$row['record_hash'])$selected=$packet;
        }
        foreach($expectedClaims as$claim=>$owner)if($this->claim($claim)!==$owner)throw new \RuntimeException();
        if($selected===null||($requireCurrentScope&&Validator::canonicalJson($selected['scope'])!==Validator::canonicalJson($scope)))throw new \RuntimeException();
        return ['record_hash'=>$row['record_hash'],'ciphertext_sha256'=>hash('sha256',$row['ciphertext']),'raw_source_sha256'=>$row['source_hash'],'state'=>$selected['state'],'supersedes'=>$selected['supersedes'],
            'authenticity'=>'UNKNOWN','ownership'=>'LOCAL_REVIEW_ONLY','settlement'=>'UNKNOWN','accounting_import_authorized'=>false,
            'transaction_recognition_authorized'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false];
    }
    private function assertNoOrphanHistory():void
    {
        global $wpdb;$records=Schema::records();$audit=Tables::audit_log();$claims=Schema::claims();$wpdb->last_error='';
        // All-four Finance authority is already mandatory. Return only existence,
        // never another scope's identity or raw content, for missing global history.
        $orphan=$wpdb->get_var($wpdb->prepare("SELECT a.id FROM {$audit} a LEFT JOIN {$records} r ON r.record_hash=a.object_id WHERE a.object_type=%s AND r.id IS NULL LIMIT 1 FOR UPDATE",'finance_native_evidence'));
        if(QueryEvidence::failed()||$orphan!==null)throw new \RuntimeException();
        $orphan=$wpdb->get_var("SELECT c.claim_hash FROM {$claims} c LEFT JOIN {$records} r ON r.record_hash=c.record_hash WHERE r.id IS NULL LIMIT 1 FOR UPDATE");
        if(QueryEvidence::failed()||$orphan!==null)throw new \RuntimeException();
    }
    private function payload(array $row):array
    {
        $plain=CredentialVault::decryptExisting($row['ciphertext'],'finance-native:v1:'.$row['record_hash']);
        $packet=json_decode($plain,true,32,JSON_THROW_ON_ERROR);if(!is_array($packet))throw new \RuntimeException();return $packet;
    }
    private function row(string $hash,string $scopeHash):?array
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::records().' WHERE record_hash=%s AND scope_hash=%s FOR UPDATE',$hash,$scopeHash),ARRAY_A);
        if(QueryEvidence::failed())throw new \RuntimeException();return is_array($row)?$row:null;
    }
    private function claim(string $hash):?string
    {
        global $wpdb;$wpdb->last_error='';$value=$wpdb->get_var($wpdb->prepare('SELECT record_hash FROM '.Schema::claims().' WHERE claim_hash=%s FOR UPDATE',$hash));
        if(QueryEvidence::failed())throw new \RuntimeException();return is_string($value)?$value:null;
    }
    private function audits(string $hash):array
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT actor_id,context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s LIMIT 2 FOR UPDATE','finance_native_evidence',$hash,'finance_native_evidence_appended'),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($rows))throw new \RuntimeException();return $rows;
    }
    private static function context(array $scope):array{return array_intersect_key($scope,array_flip(['shop_key','environment','business_id','store_id','external_shop_id','external_account_id','integration_id','source_contract_sha256']));}
    private static function scopeHash(array $scope):string{return Validator::hash(array_diff_key($scope,array_flip(['binding_hash','binding_revision'])));}
    private static function recordHash(array $document,string $scopeHash,string $parent):string{return Validator::hash(['scope'=>$scopeHash,'schema'=>$document['native_schema'],'source_sha256'=>hash('sha256',$document['raw']),'supersedes'=>$parent]);}
    private static function claimHash(string $scopeHash,string $identity):string{return hash('sha256',$scopeHash.':'.$identity);}

    private function transaction(string $scopeHash,array $scope,array $access,callable $work):array|WP_Error
    {
        global $wpdb;$lock='df-native:'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix.':'.$scopeHash),0,50);$locked=false;$started=false;$active=false;
        $old=$wpdb->suppress_errors(true);self::$writing=true;
        try{
            $wpdb->last_error='';$locked=(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1';if(!$locked||QueryEvidence::failed())throw new \RuntimeException();
            $wpdb->last_error='';$inTransaction=$wpdb->get_var('SELECT @@session.in_transaction');if(QueryEvidence::failed()||!in_array((string)$inTransaction,['0','1'],true))throw new \RuntimeException();
            $active=(string)$inTransaction==='1';
            if($wpdb->query($active?'SAVEPOINT df_native_evidence':'START TRANSACTION')===false)throw new \RuntimeException();$started=true;
            if(NativeEvidenceAccess::lockCurrent($scope)!==$access)throw new \RuntimeException();
            $result=$work();if(NativeEvidenceAccess::validate($scope)!==$access)throw new \RuntimeException();
            if($wpdb->query($active?'RELEASE SAVEPOINT df_native_evidence':'COMMIT')===false)throw new \RuntimeException();$started=false;return $result;
        }catch(\Throwable){
            if($started){$wpdb->query($active?'ROLLBACK TO SAVEPOINT df_native_evidence':'ROLLBACK');if($active)$wpdb->query('RELEASE SAVEPOINT df_native_evidence');}
            return NativeEvidenceAccess::error();
        }finally{if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$wpdb->suppress_errors($old);self::$writing=false;}
    }
}
