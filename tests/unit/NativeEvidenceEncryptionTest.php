<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class NativeEvidenceEncryptionTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testExistingKeyEncryptionIsAuthenticatedAndNeverProvisions(string $case):void
    {
        $path=dirname(__DIR__,2).'/includes/Integrations/CredentialVault.php';
        $code='function get_option($key,$fallback=""){return "";} function add_option(){throw new RuntimeException("PROVISIONING_FORBIDDEN");} ';
        if($case!=='missing')$code.='define("DIGIFORGE_CREDENTIAL_KEY",str_repeat("synthetic-only-native-key-",2));';
        $code.='require '.var_export($path,true).'; $class="DigiForge\\Integrations\\CredentialVault";';
        if($case==='missing')$code.='try{$class::encryptExisting("private synthetic bytes","native:scope");exit(2);}catch(RuntimeException $e){exit($e->getMessage()==="Credential key is unavailable."?0:3);}';
        else{
            $code.='$raw=" {\"amount\":1} ";$cipher=$class::encryptExisting($raw,"native:scope");';
            if($case==='parity')$code.='exit($class::decryptExisting($cipher,"native:scope")===$raw&&!str_contains($cipher,$raw)?0:2);';
            elseif($case==='context')$code.='try{$class::decryptExisting($cipher,"foreign:scope");exit(2);}catch(Throwable){exit(0);}';
            elseif($case==='corrupt')$code.='$cipher=substr($cipher,0,-6)."AAAAAA";try{$class::decryptExisting($cipher,"native:scope");exit(2);}catch(Throwable){exit(0);}';
            elseif($case==='key')$code.='$encoded=var_export($cipher,true);$file=tempnam(sys_get_temp_dir(),"native-key-");file_put_contents($file,"<?php define(\"DIGIFORGE_CREDENTIAL_KEY\",str_repeat(\"different-synthetic-key\",2));require ".var_export('.var_export($path,true).',true).";try{\\DigiForge\\Integrations\\CredentialVault::decryptExisting(".$encoded.",\"native:scope\");exit(2);}catch(Throwable){exit(0);}");passthru(escapeshellarg(PHP_BINARY)." ".escapeshellarg($file),$status);unlink($file);exit($status);';
        }
        $pipes=[];$process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error);self::assertSame('',$output);
    }
    /** @dataProvider deniedEnvironments */
    public function testNonlocalStorageAndInstallerHaveNoDatabasePath(string $environment):void
    {
        $path=dirname(__DIR__,2).'/includes/Database/NativeFinanceEvidenceSchema.php';
        $code='function wp_get_environment_type(){return '.var_export($environment,true).';} require '.var_export($path,true).'; $class="DigiForge\\Database\\NativeFinanceEvidenceSchema";exit(!$class::offline()&&!$class::ready()&&!$class::installOffline()?0:2);';
        $pipes=[];$process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);self::assertSame('',$out);
    }
    public static function deniedEnvironments():array{return [['production'],['staging'],['development'],['sandbox']];}
    public static function scenarios():array{return [['missing'],['parity'],['context'],['corrupt'],['key']];}
}
