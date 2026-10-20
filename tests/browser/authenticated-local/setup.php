<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($dir)||!is_file($dir.'/disposable.marker')||PHP_SAPI!=='cli')throw new RuntimeException('Disposable CLI fixture required.');
require $dir.'/test-config.php';
if(!preg_match('/^(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$/D',DB_HOST)||!str_contains(DB_NAME,'tests')||!preg_match('/^b[a-f0-9]{3}_$/D',$table_prefix))throw new RuntimeException('Only isolated loopback test databases/prefixes are permitted.');
$parts=explode(':',DB_HOST,2);$db=new mysqli($parts[0],DB_USER,DB_PASSWORD,DB_NAME,isset($parts[1])?(int)$parts[1]:3306);
$check=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND LEFT(table_name,?)=?');$database=DB_NAME;$length=strlen($table_prefix);$check->bind_param('sis',$database,$length,$table_prefix);$check->execute();$count=$check->get_result()->fetch_row()[0];$db->close();if((int)$count!==0)throw new RuntimeException('Refusing to reuse an existing fixture prefix.');
require $repo.'/vendor/autoload.php';
define('WP_TESTS_CONFIG_FILE_PATH',$dir.'/test-config.php');
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH',$repo.'/vendor/yoast/phpunit-polyfills');
require getenv('WP_TESTS_DIR').'/includes/bootstrap.php';
require_once $repo.'/tests/wordpress/fixtures/ShopAccessFixture.php';
DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
$users=[];$caps=['read','manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_digital','manage_digiforge_pod','manage_digiforge_listings','manage_digiforge_orders'];
add_role('df_browser_operator','Synthetic browser operator',array_fill_keys($caps,true));
foreach(['digital','personalized_pod','unassigned','reviewer','viewer']as $shop){$password=wp_generate_password(40,false);$id=wp_insert_user(['user_login'=>'synthetic_browser_'.$shop,'user_pass'=>$password,'role'=>$shop==='reviewer'?'administrator':($shop==='viewer'?'subscriber':'df_browser_operator')]);if(is_wp_error($id))throw new RuntimeException('Fixture actor creation failed.');if(in_array($shop,['digital','personalized_pod'],true))ShopAccessFixture::authorizeActor($id,[$shop],array_values(array_diff($caps,['read'])));if($shop==='reviewer')ShopAccessFixture::authorizeActor($id,['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);$users[$shop]=['login'=>'synthetic_browser_'.$shop,'password'=>$password,'id'=>$id];}
// Reuse existing immutable source lineage shape; these are not approved products.
$now=gmdate('Y-m-d H:i:s');global $wpdb;$productIds=[];
foreach(['digital','personalized_pod','jewelry']as $shop){$ok=$wpdb->insert(DigiForge\Database\Tables::opportunities(),['title'=>'SYNTHETIC_PRIVATE_'.$shop,'description'=>'Offline browser fixture only','state'=>'NEW','created_by'=>$users['digital']['id'],'created_at'=>$now,'updated_at'=>$now]);if($ok!==1)throw new RuntimeException('Synthetic product evidence insert failed.');$op=(int)$wpdb->insert_id;$ok=$wpdb->insert(DigiForge\Database\Tables::research_candidates(),['title'=>'SYNTHETIC_PRIVATE_'.$shop,'canonical_title'=>'synthetic '.$shop,'fingerprint'=>hash('sha256',$shop),'score_version'=>'synthetic-browser-v1','score_inputs'=>wp_json_encode(['shop_key'=>$shop]),'opportunity_id'=>$op,'created_by'=>$users['digital']['id'],'created_at'=>$now,'updated_at'=>$now]);if($ok!==1)throw new RuntimeException('Synthetic lineage insert failed.');$factory=new DigiForge\ProductFactory\Repository();$family=$factory->create('product_family',['opportunity_id'=>$op,'name'=>'SYNTHETIC_FAMILY_'.$shop]);if(is_wp_error($family))throw new RuntimeException('Synthetic family unavailable.');$product=$factory->create('product',['product_family_id'=>$family['id'],'name'=>'SYNTHETIC_PRODUCT_'.$shop]);if(is_wp_error($product))throw new RuntimeException('Synthetic product unavailable.');$version=$factory->create('product_version',['product_id'=>$product['id'],'version_label'=>'synthetic-browser-v1']);if(is_wp_error($version))throw new RuntimeException('Synthetic version unavailable.');$productIds[$shop]=$product['id'];}
file_put_contents($dir.'/products.json',wp_json_encode($productIds));
$consents=[];$workflow=new DigiForge\Portal\ResearchRunWorkflow();$sign=new ReflectionMethod($workflow,'signature');
foreach(['expired','replayed','receipt_failure']as $case){$id=wp_generate_uuid4();$issued=time()-($case==='expired'?301:0);$pending=['version'=>1,'operation_id'=>$id,'actor_id'=>$users['reviewer']['id'],'issued_at'=>$issued,'expires_at'=>$issued+300,'input'=>['shop'=>'digital','phase'=>'deep_research','selected_candidate_id'=>1],'evidence_hash'=>str_repeat('a',64),'plan'=>['quote'=>[]]];$pending['seal']=$sign->invoke($workflow,$pending);add_option('digiforge_manual_research_pending_'.$id,wp_json_encode($pending),'',false);if($case==='replayed')add_option('digiforge_manual_research_consumed_'.$id,'{"synthetic_receipt":true}','',false);$consents[$case]=['id'=>$id,'signature'=>$pending['seal']];}
file_put_contents($dir.'/consents.json',wp_json_encode($consents));chmod($dir.'/consents.json',0600);
// A signed source authority survives only this disposable fixture; no private signing key is exported.
copy(DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,$dir.'/authority.json');chmod($dir.'/authority.json',0600);
file_put_contents($dir.'/public-authority.php',"<?php\ndefine('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',".var_export(DIGIFORGE_SHOP_ACCESS_TRUST_KEY,true).");\ndefine('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',".var_export($dir.'/authority.json',true).");\n");
update_option('show_avatars',0);update_option('home',WP_HOME);update_option('siteurl',WP_SITEURL);update_option('template','df-browser');update_option('stylesheet','df-browser');update_option('permalink_structure','');
$wpdb->query('COMMIT');
file_put_contents($dir.'/actors.json',wp_json_encode($users));chmod($dir.'/actors.json',0600);
// Exclude audit/session evidence; every business row must remain byte-for-byte unchanged.
$tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'digiforge_').'%'));$snapshot=[];
foreach($tables as $table){if(!preg_match('/digiforge_(?:opportunities|products|product_versions|product_families|asset_specs|asset_revisions|orders|order_line_items|provider_orders|listing[a-z_]*|etsy_operations|pod_[a-z_]*|research_[a-z_]*|settings|integrations)$/D',$table))continue;$rows=$wpdb->get_results('SELECT * FROM `'.$table.'` ORDER BY 1',ARRAY_A);if($wpdb->last_error!=='')throw new RuntimeException('Snapshot unavailable.');$snapshot[$table]=hash('sha256',wp_json_encode($rows));}
file_put_contents($dir.'/before.json',wp_json_encode($snapshot));
