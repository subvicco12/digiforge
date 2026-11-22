<?php

declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
use DigiForge\POD\ProductIntelligence;
use DigiForge\POD\ProductIntelligenceRepository;
use DigiForge\Portal\ProductIntelligencePanel;

final class ProductIntelligenceWordPressTest extends WP_UnitTestCase
{
    public function setUp(): void {parent::setUp();PrintifyResearchAccessFixture::reset();}
    private function packet(): array
    {
        $rows=[['V2 ID'=>'DG2-001','Source DG ID'=>'DG-001','Physical Product'=>'PP-001','Concept'=>'<script>bad()</script>','Price USD'=>null]];
        return ['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'source','name'=>'fixture.csv','sha256'=>str_repeat('b',64),'modified_at'=>'2026-11-02T00:00:00Z']],'sections'=>['concepts'=>['source_id'=>'source','sheet'=>'fixture','rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)]]];
    }
    public function testImmutableImportReplayAndCorruptionDenial(): void
    {
        $repo=new ProductIntelligenceRepository();$one=$repo->import($this->packet());self::assertFalse(is_wp_error($one));
        self::assertSame($one['snapshot_id'],$repo->import($this->packet())['snapshot_id']);
        self::assertNull($repo->read($one['snapshot_id'])['sections']['concepts']['rows'][0]['Price USD']);
        update_option(ProductIntelligenceRepository::PREFIX.$one['snapshot_id'],'corrupt');
        self::assertWPError($repo->read($one['snapshot_id']));
    }
    public function testImportWorksWithRegisteredAuditWriter(): void
    {
        add_action('digiforge_log',[\DigiForge\Security\Logger::class,'write'],10,4);
        try {
            $saved=(new ProductIntelligenceRepository())->import($this->packet());
            self::assertFalse(is_wp_error($saved));
        } finally {remove_action('digiforge_log',[\DigiForge\Security\Logger::class,'write'],10);}
    }

    public function testAccessRequiresBothPortalAndPodCapabilities(): void
    {
        wp_set_current_user(0);self::assertFalse(ProductIntelligencePanel::canRead());
        $user=self::factory()->user->create(['role'=>'subscriber']);wp_set_current_user($user);
        $u=wp_get_current_user();$u->add_cap('manage_digiforge_pod');self::assertFalse(ProductIntelligencePanel::canRead());
        $u->add_cap('manage_digiforge');self::assertFalse(ProductIntelligencePanel::canRead());PrintifyResearchAccessFixture::assign($user,'personalized_pod',['manage_digiforge','manage_digiforge_pod']);self::assertTrue(ProductIntelligencePanel::canRead());self::assertFalse(ProductIntelligencePanel::canImport());
        $u->add_cap('manage_digiforge_research');self::assertFalse(ProductIntelligencePanel::canImport());PrintifyResearchAccessFixture::reset();PrintifyResearchAccessFixture::assign($user,'personalized_pod',['manage_digiforge','manage_digiforge_pod','manage_digiforge_research']);self::assertTrue(ProductIntelligencePanel::canImport());
    }
    public function testPanelMasksForeignShopAndEscapesResearchHtml(): void
    {
        $user=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($user);
        $u=wp_get_current_user();foreach(['manage_digiforge','manage_digiforge_pod'] as $cap)$u->add_cap($cap);
        PrintifyResearchAccessFixture::assign($user,'personalized_pod',['manage_digiforge','manage_digiforge_pod']);
        $saved=(new ProductIntelligenceRepository())->import($this->packet());$_GET=['df_shop'=>'digital','df_pi_snapshot'=>$saved['snapshot_id']];
        ob_start();(new ProductIntelligencePanel())->render('/portal/');$foreign=ob_get_clean();self::assertStringNotContainsString('DG2-001',$foreign);
        $_GET['df_shop']='personalized_pod';ob_start();(new ProductIntelligencePanel())->render('/portal/');$html=ob_get_clean();
        self::assertStringContainsString('DG2-001',$html);self::assertStringNotContainsString('<script>bad()',$html);self::assertStringContainsString('&lt;script&gt;',$html);
        if (($artifact=getenv('DIGIFORGE_PI_BROWSER_FIXTURE'))!==false) file_put_contents($artifact,'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents(dirname(__DIR__,2).'/assets/portal.css').'</style></head><body><main class="df-portal-content">'.$html.'</main></body></html>');
        $_GET=[];
    }
    public function testDatabaseReadFailureDoesNotBecomeEmptyQueue(): void
    {
        global $wpdb;$saved=(new ProductIntelligenceRepository())->import($this->packet());
        $filter=static fn(string $sql):string=>str_contains($sql,'SELECT option_value FROM') && str_contains($sql,ProductIntelligenceRepository::PREFIX)?'SELECT option_value FROM deliberately_missing_pi_table':$sql;
        $wpdb->suppress_errors(true);add_filter('query',$filter);
        try {self::assertWPError((new ProductIntelligenceRepository())->read($saved['snapshot_id']));}
        finally {remove_filter('query',$filter);$wpdb->suppress_errors(false);}
    }

    public function testMissingSnapshotAndInvalidIdentifiersFailClosed(): void
    {
        $repo=new ProductIntelligenceRepository();self::assertWPError($repo->read(str_repeat('0',64)));self::assertWPError($repo->read('../digital'));
        $p=$this->packet();$p['shop_key']='digital';self::assertWPError($repo->import($p));
    }
}
