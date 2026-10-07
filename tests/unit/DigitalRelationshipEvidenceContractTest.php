<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DigitalRelationshipEvidenceContractTest extends TestCase
{
    public function testRelationshipAuthorityReadsPropagateUnavailableEvidence(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Repository.php');
        $start=strpos($source,'private function validate_relationships');
        $end=strpos($source,'private function validate_descendants',$start);
        self::assertNotFalse($start); self::assertNotFalse($end);
        $method=substr($source,$start,$end-$start);

        self::assertStringNotContainsString('fn(int $id): ?array',$method);
        self::assertGreaterThanOrEqual(6,substr_count($method,'is_wp_error('));
        self::assertStringContainsString("\$products->find('product'",$method);
        self::assertStringContainsString("\$products->find('product_version'",$method);
    }
}
