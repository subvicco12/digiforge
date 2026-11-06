<?php
declare(strict_types=1);
/** Trusted offline operator only; never bootstraps WordPress or provisions production. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=getopt('',['output:','approval:','expires:','site:']);
$site=is_string($args['site']??null)?parse_url($args['site']):false;
if(!is_array($site)||($site['scheme']??null)!=='https'||empty($site['host'])||isset($site['user'])||isset($site['pass'])||isset($site['query'])||isset($site['fragment'])||!str_ends_with($args['site'],'/')||!is_string($args['output']??null)||!str_starts_with($args['output'],'/')||file_exists($args['output'])||!is_string($args['approval']??null)||preg_match('/^[a-f0-9]{64}$/D',$args['approval'])!==1||!is_string($args['expires']??null)||!ctype_digit($args['expires'])||(int)$args['expires']<=time()||(int)$args['expires']>time()+86400*30||!function_exists('sodium_crypto_sign_keypair')){fwrite(STDERR,"Use --site=https://your-site/ --output=/new/private/directory --approval=64_lowercase_hex --expires=UTC_epoch_within_30_days. Existing paths are refused.\n");exit(1);}
umask(0077);if(!mkdir($args['output'],0700,true))exit(1);
$caps=['manage_digiforge','manage_digiforge_settings','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_connections'];
$pair=sodium_crypto_sign_keypair();$public=base64_encode(sodium_crypto_sign_publickey($pair));$secret=sodium_crypto_sign_secretkey($pair);$code=bin2hex(random_bytes(32));
$manifest=json_encode(['version'=>1,'authorities'=>[['actor_id'=>1,'shop'=>'personalized_pod','caps'=>$caps,'expires_at'=>(int)$args['expires'],'source_approval_hash'=>$args['approval'],'authority'=>'OWNER']]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$signature=base64_encode(sodium_crypto_sign_detached($manifest,$secret));sodium_memzero($secret);sodium_memzero($pair);
$envelope=json_encode(['manifest'=>$manifest,'signature'=>$signature],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
$path=$args['output'].'/authority.json';
$config="<?php\n// Trusted host configuration. Place outside public web access; do not commit.\ndefine('DIGIFORGE_SHOP_ACCESS_TRUST_KEY', ".var_export($public,true).");\ndefine('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE', ".var_export($path,true).");\ndefine('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE', ".var_export($args['site'],true).");\ndefine('DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256', '".hash('sha256',$code)."');\n";
foreach(['authority.json'=>$envelope,'host-config.php'=>$config,'owner-enrollment-code.txt'=>$code."\n"] as $name=>$raw){if(file_put_contents($args['output'].'/'.$name,$raw,LOCK_EX)!==strlen($raw)){fwrite(STDERR,"Private output failed; do not provision partial output.\n");exit(1);}chmod($args['output'].'/'.$name,0600);}
sodium_memzero($code);echo "Private setup bundle prepared. No production configuration changed.\n";
