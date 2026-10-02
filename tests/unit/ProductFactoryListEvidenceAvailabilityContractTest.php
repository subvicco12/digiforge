<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryListEvidenceAvailabilityContractTest extends TestCase{
 public function testRowsAndCountFailuresCannotBecomeTrustworthyEmptyPagination():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Repository.php');
  self::assertStringContainsString('public function all(string $type, int $page = 1, int $per_page = self::DEFAULT_PAGE_SIZE): array|\\WP_Error',$s);
  self::assertStringContainsString('Product Factory list evidence could not be read.',$s);
  self::assertStringContainsString('Product Factory list count evidence could not be read.',$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,"\$wpdb->last_error = '';"));
  self::assertStringContainsString('$total_raw === null',$s);
  self::assertStringContainsString("array_map([\$this, 'normalize'], \$rows)",$s);
 }
}
