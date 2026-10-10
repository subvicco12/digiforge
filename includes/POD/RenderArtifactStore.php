<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\ProductFactory\AssetStorage;
use WP_Error;

/** Content-addressed protected local artifacts; never stores an arbitrary caller path. */
final class RenderArtifactStore
{
    public function retain(string $source,array $metadata):array|WP_Error
    {
        if(!AssetStorage::ensureProtectedRoot())return self::error('Protected artifact storage is unavailable.');
        $uploads=wp_upload_dir();$root=trailingslashit((string)$uploads['basedir']).AssetStorage::RELATIVE_ROOT;$directory=$root.'/pod-render-artifacts';
        if(is_link($root)||is_link($directory)||!wp_mkdir_p($directory))return self::error('Protected artifact directory is invalid.');
        $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/svg+xml'=>'svg'][$metadata['mime']]??null;if($extension===null||!preg_match('/^[a-f0-9]{64}$/D',(string)$metadata['sha256']))return self::error('Artifact identity invalid.');
        $reference=AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/'.$metadata['sha256'].'.'.$extension;$target=$directory.'/'.$metadata['sha256'].'.'.$extension;
        if(is_link($target))return self::error('Artifact symlink is forbidden.');
        if(!is_file($target)){
            $bytes=@file_get_contents($source,false,null,0,16777217);if(!is_string($bytes)||strlen($bytes)!==$metadata['bytes']||!hash_equals($metadata['sha256'],hash('sha256',$bytes)))return self::error('Source artifact changed before retention.');
            $handle=@fopen($target,'x+b');if($handle!==false){try{$locked=flock($handle,LOCK_EX);if(!$locked||fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))return self::error('Artifact write is uncertain.');}finally{fclose($handle);}}
        }
        $path=AssetStorage::absolutePath($reference);if($path===null)return self::error('Retained artifact unavailable.');$confirmed=(new RenderArtifactVerifier())->readArtifact($path);if($confirmed instanceof WP_Error)return $confirmed;
        return $confirmed===$metadata?$metadata+['storage_reference'=>$reference]:self::error('Retained artifact does not agree with its computed identity.');
    }
    public function verify(array $metadata):array|WP_Error
    {
        $reference=$metadata['storage_reference']??null;$prefix=AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/';if(!is_string($reference)||!preg_match('~^'.preg_quote($prefix,'~').'[a-f0-9]{64}\.(?:png|jpg|svg)$~D',$reference))return self::error('Current protected artifact reference required.');
        $path=AssetStorage::absolutePath($reference);if($path===null)return self::error('Certified artifact is no longer available.');$read=(new RenderArtifactVerifier())->readArtifact($path);if($read instanceof WP_Error)return $read;$expected=$metadata;unset($expected['storage_reference']);return $read===$expected?$metadata:self::error('Stored artifact changed; certification is blocked.');
    }
    private static function error(string $message):WP_Error{return new WP_Error('render_storage_unavailable',$message,['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
