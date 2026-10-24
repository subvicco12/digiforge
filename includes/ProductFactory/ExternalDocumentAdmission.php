<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Protected exact-byte custody only. Does not register revisions or grant approval. */
final class ExternalDocumentAdmission
{
    private const MAX_BYTES = 16777216;

    public function admit(array $request): array|WP_Error
    {
        if (($request['shop_key'] ?? null) !== 'digital') {
            return $this->error('scope',403);
        }
        $access=ShopAccess::authorize('digital','manage_digiforge_products');
        if (is_wp_error($access)) { return $access; }
        $binding=$this->binding($request);
        if (is_wp_error($binding)) { return $binding; }
        $scope=$this->scope($binding);
        if (is_wp_error($scope)) { return $scope; }
        $inputs=[];
        foreach (['source','export'] as $kind) {
            $hash=$request[$kind.'_sha256']??null;
            $path=$request[$kind.'_path']??null;
            if (!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_string($path)) {
                return $this->error('input',400);
            }
            $bytes=$this->read($path,self::MAX_BYTES);
            if (is_wp_error($bytes)) { return $bytes; }
            if (!hash_equals($hash,hash('sha256',$bytes))) { return $this->error('checksum',409); }
            $inputs[$kind]=['bytes'=>$bytes,'hash'=>$hash];
        }
        $uploads=wp_upload_dir();
        if (!empty($uploads['error'])||empty($uploads['basedir'])
            ||!$this->safePath(trailingslashit((string)$uploads['basedir']).AssetStorage::RELATIVE_ROOT)
            ||!AssetStorage::ensureProtectedRoot()) { return $this->error('storage',503); }
        $relative=AssetStorage::RELATIVE_ROOT.'/external-documents/'.$binding['actor_id'].'/'.$binding['product_version_id'].'/'.$binding['asset_spec_id'];
        $directory=trailingslashit((string)$uploads['basedir']).$relative;
        if (!$this->safePath($directory)||!wp_mkdir_p($directory)||!$this->safePath($directory)) { return $this->error('storage',503); }
        $manifestReference=$relative.'/'.hash('sha256',$binding['operation_key']).'.json';
        $manifestPath=trailingslashit((string)$uploads['basedir']).$manifestReference;
        $existing=null;
        if (file_exists($manifestPath)||is_link($manifestPath)) {
            $raw=$this->read($manifestPath,65536);
            if (is_wp_error($raw)) { return $raw; }
            $existing=json_decode($raw,true);
            if (!is_array($existing)||!is_array($existing['binding']??null)
                ||(new \DigiForge\Production\Validator())->canonicalJson($existing['binding'])!==(new \DigiForge\Production\Validator())->canonicalJson($binding)
                ||($existing['source']['checksum_sha256']??null)!==$inputs['source']['hash']
                ||($existing['export']['checksum_sha256']??null)!==$inputs['export']['hash']) {
                return $this->error('replay_conflict',409);
            }
        }
        $assets=[];
        foreach (['source'=>'docx','export'=>'pdf'] as $kind=>$extension) {
            $reference=$relative.'/'.$kind.'-'.$inputs[$kind]['hash'].'.'.$extension;
            $path=trailingslashit((string)$uploads['basedir']).$reference;
            $written=$this->retain($path,$inputs[$kind]['bytes']);
            if (is_wp_error($written)) { return $written; }
            $assets[$kind]=['storage_reference'=>$reference,'checksum_sha256'=>$inputs[$kind]['hash'],'byte_size'=>strlen($inputs[$kind]['bytes']),'mime_type'=>$kind==='source'?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/pdf'];
        }
        $sourcePath=AssetStorage::absolutePath($assets['source']['storage_reference']);
        $exportPath=AssetStorage::absolutePath($assets['export']['storage_reference']);
        if ($sourcePath===null||$exportPath===null) { return $this->error('storage',503); }
        $evidence=ExternalDocumentEvidence::inspect($sourcePath,$exportPath);
        if (is_wp_error($evidence)) { return $evidence; }
        foreach (['source','export'] as $kind) {
            if (($evidence[$kind.'_sha256']??null)!==$assets[$kind]['checksum_sha256']) { return $this->error('checksum',409); }
        }
        $access=ShopAccess::authorize('digital','manage_digiforge_products');
        if (is_wp_error($access)) { return $access; }
        if (get_current_user_id()!==$binding['actor_id']) { return $this->error('scope',403); }
        $scope=$this->scope($binding);
        if (is_wp_error($scope)) { return $scope; }
        foreach (['source'=>$sourcePath,'export'=>$exportPath] as $kind=>$path) {
            $bytes=$this->read($path,self::MAX_BYTES);
            if (is_wp_error($bytes)||!hash_equals($assets[$kind]['checksum_sha256'],hash('sha256',$bytes))) { return $this->error('checksum',409); }
        }
        $manifest=['binding'=>$binding,'source'=>$assets['source'],'export'=>$assets['export'],'evidence'=>$evidence];
        $json=(new \DigiForge\Production\Validator())->canonicalJson($manifest);
        if ($existing!==null&&(new \DigiForge\Production\Validator())->canonicalJson($existing)!==$json) { return $this->error('replay_conflict',409); }
        $saved=$this->retain($manifestPath,$json);
        if (is_wp_error($saved)) { return $saved; }
        $provenance=$evidence+[
            'generator'=>'digiforge_external_document',
            'pdf_generator_profile'=>'digiforge-external-docx-pdf-v1',
            'source_storage_reference'=>$assets['source']['storage_reference'],
            'source_byte_size'=>$assets['source']['byte_size'],
            'custody_manifest_storage_reference'=>$manifestReference,
            'custody_manifest_sha256'=>hash('sha256',$json),
            'binding'=>$binding,'evidence'=>$evidence,
        ];
        return [
            'source'=>$assets['source']+['absolute_path'=>$sourcePath],
            'export'=>$assets['export']+['absolute_path'=>$exportPath],
            'evidence'=>$evidence,'binding'=>$binding,
            'revision_input'=>[
                'asset_spec_id'=>$binding['asset_spec_id'],'revision_label'=>$binding['operation_key'],
                'storage_reference'=>$assets['export']['storage_reference'],'checksum_sha256'=>$assets['export']['checksum_sha256'],
                'mime_type'=>'application/pdf','byte_size'=>$assets['export']['byte_size'],'provenance'=>$provenance,
            ],
            'technical_only'=>true,'approval_required'=>true,'external_action_performed'=>false,
            'production_authorized'=>false,'rights_certified'=>false,
        ];
    }

    private function binding(array $request): array|WP_Error
    {
        $binding=['actor_id'=>get_current_user_id(),'shop_key'=>'digital'];
        foreach (['product_id','product_version_id','asset_spec_id'] as $field) {
            if (!is_int($request[$field]??null)||$request[$field]<1) { return $this->error('scope',403); }
            $binding[$field]=$request[$field];
        }
        $key=$request['operation_key']??null;
        if (!is_string($key)||preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$key)!==1) { return $this->error('input',400); }
        $binding['operation_key']=$key;
        return $binding;
    }

