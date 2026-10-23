<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Fixed Drive v3 wire plans; no network, OAuth, browsing, sharing, update or delete. */
final class DriveTransferRequest
{
    public const FOLDER = '1LZtNxGDokxIDaTcX7TaKVfJidGiOoG5y';
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const DOC = 'application/vnd.google-apps.document';
    public const LIMIT = 5000000;
    public static function build(string $stage, array $plan, array $completed): array
    {
        self::scope($plan);
        $id = $stage==='original_readback' ? ($completed['original_upload']['id']??'') : ($completed['native_conversion']['id']??'');
        $base=['stage'=>$stage,'timeout'=>20,'redirection'=>0,'sslverify'=>true,'max_response_bytes'=>$stage==='pdf_export'?10000000:self::LIMIT,'network_request_permitted'=>false];
        if ($stage==='folder') return $base+['method'=>'GET','path'=>'/drive/v3/files/'.self::FOLDER,'query'=>['fields'=>'id,mimeType,trashed,capabilities']];
        if (in_array($stage,['original_upload','native_conversion'],true)) {
            $mime=$stage==='original_upload'?self::DOCX:self::DOC;
            return $base+['method'=>'POST','path'=>'/upload/drive/v3/files','query'=>['uploadType'=>'multipart','fields'=>'id,mimeType,parents,trashed,appProperties,version,capabilities'],'media_mime_type'=>self::DOCX,'metadata'=>['name'=>'DigiForge-'.$plan['product_id'].'-'.$plan['product_version_id'].'-'.substr($plan['source_sha256'],0,12).($stage==='original_upload'?'.docx':''),'mimeType'=>$mime,'parents'=>[self::FOLDER],'appProperties'=>['digiforge_transfer'=>$plan['id'],'digiforge_role'=>$stage,'source_sha256'=>$plan['source_sha256']]]];
        }
        if (!self::validId($id)) throw new \InvalidArgumentException('Missing exact transfer file identity.');
        return match($stage) {
            'original_readback'=>$base+['method'=>'GET','path'=>'/drive/v3/files/'.$id,'query'=>['alt'=>'media']],
            'native_readback','native_final'=>$base+['method'=>'GET','path'=>'/drive/v3/files/'.$id,'query'=>['fields'=>'id,mimeType,parents,trashed,appProperties,version,capabilities']],
            'pdf_export'=>$base+['method'=>'GET','path'=>'/drive/v3/files/'.$id.'/export','query'=>['mimeType'=>'application/pdf']],
            default=>throw new \InvalidArgumentException('Unsupported transfer stage.'),
        };
    }
    public static function reconciliation(string $stage,array $plan): array
    {
        self::scope($plan);
        if (!in_array($stage,['original_upload','native_conversion'],true)) throw new \InvalidArgumentException('Only ambiguous creates reconcile.');
        return ['stage'=>'reconcile_'.$stage,'method'=>'GET','path'=>'/drive/v3/files','query'=>['q'=>"'".self::FOLDER."' in parents and trashed = false and appProperties has { key='digiforge_transfer' and value='".$plan['id']."' } and appProperties has { key='digiforge_role' and value='".$stage."' }",'pageSize'=>2,'fields'=>'files(id,mimeType,parents,trashed,appProperties,version,capabilities),nextPageToken'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,'max_response_bytes'=>8192,'network_request_permitted'=>false];
    }
    private static function scope(array $plan):void {foreach(['id','source_sha256'] as $key)if(!is_string($plan[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$plan[$key])!==1)throw new \InvalidArgumentException('Invalid operation identity.');foreach(['product_id','product_version_id'] as $key)if(!is_int($plan[$key]??null)||$plan[$key]<1)throw new \InvalidArgumentException('Invalid product identity.');}
    public static function validId(mixed $id): bool {return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id)===1;}
}
