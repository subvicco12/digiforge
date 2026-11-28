<?php
declare(strict_types=1);
require_once __DIR__.'/ExternalDocumentAdmissionTest.php';
/** Real custody and QA gate over synthetic passive artwork/header sources. */
final class ExternalIllustratedDocumentAdmissionTest extends WP_UnitTestCase
{
    private array $paths=[];
    protected function setUp():void {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
    }
    protected function tearDown():void {
        foreach($this->paths as $path)@unlink($path);
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());parent::tearDown();
    }
    private function request():array {
        $r=(new ReflectionMethod(ExternalDocumentAdmissionTest::class,'documentRequest'))->invoke(new ExternalDocumentAdmissionTest());
        $this->paths[]=$r['source_path'];$this->paths[]=$r['export_path'];
        $process=proc_open(['/usr/bin/python3','-B',dirname(__DIR__).'/offline/create_illustrated_document_fixture.py',$r['source_path'],$r['export_path']],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err.$out);
        $r['source_sha256']=hash_file('sha256',$r['source_path']);$r['export_sha256']=hash_file('sha256',$r['export_path']);return $r;
    }
    public function testPassiveSourceCustodyReplayAndQaRemainTechnicalOnly():void {
        $r=$this->request();$service=new DigiForge\ProductFactory\ExternalDocumentAdmission();$a=$service->admit($r);self::assertNotWPError($a);
        self::assertSame('INLINE_RGBA8_PNG_STATIC_HEADER_V1',$a['evidence']['source_layout_profile']);self::assertFalse($a['evidence']['artwork_export_parity_verified']);
        self::assertFalse($a['rights_certified']);self::assertFalse($a['production_authorized']);self::assertTrue($a['approval_required']);self::assertSame($a,$service->admit($r));
        $repo=new DigiForge\Production\Repository();$revision=$repo->addRevision($a['revision_input']);self::assertNotWPError($revision);
        self::assertNotWPError($repo->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$a['evidence']+['binding'=>$a['binding']]]));
        self::assertTrue(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
        self::assertSame($r['source_sha256'],hash_file('sha256',$a['source']['absolute_path']));
        ShopAccessFixture::reset();self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
    }
    public function testForeignIdentityAndForgedHeaderCannotAdmit():void {
        $r=$this->request();$bad=$r;$bad['product_id']+=999;self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($bad));
        $zip=new ZipArchive();self::assertTrue($zip->open($r['source_path'])===true);$header=$zip->getFromName('word/header1.xml');self::assertIsString($header);$zip->addFromString('word/header1.xml',str_replace('OUR CELEBRATION','FORGED CELEBRATION',$header));$zip->close();
        $r['source_sha256']=hash_file('sha256',$r['source_path']);self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($r));
    }
}
