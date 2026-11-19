<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\NativeProducerPublicationJournal;

/** Run only in a disposable local WordPress suite configured for the journal. */
final class NativeProducerSynchronizationTest extends WP_UnitTestCase
{
    public function testClaimAndIntentSurviveOnlyTheirOwningCommits(): void
    {
        if (wp_get_environment_type() !== 'local') {
            self::markTestSkipped('Requires an explicitly local disposable WordPress database.');
        }
        if (!defined('DIGIFORGE_DISPOSABLE_PROVENANCE')) {
            define('DIGIFORGE_DISPOSABLE_PROVENANCE', true);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'digiforge_fixture_provenance';
        remove_filter('query', [$this, '_create_temporary_tables']);
        try {
            self::assertNotFalse($wpdb->query("CREATE TABLE {$table} (
                mapping_id char(64) NOT NULL PRIMARY KEY,
                operation_id char(64) NOT NULL UNIQUE,
                phase varchar(24) NOT NULL,
                block_control longtext NOT NULL,
                block_proof longtext NULL,
                source_hash char(64) NULL,
                source_snapshot longtext NULL,
                prepare_control longtext NULL,
                prepare_proof longtext NULL
            ) ENGINE=InnoDB"));
        } finally {
            add_filter('query', [$this, '_create_temporary_tables']);
        }
        try {
            $wpdb->query('COMMIT');
            $journal = new NativeProducerPublicationJournal($wpdb);
            $mapping = hash('sha256', 'mapping');
            $operation = hash('sha256', 'operation');
            $snapshot = '{"synthetic":"confirmed"}';
            $hash = hash('sha256', $snapshot);

            self::assertTrue($journal->claim($mapping, $operation, 'signed-block'));
            $previous = $wpdb->suppress_errors(true);
            try {
                self::assertFalse($journal->claim($mapping, hash('sha256', 'competitor'), 'competing-block'));
            } finally {
                $wpdb->suppress_errors($previous);
            }
            self::assertTrue($journal->blocked($mapping, $operation, 'verified-block'));
            self::assertFalse($journal->recordConfirmedIntent($wpdb, $mapping, $operation, $hash, $snapshot));
            $wpdb->query('START TRANSACTION');
            self::assertTrue($journal->recordConfirmedIntent($wpdb, $mapping, $operation, $hash, $snapshot));
            self::assertNull($journal->confirmedSnapshot($mapping, $operation, $hash));
            $wpdb->query('ROLLBACK');
            self::assertNull($journal->confirmedSnapshot($mapping, $operation, $hash));
            self::assertTrue($journal->claimedOn($wpdb, $mapping, $operation));

            $wpdb->query('START TRANSACTION');
            self::assertTrue($journal->recordConfirmedIntent($wpdb, $mapping, $operation, $hash, $snapshot));
            $wpdb->query('COMMIT');
            self::assertSame($snapshot, $journal->confirmedSnapshot($mapping, $operation, $hash));
            self::assertTrue($journal->preparing($mapping, $operation, 'signed-prepare'));
            $reopened = new NativeProducerPublicationJournal($wpdb);
            self::assertFalse($reopened->preparing($mapping, $operation, 'replayed-prepare'));
            self::assertSame('PREPARE_UNKNOWN', $wpdb->get_var("SELECT phase FROM {$table}"));
            self::assertTrue($reopened->prepared($mapping, $operation, 'verified-prepare'));
            self::assertSame('PREPARED_BLOCKED', $wpdb->get_var("SELECT phase FROM {$table}"));
        } finally {
            $wpdb->query('ROLLBACK');
            remove_filter('query', [$this, '_drop_temporary_tables']);
            try {
                $wpdb->query("DROP TABLE {$table}");
            } finally {
                add_filter('query', [$this, '_drop_temporary_tables']);
            }
        }
    }
}