    private function scope(array $binding): true|WP_Error
    {
        global $wpdb;
        $versions=Tables::product_versions();$specs=Tables::asset_specs();
        $predicate=ProductEvidenceScope::forTable($versions,'digital');
        if (!is_string($predicate)) { return $this->error('scope',403); }
        $wpdb->last_error='';
        $count=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$versions.' INNER JOIN '.$specs.' ON '.$specs.'.product_version_id='.$versions.'.id WHERE '.$versions.'.id=%d AND '.$versions.'.product_id=%d AND '.$specs.'.id=%d AND '.$specs.".format='pdf' AND ".$predicate,$binding['product_version_id'],$binding['product_id'],$binding['asset_spec_id']));
        if ($wpdb->last_error!==''||!is_numeric($count)) { return $this->error('scope_unavailable',503); }
        return (int)$count===1?true:$this->error('scope',403);
    }

    private function safePath(string $path): bool
    {
        if ($path===''||$path[0]!=='/'||str_contains($path,"\0")||str_contains($path,':')||preg_match('~(?:^|/)\.\.(?:/|$)~',$path)) { return false; }
        for ($part=$path;$part!=='/';$part=dirname($part)) { if (is_link($part)) { return false; } }
        return true;
    }

    private function read(string $path,int $limit): string|WP_Error
    {
        clearstatcache(true,$path);
        if (!$this->safePath($path)||!is_file($path)||!is_readable($path)) { return $this->error('local_file',409); }
        $size=filesize($path);
        if (!is_int($size)||$size<1||$size>$limit) { return $this->error('local_file',409); }
        $bytes=file_get_contents($path,false,null,0,$limit+1);
        clearstatcache(true,$path);
        if (!is_string($bytes)||strlen($bytes)!==$size||!$this->safePath($path)||filesize($path)!==$size) { return $this->error('local_file',409); }
        return $bytes;
    }

    private function retain(string $path,string $bytes): true|WP_Error
    {
        if (!$this->safePath($path)) { return $this->error('storage',503); }
        if (!file_exists($path)) {
            $handle=@fopen($path,'xb');
            if (is_resource($handle)) {
                try {
                    $offset=0;$length=strlen($bytes);
                    while ($offset<$length) { $written=fwrite($handle,substr($bytes,$offset));if (!is_int($written)||$written<1) { return $this->error('storage',503); }$offset+=$written; }
                    if (!fflush($handle)) { return $this->error('storage',503); }
                } finally { fclose($handle); }
            }
        }
        $read=$this->read($path,max(self::MAX_BYTES,strlen($bytes)));
        return is_string($read)&&hash_equals(hash('sha256',$bytes),hash('sha256',$read))?true:$this->error('checksum',409);
    }

    private function error(string $code,int $status): WP_Error
    {
        return new WP_Error('digiforge_external_document_'.$code,'External document custody could not establish exact current local identity. No approval is granted.',['status'=>$status]);
    }
}
