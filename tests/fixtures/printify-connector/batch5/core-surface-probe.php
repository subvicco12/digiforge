<?php
declare(strict_types=1);
$manifest=json_decode(file_get_contents(dirname(__DIR__,4).'/docs/printify-connector/BATCH5_CORE_CONTRACT_MANIFEST.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest['files']as $path=>$hash){$file=__DIR__.'/'.basename($path);if(hash_file('sha256',$file)!==$hash)exit(2);require_once $file;}
require dirname(__DIR__,4).'/companion/digiforge-printify-connector/Preparation/CoreContractAdapter.php';
$r=DigiForgePrintifyConnector\Preparation\CoreContractAdapter::inspect();$r['wordpress_booted']=function_exists('add_action');echo json_encode($r,JSON_THROW_ON_ERROR);
