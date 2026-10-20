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
        return $this->transaction($scopeHash,function()use($document,$scope,$access,$observation,$supersedes,$scopeHash,$hash):array{
            global $wpdb;
            if(NativeEvidenceAccess::validate($scope)!==$access)throw new \RuntimeException();
            $existing=$this->row($hash,$scopeHash);
            if($existing!==null)return $this->decode($existing,$scope);
            // An audit without its original row is missing history, never a fresh retry.
            if($this->audits($hash)!==[])throw new \RuntimeException();
            $state=$observation['source_identity_hashes']===[]?'OBSERVATION_ONLY':'STORED_UNVERIFIED';
            $claims=[];
            foreach($observation['source_identity_keys']as$key){
                $claim=self::claimHash($scopeHash,$key);$owner=$this->claim($claim);$claims[$claim]=$owner;
                if($owner!==null){
                    $row=$this->row($owner,$scopeHash);if($row===null)throw new \RuntimeException();
                    $this->decode($row,$scope);$state='QUARANTINED';
                }
            }
            if($supersedes!==''){
                $parent=$this->row($supersedes,$scopeHash);if($parent===null||$claims===[])throw new \RuntimeException();
                $this->decode($parent,$scope);$p=$this->payload($parent);
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
        try{
            $row=$this->row($hash,self::scopeHash($scope));if($row===null)throw new \RuntimeException();
            $result=$this->decode($row,$scope);
            if(NativeEvidenceAccess::validate($scope)!==$access)throw new \RuntimeException();return $result;
        }catch(\Throwable){return NativeEvidenceAccess::error();}
    }
    private function decode(array $row,array $scope):array
    {
        $packet=$this->payload($row);
        if(($packet['version']??null)!==1||Validator::canonicalJson($packet['scope']??[])!==Validator::canonicalJson($scope)||!is_array($packet['document']??null)||!is_string($packet['supersedes']??null)
            ||!in_array($packet['state']??null,['STORED_UNVERIFIED','OBSERVATION_ONLY','QUARANTINED','UNREVIEWED_CORRECTION'],true))throw new \RuntimeException();
        $doc=$packet['document'];$context=self::context($scope);$stage=EtsyNativeObservationStage::preview([$doc],$context,$context,time());
        if(self::scopeHash($scope)!==$row['scope_hash']||self::recordHash($doc,$row['scope_hash'],$packet['supersedes'])!==$row['record_hash']
            ||($packet['record_hash']??null)!==$row['record_hash']||hash('sha256',$doc['raw'])!==$row['source_hash'])throw new \RuntimeException();
        $audits=$this->audits($row['record_hash']);
        if(count($audits)!==1||$audits[0]['context']!==wp_json_encode(['record_hash'=>$row['record_hash'],'ciphertext_sha256'=>hash('sha256',$row['ciphertext'])]))throw new \RuntimeException();
        foreach($stage['documents'][0]['observation']['source_identity_keys']as$key){
            $owner=$this->claim(self::claimHash($row['scope_hash'],$key));if($owner===null)throw new \RuntimeException();
            $original=$this->row($owner,$row['scope_hash']);if($original===null)throw new \RuntimeException();
            // Authenticate the claim's immutable original without recursive chain traversal.
            $p=$this->payload($original);if(Validator::canonicalJson($p['scope']??[])!==Validator::canonicalJson($scope)||self::recordHash($p['document'],$row['scope_hash'],$p['supersedes'])!==$owner
                ||hash('sha256',$p['document']['raw'])!==$original['source_hash'])throw new \RuntimeException();
            $originalStage=EtsyNativeObservationStage::preview([$p['document']],$context,$context,time());
            if(!in_array($key,$originalStage['documents'][0]['observation']['source_identity_keys'],true))throw new \RuntimeException();
            $proof=$this->audits($owner);if(count($proof)!==1||$proof[0]['context']!==wp_json_encode(['record_hash'=>$owner,'ciphertext_sha256'=>hash('sha256',$original['ciphertext'])]))throw new \RuntimeException();
        }
        if($packet['supersedes']!==''){
            $parent=$this->row($packet['supersedes'],$row['scope_hash']);
            if($parent===null||(int)$parent['id']>=(int)$row['id'])throw new \RuntimeException();
            $p=$this->payload($parent);if(Validator::canonicalJson($p['scope']??[])!==Validator::canonicalJson($scope)||self::recordHash($p['document'],$row['scope_hash'],$p['supersedes'])!==$packet['supersedes'])throw new \RuntimeException();
        }
        return ['record_hash'=>$row['record_hash'],'raw_source_sha256'=>$row['source_hash'],'state'=>$packet['state'],'supersedes'=>$packet['supersedes'],
            'authenticity'=>'UNKNOWN','ownership'=>'LOCAL_REVIEW_ONLY','settlement'=>'UNKNOWN','accounting_import_authorized'=>false,
            'transaction_recognition_authorized'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false];
    }
    private function payload(array $row):array
    {
        $plain=CredentialVault::decryptExisting($row['ciphertext'],'finance-native:v1:'.$row['record_hash']);
        $packet=json_decode($plain,true,32,JSON_THROW_ON_ERROR);if(!is_array($packet))throw new \RuntimeException();return $packet;
    }
    private function row(string $hash,string $scopeHash):?array
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::records().' WHERE record_hash=%s AND scope_hash=%s',$hash,$scopeHash),ARRAY_A);
        if(QueryEvidence::failed())throw new \RuntimeException();return is_array($row)?$row:null;
    }
    private function claim(string $hash):?string
    {
        global $wpdb;$wpdb->last_error='';$value=$wpdb->get_var($wpdb->prepare('SELECT record_hash FROM '.Schema::claims().' WHERE claim_hash=%s',$hash));
        if(QueryEvidence::failed())throw new \RuntimeException();return is_string($value)?$value:null;
    }
    private function audits(string $hash):array
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s LIMIT 2','finance_native_evidence',$hash,'finance_native_evidence_appended'),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($rows))throw new \RuntimeException();return $rows;
    }
    private static function context(array $scope):array{return array_intersect_key($scope,array_flip(['shop_key','environment','business_id','store_id','external_shop_id','external_account_id','integration_id','source_contract_sha256']));}
    private static function scopeHash(array $scope):string{return Validator::hash(array_diff_key($scope,array_flip(['binding_hash','binding_revision'])));}
    private static function recordHash(array $document,string $scopeHash,string $parent):string{return Validator::hash(['scope'=>$scopeHash,'schema'=>$document['native_schema'],'source_sha256'=>hash('sha256',$document['raw']),'supersedes'=>$parent]);}
    private static function claimHash(string $scopeHash,string $identity):string{return hash('sha256',$scopeHash.':'.$identity);}

    private function transaction(string $scopeHash,callable $work):array|WP_Error
    {
        global $wpdb;$lock='df-native:'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix.':'.$scopeHash),0,50);$locked=false;$started=false;$active=false;
        $old=$wpdb->suppress_errors(true);self::$writing=true;
        try{
            $wpdb->last_error='';$locked=(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1';if(!$locked||QueryEvidence::failed())throw new \RuntimeException();
            $wpdb->last_error='';$inTransaction=$wpdb->get_var('SELECT @@session.in_transaction');if(QueryEvidence::failed()||!in_array((string)$inTransaction,['0','1'],true))throw new \RuntimeException();
            $active=(string)$inTransaction==='1';
            if($wpdb->query($active?'SAVEPOINT df_native_evidence':'START TRANSACTION')===false)throw new \RuntimeException();$started=true;
            $result=$work();if($wpdb->query($active?'RELEASE SAVEPOINT df_native_evidence':'COMMIT')===false)throw new \RuntimeException();$started=false;return $result;
        }catch(\Throwable){
            if($started){$wpdb->query($active?'ROLLBACK TO SAVEPOINT df_native_evidence':'ROLLBACK');if($active)$wpdb->query('RELEASE SAVEPOINT df_native_evidence');}
            return NativeEvidenceAccess::error();
        }finally{if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$wpdb->suppress_errors($old);self::$writing=false;}
    }
}
