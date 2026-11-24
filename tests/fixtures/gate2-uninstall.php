<?php
// Isolated function harness: execute real uninstall guards without any WordPress/database connection.
define('WP_UNINSTALL_PLUGIN',true);
$mode=$argv[1]??'default';$queries=[];$deleted=[];
function is_multisite(){return $GLOBALS['mode']==='multisite';}
function get_option($key,$fallback=false){return $key==='digiforge_cleanup_on_uninstall'&&$GLOBALS['mode']==='optin';}
function get_role($role){return null;}
function delete_option($key){$GLOBALS['deleted'][]=$key;}
function esc_sql($value){return $value;}
$wpdb=new class{public $prefix='isolated_test_';public $options='isolated_test_options';public function query($sql){$GLOBALS['queries'][]=$sql;return 0;}public function prepare($sql,...$a){return $sql;}public function esc_like($v){return $v;}public function get_col($sql){return [];}};
require dirname(__DIR__,2).'/uninstall.php';
echo json_encode(['mode'=>$mode,'queries'=>$queries,'deleted'=>$deleted]);
