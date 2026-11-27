<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\NativePortContract;

/** A disposable migration profile. Its tables cannot be read by the operational journal. */
final class VersionedJournalRecoveryWordPressTest extends WP_UnitTestCase
{
    public function testInterruptedDDLAndRestoredSchemaDenyCheckpoint(): void
    {
        // Temporary tables disappear on reconnect and cannot model durable DDL recovery.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        $suffix = bin2hex(random_bytes(5));
        $journal = 'df_fixture_journal_'.$suffix;
        $backup = 'df_fixture_backup_'.$suffix;
        $version = 'df_fixture_version_'.$suffix;
        $event = ['operation_id'=>str_repeat('a', 64), 'revision'=>3, 'state'=>'UNKNOWN'];
        $digest = NativePortContract::hash($event);
        // Retained outside MariaDB to model an independently surviving recovery floor.
        $anchor = ['schema'=>2, 'digest'=>$digest];

        $connection = static fn(): wpdb => new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db = $connection();
        $inspect = static function (wpdb $reader) use ($journal, $version, $anchor): array {
            $result = ['state'=>'DENIED', 'retry_permitted'=>false, 'provider_execution_authorized'=>false];
            if ((int)$reader->get_var("SELECT revision FROM $version") !== $anchor['schema']) {
                return $result;
            }
            $record = $reader->get_row("SELECT payload, checkpoint_digest FROM $journal", ARRAY_A);
            if (!is_array($record) || $record['checkpoint_digest'] !== $anchor['digest']) {
                return $result;
            }
            $event = json_decode($record['payload'], true);
            if (!is_array($event) || NativePortContract::hash($event) !== $anchor['digest']) {
                return $result;
            }
            return ['state'=>'UNKNOWN'] + $result;
        };

        try {
            self::assertNotFalse($db->query("CREATE TABLE $version (revision INT NOT NULL) ENGINE=InnoDB"));
            self::assertSame(1, $db->insert($version, ['revision'=>1]));
            self::assertNotFalse($db->query("CREATE TABLE $journal (payload TEXT NOT NULL) ENGINE=InnoDB"));
            self::assertSame(1, $db->insert($journal, ['payload'=>wp_json_encode($event)]));
            self::assertNotFalse($db->query("CREATE TABLE $backup LIKE $journal"));
            self::assertSame(1, $db->query("INSERT INTO $backup SELECT * FROM $journal"));
            self::assertSame('DENIED', $inspect($db)['state']);

            // ALTER commits implicitly in MariaDB. Interrupt before publishing the version marker.
            self::assertNotFalse($db->query("ALTER TABLE $journal ADD COLUMN checkpoint_digest CHAR(64) NULL"));
            $db->close();
            $db = $connection();
            self::assertSame('DENIED', $inspect($db)['state']);

            // Retry from durable layout. Materialization and marker publish in one transaction.
            self::assertNotNull($db->get_row("SHOW COLUMNS FROM $journal LIKE 'checkpoint_digest'"));
            self::assertNotFalse($db->query('START TRANSACTION'));
            self::assertSame(1, $db->update($journal, ['checkpoint_digest'=>$digest], ['payload'=>wp_json_encode($event)]));
            self::assertSame(1, $db->update($version, ['revision'=>2], ['revision'=>1]));
            self::assertNotFalse($db->query('ROLLBACK'));
            self::assertSame('DENIED', $inspect($db)['state']);
            self::assertNotFalse($db->query('START TRANSACTION'));
            self::assertSame(1, $db->update($journal, ['checkpoint_digest'=>$digest], ['payload'=>wp_json_encode($event)]));
            self::assertSame(1, $db->update($version, ['revision'=>2], ['revision'=>1]));
            self::assertNotFalse($db->query('COMMIT'));
            $db->close();
            $db = $connection();
            $unknown = $inspect($db);
            self::assertSame('UNKNOWN', $unknown['state']);
            self::assertFalse($unknown['retry_permitted']);
            self::assertFalse($unknown['provider_execution_authorized']);
            self::assertSame(0, $db->update($version, ['revision'=>2], ['revision'=>1]));
            self::assertSame($unknown, $inspect($db));
            self::assertSame(1, $db->query("UPDATE $journal SET checkpoint_digest=NULL"));
            self::assertSame('DENIED', $inspect($db)['state']);

            // Restore the earlier schema and row bytes while retaining the anchor.
            self::assertNotFalse($db->query("ALTER TABLE $journal DROP COLUMN checkpoint_digest"));
            self::assertSame(1, $db->query("DELETE FROM $journal"));
            self::assertSame(1, $db->query("INSERT INTO $journal SELECT * FROM $backup"));
            self::assertSame(1, $db->update($version, ['revision'=>1], ['revision'=>2]));
            self::assertSame(wp_json_encode($event), $db->get_var("SELECT payload FROM $journal"));
            self::assertSame('DENIED', $inspect($db)['state']);
        } finally {
            $db->query("DROP TABLE IF EXISTS $journal, $backup, $version");
            $db->close();
        }
    }
}
