<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_file($dir.'/disposable.marker')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/df-assets/')){$relative=substr($path,11);if(str_starts_with($relative,'assets/'))$relative=substr($relative,7);if(!preg_match('/^[a-z0-9-]+\.(?:css|js)$/D',$relative)){http_response_code(404);exit;}$file=$repo.'/assets/'.$relative;if(!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'application/javascript'));readfile($file);return;}
$allocationPreview=$path==='/'&&($_GET['df_view']??null)==='finance'
 &&in_array($_GET['df_shop']??null,['digital','personalized_pod'],true)
 &&($_POST['shop_key']??null)===($_GET['df_shop']??null)
 &&in_array($_POST['action']??null,['digiforge_finance_allocation_preview','digiforge_finance_allocation_review','digiforge_finance_allocation_profitability','digiforge_finance_review_export','digiforge_finance_native_preview'],true);
if($_SERVER['REQUEST_METHOD']==='POST'&&!in_array($path,['/wp-login.php','/wp-admin/admin-post.php'],true)&&!$allocationPreview){http_response_code(405);exit;}
if($path==='/wp-admin/admin-post.php'&&!in_array($_POST['action']??'',['digiforge_portal_prepare_research_run','digiforge_portal_execute_research_run'],true)){http_response_code(403);exit;}
if(in_array($path,['/','/index.php','/wp-login.php','/wp-admin/admin-post.php'],true)){require $dir.'/core'.($path==='/'?'/index.php':$path);return;}
if(preg_match('#^/wp-(?:admin|includes)/(?:css|js|images)/[a-zA-Z0-9_./-]+\.(?:css|js|png|gif|svg|woff2?)$#D',$path)&&!str_contains($path,'..'))return false;
http_response_code(404);
