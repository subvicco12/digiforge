<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** A recorded rights declaration is distinct from commercial and supplier certification. */
final class TransformationFixtureManifest
{
    public static function normalize(array $manifest):array
    {
        TransformationEngineContract::exact($manifest,['evidence_kind','fixture_id','engine_id','mode','version','source_asset_sha256','result_asset_sha256','MIME','pixel_size','provenance','license_holder','license_uri_or_document','permitted_uses','consent_basis','review_owner','approved_at','expiry','qa_rubric_revision','template_id','supplier_route','storage_location','rights_document_sha256','consent_document_sha256','retention_policy_sha256','consent_document_reference','retention_policy_reference']);
        $kind=$manifest['evidence_kind']??null;if(!in_array($kind,['SYNTHETIC_TEST_ONLY','OWNER_SUPPLIED'],true))throw new \InvalidArgumentException('Explicit rights/fixture evidence kind required.');
        foreach(['fixture_id','engine_id','version','qa_rubric_revision','review_owner','template_id'] as $field)TransformationEngineContract::token($manifest[$field]??null);
        if(!in_array($manifest['engine_id'],TransformationEngineContract::ENGINES,true)||$manifest['version']!==TransformationEngineContract::VERSION)throw new \InvalidArgumentException('Exact fixture engine contract required.');
        foreach(['source_asset_sha256','result_asset_sha256'] as $field)TransformationEngineContract::hash($manifest[$field]??null);
        if($kind==='OWNER_SUPPLIED'||isset($manifest['MIME'])||isset($manifest['pixel_size'])){
            if(($manifest['MIME']??null)!=='image/png'||!is_array($manifest['pixel_size']??null))throw new \InvalidArgumentException('Exact supported output MIME and typed pixel dimensions required.');
            TransformationEngineContract::exact($manifest['pixel_size'],['width_px','height_px']);foreach(['width_px','height_px'] as $field)if(!is_int($manifest['pixel_size'][$field]??null)||$manifest['pixel_size'][$field]<1||$manifest['pixel_size'][$field]>10000)throw new \InvalidArgumentException('Bounded result pixel dimensions required.');
            if($manifest['pixel_size']['width_px']*$manifest['pixel_size']['height_px']>RasterArtworkCodec::MAX_PIXELS)throw new \InvalidArgumentException('Result pixel work bound exceeded.');
        }
        if($kind==='OWNER_SUPPLIED'){
            foreach(['license_holder','consent_basis','provenance','supplier_route'] as $field)TransformationEngineContract::token($manifest[$field]??null);
            $uses=$manifest['permitted_uses']??null;if(!is_array($uses)||!array_is_list($uses)||array_diff(['commercial','derivative','ai_processing','storage'],$uses)!==[]||array_diff($uses,['commercial','derivative','ai_processing','storage'])!==[])throw new \InvalidArgumentException('Explicit commercial, derivative, AI-processing and storage rights required.');
            foreach(['rights_document_sha256','consent_document_sha256','retention_policy_sha256'] as $field)TransformationEngineContract::hash($manifest[$field]??null);
            foreach(['license_uri_or_document','storage_location','consent_document_reference','retention_policy_reference'] as $field){$reference=$manifest[$field]??null;if(!is_string($reference)||!preg_match('~^digiforge/product-factory/[A-Za-z0-9_./-]+$~D',$reference)||array_intersect(explode('/',$reference),['.','..',''])!==[])throw new \InvalidArgumentException('Protected private rights and fixture references required; remote URLs forbidden.');}
            foreach(['approved_at','expiry'] as $field){$value=$manifest[$field]??null;$date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC')):false;if($date===false||$date->format('Y-m-d H:i:s')!==$value)throw new \InvalidArgumentException('Exact UTC approval/expiry required.');}
            if($manifest['approved_at']>$manifest['expiry']||$manifest['approved_at']>gmdate('Y-m-d H:i:s')||$manifest['expiry']<=gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Current rights approval and unexpired license required.');
        }
        if(isset($manifest['mode']))TransformationEngineContract::token($manifest['mode']);
        return $manifest+['licensed'=>false,'rights_declaration_present'=>$kind==='OWNER_SUPPLIED','commercial_quality_certified'=>false,'supplier_certified'=>false];
    }
}
