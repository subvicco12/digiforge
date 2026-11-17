<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_file($dir.'/disposable.marker')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$_SERVER['HTTP_HOST']??'')){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
if(str_starts_with($path,'/df-assets/')){$asset=basename($path);if($method!=='GET'||!in_array($asset,['customer-editor.js','customer-editor.css','portal.css','portal-ui.js','portal-ui.css','portal-access.js','portal-access.css','printify-product-intelligence.css'],true)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($asset,'.css')?'text/css':'application/javascript'));readfile($repo.'/assets/'.$asset);return;}
$rest=$_GET['rest_route']??'';
if($rest!==''){
    if(!is_string($rest)||!preg_match('#^/digiforge/v1/customer-editor/[A-Za-z0-9_-]{1,80}/(view|upload|fields|approve|revoke|intake|publish)$#D',$rest)||!in_array($method,['GET','POST'],true)){http_response_code(403);exit;}
    require $dir.'/core/index.php';return;
}
if($path==='/wp-admin/admin-post.php'){
    $allowed=['digiforge_portal_login','digiforge_portal_continue','digiforge_portal_order_local_begin_review','digiforge_portal_order_local_approve','digiforge_portal_order_local_draft_plan','digiforge_portal_order_local_begin_plan_review','digiforge_portal_order_local_approve_plan','digiforge_portal_order_local_prepare_intent','digiforge_portal_order_held_job_enqueue'];
    if($method!=='POST'||!in_array($_POST['action']??'', $allowed,true)){http_response_code(403);exit;}
}
if($method==='POST'&&!in_array($path,['/wp-login.php','/wp-admin/admin-post.php'],true)){http_response_code(405);exit;}
if(in_array($path,['/','/index.php','/wp-login.php','/wp-admin/admin-post.php'],true)){require $dir.'/core'.($path==='/'?'/index.php':$path);return;}
if($method==='GET'&&preg_match('#^/wp-(?:admin|includes)/(?:css|js|images)/[a-zA-Z0-9_./-]+\.(?:css|js|png|gif|svg|woff2?)$#D',$path)&&!str_contains($path,'..'))return false;
http_response_code(404);
