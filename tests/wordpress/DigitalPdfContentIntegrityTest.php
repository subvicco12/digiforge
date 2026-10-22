<?php
declare(strict_types=1);

/** Actual protected asset generation; structural PDF validity never proves completeness. */
final class DigitalPdfContentIntegrityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    private function write(mixed $text,string $filename='guide.pdf'): array|WP_Error
    {
        return (new DigiForge\ProductFactory\LocalAssetProducer())->write(wp_rand(100000,999999999),$filename,'pdf',$text);
    }

    public function testRequiredContentPastTheOldLimitCannotBecomeAnIncompletePdf(): void
    {
        $text=implode("\n",array_map(static fn(int $i):string=>sprintf('Required section %02d',$i),range(1,60)))."\nREQUIRED_FINAL_SENTINEL";
        $result=$this->write($text);
        self::assertWPError($result,'Oversized pages must not return a valid but incomplete PDF.');
        self::assertSame('digiforge_u3_pdf_page_overflow',$result->get_error_code());
    }

    /** @dataProvider unsupportedContent */
    public function testUnrepresentableInputFailsWithoutSilentSubstitution(mixed $text): void
    {
        self::assertWPError($this->write($text));
    }

    public static function unsupportedContent(): array
    {
        return [['café'],["cafe\u{0301}"],['emoji 😀'],["invalid\xff"],["tab\tseparated"],[[['unapproved'=>'nested text']]],['Long paragraph '.str_repeat('required words ',100)],[str_repeat('W',100)]];
    }

    public function testExplicitPagesPreserveLiteralTextSpacesAndOptionalSectionOmission(): void
    {
        $pages=['Required <literal> (text) \\ path','  Exact spacing  ',"\n   \n",'Last required section'];
        $asset=$this->write($pages);self::assertNotWPError($asset);
        $actual=$this->extract((string)file_get_contents($asset['absolute_path']));
        self::assertSame([$pages[0],$pages[1],$pages[3]],$actual);
        self::assertSame(hash('sha256',wp_json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),$asset['pdf_text_sha256']);
        self::assertTrue((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
        $other=$this->write(implode('[[PAGE_BREAK]]',$actual));self::assertNotWPError($other);
        self::assertSame($asset['checksum_sha256'],$other['checksum_sha256']);
    }

    /** @dataProvider pageBoundaries */
    public function testPhysicalPageBoundaryIsExplicit(string $filename,int $lines): void
    {
        $content=implode("\n",array_map(static fn(int $i):string=>'Line '.$i,range(1,$lines)));
        $asset=$this->write($content,$filename);self::assertNotWPError($asset);
        self::assertSame([$content],$this->extract((string)file_get_contents($asset['absolute_path'])));
        $overflow=$this->write($content."\nLAST REQUIRED LINE",$filename);
        self::assertWPError($overflow);self::assertSame('digiforge_u3_pdf_page_overflow',$overflow->get_error_code());
    }

    public static function pageBoundaries(): array{return [['guide.pdf',50],['guide-a4.pdf',54],['guide-mobile.pdf',42]];}

    public function testDocumentLimitsAreBoundedAndExplicit(): void
    {
        $pages=array_map(static fn(int $i):string=>'Page '.$i,range(1,100));
        $asset=$this->write($pages);self::assertNotWPError($asset);
        self::assertSame($pages,$this->extract((string)file_get_contents($asset['absolute_path'])));
        self::assertWPError($this->write([...$pages,'Too many pages']));
        self::assertWPError($this->write(str_repeat('x',8388609)));
        self::assertWPError($this->write(['',"\n   \n"]));
    }

    public function testMissingOrChangedExpectedTextCannotReceiveQaPass(): void
    {
        $asset=$this->write('All required text');self::assertNotWPError($asset);
        $missing=$asset;unset($missing['pdf_text_sha256']);
        self::assertFalse((new DigiForge\ProductFactory\AutomatedQa())->inspect($missing)['passed']);
        $pdf=(string)file_get_contents($asset['absolute_path']);
        file_put_contents($asset['absolute_path'],str_replace('All required text','All removed! text',$pdf));
        $asset['checksum_sha256']=hash_file('sha256',$asset['absolute_path']);
        self::assertFalse((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
    }

    public function testEverySupportedAsciiCharacterExtractsWithoutFontSubstitution(): void
    {
        $text=implode("\n",str_split(implode('',array_map('chr',range(32,126))),20));
        $asset=$this->write($text);self::assertNotWPError($asset);
        self::assertSame([$text],$this->extract((string)file_get_contents($asset['absolute_path'])));
        self::assertTrue((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
    }

    public function testLiteralPdfOperatorsArePreservedAsCustomerText(): void
    {
        $asset=$this->write('/Type /Page is plain customer text');self::assertNotWPError($asset);
        self::assertTrue((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
    }

    public function testOffPageTextCannotPassEvenWhenItsFileHashIsUpdated(): void
    {
        $asset=$this->write('Required visible text');self::assertNotWPError($asset);
        $bytes=(string)file_get_contents($asset['absolute_path']);
        file_put_contents($asset['absolute_path'],str_replace('50 742 Td','50 999 Td',$bytes));
        $asset['checksum_sha256']=hash_file('sha256',$asset['absolute_path']);
        self::assertFalse((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
    }

    public function testMaximumBoundedDocumentIsFullyReadAndComparedByQa(): void
    {
        $pages=[];
        for($p=1;$p<=100;$p++){
            $lines=[];for($l=1;$l<=50;$l++)$lines[]=str_pad('Page '.$p.' line '.$l,42,'.');
            $pages[]=implode("\n",$lines);
        }
        $asset=$this->write($pages);self::assertNotWPError($asset);
        self::assertGreaterThan(262144,$asset['byte_size']);
        self::assertSame($pages,$this->extract((string)file_get_contents($asset['absolute_path'])));
        self::assertTrue((new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['passed']);
    }

    public function testIncompleteGenerationCannotReachRevisionQaOrAReleaseBundle(): void
    {
        $factory=new DigiForge\ProductFactory\Repository();
        $opportunity=$factory->create('opportunity',['title'=>'PDF integrity fixture']);self::assertNotWPError($opportunity);
        $family=$factory->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>'PDF fixture']);self::assertNotWPError($family);
        $product=$factory->create('product',['product_family_id'=>$family['id'],'name'=>'PDF fixture']);self::assertNotWPError($product);
        $version=$factory->create('product_version',['product_id'=>$product['id'],'version_label'=>'synthetic-v1']);self::assertNotWPError($version);
        $manifest=['product_assets'=>[['asset_key'=>'customer-guide','filename'=>'guide.pdf','format'=>'pdf','purpose'=>'Required customer text','content'=>implode("\n",array_fill(0,60,'Required text'))]],'marketing_assets'=>[['asset_key'=>'preview','filename'=>'preview.txt','format'=>'txt','purpose'=>'Marketing preview','content'=>'Synthetic preview only']]];
        $result=(new DigiForge\ProductFactory\Orchestrator())->build(1,['shop'=>'digital','_developed'=>['product'=>$product,'product_version'=>$version,'spec'=>['product_name'=>'PDF fixture','asset_requirements'=>['package_filename'=>'customer.zip']]],'_manifest_ai'=>['payload'=>$manifest]],'pdf-integrity-'.wp_generate_uuid4());
        self::assertWPError($result);self::assertSame('digiforge_u3_pdf_page_overflow',$result->get_error_code());
        global $wpdb;$t=DigiForge\Database\Tables::class;
        self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$t::asset_revisions().' r INNER JOIN '.$t::asset_specs().' s ON s.id=r.asset_spec_id WHERE s.product_version_id=%d',$version['id'])));
        self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$t::release_bundles().' b INNER JOIN '.$t::production_plans()." p ON p.id=b.production_plan_id WHERE p.product_version_id=%d AND b.state='RELEASE_READY'",$version['id'])));
    }

    public function testHistoricalStructuralOnlyQaCannotApproveOrReleaseLocalPdf(): void
    {
        [$production,$plan,$revision,$bundle]=$this->reviewFixture(false);
        $gate=new ReflectionMethod(DigiForge\ProductFactory\ProductReview::class,'requiredQaPassedRevisions');
        self::assertWPError($gate->invoke(new DigiForge\ProductFactory\ProductReview(),(int)$plan['id']));
        self::assertNotWPError($production->transition('revision',(int)$revision['id'],'APPROVED'));
        $result=$production->validateBundle((int)$bundle['id']);self::assertNotWPError($result);
        self::assertNotSame('RELEASE_READY',$result['state']);
    }

    public function testExactSourceBoundQaPermitsOnlyTheExistingLocalReviewBoundary(): void
    {
        [$production,$plan,$revision,$bundle]=$this->reviewFixture(true);
        $gate=new ReflectionMethod(DigiForge\ProductFactory\ProductReview::class,'requiredQaPassedRevisions');
        self::assertSame([(int)$revision['id']],$gate->invoke(new DigiForge\ProductFactory\ProductReview(),(int)$plan['id']));
        self::assertNotWPError($production->transition('revision',(int)$revision['id'],'APPROVED'));
        self::assertSame('RELEASE_READY',$production->validateBundle((int)$bundle['id'])['state']);
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }

    public function testConflictingCompletenessRowsAndUnavailableReadsFailClosed(): void
    {
        [$production,$plan,$revision]=$this->reviewFixture(true);
        self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'pdf_source_text_completeness','status'=>'PASS','details'=>['conflicting'=>'synthetic']]));
        global $wpdb;$stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::asset_revisions().' WHERE id=%d',$revision['id']),ARRAY_A);
        self::assertWPError(DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($stored));
        $filter=static fn(string $sql):string=>str_starts_with($sql,'SELECT status,details FROM ')?str_replace(DigiForge\Database\Tables::production_qa(),'missing_pdf_completeness_fixture',$sql):$sql;
        $suppress=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$error=DigiForge\ProductFactory\PdfTextIntegrity::validateRevision($stored);self::assertWPError($error);self::assertSame(503,$error->get_error_data()['status']);}
        finally{remove_filter('query',$filter);$wpdb->suppress_errors($suppress);}
    }

    private function reviewFixture(bool $proof): array
    {
        $factory=new DigiForge\ProductFactory\Repository();
        $o=$factory->create('opportunity',['title'=>'PDF review fixture']);self::assertNotWPError($o);
        $f=$factory->create('product_family',['opportunity_id'=>$o['id'],'name'=>'PDF review fixture']);self::assertNotWPError($f);
        $p=$factory->create('product',['product_family_id'=>$f['id'],'name'=>'PDF review fixture']);self::assertNotWPError($p);
        $v=$factory->create('product_version',['product_id'=>$p['id'],'version_label'=>'synthetic-review-v1']);self::assertNotWPError($v);
        $asset=(new DigiForge\ProductFactory\LocalAssetProducer())->write((int)$v['id'],'review.pdf','pdf','All required review text');self::assertNotWPError($asset);
        $production=new DigiForge\Production\Repository();
        $spec=$production->createSpec(['product_version_id'=>$v['id'],'asset_key'=>'customer-pdf','asset_type'=>'digital','format'=>'pdf']);self::assertNotWPError($spec);
        $plan=$production->createPlan(['product_version_id'=>$v['id'],'plan_key'=>'pdf-review','version_label'=>'1','channel'=>'digital','production_type'=>'pdf']);self::assertNotWPError($plan);
        self::assertTrue($production->linkAsset((int)$plan['id'],(int)$spec['id']));
        $provenance=['generator'=>'digiforge_u3'];if($proof)$provenance+=array_intersect_key($asset,array_flip(['pdf_source_sha256','pdf_text_sha256','pdf_generator_profile']));
        $revision=$production->addRevision(['asset_spec_id'=>$spec['id'],'revision_label'=>'synthetic-r1','storage_reference'=>$asset['storage_reference'],'checksum_sha256'=>$asset['checksum_sha256'],'mime_type'=>'application/pdf','byte_size'=>$asset['byte_size'],'provenance'=>$provenance]);self::assertNotWPError($revision);
        $checks=$proof?(new DigiForge\ProductFactory\AutomatedQa())->inspect($asset)['checks']:[['name'=>'format_integrity','passed'=>true,'details'=>[]]];
        foreach($checks as $check)self::assertNotWPError($production->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>$check['name'],'status'=>$check['passed']?'PASS':'FAIL','details'=>$check['details']]));
        self::assertNotWPError($production->transition('revision',(int)$revision['id'],'QA_PASSED'));
        $bundle=$production->createBundle(['production_plan_id'=>$plan['id'],'bundle_key'=>'pdf-review','version_label'=>'1']);self::assertNotWPError($bundle);
        foreach(['plan'=>(int)$plan['id'],'bundle'=>(int)$bundle['id']] as $entity=>$id)foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($production->transition($entity,$id,$state));
        return [$production,$plan,$revision,$bundle];
    }

    public function testChangedSourceNeverOverwritesAnImmutableArtifact(): void
    {
        $producer=new DigiForge\ProductFactory\LocalAssetProducer();$version=wp_rand(100000,999999999);
        $first=$producer->write($version,'immutable.pdf','pdf','Original required text');self::assertNotWPError($first);
        $again=$producer->write($version,'immutable.pdf','pdf','Original required text');self::assertNotWPError($again);self::assertSame($first,$again);
        $changed=$producer->write($version,'immutable.pdf','pdf','Different required text');self::assertWPError($changed);
        self::assertSame('digiforge_u3_asset_replay_conflict',$changed->get_error_code());
        self::assertSame($first['checksum_sha256'],hash_file('sha256',$first['absolute_path']));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
    }

    /** Independent extraction of this uncompressed local PDF's literal text operators. */
    private function extract(string $pdf): array
    {
        preg_match_all('/stream\n(.*?)\nendstream/s',$pdf,$streams);$pages=[];
        foreach($streams[1] as $stream){preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\) Tj T\*/s',$stream,$matches);$lines=[];
            foreach($matches[1] as $line)$lines[]=preg_replace_callback('/\\\\([\\\\()])/',static fn(array $m):string=>$m[1],$line);
            // PDF StandardEncoding maps these code points to curly quote glyphs.
            // WinAnsiEncoding is needed for the supported ASCII punctuation semantics.
            if(!str_contains($pdf,'/Encoding /WinAnsiEncoding'))$lines=array_map(static fn(string $line):string=>strtr($line,["'"=>"’","`"=>"‘"]),$lines);
            $pages[]=implode("\n",$lines);
        }
        return $pages;
    }
}
