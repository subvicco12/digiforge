<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MasterCatalogStoredEvidenceFixture.php';

final class MasterCatalogV2PersistenceTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        MasterCatalogStoredEvidenceFixture::resetCatalogEvidence();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testGovernedV2PersistsAtomicallyAndReplaysOnlyExactEvidence(): void
    {
        global $wpdb;
        $versions=DigiForge\Database\Tables::catalog_versions();
        $items=DigiForge\Database\Tables::catalog_items();
        $parent=MasterCatalogStoredEvidenceFixture::persistParent();self::assertNotWPError($parent);$parentId=(int)$parent['id'];
        $normalized=MasterCatalogStoredEvidenceFixture::originalCandidate();
        $repo=new DigiForge\POD\MasterCatalogV2IngestionService();
        $label='v2-test-'.wp_rand(100000,999999);
        $saved=$repo->ingest($normalized,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$label);
        self::assertFalse(is_wp_error($saved),is_wp_error($saved)?$saved->get_error_message():'');
        $versionId=(int)$saved['id'];
        self::assertSame(500,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$items.' WHERE catalog_version_id=%d',$versionId)));
        self::assertSame('MIGRATION_CANDIDATE',(string)$saved['source_state']);
        self::assertSame(0,(int)$saved['production_authority']);

        $replay=$repo->ingest($normalized,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$label);
        self::assertFalse(is_wp_error($replay));
        self::assertTrue((bool)$replay['idempotent_replay']);

        $changed=$normalized;$changed['rows'][0]['Concept']='Different governed concept';$changed=DigiForge\POD\MasterCatalogV2MigrationContract::normalize(DigiForge\POD\MasterCatalogV2MigrationContract::MASTER_REQUIRED,array_map('array_values',$changed['rows']),DigiForge\POD\MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,array_map('array_values',$changed['migration']));
        $conflict=$repo->ingest($changed,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$label);
        self::assertTrue(is_wp_error($conflict));
        self::assertSame('immutable_version_conflict',$conflict->get_error_code());
        self::assertSame(500,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$items.' WHERE catalog_version_id=%d',$versionId)));
    }
}
