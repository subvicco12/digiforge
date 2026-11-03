<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Synthetic local custody only; no business approval or external execution. */
final class ExternalDocumentAdmissionTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    public function testExternalDocumentCustodyCapabilityExists(): void
    {
        self::assertTrue(class_exists(DigiForge\ProductFactory\ExternalDocumentAdmission::class), 'External document custody service must exist.');
    }

    public function testExternalGeneratorCannotBypassPdfGateWithoutAuthenticProof(): void
    {
        $revision=['id'=>999999,'mime_type'=>'application/pdf','checksum_sha256'=>hash('sha256','forged'),'storage_reference'=>'local://forged.pdf','provenance'=>['generator'=>'digiforge_external_document','pdf_generator_profile'=>'digiforge-external-docx-pdf-v1']];
        self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
    }

    public function testOrdinaryCapabilityWithoutExplicitShopAssignmentCannotAdmit(): void
    {
        $result=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($this->request());
        self::assertWPError($result);
        self::assertSame(403,$result->get_error_data()['status']);
    }

    public function testForeignProductCannotUseDigitalAssignment(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $result=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($this->request('jewelry'));
        self::assertWPError($result);
        self::assertSame('digiforge_external_document_scope',$result->get_error_code());
    }

    public function testVersionAndTemplateCannotBeBorrowedFromAnotherProduct(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->request();$other=$this->request();
        $request['asset_spec_id']=$other['asset_spec_id'];
        self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request));
        $request=$this->request();$request['product_id']=$other['product_id'];
        self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request));
    }

    public function testRemoteAndSymlinkInputsAreRejected(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->request();$request['source_path']='https://example.invalid/synthetic.docx';
        self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request));
        $path=tempnam(sys_get_temp_dir(),'df-external-source-');$link=$path.'.docx';
        try {
            file_put_contents($path,'Synthetic untrusted bytes');
            self::assertTrue(symlink($path,$link));$request['source_path']=$link;
            self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request));
        } finally { @unlink($link);@unlink($path); }
    }

    public function testRetainedExactBytesReplayAndTamperRemainTechnicalOnly(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->documentRequest();
        try {
            $service=new DigiForge\ProductFactory\ExternalDocumentAdmission();$result=$service->admit($request);
            self::assertNotWPError($result);
            foreach(['source','export'] as $kind){
                self::assertSame($request[$kind.'_sha256'],$result[$kind]['checksum_sha256']);
                self::assertSame($request[$kind.'_sha256'],hash_file('sha256',$result[$kind]['absolute_path']));
                self::assertNotSame($request[$kind.'_path'],$result[$kind]['absolute_path']);
            }
            self::assertSame(get_current_user_id(),$result['binding']['actor_id']);
            self::assertSame($request['asset_spec_id'],$result['revision_input']['asset_spec_id']);
            self::assertSame('digiforge_external_document',$result['revision_input']['provenance']['generator']);
            self::assertSame('digiforge-external-docx-pdf-v1',$result['revision_input']['provenance']['pdf_generator_profile']);
            self::assertFalse($result['production_authorized']);self::assertFalse($result['rights_certified']);
            self::assertFalse($result['external_action_performed']);self::assertTrue($result['approval_required']);
            self::assertSame($result,$service->admit($request));
            file_put_contents($result['export']['absolute_path'],'tampered retained export');
            self::assertWPError($service->admit($request));
            self::assertSame('tampered retained export',file_get_contents($result['export']['absolute_path']));
            self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
            self::assertTrue(DigiForge\Core\Settings::safety_locked());
            self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
        } finally { @unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public function testWrongDeclaredHashAndChangedReplayAreRejected(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->documentRequest();
        try {
            $service=new DigiForge\ProductFactory\ExternalDocumentAdmission();$wrong=$request;$wrong['source_sha256']=str_repeat('0',64);
            self::assertWPError($service->admit($wrong));
            self::assertNotWPError($service->admit($request));
            file_put_contents($request['export_path'],"\n",FILE_APPEND);$request['export_sha256']=hash_file('sha256',$request['export_path']);
            $conflict=$service->admit($request);self::assertWPError($conflict);
            self::assertSame('digiforge_external_document_replay_conflict',$conflict->get_error_code());
        } finally { @unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public function testRealCustodyRequiresOneExactQaAndRejectsTamperingAndBorrowedBinding(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->documentRequest();
        try {
            $admitted=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request);self::assertNotWPError($admitted);
            $production=new DigiForge\Production\Repository();$revision=$production->addRevision($admitted['revision_input']);self::assertNotWPError($revision);
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            $details=$admitted['evidence']+['binding'=>$admitted['binding']];
            $qa=['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$details];
            self::assertNotWPError($production->addQa($qa));
            self::assertTrue(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            foreach(['source','export'] as $kind){$path=$admitted[$kind]['absolute_path'];$bytes=file_get_contents($path);file_put_contents($path,'tampered');try{self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));}finally{file_put_contents($path,$bytes);}}
            $borrowed=$revision;$borrowed['provenance']['binding']['asset_spec_id']++;
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($borrowed));
            $forged=$revision;$forged['provenance']['normalized_text_sha256']=str_repeat('0',64);
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($forged));
            self::assertNotWPError($production->addQa($qa));
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            ShopAccessFixture::reset();
            self::assertWPError((new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request));
        } finally { @unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public function testRevocationDuringFinalQaReadPreventsGateAcceptance(): void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->documentRequest();$revoked=false;
        $filter=static function(string $query)use(&$revoked):string{
            if (!$revoked&&str_contains($query,'SELECT status,details FROM ' . DigiForge\Database\Tables::production_qa())&&str_contains($query,'external_document_source_completeness')) {
                $revoked=true;ShopAccessFixture::reset();
            }
            return $query;
        };
        try {
            $admitted=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request);self::assertNotWPError($admitted);
            $production=new DigiForge\Production\Repository();$revision=$production->addRevision($admitted['revision_input']);self::assertNotWPError($revision);
            self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$admitted['evidence']+['binding'=>$admitted['binding']]]));
            add_filter('query',$filter);
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            self::assertTrue($revoked,'The real final QA read must trigger the mid-validation revocation.');
        } finally { remove_filter('query',$filter);@unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public function testExternalMimeDowngradeAndStrippedProvenanceCannotBypassGate(): void
    {
        [$request,$admitted,$revision]=$this->reviewableRevision();
        try {
            self::assertTrue(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            $downgraded=$revision;$downgraded['mime_type']='text/plain';
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($downgraded));
            $stripped=$revision;$stripped['provenance']=[];
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($stripped));
            $stripped['mime_type']='text/plain';
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($stripped));
        } finally { @unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public function testDifferentCurrentlyAuthorizedReviewerCanValidateCustody(): void
    {
        [$request,$admitted,$revision]=$this->reviewableRevision();$importer=get_current_user_id();
        try {
            $reviewer=self::factory()->user->create(['role'=>'administrator']);
            ShopAccessFixture::authorizeActor($reviewer,['digital'],['manage_digiforge_products']);
            wp_set_current_user($reviewer);
            self::assertNotSame($importer,get_current_user_id());
            self::assertSame($importer,$admitted['binding']['actor_id']);
            self::assertTrue(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
        } finally { wp_set_current_user($importer);@unlink($request['source_path']);@unlink($request['export_path']); }
    }

    public static function lateMutationTargets(): array
    {
        return [['source'],['export'],['manifest'],['lineage']];
    }

    /** @dataProvider lateMutationTargets */
    public function testLateQaReadMutationCannotRetainGateAcceptance(string $target): void
    {
        [$request,$admitted,$revision]=$this->reviewableRevision();$mutated=false;$path=null;$original=null;
        $other=$this->request();$mutationKey='late-mutation-'.wp_generate_uuid4();$mutationSaved=null;
        if ($target!=='lineage') {
            $path=$target==='manifest'?DigiForge\ProductFactory\AssetStorage::absolutePath($revision['provenance']['custody_manifest_storage_reference']):$admitted[$target]['absolute_path'];
            self::assertIsString($path);$original=file_get_contents($path);
        }
        $filter=static function(string $query)use(&$mutated,&$mutationSaved,$target,$request,$other,$path,$mutationKey):string{
            if (!$mutated&&str_contains($query,'SELECT status,details FROM '.DigiForge\Database\Tables::production_qa())&&str_contains($query,'external_document_source_completeness')) {
                $mutated=true;
                if ($target==='lineage') { global $wpdb;$mutationSaved=$wpdb->update(DigiForge\Database\Tables::asset_specs(),['product_version_id'=>$other['product_version_id'],'asset_key'=>$mutationKey],['id'=>$request['asset_spec_id']]); }
                else { file_put_contents($path,'changed during final QA read'); }
            }
            return $query;
        };
        try {
            self::assertTrue(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            add_filter('query',$filter);
            self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
            self::assertTrue($mutated,'The final real QA read must trigger the mutation.');
            if ($target==='lineage') { self::assertSame(1,$mutationSaved,'The lineage change must be a real successful database mutation.'); }
        } finally {
            remove_filter('query',$filter);
            if ($path!==null&&is_string($original)) { file_put_contents($path,$original); }
            if ($target==='lineage') { global $wpdb;$wpdb->update(DigiForge\Database\Tables::asset_specs(),['product_version_id'=>$request['product_version_id'],'asset_key'=>'external-document'],['id'=>$request['asset_spec_id']]); }
            @unlink($request['source_path']);@unlink($request['export_path']);
        }
    }

    public function testRetainedSameByteSymlinkCannotSubstituteForCustody(): void
    {
        [$request,$admitted,$revision]=$this->reviewableRevision();
        try {
            foreach (['source','export','manifest'] as $target) {
                $path=$target==='manifest'?DigiForge\ProductFactory\AssetStorage::absolutePath($revision['provenance']['custody_manifest_storage_reference']):$admitted[$target]['absolute_path'];
                self::assertIsString($path);$original=file_get_contents($path);$outside=tempnam(sys_get_temp_dir(),'df-same-byte-');
                file_put_contents($outside,$original);unlink($path);
                try {
                    self::assertTrue(symlink($outside,$path));
                    self::assertSame(hash_file('sha256',$outside),hash_file('sha256',$path));
                    self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($revision));
                } finally { @unlink($path);file_put_contents($path,$original);@unlink($outside); }
            }
        } finally { @unlink($request['source_path']);@unlink($request['export_path']); }
    }

    private function reviewableRevision(): array
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $request=$this->documentRequest();$admitted=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($request);self::assertNotWPError($admitted);
        $production=new DigiForge\Production\Repository();$revision=$production->addRevision($admitted['revision_input']);self::assertNotWPError($revision);
        self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$admitted['evidence']+['binding'=>$admitted['binding']]]));
        return [$request,$admitted,$revision];
    }

    private function documentRequest(): array
    {
        $request=$this->request();$doc=tempnam(sys_get_temp_dir(),'df-external-docx-');$pdf=tempnam(sys_get_temp_dir(),'df-external-pdf-');
        $zip=new ZipArchive();self::assertTrue($zip->open($doc,ZipArchive::OVERWRITE)===true);
        $zip->addFromString('word/document.xml','<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Exact source - punctuation!</w:t></w:r></w:p><w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:bottom="1440" w:left="1440" w:right="1440"/></w:sectPr></w:body></w:document>');$zip->close();
        $stream='BT /F1 12 Tf 72 702 Td (Exact source - punctuation!) Tj ET';
        $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $bytes="%PDF-1.4\n";$offsets=[];foreach($objects as $i=>$object){$offsets[]=strlen($bytes);$bytes.=($i+1)." 0 obj\n".$object."\nendobj\n";}$start=strlen($bytes);$bytes.="xref\n0 6\n0000000000 65535 f \n";foreach($offsets as $offset)$bytes.=sprintf('%010d 00000 n ',$offset)."\n";$bytes.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";file_put_contents($pdf,$bytes);
        return array_replace($request,['source_path'=>$doc,'export_path'=>$pdf,'source_sha256'=>hash_file('sha256',$doc),'export_sha256'=>hash_file('sha256',$pdf)]);
    }

    private function request(string $shop='digital'): array
    {
        $products=new DigiForge\ProductFactory\Repository();
        $opportunity=$products->create('opportunity',['title'=>'Synthetic external document '.wp_generate_uuid4()]);self::assertNotWPError($opportunity);
        $family=$products->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>'Synthetic external document']);self::assertNotWPError($family);
        $product=$products->create('product',['product_family_id'=>$family['id'],'name'=>'Synthetic external document']);self::assertNotWPError($product);
        $version=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'synthetic-v1']);self::assertNotWPError($version);
        global $wpdb;$now=current_time('mysql',true);$unique=wp_generate_uuid4();
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::research_candidates(),['title'=>'Synthetic document owner','canonical_title'=>$unique,'fingerprint'=>hash('sha256',$unique),'score_version'=>'synthetic','score_inputs'=>wp_json_encode(['shop_key'=>$shop]),'opportunity_id'=>$opportunity['id'],'created_at'=>$now,'updated_at'=>$now]));
        $spec=(new DigiForge\Production\Repository())->createSpec(['product_version_id'=>$version['id'],'asset_key'=>'external-document','asset_type'=>'digital_document','format'=>'pdf']);self::assertNotWPError($spec);
        return ['shop_key'=>'digital','product_id'=>(int)$product['id'],'product_version_id'=>(int)$version['id'],'asset_spec_id'=>(int)$spec['id'],'operation_key'=>'synthetic-'.wp_generate_uuid4(),'source_path'=>'/missing/synthetic.docx','export_path'=>'/missing/synthetic.pdf','source_sha256'=>hash('sha256','source'),'export_sha256'=>hash('sha256','export')];
    }
}
