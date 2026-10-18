<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');
if(PHP_SAPI!=='cli'||getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($dir)||!is_file($dir.'/disposable.marker'))throw new RuntimeException('Disposable CLI cleanup required.');
require $dir.'/test-config.php';
if(!preg_match('/^(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$/D',DB_HOST)||!str_contains(DB_NAME,'tests')||!preg_match('/^b[a-f0-9]{3}_$/D',$table_prefix))throw new RuntimeException('Refusing non-fixture cleanup.');
$parts=explode(':',DB_HOST,2);$db=new mysqli($parts[0],DB_USER,DB_PASSWORD,DB_NAME,isset($parts[1])?(int)$parts[1]:3306);
$stmt=$db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND LEFT(table_name,?)=?');$database=DB_NAME;$length=strlen($table_prefix);$stmt->bind_param('sis',$database,$length,$table_prefix);$stmt->execute();$tables=$stmt->get_result()->fetch_all(MYSQLI_NUM);
foreach($tables as [$table]){if(!str_starts_with($table,$table_prefix)||!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected fixture table.');$db->query('DROP TABLE `'.$table.'`');}$db->close();
