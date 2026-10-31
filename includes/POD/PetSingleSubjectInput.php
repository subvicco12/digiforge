<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Orders\Validator;

/** Technical PNG input preparation only. No detector, artistic style or production authority. */
final class PetSingleSubjectInput
{
    public const VERSION='pet-png-input-v1';
    public const PROPOSAL_SHA256='dc2219ecbee14e847c2c632382461c0dc14f9287d1dd51974f483c42d9899bd0';
    public static function proposal():array
    {
        $bytes=@file_get_contents(__DIR__.'/contracts/pet-single-subject-proposal-v0.1.0.json',false,null,0,2049);
        if(!is_string($bytes)||!hash_equals(self::PROPOSAL_SHA256,hash('sha256',$bytes)))throw new \InvalidArgumentException('Exact inactive proposal reference required.');
        $p=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($p)||$p['approval_state']!=='PROPOSED'||$p['engine']!=='PET_PORTRAIT'||$p['profile']!=='pet_single_subject'||count($p['catalog_ids'])!==21)throw new \InvalidArgumentException('Exact inactive proposal reference required.');
        return $p+['mapping_kind'=>'PROPOSED_REVIEW_REFERENCE_ONLY','catalog_activation_allowed'=>false,'artistic_transform_available'=>false,'production_ready'=>false];
    }
    public static function prepare(string $original,string $declaredMime,int $subjectCount,array $limits):array
    {
        $started=hrtime(true);$limits=TransformationEngineContract::limits($limits);
        if($declaredMime!=='image/png'||$subjectCount!==1||strlen($original)>$limits['max_input_bytes'])throw new \InvalidArgumentException('One declared subject and supported bounded PNG required.');
        $pixels=RasterArtworkCodec::decode($original);
        if($pixels['width_px']*$pixels['height_px']>$limits['max_pixels'])throw new \InvalidArgumentException('Pixel limit exceeded.');
        // Existing decoder rejects profiles/metadata; this never silently strips or converts them.
        $png=RasterArtworkCodec::encode($pixels['width_px'],$pixels['height_px'],$pixels['rgba']);
        if(strlen($png)>$limits['max_output_bytes']||(hrtime(true)-$started)/1e9>$limits['max_execution_seconds'])throw new \InvalidArgumentException('Preparation exceeds explicit job bounds.');
        $receipt=['version'=>self::VERSION,'operation'=>'LOSSLESS_RGBA8_REENCODE','decoder_version'=>$pixels['decoder_version'],
            'source_sha256'=>hash('sha256',$original),'prepared_sha256'=>hash('sha256',$png),'pixel_sha256'=>$pixels['pixel_sha256'],
            'source_bytes'=>strlen($original),'prepared_bytes'=>strlen($png),'backend_version'=>'php-zlib/'.ZLIB_VERSION,
            'width_px'=>$pixels['width_px'],'height_px'=>$pixels['height_px'],'mask_applied'=>false,'crop_applied'=>false,'text_applied'=>false,'declared_subject_count'=>1,'semantic_subject_count'=>'UNKNOWN',
            'species'=>'UNVERIFIED','color_conversion'=>'NONE','unsupported_metadata'=>'REJECTED_NOT_STRIPPED',
            'limits_sha256'=>hash('sha256',Validator::canonicalJson($limits)),'artistic_transform_executed'=>false,
            'commercial_quality_certified'=>false,'external_execution_authorized'=>false];
        $receipt['receipt_sha256']=hash('sha256',Validator::canonicalJson($receipt));return ['png'=>$png,'receipt'=>$receipt];
    }
    public static function verify(string $original,string $prepared,array $receipt,array $limits):true
    {
        $expected=self::prepare($original,'image/png',1,$limits);
        if($prepared!==$expected['png']||Validator::canonicalJson($receipt)!==Validator::canonicalJson($expected['receipt']))throw new \InvalidArgumentException('Prepared bytes or immutable input receipt drifted.');return true;
    }
}
