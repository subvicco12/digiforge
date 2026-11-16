"""Provision only a disposable WP filesystem; initialize its private MariaDB separately.
No source/runtime modifications and no reusable private credentials in the repository.
"""
from pathlib import Path
import shutil,secrets
root=Path('/workspace/lane-a-photo-http-20261116');site=root/'site';private=root/'private';private.mkdir(parents=True,exist_ok=True);private.chmod(0o700)
source=Path('/workspace/lane-a-factory-evidence-1266/browser/wordpress')
site.mkdir(exist_ok=True)
for p in source.iterdir():
 if p.name in ['wp-config.php','wp-content']:continue
 target=site/p.name
 if target.exists() or target.is_symlink():continue
 if p.is_dir():shutil.copytree(p,target) # Copy admin: its dirname bootstrap must resolve to this site.
 else:shutil.copy2(p,target)
(site/'wp-content/mu-plugins').mkdir(parents=True,exist_ok=True)
plugins=site/'wp-content/plugins/workspace';plugins.mkdir(parents=True,exist_ok=True)
link=plugins/'lane-a-product-factory-1266'
if not link.exists():link.symlink_to('/workspace/lane-a-product-factory-1266',target_is_directory=True)
if not (site/'wp-config.php').exists():
 config="<?php\ndefine('DB_NAME','photo_http');define('DB_USER','root');define('DB_PASSWORD','');define('DB_HOST','localhost:/workspace/lane-a-photo-http-20261116/private/mysql.sock');define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');$table_prefix='wp_';\n"
 for k in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT','DIGIFORGE_CREDENTIAL_KEY']:config+=f"define('{k}','{secrets.token_hex(32)}');\n"
 config+="define('WP_HOME','http://127.0.0.1:18826');define('WP_SITEURL',WP_HOME);define('WP_ENVIRONMENT_TYPE','local');define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_AUTO_UPDATE_CORE',false);if(file_exists('/workspace/lane-a-photo-http-20261116/private/trust.php'))require '/workspace/lane-a-photo-http-20261116/private/trust.php';if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require ABSPATH.'wp-settings.php';\n"
 (site/'wp-config.php').write_text(config);(site/'wp-config.php').chmod(0o600)
(site/'wp-content/mu-plugins/harness.php').write_text("<?php\nrequire '/workspace/lane-a-product-factory-1266/digiforge.php';add_filter('pre_http_request',static function($pre,$args,$url){file_put_contents('/workspace/lane-a-photo-http-20261116/private/external-attempts.log',parse_url($url,PHP_URL_HOST).\"\\n\",FILE_APPEND);return new WP_Error('offline_only');},PHP_INT_MAX,3);\n")
print('Disposable filesystem ready; private MariaDB setup and fixture.php are separate.')
