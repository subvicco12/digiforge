<?php
declare(strict_types=1);
use DigiForge\Database\{CatalogAcceptanceSchema,ScopedPolicySchema,Tables};

/** Synthetic fixture only; no production SQL/records are embedded in these tests. */
final class ComponentSchemaMonotonicityTest extends WP_UnitTestCase
{
    private array $originalMarkers = [];
    protected function setUp(): void {
        parent::setUp();global $wpdb;
        foreach(['digiforge_db_schema_version','digiforge_db_version'] as $key)$this->originalMarkers[$key]=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
    }
    protected function tearDown(): void {
        try{parent::tearDown();}finally{
            // DDL commits are real: restore the fixture's original lineage, not hardcoded 25.
            wp_cache_flush();foreach($this->originalMarkers as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}wp_cache_flush();
        }
    }
    private function marker(): string {global $wpdb;return (string)$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='digiforge_db_schema_version'");}
    private function version(string $value): void {update_option('digiforge_db_schema_version',$value,false);update_option('digiforge_db_version','23',false);}
    private function temporary(string $table,callable $case): void {
        global $wpdb;$backup=$table.'_preimage';
        // MariaDB 10 information_schema does not expose session TEMPORARY tables.
        // Use real disposable DDL with explicit cleanup to test physical schema inspection.
        remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
        self::assertNotFalse($wpdb->query("RENAME TABLE `$table` TO `$backup`"));
        try{$case($table,$backup);}finally{$wpdb->query("DROP TABLE IF EXISTS `$table`");$wpdb->query("RENAME TABLE `$backup` TO `$table`");$this->version('25');$wpdb->query('START TRANSACTION');add_filter('query',[$this,'_create_temporary_tables']);add_filter('query',[$this,'_drop_temporary_tables']);}
    }
    public function testMissingComponentTablesCannotRewindGlobal25OrLegacy23(): void {
        foreach([[ScopedPolicySchema::class,Tables::scoped_capability_policies()],[CatalogAcceptanceSchema::class,Tables::catalog_acceptance_evidence()]] as [$class,$table]){
            $this->version('25');$this->temporary($table,function()use($class){self::assertTrue($class::migrateIfNeeded(),$class.' '.wp_json_encode(get_option('digiforge_last_migration_failure')));self::assertSame('25',$this->marker());self::assertSame('23',get_option('digiforge_db_version'));});
        }
    }
    public function testIncompleteColumnsDenyWithoutRepairOrMarkerAdvance(): void {
        global $wpdb;$this->version('25');$this->temporary(Tables::catalog_acceptance_evidence(),function($table,$backup)use($wpdb){
            $wpdb->query("CREATE TABLE `$table` LIKE `$backup`");$wpdb->query("ALTER TABLE `$table` DROP COLUMN fingerprint");self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('25',$this->marker());self::assertNull($wpdb->get_var("SHOW COLUMNS FROM `$table` LIKE 'fingerprint'"));
        });
    }
    public function testMissingOrNonuniqueRequiredIndexDenies(): void {
        global $wpdb;
        foreach([false,true] as $wrongIndex){$this->version('25');$this->temporary(Tables::catalog_acceptance_evidence(),function($table,$backup)use($wpdb,$wrongIndex){
            $wpdb->query("CREATE TABLE `$table` LIKE `$backup`");$wpdb->query("ALTER TABLE `$table` DROP INDEX catalog_version");if($wrongIndex)$wpdb->query("CREATE INDEX catalog_version ON `$table` (catalog_version_id)");self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('25',$this->marker());
        });}
    }
    public function testInterruptedPartialCreationCannotBeCertified(): void {
        global $wpdb;$this->version('22');$this->temporary(Tables::scoped_capability_policies(),function($table)use($wpdb){
            $wpdb->query("CREATE TABLE `$table` (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB");self::assertFalse(ScopedPolicySchema::migrateIfNeeded());self::assertSame('22',$this->marker());
        });
    }
    public function testRepeatReadinessDoesNotWriteOrRewind(): void {
        $this->version('25');$writes=[];$filter=static function($sql)use(&$writes){if(preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER)\b/i',$sql))$writes[]=$sql;return $sql;};add_filter('query',$filter);
        try{for($i=0;$i<3;$i++){self::assertTrue(ScopedPolicySchema::migrateIfNeeded());self::assertTrue(CatalogAcceptanceSchema::migrateIfNeeded());}self::assertSame([],$writes);self::assertSame('25',$this->marker());}finally{remove_filter('query',$filter);}
    }
    public function testMarkerWriteFailureCannotReportSuccess(): void {
        global $wpdb;$this->version('22');$wpdb->query('COMMIT');$errors=$wpdb->suppress_errors(true);
        $filter=static function($sql){if(preg_match('/^\s*(INSERT|UPDATE)\b/i',$sql)&&str_contains($sql,'digiforge_db_schema_version'))return 'SELECT * FROM deliberately_absent_marker_write_test';return $sql;};add_filter('query',$filter);
        try{self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('22',$this->marker());}finally{remove_filter('query',$filter);$wpdb->suppress_errors($errors);$this->version('25');$wpdb->query('START TRANSACTION');}
    }
    public function testReadyTableMarkerTransactionCanRollbackWithoutClaimingDdlRollback(): void {
        global $wpdb;$this->version('22');$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');
        try{self::assertTrue(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('24',$this->marker());$wpdb->query('ROLLBACK');wp_cache_flush();self::assertSame('22',$this->marker());self::assertTrue(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('24',$this->marker());}finally{$this->version('25');$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');}
    }
    public function testCorruptMarkerFailsClosed(): void {
        $this->version('not-a-version');try{self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('not-a-version',$this->marker());}finally{$this->version('25');}
    }
    public function testUnavailableMetadataFailsClosed(): void {
        global $wpdb;$this->version('25');$errors=$wpdb->suppress_errors(true);
        $filter=static function($sql){return str_contains($sql,'information_schema.TABLES')?'SELECT * FROM absent_schema_metadata_test':$sql;};add_filter('query',$filter);
        try{self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('25',$this->marker());}finally{remove_filter('query',$filter);$wpdb->suppress_errors($errors);}
    }
    public function testFailedCreationCannotAdvanceMarker(): void {
        global $wpdb;$this->version('22');$errors=$wpdb->suppress_errors(true);
        try{$this->temporary(Tables::catalog_acceptance_evidence(),function($table){
            $filter=static function($sql)use($table){return preg_match('/^\s*CREATE\b/i',$sql)&&str_contains($sql,$table)?'SELECT * FROM absent_creation_test':$sql;};add_filter('query',$filter);
            try{self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('22',$this->marker());}finally{remove_filter('query',$filter);}
        });}finally{$wpdb->suppress_errors($errors);}
    }
    public function testIncorrectColumnTypeAndNontransactionalEngineDeny(): void {
        global $wpdb;
        foreach(['ALTER TABLE `%s` MODIFY fingerprint varchar(32) NOT NULL','ALTER TABLE `%s` ENGINE=MyISAM'] as $alter){$this->version('25');$this->temporary(Tables::catalog_acceptance_evidence(),function($table,$backup)use($wpdb,$alter){
            $wpdb->query("CREATE TABLE `$table` LIKE `$backup`");self::assertNotFalse($wpdb->query(sprintf($alter,$table)));self::assertFalse(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('25',$this->marker());
        });}
    }
    public function testFutureMarkerIsPreserved(): void {
        $this->version('30');try{self::assertTrue(CatalogAcceptanceSchema::migrateIfNeeded());self::assertSame('30',$this->marker());self::assertSame('23',get_option('digiforge_db_version'));}finally{$this->version('25');}
    }

}
