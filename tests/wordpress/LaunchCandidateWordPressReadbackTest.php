<?php
declare(strict_types=1);

final class LaunchCandidateWordPressReadbackTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testExistingApprovedCandidateIsReadFromWordPressQueryResult(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::research_candidates();
        $id = 987654321;
        $wpdb->insert($table, [
            'title' => 'Candidate readback regression',
            'summary' => 'Readback verification',
            'review_status' => \DigiForge\Research\Repository::REVIEW_APPROVED,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
        $insertId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $insertId, 'Fixture must be persisted in the real WordPress database.');
        $method = new ReflectionMethod(\DigiForge\Launch\ExecutionEngine::class, 'candidate');
        $method->setAccessible(true);
        $result = $method->invoke(new \DigiForge\Launch\ExecutionEngine(), $insertId);
        self::assertIsArray($result);
        self::assertSame($insertId, (int) $result['id']);
        self::assertSame('Candidate readback regression', $result['title']);
    }

    public function testMissingCandidateReturnsNull(): void
    {
        $method = new ReflectionMethod(\DigiForge\Launch\ExecutionEngine::class, 'candidate');
        $method->setAccessible(true);
        self::assertNull($method->invoke(new \DigiForge\Launch\ExecutionEngine(), 2147483647));
    }
}
