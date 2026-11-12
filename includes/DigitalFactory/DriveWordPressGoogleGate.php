<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Core\Settings;
use DigiForge\Database\{Tables,QueryEvidence};
use DigiForge\Security\ShopAccess;
use DigiForge\ProductFactory\{ExternalDocumentRevision,AssetStorage};
use DigiForge\Operations\RecoveryDispatchLedger;

/** Server authority only; no settings setter, credential provisioning or activation side effect. */
final class DriveWordPressGoogleGate implements DriveGoogleGate
{
    public function now():int { return time(); }
    public function owns(array $binding):bool
    {
        try{
            $b=DriveGoogleOAuth::binding($binding);
            if($b['actor_id']!==get_current_user_id()||$b['installation_sha256']!==hash('sha256',site_url())
                ||$b['environment']!==wp_get_environment_type()||ShopAccess::authorize('digital','manage_digiforge_products')!==true
                ||!current_user_can('manage_digiforge_settings'))return false;
            global $wpdb;$wpdb->last_error='';
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_revisions().' WHERE id=%d',$b['revision_id']),ARRAY_A);
            if(QueryEvidence::failed()||!is_array($row)||ExternalDocumentRevision::validate($row) instanceof \WP_Error)return false;
            $p=json_decode($row['provenance'],true,32,JSON_THROW_ON_ERROR);$owner=$p['binding']??[];
            if(($owner['product_id']??null)!==$b['product_id']||($owner['product_version_id']??null)!==$b['product_version_id']
                ||($p['source_sha256']??null)!==$b['source_sha256'])return false;
            $path=AssetStorage::absolutePath($p['source_storage_reference']??'');
            return $path!==null&&!is_link($path)&&is_file($path)&&filesize($path)<=DriveTransferRequest::LIMIT
                &&hash_equals($b['source_sha256'],(string)hash_file('sha256',$path));
        }catch(\Throwable){return false;}
    }
    public function allows(array $binding):bool
    {
        try{
            // A separate expiring Google-specific server authorization is mandatory.
            // None is installed by this implementation or inferred from local review/Etsy/Printify authority.
            if(!defined('DIGIFORGE_GOOGLE_EXECUTION_UNTIL')||!is_int(DIGIFORGE_GOOGLE_EXECUTION_UNTIL)
                ||DIGIFORGE_GOOGLE_EXECUTION_UNTIL<=time()||DIGIFORGE_GOOGLE_EXECUTION_UNTIL>time()+900
                ||!defined('DIGIFORGE_GOOGLE_EXECUTION_INSTALLATION')
                ||DIGIFORGE_GOOGLE_EXECUTION_INSTALLATION!==hash('sha256',site_url())
                ||Settings::safety_locked()||!Settings::is_enabled('product_development')
                ||!RecoveryDispatchLedger::googleExecutionAllowed())return false;
            return $this->owns($binding);
        }catch(\Throwable){return false;}
    }
}
