<?php
declare(strict_types=1);

namespace DigiForge\ProductFactory {
    /** Test-process-only barrier/fault at the actual atomic publication boundary. */
    function link(string $source,string $destination):bool
    {
        $c=$GLOBALS['df_zip_worker'];
        if($c['mode']==='fail_link')return false;
        if($c['mode']==='truncate_pause')file_put_contents($source,"PK\x03\x04incomplete");
        $committed=$c['mode']==='after_link_pause'?\link($source,$destination):null;
        file_put_contents($c['control'].'.ready','1');
        $until=microtime(true)+20;
        while(!is_file($c['control'].'.go')){if(microtime(true)>$until)throw new \RuntimeException('Publication barrier timed out.');usleep(10000);}
        return $committed??\link($source,$destination);
    }
}
namespace {
    // Actual WordPress and production producer; never bootstrap a live installation.
    $c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['df_zip_worker']=$c;
    putenv('WP_TESTS_SKIP_INSTALL=1');
    define('UPLOADS',$c['uploads']);
    require dirname(__DIR__,2).'/vendor/autoload.php';
    require dirname(__DIR__).'/wordpress/bootstrap.php';
    if(wp_get_environment_type()!=='local'||!str_starts_with(DB_NAME,'digiforge_tests'))throw new RuntimeException('Disposable local WordPress required.');
    add_filter('pre_http_request',static fn()=>new WP_Error('test_outbound_denied','No outbound calls in publication qualification.'),PHP_INT_MAX);
    $r=(new \DigiForge\ProductFactory\LocalAssetProducer())->package($c['version'],$c['assets']);
    $out=is_wp_error($r)?['error'=>$r->get_error_code(),'status'=>$r->get_error_data()['status']??null]:array_intersect_key($r,array_flip(['checksum_sha256','byte_size','package_members']));
    file_put_contents($c['control'].'.result',json_encode($out,JSON_THROW_ON_ERROR));
}
