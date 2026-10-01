<?php
declare(strict_types=1);

final class MasterCatalogV2PersistenceTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testGovernedV2PersistsAtomicallyAndReplaysOnlyExactEvidence(): void
    {
        global $wpdb;
        $versions=DigiForge\Database\Tables::catalog_versions();
        $items=DigiForge\Database\Tables::catalog_items();
        $parentLabel='v1-parent-'.wp_rand(100000,999999);
        $parentFingerprint=hash('sha256',$parentLabel);
        $wpdb->insert($versions,[
            'catalog_key'=>DigiForge\POD\PersonalizedCatalogReference::CATALOG_KEY,
            'version_label'=>$parentLabel,
            'source_sha256'=>DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256,
            'source_state'=>'IMMUTABLE_REFERENCE','parent_version_id'=>0,'migration_metadata'=>wp_json_encode([]),
            'row_count'=>500,'fingerprint'=>$parentFingerprint,'production_authority'=>0,
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),
        ]);
        self::assertSame(1,$wpdb->rows_affected);
        $parentId=(int)$wpdb->insert_id;

        $rows=[];
        for($i=1;$i<=500;$i++){
            $id=sprintf('%03d',$i);
            $rows[]=['Listing ID'=>'DG2-'.$id,'Family'=>'Family','Concept'=>'Concept '.$id,'Engine'=>'FACE_REPEAT','Physical Product'=>'PP-001','Supplier Gate'=>'PRINTIFY_PRIMARY','Template State'=>'TEMPLATE_RESEARCH','Wave'=>'W1','US Route'=>'','EU Route'=>'','Priority'=>'','Notes'=>'Keep','V2 Evidence'=>['source_dg_id'=>'DG-'.$id]];
        }
        $normalized=['catalog_key'=>DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY,'fingerprint'=>hash('sha256','v2-'.$parentLabel),'rows'=>$rows];
        $migration=['source_state'=>'MIGRATION_CANDIDATE','parent_version_id'=>$parentId,'production_authority'=>false,'promotion_authorized'=>false];
        $repo=new DigiForge\POD\GovernedCatalogRepository();
        $label='v2-test-'.wp_rand(100000,999999);
        $saved=$repo->ingest($normalized,$label,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$parentId,$migration,'MIGRATION_CANDIDATE');
        self::assertFalse(is_wp_error($saved),is_wp_error($saved)?$saved->get_error_message():'');
        $versionId=(int)$saved['id'];
        self::assertSame(500,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$items.' WHERE catalog_version_id=%d',$versionId)));
        self::assertSame('MIGRATION_CANDIDATE',(string)$saved['source_state']);
        self::assertSame(0,(int)$saved['production_authority']);

        $replay=$repo->ingest($normalized,$label,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$parentId,$migration,'MIGRATION_CANDIDATE');
        self::assertFalse(is_wp_error($replay));
        self::assertTrue((bool)$replay['idempotent_replay']);

        $conflict=$repo->ingest($normalized,$label,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,$parentId+1,$migration,'MIGRATION_CANDIDATE');
        self::assertTrue(is_wp_error($conflict));
        self::assertSame('immutable_version_conflict',$conflict->get_error_code());
        self::assertSame(500,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$items.' WHERE catalog_version_id=%d',$versionId)));
    }
}
