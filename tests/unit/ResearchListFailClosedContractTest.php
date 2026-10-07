<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResearchListFailClosedContractTest extends TestCase
{
    public function testResearchListChecksRowsAndCountEvidence(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Research/Repository.php');
        $start=strpos($source,'public function list(');
        $end=strpos($source,'private function insertIdempotent',$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method=substr($source,$start,$end-$start);

        self::assertStringContainsString('array|\\WP_Error',$method);
        self::assertGreaterThanOrEqual(2,substr_count($method,"$"."wpdb->last_error=''"));
        self::assertStringContainsString('!is_array($rows)||!empty($wpdb->last_error)',$method);
        self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($rawTotal)',$method);
        self::assertStringContainsString("'research_list_evidence_unavailable'",$method);
        self::assertStringContainsString('503',$method);
        self::assertStringNotContainsString('is_array($rows)?$rows:[]',$method);
    }
}
