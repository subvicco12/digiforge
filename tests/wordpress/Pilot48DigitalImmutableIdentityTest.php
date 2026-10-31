<?php
declare(strict_types=1);
require_once __DIR__.'/DigitalFactoryIntentScopeTest.php';

/** Offline registry custody: QA references must retain their original byte identity. */
final class Pilot48DigitalImmutableIdentityTest extends WP_UnitTestCase
{
    private function fixture(): array
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        return (new ReflectionMethod(DigitalFactoryIntentScopeTest::class,'hierarchy'))->invoke(new DigitalFactoryIntentScopeTest(),'digital');
    }

    public function testFileVersionCannotReplaceBytesUnderExistingPassingQa(): void
    {
        $f=$this->fixture();$repo=new DigiForge\DigitalFactory\Repository();$version=$f['rows']['digital_file_version'];
        $qa=$repo->update('digital_download_check',$f['rows']['digital_download_check']['id'],['validation_result'=>'PASS']);self::assertNotWPError($qa);
        $other=$repo->create('digital_file',['name'=>'Other fictional PDF','file_type'=>'pdf','digital_product_id'=>$f['rows']['digital_product']['id'],'product_version_id'=>$f['rows']['digital_product']['product_version_id']]);self::assertNotWPError($other);
        foreach(['digital_file_id'=>$other['id'],'checksum_sha256'=>hash('sha256','edited fictional document'),'storage_reference'=>'local://edited.pdf','byte_size'=>99] as $field=>$value){
            $result=$repo->update('digital_file_version',$version['id'],[$field=>$value]);
            self::assertWPError($result,'A passing QA target ID must never acquire replacement '.$field);
            self::assertSame(409,$result->get_error_data()['status']);
            self::assertSame($version,$repo->find('digital_file_version',$version['id']));
            self::assertSame($qa,$repo->find('digital_download_check',$qa['id']));
        }
        $next=$repo->create('digital_file_version',['digital_file_id'=>$version['digital_file_id'],'version_label'=>'edited-v2','checksum_sha256'=>hash('sha256','edited fictional document'),'storage_reference'=>'local://edited.pdf','byte_size'=>99]);
        self::assertNotWPError($next);self::assertNotSame($version['id'],$next['id']);
        $status=$repo->update('digital_file_version',$version['id'],['status'=>'RETIRED','checksum_sha256'=>$version['checksum_sha256']]);self::assertNotWPError($status);
        self::assertSame($version['checksum_sha256'],$status['checksum_sha256']);
    }

    public function testPendingPlaceholdersCanBePopulatedOnceBeforeQa(): void
    {
        $f=$this->fixture();$repo=new DigiForge\DigitalFactory\Repository();
        foreach(['digital_file_version','digital_package'] as $type){
            $input=$type==='digital_package'?['name'=>'Pending fixture','digital_product_id'=>$f['rows']['digital_product']['id'],'product_version_id'=>$f['rows']['digital_product']['product_version_id']]:['digital_file_id'=>$f['rows']['digital_file']['id'],'version_label'=>'pending-v2'];
            $row=$repo->create($type,$input);self::assertNotWPError($row);
            $identity=['checksum_sha256'=>hash('sha256','final offline fixture'),'byte_size'=>25];
            $identity+=$type==='digital_package'?['manifest'=>['files'=>['final.pdf']],'generation_status'=>'COMPLETE']:['storage_reference'=>'local://final.pdf','status'=>'READY'];
            $filled=$repo->update($type,$row['id'],$identity);self::assertNotWPError($filled,'Pending defaults must allow initial population.');
            self::assertWPError($repo->update($type,$row['id'],['checksum_sha256'=>hash('sha256','replacement')]));
            $empty=$repo->create($type,array_replace($input,$type==='digital_file_version'?['version_label'=>'pending-v3']:[]));self::assertNotWPError($empty);
            $qa=$repo->create('digital_download_check',['digital_product_id'=>$f['rows']['digital_product']['id'],'target_type'=>$type==='digital_package'?'package':'file_version','target_id'=>$empty['id'],'check_type'=>'checksum','validation_result'=>'PASS']);self::assertNotWPError($qa);
            self::assertWPError($repo->update($type,$empty['id'],$identity),'Existing QA must prevent even initial identity population.');
        }
    }

    public function testCompetingInitializerCannotReplaceWinningPackageIdentity(): void
    {
        $f=$this->fixture();$repo=new DigiForge\DigitalFactory\Repository();
        $row=$repo->create('digital_package',['name'=>'Competing placeholder','digital_product_id'=>$f['rows']['digital_product']['id'],'product_version_id'=>$f['rows']['digital_product']['product_version_id']]);self::assertNotWPError($row);
        $winner=null;$injected=false;$hook=function(string $sql)use(&$injected,&$winner,$repo,$row):string{
            if(!$injected && str_starts_with($sql,'UPDATE ') && str_contains($sql,DigiForge\Database\Tables::digital_packages())){
                $injected=true;
                $winner=$repo->update('digital_package',$row['id'],['manifest'=>['files'=>['winner.pdf']],'checksum_sha256'=>hash('sha256','winning bytes'),'byte_size'=>20]);$this->assertNotWPError($winner);
            }
            return $sql;
        };
        add_filter('query',$hook);
        try{$result=$repo->update('digital_package',$row['id'],['manifest'=>['files'=>['loser.pdf']],'checksum_sha256'=>hash('sha256','losing bytes'),'byte_size'=>10]);}
        finally{remove_filter('query',$hook);}
        self::assertTrue($injected);self::assertWPError($result);self::assertSame(409,$result->get_error_data()['status']);self::assertSame($winner,$repo->find('digital_package',$row['id']));
    }

    public function testConcurrentQaPreventsPlaceholderPopulation(): void
    {
        $f=$this->fixture();$repo=new DigiForge\DigitalFactory\Repository();
        $row=$repo->create('digital_package',['name'=>'Concurrent placeholder','digital_product_id'=>$f['rows']['digital_product']['id'],'product_version_id'=>$f['rows']['digital_product']['product_version_id']]);self::assertNotWPError($row);
        $injected=false;$hook=function(string $sql)use(&$injected,$repo,$row):string{
            if(!$injected && str_starts_with($sql,'UPDATE ') && str_contains($sql,DigiForge\Database\Tables::digital_packages())){
                $injected=true;
                $qa=$repo->create('digital_download_check',['digital_product_id'=>$row['digital_product_id'],'target_type'=>'package','target_id'=>$row['id'],'check_type'=>'checksum','validation_result'=>'PASS']);$this->assertNotWPError($qa);
            }
            return $sql;
        };
        add_filter('query',$hook);
        try{$result=$repo->update('digital_package',$row['id'],['manifest'=>['files'=>['late.pdf']],'checksum_sha256'=>hash('sha256','late bytes'),'byte_size'=>10]);}
        finally{remove_filter('query',$hook);}
        self::assertTrue($injected);self::assertWPError($result);self::assertSame(409,$result->get_error_data()['status']);self::assertSame($row,$repo->find('digital_package',$row['id']));
    }

    public function testCustomerPackageCannotReplaceManifestOrReopenItsIdentity(): void
    {
        $f=$this->fixture();$repo=new DigiForge\DigitalFactory\Repository();$package=$f['rows']['digital_package'];
        self::assertWPError($repo->update('digital_package',$package['id'],['manifest'=>['files'=>['unreviewed edit.pdf']]]));
        $complete=$repo->update('digital_package',$package['id'],['generation_status'=>'COMPLETE']);self::assertNotWPError($complete);
        $qa=$repo->create('digital_download_check',['digital_product_id'=>$package['digital_product_id'],'target_type'=>'package','target_id'=>$package['id'],'check_type'=>'archive_integrity','validation_result'=>'PASS']);self::assertNotWPError($qa);
        // Relabeling operational status must never reopen the retained package identity.
        self::assertNotWPError($repo->update('digital_package',$package['id'],['generation_status'=>'PENDING']));
        foreach(['manifest'=>['files'=>['substituted.pdf']],'checksum_sha256'=>hash('sha256','substitute archive'),'byte_size'=>99] as $field=>$value){
            $before=$repo->find('digital_package',$package['id']);
            $result=$repo->update('digital_package',$package['id'],[$field=>$value]);self::assertWPError($result,'Retained customer package identity must be immutable: '.$field);
            self::assertSame(409,$result->get_error_data()['status']);self::assertSame($before,$repo->find('digital_package',$package['id']));
        }
        $same=$repo->update('digital_package',$package['id'],['name'=>'Renamed fixture','manifest'=>['version'=>1,'files'=>['synthetic.pdf']]]);self::assertNotWPError($same);self::assertSame($package['manifest'],$same['manifest']);
        $next=$repo->create('digital_package',['name'=>'Edited fixture package','digital_product_id'=>$package['digital_product_id'],'product_version_id'=>$package['product_version_id'],'manifest'=>['files'=>['substituted.pdf']]]);self::assertNotWPError($next);self::assertNotSame($package['id'],$next['id']);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
}
