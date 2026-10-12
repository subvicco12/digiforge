<?php
declare(strict_types=1);

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;

/** Blueprint p77/table23/r6/c3: bounded recorded TXT/PNG QA visibility, not universal artifact certification. */
final class PortalArtifactCoverageAcceptanceTest extends WP_UnitTestCase
{
    private array $paths = [];
    private int $networkCalls = 0;
    private mixed $networkFilter;

    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        add_action('digiforge_log', [DigiForge\Security\Logger::class, 'write'], 10, 4);
        $this->networkFilter = function () {
            ++$this->networkCalls;
            return new WP_Error('offline_artifact_fixture', 'No provider calls permitted.');
        };
        add_filter('pre_http_request', $this->networkFilter, 1, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->networkFilter, 1);
        foreach ($this->paths as $path) if (is_file($path)) unlink($path);
        Settings::protectProduction();
        parent::tearDown();
    }

    private function render(string $shop): string
    {
        $get = $_GET;
        try { $_GET = ['df_view'=>'digital','df_shop'=>$shop]; return (new DigiForge\Portal\Portal())->render(); }
        finally { $_GET = $get; }
    }

    private function product(string $shop): array
    {
        $research = new DigiForge\Research\Repository();
        $source = $research->createSource(['shop_key'=>$shop,'name'=>'Artifact visibility fixture','source_type'=>'manual','environment'=>'sandbox']);
        self::assertNotWPError($source);
        $observation = $research->ingest(['source_id'=>$source['id'],'title'=>'Synthetic artifact','body'=>'Local fixture only.']);
        self::assertNotWPError($observation);
        $evidence = $research->addEvidence((int)$observation['id'], ['evidence_type'=>'manual_note','value'=>'Synthetic local provenance.']);
        self::assertNotWPError($evidence);
        $candidate = $research->createCandidate(['shop_key'=>$shop,'title'=>'Artifact visibility '.$shop]);
        self::assertNotWPError($candidate);
        self::assertTrue($research->linkEvidence((int)$candidate['id'], (int)$evidence['id']));
        self::assertNotWPError($research->review((int)$candidate['id'], 'APPROVED', 'Local fixture only.'));
        $opportunity = $research->promote((int)$candidate['id']);
        self::assertNotWPError($opportunity);
        $products = new DigiForge\ProductFactory\Repository();
        $family = $products->create('product_family', ['opportunity_id'=>$opportunity['id'],'name'=>'Artifact fixture family']);
        self::assertNotWPError($family);
        $product = $products->create('product', ['product_family_id'=>$family['id'],'name'=>'Artifact fixture product']);
        self::assertNotWPError($product);
        $version = $products->create('product_version', ['product_id'=>$product['id'],'version_label'=>'local-artifact-v1']);
        self::assertNotWPError($version);
        $digital = (new DigiForge\DigitalFactory\Repository())->create('digital_product',
            ['name'=>'Recorded artifact '.$shop,'category'=>'fixture','product_id'=>$product['id'],'product_version_id'=>$version['id']]);
        self::assertNotWPError($digital);
        return $digital;
    }

    /** Produce actual supported bytes and persist the resulting deterministic checks. */
    private function check(array $product, string $format, string $bytes): array
    {
        if ($format === 'txt') $bytes .= 'Fixture identity: '.wp_generate_uuid4()."\n";
        $path = sys_get_temp_dir().'/df-qa-'.wp_generate_uuid4().'.'.$format;
        $this->paths[] = $path;
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $hash = hash_file('sha256', $path);
        $qa = (new DigiForge\ProductFactory\AutomatedQa())->inspect(
            ['absolute_path'=>$path,'format'=>$format,'checksum_sha256'=>$hash]);
        self::assertTrue($qa['passed']);
        $repository = new DigiForge\DigitalFactory\Repository();
        $file = $repository->create('digital_file', ['name'=>'Synthetic '.$format,'file_type'=>$format,
            'digital_product_id'=>$product['id'],'product_version_id'=>$product['product_version_id']]);
        self::assertNotWPError($file);
        $version = $repository->create('digital_file_version', ['version_label'=>'local-qa-v1','digital_file_id'=>$file['id'],
            'storage_reference'=>'local://fixture/'.$format,'checksum_sha256'=>$hash,'byte_size'=>strlen($bytes)]);
        self::assertNotWPError($version);
        $check = $repository->create('digital_download_check', ['digital_product_id'=>$product['id'],
            'target_type'=>'file_version','target_id'=>$version['id'],'check_type'=>'custom_deterministic_qa','validation_result'=>'PASS',
            'review_status'=>'UNREVIEWED','details'=>['format'=>$format,'checksum_sha256'=>$hash,'byte_size'=>strlen($bytes),'checks'=>$qa['checks']]]);
        self::assertNotWPError($check);
        return ['id'=>(int)$check['id'],'hash'=>$hash,'checks'=>$qa['checks'],'bytes'=>strlen($bytes),'format'=>$format];
    }

    public function testActualTxtAndPngQaProofIsVisibleOnlyWithinPersistedShopLineage(): void
    {
        $digital = $this->product('digital');
        $txt = $this->check($digital, 'txt', "Original local TXT acceptance fixture.\n");
        $png = $this->check($digital, 'png', DigiForge\POD\RasterArtworkCodec::encode(2, 2, str_repeat("\xff\x00\x00\xff", 4)));
        $other = $this->check($this->product('personalized_pod'), 'txt', "Other shop local TXT fixture.\n");
        $html = $this->render('digital');
        self::assertStringContainsString('Qa Evidence', $html);
        foreach ([$txt,$png] as $check) self::assertStringContainsString('Recorded '.strtoupper($check['format']).' QA; SHA-256 '.$check['hash'], $html);
        self::assertStringContainsString('png_dimensions: PASS', $html);
        self::assertStringContainsString('checksum_match: PASS', $html);
        self::assertStringContainsString('Readiness not inferred.', $html);
        self::assertStringNotContainsString($other['hash'], $html);
        $otherHtml = $this->render('personalized_pod');
        self::assertStringContainsString($other['hash'], $otherHtml);
        self::assertStringNotContainsString($txt['hash'], $otherHtml);
        self::assertStringNotContainsString($png['hash'], $otherHtml);
        self::assertSame(0, $this->networkCalls);
        self::assertTrue(Settings::safety_locked());
        self::assertTrue(Settings::get('stop_all'));
        self::assertFalse(Settings::get('automation_armed'));
    }

    public function testArbitrarySecretsAndXssNeverEnterBoundedQaSummaryAndMissingProofStaysUnverified(): void
    {
        $check = $this->check($this->product('digital'), 'txt', "Local redaction acceptance fixture.\n");
        global $wpdb;
        $safe = ['format'=>'txt','checksum_sha256'=>$check['hash'],'byte_size'=>$check['bytes'],'checks'=>$check['checks']];
        $unsafe = $safe + ['access_token'=>'synthetic-secret-never-display', 'summary'=>'<script>qaXssSentinel()</script>',
            'nested'=>['api_key'=>'synthetic-nested-secret']];
        $unsafe['checks'][0]['details'] = ['access_token'=>'synthetic-check-secret','filename'=>'<img src=x onerror=qaXssSentinel()>'];
        self::assertSame(1, $wpdb->update(Tables::digital_download_checks(), ['details'=>wp_json_encode($unsafe)], ['id'=>$check['id']]));
        $html = $this->render('digital');
        self::assertStringContainsString('Recorded TXT QA; SHA-256 '.$check['hash'], $html);
        foreach (['synthetic-secret-never-display','synthetic-nested-secret','synthetic-check-secret','qaXssSentinel','access_token','api_key'] as $value)
            self::assertStringNotContainsString($value, $html);
        foreach (['', '{}', 'not JSON', str_repeat('x',65537), wp_json_encode(array_replace($safe,['format'=>'<script>qaXssSentinel()</script>'])),
            wp_json_encode(array_replace($safe,['checks'=>[['name'=>'checksum_match','passed'=>'true']]]))] as $details) {
            $wpdb->update(Tables::digital_download_checks(), ['details'=>$details], ['id'=>$check['id']]);
            $html = $this->render('digital');
            self::assertStringContainsString('UNVERIFIED: structured QA/provenance evidence is missing or unsupported.', $html);
            self::assertStringNotContainsString('Recorded TXT QA; SHA-256 '.$check['hash'], $html);
            self::assertStringNotContainsString('qaXssSentinel', $html);
        }
        self::assertSame(0, $this->networkCalls);
        self::assertTrue(Settings::safety_locked());
    }

    public function testQaReadFailureAndUnauthorizedOperatorCannotExposeOrInferArtifactEvidence(): void
    {
        $check = $this->check($this->product('digital'), 'txt', "Local unavailable proof fixture.\n");
        global $wpdb;
        $filter = static fn(string $sql): string => str_contains($sql,'FROM '.Tables::digital_download_checks())
            ? str_replace(Tables::digital_download_checks(),'digiforge_missing_qa_visibility_fixture',$sql) : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { $html = $this->render('digital'); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); }
        self::assertStringContainsString('Evidence unavailable. Source records could not be read', $html);
        self::assertStringNotContainsString('Recorded TXT QA; SHA-256 '.$check['hash'], $html);
        wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));
        self::assertStringNotContainsString($check['hash'], $this->render('digital'));
        wp_set_current_user(0);
        self::assertStringNotContainsString($check['hash'], $this->render('digital'));
        self::assertSame(0, $this->networkCalls);
        self::assertTrue(Settings::safety_locked());
    }
}
