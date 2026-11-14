<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/** Execute real uninstall against a subprocess-only recorder; never a database. */
final class UninstallResearchStateTest extends TestCase
{
    private function scenario(string $mode):array
    {
        $script=<<<'SCRIPT'
<?php
const WP_UNINSTALL_PLUGIN=true;
$mode=$argv[1];$deleted=[];
function is_multisite(){global $mode;return $mode==='multisite';}
function get_option($name,$default=false){global $mode;return $name==='digiforge_cleanup_on_uninstall'?$mode!=='retain':$default;}
function get_role($name){return null;}
function esc_sql($value){return $value;}
function delete_option($name){global $deleted;$deleted[]=$name;return true;}
$wpdb=new class {
 public $prefix='fixture_';public $options='fixture_options';public $queries=[];
 function query($sql){$this->queries[]=$sql;return 1;}
 function esc_like($value){return addcslashes($value,'_%\\');}
 function prepare($sql,$value){return str_replace('%s',"'".$value."'",$sql);}
 function get_col($sql){$this->queries[]=$sql;return [str_contains($sql,'pending')?'digiforge_manual_research_pending_fixture':'digiforge_manual_research_consumed_fixture','unrelated_owner_option'];}
};
require __TARGET__;
echo json_encode(['queries'=>$wpdb->queries,'deleted'=>$deleted]);
SCRIPT;
        $script=str_replace('__TARGET__',var_export(dirname(__DIR__,2).'/uninstall.php',true),$script);$file=tempnam(sys_get_temp_dir(),'df-uninstall-test-');self::assertNotFalse($file);
        try{self::assertSame(strlen($script),file_put_contents($file,$script));$process=proc_open([PHP_BINARY,$file,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);return json_decode($output,true,16,JSON_THROW_ON_ERROR);}finally{unlink($file);}
    }
    public function testDefaultAndMultisiteRetainAllResearchState():void
    {
        foreach(['retain','multisite']as $mode){$result=$this->scenario($mode);self::assertSame([],$result['queries']);self::assertSame([],$result['deleted']);}
    }
    public function testExplicitSingleSiteCleanupRemovesOnlyManagedResearchNamespaces():void
    {
        $result=$this->scenario('cleanup');self::assertContains('digiforge_manual_research_pending_fixture',$result['deleted']);self::assertContains('digiforge_manual_research_consumed_fixture',$result['deleted']);self::assertNotContains('unrelated_owner_option',$result['deleted']);self::assertTrue((bool)array_filter($result['queries'],static fn(string $sql):bool=>str_contains($sql,'DROP TABLE IF EXISTS `fixture_digiforge_etsy_order_event_evidence`')));self::assertTrue((bool)array_filter($result['queries'],static fn(string $sql):bool=>str_contains($sql,"digiforge\\_manual\\_research\\_pending\\_%")));
    }
}
