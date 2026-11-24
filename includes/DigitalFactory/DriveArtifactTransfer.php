<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Operations\RecoveryDispatchLedger as Ledger;
use DigiForge\ProductFactory\AssetStorage;
use DigiForge\ProductFactory\ExternalDocumentEvidence;
use DigiForge\ProductFactory\ExternalDocumentRevision;
use DigiForge\Security\Logger;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Source-bound offline operation orchestration. There is deliberately no live transport. */
final class DriveArtifactTransfer
{
    private const STAGES=['folder','original_upload','original_readback','native_conversion','native_readback','pdf_export','native_final'];
    public function prepare(int $revisionId,int $productId,int $versionId,string $sourceSha,string $requestId,string $folder=DriveTransferRequest::FOLDER): array|WP_Error
    {
        if (!self::local()||$folder!==DriveTransferRequest::FOLDER||!self::sha($sourceSha)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$requestId)!==1) return self::error('scope');
        $plan=['profile'=>'digiforge-drive-offline-v1','actor_id'=>get_current_user_id(),'installation_sha256'=>hash('sha256',site_url()),'environment'=>wp_get_environment_type(),'shop_key'=>'digital','folder_id'=>$folder,'revision_id'=>$revisionId,'product_id'=>$productId,'product_version_id'=>$versionId,'source_sha256'=>$sourceSha,'request_id'=>$requestId];
        $source=$this->source($plan);if($source instanceof WP_Error)return $source;
        $identity=$plan;unset($identity['request_id'],$identity['actor_id']);
        $plan['id']=self::hash($identity);$name=self::name($plan['id'],'plan');$existing=Ledger::read($name);if($existing instanceof WP_Error)return self::error('persistence');
        if($existing!==[])return $this->load($plan['id']);
        if($this->auditExists($plan['id'],'drive_transfer_prepared')!==false)return self::error('history');
        $plan['expires_at']=time()+3600;
        if(!Ledger::insert($name,$plan))return self::error('claim');
        if(!Logger::write('drive_transfer_prepared',['plan_sha256'=>self::hash($plan),'acceptance'=>'UNVERIFIED','external_execution_authorized'=>false],'drive_transfer',$plan['id']))return self::error('audit');
        return $plan;
    }
    /** Always denies: no Google execution grant/credential path has been authorized or installed. */
    public function execute(string $planId): WP_Error
    {
        $plan=$this->load($planId);if($plan instanceof WP_Error)return $plan;
        if(Settings::get('stop_all',true)||Settings::safety_locked()||!Settings::get('automation_armed',false))return self::error('safety_locked');
        return self::error('scoped_google_authorization_unavailable');
    }
    /** Explicit local synthetic transport only, never usable as the production dispatch path. */
    public function runOffline(string $planId,DriveOfflineTransport $transport): array|WP_Error
    {
        $plan=$this->load($planId);if($plan instanceof WP_Error)return $plan;
        $completed=[];
        foreach(self::STAGES as $stage){
            $current=$this->load($planId);if($current instanceof WP_Error)return $current;
            $source=$this->source($plan);if($source instanceof WP_Error)return $source;
            $intent=self::name($planId,$stage.'-intent');$resultName=self::name($planId,$stage.'-result');
            $claim=Ledger::read($intent);$result=Ledger::read($resultName);
            if($claim instanceof WP_Error||$result instanceof WP_Error)return self::error('persistence');
            $expected=['plan_id'=>$planId,'stage'=>$stage,'source_sha256'=>$plan['source_sha256']];
            if($claim!==[]&&$claim!==$expected)return self::error('history');
            if($result!==[]){
                if($claim===[]||!$this->resultProof($planId,$stage,$result))return self::error('history');
                if(($result['state']??'')!=='CONFIRMED'){
                    $reconciled=in_array($stage,['original_upload','native_conversion'],true)?$this->reconcile($plan,$stage,$result,$transport):$this->retryRead($plan,$stage,$result,$transport,$completed);if($reconciled instanceof WP_Error||($reconciled['state']??null)!=='CONFIRMED')return $reconciled;$result=$reconciled;
                }
                $completed[$stage]=$result['value'];continue;
            }
            // Missing result after a committed claim is uncertain, never permission to repeat POST.
            if($claim!==[]||$this->auditExists($planId,'drive_transfer_intent',$stage)!==false)return $this->unknown($planId,$stage);
            if(!Ledger::insert($intent,$expected))return self::error('claim');
            if(!Logger::write('drive_transfer_intent',['stage'=>$stage,'claim_sha256'=>self::hash($expected)],'drive_transfer',$planId))return self::error('audit');
            try{
                $response=$transport->exchange(DriveTransferRequest::build($stage,$plan,$completed),$source['bytes']);
                // A callback cannot lend stale actor/scope/hash authority to result acceptance.
                if($this->load($planId) instanceof WP_Error)return self::error('scope');
                $value=$this->validate($stage,$response,$plan,$source,$completed);
                $record=$value instanceof WP_Error?['state'=>'UNKNOWN','value'=>[]]:['state'=>'CONFIRMED','value'=>$value];
            }catch(\Throwable){$record=['state'=>'UNKNOWN','value'=>[]];}
            if(!Ledger::insert($resultName,$record))return self::error('persistence');
            if(!Logger::write('drive_transfer_result',['stage'=>$stage,'result_sha256'=>self::hash($record),'state'=>$record['state'],'synthetic_only'=>true],'drive_transfer',$planId))return self::error('audit');
            if($record['state']!=='CONFIRMED')return $this->unknown($planId,$stage);
            $completed[$stage]=$record['value'];
        }
        if(($completed['native_readback']['version']??null)!==($completed['native_final']['version']??null))return self::error('native_version_drift');
        if($this->load($planId) instanceof WP_Error)return self::error('scope');
        $source=$this->source($plan);if($source instanceof WP_Error)return $source;
        $pdf=AssetStorage::absolutePath($completed['pdf_export']['storage_reference']);
        if($pdf===null||is_link($pdf)||hash_file('sha256',$pdf)!==$completed['pdf_export']['sha256'])return self::error('integrity');
        $qa=ExternalDocumentEvidence::inspect($source['path'],$pdf);if($qa instanceof WP_Error)return self::error('export_qa');
        if($qa['source_sha256']!==$plan['source_sha256']||$qa['page_count']!==$source['page_count'])return self::error('export_qa');
        if($this->load($planId) instanceof WP_Error)return self::error('scope');
        return ['plan_id'=>$planId,'state'=>'OFFLINE_VERIFIED','original_file_id'=>$completed['original_upload']['id'],'native_file_id'=>$completed['native_conversion']['id'],'native_version'=>$completed['native_final']['version'],'source_sha256'=>$plan['source_sha256'],'pdf_export'=>$completed['pdf_export'],'technical_qa'=>$qa,'external_actions_performed'=>false,'provider_acceptance'=>'UNKNOWN','commercial_approval'=>false,'production_authorized'=>false];
    }
    private function retryRead(array $plan,string $stage,array $unknown,DriveOfflineTransport $transport,array $completed): array|WP_Error
    {
        if(!Ledger::insert(self::name($plan['id'],$stage.'-read-retry'),['plan_id'=>$plan['id'],'stage'=>$stage]))return $this->unknown($plan['id'],$stage);
        $source=$this->source($plan);if($source instanceof WP_Error)return $source;
        try{
            $r=$transport->exchange(DriveTransferRequest::build($stage,$plan,$completed),$source['bytes']);
            if($this->load($plan['id']) instanceof WP_Error)return self::error('scope');
            $value=$this->validate($stage,$r,$plan,$source,$completed);if($value instanceof WP_Error)return $this->unknown($plan['id'],$stage);
            $record=['state'=>'CONFIRMED','value'=>$value];
            if(!Logger::write('drive_transfer_reconciled',['stage'=>$stage,'result_sha256'=>self::hash($record),'previous_sha256'=>self::hash($unknown),'synthetic_only'=>true],'drive_transfer',$plan['id']))return self::error('audit');
            if(!Ledger::compareAndSwap(self::name($plan['id'],$stage.'-result'),$unknown,$record))return self::error('persistence');
            return $record;
        }catch(\Throwable){return $this->unknown($plan['id'],$stage);}
    }
    private function reconcile(array $plan,string $stage,array $unknown,DriveOfflineTransport $transport): array|WP_Error
    {
        if(!Ledger::insert(self::name($plan['id'],$stage.'-reconcile'),['plan_id'=>$plan['id'],'stage'=>$stage]))return $this->unknown($plan['id'],$stage);
        try{
            $r=$transport->exchange(DriveTransferRequest::reconciliation($stage,$plan),'');
            if($this->load($plan['id']) instanceof WP_Error)return self::error('scope');
            if(($r['status']??null)!==200||!is_array($r['files']??null)||count($r['files'])!==1||!empty($r['nextPageToken']))return $this->unknown($plan['id'],$stage);
            $value=$this->metadata($r['files'][0],$stage,$plan);if($value instanceof WP_Error)return $value;
            $record=['state'=>'CONFIRMED','value'=>$value];
            if(!Logger::write('drive_transfer_reconciled',['stage'=>$stage,'result_sha256'=>self::hash($record),'previous_sha256'=>self::hash($unknown),'synthetic_only'=>true],'drive_transfer',$plan['id']))return self::error('audit');
            if(!Ledger::compareAndSwap(self::name($plan['id'],$stage.'-result'),$unknown,$record))return self::error('persistence');
            return $record;
        }catch(\Throwable){return $this->unknown($plan['id'],$stage);}
    }
    private function validate(string $stage,array $r,array $plan,array $source,array $completed): array|WP_Error
    {
        if(($r['status']??null)!==200)return self::error('response');
        if($stage==='folder'){
            $m=$r['metadata']??[];
            return ($m['id']??null)===DriveTransferRequest::FOLDER&&($m['mimeType']??null)==='application/vnd.google-apps.folder'&&($m['trashed']??null)===false&&($m['capabilities']['canAddChildren']??null)===true?['id'=>DriveTransferRequest::FOLDER]:self::error('folder');
        }
        if(in_array($stage,['original_upload','native_conversion','native_readback','native_final'],true)){
            $role=$stage==='original_upload'?'original_upload':'native_conversion';$m=$this->metadata($r['metadata']??[],$role,$plan);if($m instanceof WP_Error)return $m;
            if($stage==='native_conversion'&&$m['id']===$completed['original_upload']['id'])return self::error('identity');
            if(in_array($stage,['native_readback','native_final'],true)&&$m['id']!==$completed['native_conversion']['id'])return self::error('identity');
            return $m;
        }
        $bytes=$r['bytes']??null;
        if(!is_string($bytes)||$bytes===''||strlen($bytes)>($stage==='pdf_export'?10000000:DriveTransferRequest::LIMIT))return self::error('response');
        $hash=hash('sha256',$bytes);
        if($stage==='original_readback')return hash_equals($plan['source_sha256'],$hash)?['sha256'=>$hash]:self::error('integrity');
        if(!str_starts_with($bytes,'%PDF-'))return self::error('response');
        if(!AssetStorage::ensureProtectedRoot())return self::error('storage');
        $ref=AssetStorage::RELATIVE_ROOT.'/drive-offline-'.$plan['id'].'-'.$hash.'.pdf';$uploads=wp_upload_dir();$path=trailingslashit($uploads['basedir']).$ref;
        // Exclusive immutable output; reject same-byte symlink substitutions as well.
        for($part=$path;;$part=dirname($part)){if(is_link($part))return self::error('storage');if(dirname($part)===$part)break;}
        if(is_file($path)){if(hash_file('sha256',$path)!==$hash)return self::error('storage');}
        else{$f=@fopen($path,'x');if($f===false)return self::error('storage');try{if(fwrite($f,$bytes)!==strlen($bytes))return self::error('storage');}finally{fclose($f);}}
        return ['storage_reference'=>$ref,'sha256'=>$hash,'byte_size'=>strlen($bytes)];
    }
    private function metadata(mixed $m,string $role,array $plan): array|WP_Error
    {
        if(!is_array($m)||!DriveTransferRequest::validId($m['id']??null)||$m['id']===DriveTransferRequest::FOLDER||($m['mimeType']??null)!==($role==='original_upload'?DriveTransferRequest::DOCX:DriveTransferRequest::DOC)||($m['parents']??null)!==[DriveTransferRequest::FOLDER]||($m['trashed']??null)!==false||!is_array($m['appProperties']??null)||self::hash($m['appProperties'])!==self::hash(['digiforge_transfer'=>$plan['id'],'digiforge_role'=>$role,'source_sha256'=>$plan['source_sha256']])||($m['capabilities']['canDownload']??null)!==true||!is_string($m['version']??null)||preg_match('/^[1-9][0-9]{0,19}$/D',$m['version'])!==1)return self::error('metadata');
        return ['id'=>$m['id'],'version'=>$m['version']];
    }
    /** @phpstan-impure */
    private function load(string $id): array|WP_Error
    {
        if(!self::local()||!self::sha($id))return self::error('scope');
        $access=ShopAccess::authorize('digital','manage_digiforge_products');if($access instanceof WP_Error)return $access;
        $plan=Ledger::read(self::name($id,'plan'));if($plan instanceof WP_Error||$plan===[])return self::error('history');
        if(($plan['id']??null)!==$id||($plan['actor_id']??null)!==get_current_user_id()||($plan['installation_sha256']??null)!==hash('sha256',site_url())||($plan['environment']??null)!==wp_get_environment_type()||($plan['folder_id']??null)!==DriveTransferRequest::FOLDER||!is_int($plan['expires_at']??null)||$plan['expires_at']<=time())return self::error('scope');
        $proof=$this->auditExists($id,'drive_transfer_prepared');if(!is_array($proof)||($proof['plan_sha256']??null)!==self::hash($plan))return self::error('audit');
        $source=$this->source($plan);return $source instanceof WP_Error?$source:$plan;
    }
    /** @phpstan-impure */
    private function source(array $plan): array|WP_Error
    {
        if(!self::local())return self::error('scope');
        $access=ShopAccess::authorize('digital','manage_digiforge_products');if($access instanceof WP_Error)return $access;
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_revisions().' WHERE id=%d',(int)($plan['revision_id']??0)),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($row))return self::error('source');
        $validated=ExternalDocumentRevision::validate($row);if($validated instanceof WP_Error)return $validated;
        $p=json_decode((string)$row['provenance'],true);$b=$p['binding']??[];
        if(($b['product_id']??null)!==($plan['product_id']??null)||($b['product_version_id']??null)!==($plan['product_version_id']??null)||($p['source_sha256']??null)!==($plan['source_sha256']??null))return self::error('scope');
        $path=AssetStorage::absolutePath($p['source_storage_reference']??'');if($path===null||filesize($path)>DriveTransferRequest::LIMIT)return self::error('source');
        $bytes=file_get_contents($path);if(!is_string($bytes)||!hash_equals($plan['source_sha256'],hash('sha256',$bytes)))return self::error('integrity');
        return ['path'=>$path,'bytes'=>$bytes,'page_count'=>$p['evidence']['page_count']??0];
    }
    /** @phpstan-impure */
    private function resultProof(string $id,string $stage,array $record): bool
    {
        foreach(['drive_transfer_result','drive_transfer_reconciled']as$event){$proof=$this->auditExists($id,$event,$stage);if(is_array($proof)&&($proof['result_sha256']??null)===self::hash($record))return true;}return false;
    }
    /** @phpstan-impure */
    private function auditExists(string $id,string $event,?string $stage=null): array|bool
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s ORDER BY id ASC LIMIT 20','drive_transfer',$id,$event),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($rows))return true;$matches=[];
        foreach($rows as$row){$context=json_decode($row['context'],true);if(!is_array($context))return true;if($stage===null||($context['stage']??null)===$stage)$matches[]=$context;}
        return count($matches)===0?false:(count($matches)===1?$matches[0]:true);
    }
    private function unknown(string $id,string $stage): array {return ['plan_id'=>$id,'state'=>'UNKNOWN','blocked_stage'=>$stage,'reconciliation_required'=>true,'external_actions_performed'=>false,'provider_acceptance'=>'UNKNOWN','production_authorized'=>false];}
    private static function local():bool{return wp_get_environment_type()==='local';}
    private static function name(string $id,string $part):string{return 'digiforge_drive_offline_'.$id.'_'.$part;}
    private static function hash(array $a):string{return hash('sha256',(new \DigiForge\Production\Validator())->canonicalJson($a));}
    private static function sha(mixed $s):bool{return is_string($s)&&preg_match('/^[a-f0-9]{64}$/D',$s)===1;}
    private static function error(string $code):WP_Error{return new WP_Error('digiforge_drive_'.$code,'Scoped artifact transfer blocked. No provider, rights or production approval is granted.',['status'=>409]);}
}
