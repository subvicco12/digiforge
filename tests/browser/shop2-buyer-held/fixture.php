<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/wordpress/CustomerPhotoHeldIntegrationTest.php';

/** Reuses the existing real repository fixture; never provisions provider authority. */
final class Shop2BuyerHeldBrowserFixture extends CustomerPhotoHeldIntegrationTest
{
    public function seed():array
    {
        $this->setUp();
        remove_filter('query',[$this,'_create_temporary_tables']);
        remove_filter('query',[$this,'_drop_temporary_tables']);
        $journeys=[];
        // One immutable supplier observation and one order across viewport revisions.
        // Re-observing the same variant for separate fixtures correctly invalidates
        // older template provenance, so do not manufacture three competing catalogs.
        $f=$this->photo(false);
        $password=wp_generate_password(40,false);
        wp_set_password($password,$f['customer']);
        $buyer=get_userdata($f['customer']);
        $grant=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\CustomerEditorRepository())->access($f['session'],true));
        foreach([390,768,1280] as $width){
            $journeys[]=$f+['width'=>$width,'access_sha256'=>$grant['sha256'],'buyer'=>['id'=>$f['customer'],'login'=>$buyer->user_login,'password'=>$password]];
        }
        \DigiForge\Core\Settings::protectProduction();
        global $wpdb;
        $wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
        require_once dirname(__DIR__,3).'/services/native-authority/wordpress/bootstrap.php';
        PrintifyApiApprovalFixture::checked(\DigiForge\NativeAuthority\WordPress\OfflineSchema::installForDisposableTests());
        return $journeys;
    }
}
