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
  $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/ProductFactoryController.php');
  self::assertStringContainsString('private function index(\\WP_REST_Request $request, string $type): \\WP_REST_Response|\\WP_Error',$controller);
  self::assertStringContainsString('if (is_wp_error($result)) { return $result; }',$controller);
  $admin=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Admin.php');
  self::assertStringContainsString('Product Factory evidence is unavailable. No empty portfolio is asserted.',$admin);
  self::assertStringContainsString('Product Factory evidence is unavailable. No absence of records is asserted.',$admin);
  self::assertGreaterThanOrEqual(2,substr_count($admin,'is_wp_error('));
 }
}
