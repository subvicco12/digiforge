<?php
declare(strict_types=1);

namespace DigiForge\POD {
    /** Scoped test-process I/O fault; no production hook or replacement renderer. */
    function fwrite($handle,string $bytes):int|false
    {
        $fault=$GLOBALS['df_render_store_fault']??null;
        if(!is_array($fault))return \fwrite($handle,$bytes);
        unset($GLOBALS['df_render_store_fault']);
        $half=max(1,intdiv(strlen($bytes),2));$n=\fwrite($handle,substr($bytes,0,$half));\fflush($handle);
        if($fault['mode']==='short')return $n;
        file_put_contents($fault['control'].'.ready','1');$until=microtime(true)+20;
        while(!is_file($fault['control'].'.go')){if(microtime(true)>$until)throw new \RuntimeException('Retention barrier timed out.');usleep(10000);}
        $rest=\fwrite($handle,substr($bytes,$half));return $n===false||$rest===false?false:$n+$rest;
    }
}
namespace {
    final class RenderArtifactInterruptedRetentionTest extends \WP_UnitTestCase
    {
        private array $paths=[];
        private array $processes=[];
        protected function tearDown():void
        {
            unset($GLOBALS['df_render_store_fault']);
            foreach($this->processes as$p){proc_terminate($p,9);proc_close($p);}
            foreach(array_reverse($this->paths)as$p){if(is_file($p))unlink($p);elseif(is_dir($p))rmdir($p);}
            parent::tearDown();
        }
        private function fixture():array
        {
            $source=tempnam(sys_get_temp_dir(),'df-retention-source-');$this->paths[]=$source;
            $rgba=random_bytes(16);file_put_contents($source,\DigiForge\POD\RasterArtworkCodec::encode(2,2,$rgba));
            $metadata=(new \DigiForge\POD\RenderArtifactVerifier())->readArtifact($source);self::assertNotWPError($metadata);
            $store=new \DigiForge\POD\RenderArtifactStore();$root=wp_upload_dir()['basedir'].'/'.\DigiForge\ProductFactory\AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/shop-personalized_pod';wp_mkdir_p($root);
            $target=$root.'/'.$metadata['sha256'].'.png';$this->paths[]=$target;return [$store,$source,$metadata,$target];
        }
        private function child(string $source,array $metadata,bool $disableLink=false):string
        {
            $base=tempnam(sys_get_temp_dir(),'df-retention-child-');$this->paths[]=$base;
            $request=$base.'.json';$this->paths[]=$request;$this->paths[]=$base.'.ready';$this->paths[]=$base.'.go';$this->paths[]=$base.'.result';$this->paths[]=$base.'.log';$this->paths[]=$base.'.err';
            file_put_contents($request,json_encode(['source'=>$source,'metadata'=>$metadata,'uploads'=>defined('UPLOADS')?UPLOADS:null,'control'=>$base,'pause'=>!$disableLink],JSON_THROW_ON_ERROR));
            $worker=$base.'.php';$this->paths[]=$worker;
            $dir=rtrim((string)getenv('WP_TESTS_DIR'),'/');$config=$dir.'/wp-tests-config.php';
            if(!is_file($config)&&basename($dir)==='phpunit'&&basename(dirname($dir))==='tests')$config=dirname($dir,2).'/wp-tests-config.php';
            if(defined('WP_TESTS_CONFIG_FILE_PATH'))$config=WP_TESTS_CONFIG_FILE_PATH;
            $code='<?php $c=json_decode(file_get_contents($argv[1]),true); putenv("WP_TESTS_SKIP_INSTALL=1"); define("WP_TESTS_CONFIG_FILE_PATH",'.var_export($config,true).'); if(is_string($c["uploads"]))define("UPLOADS",$c["uploads"]); require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).'; require '.var_export(__DIR__.'/bootstrap.php',true).'; require '.var_export(__FILE__,true).'; if(wp_get_environment_type()!=="local"||DB_NAME!=='.var_export(DB_NAME,true).'||!str_starts_with(DB_NAME,"digiforge_tests"))throw new RuntimeException("Isolated test database required"); if($c["pause"])$GLOBALS["df_render_store_fault"]=["mode"=>"pause","control"=>$c["control"]]; $r=(new DigiForge\\POD\\RenderArtifactStore())->retain($c["source"],$c["metadata"],"personalized_pod"); file_put_contents($c["control"].".result",json_encode(is_wp_error($r)?["error"=>$r->get_error_code()]:$r));';
            file_put_contents($worker,$code);$command=[PHP_BINARY];if($disableLink){$command[]='-d';$command[]='disable_functions=link';}$command[]=$worker;$command[]=$request;
            $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$base.'.log','w'],2=>['file',$base.'.err','w']],$pipes);self::assertIsResource($p);$this->processes[$base]=$p;
            if($disableLink){self::assertSame(0,proc_close($p),file_get_contents($base.'.err'));unset($this->processes[$base]);return $base;}
            $until=microtime(true)+20;while(!is_file($base.'.ready')&&microtime(true)<$until){if(!proc_get_status($p)['running'])break;usleep(10000);}self::assertFileExists($base.'.ready',file_get_contents($base.'.err').file_get_contents($base.'.log'));return $base;
        }
        public function testShortWriteCannotPoisonTheContentAddressedIdentity():void
        {
            [$store,$source,$metadata,$target]=$this->fixture();$GLOBALS['df_render_store_fault']=['mode'=>'short'];self::assertWPError($store->retain($source,$metadata,'personalized_pod'));
            self::assertFileDoesNotExist($target,'An incomplete write must never occupy the final content address.');self::assertNotWPError($store->retain($source,$metadata,'personalized_pod'));self::assertSame($metadata['sha256'],hash_file('sha256',$target));
        }
        public function testIndependentPublisherCannotObserveAnotherWritersPartialFinal():void
        {
            [$store,$source,$metadata,$target]=$this->fixture();$base=$this->child($source,$metadata);$other=$store->retain($source,$metadata,'personalized_pod');self::assertNotWPError($other,'A partial unpublished writer must not poison an independent publisher.');$before=file_get_contents($target);$inode=stat($target)['ino'];file_put_contents($base.'.go','1');self::assertSame(0,proc_close($this->processes[$base]));unset($this->processes[$base]);$first=json_decode(file_get_contents($base.'.result'),true);self::assertSame($other,$first);self::assertSame($before,file_get_contents($target));self::assertSame($inode,stat($target)['ino']);
        }
        public function testKilledWriterLeavesNoAcceptedPartialDestination():void
        {
            [$store,$source,$metadata,$target]=$this->fixture();$base=$this->child($source,$metadata);self::assertFileDoesNotExist($target);proc_terminate($this->processes[$base],9);proc_close($this->processes[$base]);unset($this->processes[$base]);self::assertFileDoesNotExist($base.'.result');self::assertNotWPError($store->retain($source,$metadata,'personalized_pod'));self::assertSame($metadata['sha256'],hash_file('sha256',$target));
            foreach(glob($target.'.tmp-*')as$p)$this->paths[]=$p;
        }
        public function testHistoricalCorruptDestinationRemainsBlockedAndUntouched():void
        {
            [$store,$source,$metadata,$target]=$this->fixture();file_put_contents($target,'Historical incomplete bytes');$before=file_get_contents($target);self::assertWPError($store->retain($source,$metadata,'personalized_pod'));self::assertSame($before,file_get_contents($target));
        }
        public function testUnavailableAtomicCommitDeniesNewButPreservesRetainedReadback():void
        {
            [$store,$source,$metadata,$target]=$this->fixture();$base=$this->child($source,$metadata,true);$result=json_decode(file_get_contents($base.'.result'),true);self::assertSame('render_storage_unavailable',$result['error']);self::assertFileDoesNotExist($target);
            $retained=$store->retain($source,$metadata,'personalized_pod');self::assertNotWPError($retained);$before=file_get_contents($target);$inode=stat($target)['ino'];$base=$this->child($source,$metadata,true);self::assertSame($retained,json_decode(file_get_contents($base.'.result'),true));self::assertSame($before,file_get_contents($target));self::assertSame($inode,stat($target)['ino']);
        }
    }
}
