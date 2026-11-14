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
        $parent=PackageAcceptanceCatalogFixture::parent();$parentId=(int)$parent['id'];
        self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$versions.' WHERE id=%d',$parentId)));
        $candidate=PackageAcceptanceCatalogFixture::candidate();
        $repo=new DigiForge\POD\GovernedCatalogRepository();
        // Obtain exact mapped rows and migration metadata through the production contract.
        $prepared=(new DigiForge\POD\MasterCatalogV2IngestionService())->ingest($candidate,DigiForge\POD\MasterCatalogV2Reference::SOURCE_SHA256,'prepare-'.wp_generate_uuid4());
        self::assertFalse(is_wp_error($prepared),is_wp_error($prepared)?$prepared->get_error_message():'');
        $migration=json_decode($prepared['migration_metadata'],true);unset($migration['catalog_item_order']);
        $rows=[];
        for($offset=0;$offset<500;$offset+=100)foreach($repo->browse((int)$prepared['id'],[],100,$offset) as $item){
            $p=json_decode($item['attributes'],true);$rows[]=['Listing ID'=>$item['listing_id'],'Family'=>$p['family'],'Concept'=>$p['concept'],'Engine'=>$p['engine'],'Physical Product'=>$p['physical_product'],'Supplier Gate'=>$p['supplier_gate'],'Template State'=>$p['template_state'],'Wave'=>$p['wave'],'US Route'=>$p['us_route'],'EU Route'=>$p['eu_route'],'Priority'=>$p['priority'],'Notes'=>$p['notes'],'V2 Evidence'=>$p['lineage']];
        }
        self::assertSame(500,$wpdb->delete($items,['catalog_version_id'=>(int)$prepared['id']]));
        self::assertSame(1,$wpdb->delete($versions,['id'=>(int)$prepared['id']]));
        $normalized=['catalog_key'=>$candidate['catalog_key'],'fingerprint'=>$candidate['fingerprint'],'rows'=>$rows];
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

/** Complete synthetic local evidence; no original-workbook or supplier certification. */
if (!class_exists('PackageAcceptanceCatalogFixture')) {
 final class PackageAcceptanceCatalogFixture {
  public static function parent():array {
   // Catalog ingestion commits; explicit cleanup is required between WP test transactions.
   global $wpdb;foreach([DigiForge\Database\Tables::catalog_acceptance_evidence(),DigiForge\Database\Tables::catalog_items(),DigiForge\Database\Tables::catalog_versions()] as $table)if($wpdb->query('DELETE FROM '.$table)===false)throw new RuntimeException('Catalog fixture isolation failed.');
   $rows=[];for($i=1;$i<=500;$i++)$rows[]=[sprintf('DG-%03d',$i),'W1','Family','Concept '.$i,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH','','','','Offline fixture'];
   $normalized=DigiForge\POD\MasterCatalogImportContract::normalize(DigiForge\POD\MasterCatalogImportContract::REQUIRED,$rows);
   $saved=(new DigiForge\POD\GovernedCatalogRepository())->ingest($normalized,'acceptance-parent-'.wp_generate_uuid4(),DigiForge\POD\PersonalizedCatalogReference::SOURCE_SHA256);
   if(is_wp_error($saved))throw new RuntimeException($saved->get_error_message());return $saved;
  }
  public static function candidate():array {
   $masters=[];$migration=[];
   for($i=1;$i<=500;$i++){$n=sprintf('%03d',$i);$retained=$i<=428;$origin=$retained?'V1 retained':($i<=458?'V2 addition':'V2 new family');
    $masters[]=['DG2-'.$n,$retained?'DG-'.$n:'','Family','Concept '.$n,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH',$origin,$retained?'Re-score':($i<=458?'Deep research':'Provider + demand research')];
    $migration[]=['DG-'.$n,'W1','Family','Concept '.$n,'PHOTO_TEXT',$retained?'KEEP':($i<=465?'MERGE':'DOWNGRADE'),'Preserve source decision',$retained?'DG2-'.$n:'','No'];
   }
   return DigiForge\POD\MasterCatalogV2MigrationContract::normalize(DigiForge\POD\MasterCatalogV2MigrationContract::MASTER_REQUIRED,$masters,DigiForge\POD\MasterCatalogV2MigrationContract::MIGRATION_REQUIRED,$migration);
  }
 }
}
