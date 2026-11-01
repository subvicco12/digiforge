<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory {
    function publishAsset(string $source,string $target,bool $rename):bool
    {
        $c=$GLOBALS['df_local_asset_worker'];file_put_contents($c['control'].'.ready','1');$until=microtime(true)+15;
        while(!is_file($c['control'].'.go')){if(microtime(true)>$until)throw new \RuntimeException('Publication barrier timeout.');usleep(10000);}
        return $rename?\rename($source,$target):\link($source,$target);
    }
    function rename(string $source,string $target):bool{return publishAsset($source,$target,true);}
    function link(string $source,string $target):bool{return publishAsset($source,$target,false);}
}
namespace {
    $c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$GLOBALS['df_local_asset_worker']=$c;
    putenv('WP_TESTS_SKIP_INSTALL=1');define('UPLOADS',$c['uploads']);require dirname(__DIR__,2).'/vendor/autoload.php';require dirname(__DIR__).'/wordpress/bootstrap.php';
    if(wp_get_environment_type()!=='local'||!str_starts_with(DB_NAME,'digiforge_tests'))throw new RuntimeException('Disposable WordPress required.');
    add_filter('pre_http_request',static fn()=>new WP_Error('test_outbound_denied','No outbound calls.'),PHP_INT_MAX);
    $r=(new \DigiForge\ProductFactory\LocalAssetProducer())->write($c['version'],'guide.txt','txt',$c['text']);
    file_put_contents($c['control'].'.result',json_encode(is_wp_error($r)?['error'=>$r->get_error_code(),'status'=>$r->get_error_data()['status']??null]:['hash'=>$r['checksum_sha256']],JSON_THROW_ON_ERROR));
}
