<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Database\{Tables,QueryEvidence};
use DigiForge\ProductFactory\{AssetStorage,ExternalDocumentRevision,ExternalDocumentEvidence};

/** Source custody and immutable export storage; reuses existing full DOCX/PDF integrity QA. */
final class DriveWordPressArtifacts
{
    public function source(array $b):?array
    {
        try{
            if(!(new DriveWordPressGoogleGate())->allows($b))return null;
            global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_revisions().' WHERE id=%d',$b['revision_id']),ARRAY_A);
            if(QueryEvidence::failed()||!is_array($row)||ExternalDocumentRevision::validate($row) instanceof \WP_Error)return null;
            $p=json_decode($row['provenance'],true,32,JSON_THROW_ON_ERROR);$path=AssetStorage::absolutePath($p['source_storage_reference']??'');
            if($path===null||is_link($path)||filesize($path)>DriveTransferRequest::LIMIT)return null;$bytes=file_get_contents($path);
            if(!is_string($bytes)||!hash_equals($b['source_sha256'],hash('sha256',$bytes)))return null;
            return ['path'=>$path,'bytes'=>$bytes,'page_count'=>$p['evidence']['page_count']??0];
        }catch(\Throwable){return null;}
    }
    public function export(array $b,#[\SensitiveParameter] string $bytes):?array
    {
        try{
            if(!(new DriveWordPressGoogleGate())->allows($b)||!str_starts_with($bytes,'%PDF-')||strlen($bytes)>10000000||!AssetStorage::ensureProtectedRoot())return null;
            $sha=hash('sha256',$bytes);$ref=AssetStorage::RELATIVE_ROOT.'/google-'.$b['id'].'-'.$sha.'.pdf';$u=wp_upload_dir();$path=trailingslashit($u['basedir']).$ref;
            for($part=$path;;$part=dirname($part)){if(is_link($part))return null;if(dirname($part)===$part)break;}
            if(is_file($path)){if(hash_file('sha256',$path)!==$sha)return null;}
            else{$f=@fopen($path,'x');if($f===false)return null;try{if(fwrite($f,$bytes)!==strlen($bytes))return null;}finally{fclose($f);}}
            return ['storage_reference'=>$ref,'sha256'=>$sha,'byte_size'=>strlen($bytes)];
        }catch(\Throwable){return null;}
    }
    public function finalize(array $b,array $source,array $export):?array
    {
        try{
            if(!(new DriveWordPressGoogleGate())->allows($b))return null;
            $path=AssetStorage::absolutePath($export['storage_reference']??'');
            if($path===null||is_link($path)||hash_file('sha256',$path)!==($export['sha256']??null))return null;
            $r=ExternalDocumentEvidence::inspect($source['path'],$path);
            if($r instanceof \WP_Error||$r['source_sha256']!==$b['source_sha256']||$r['page_count']!==$source['page_count'])return null;return $r;
        }catch(\Throwable){return null;}
    }
}
