<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryEntityEvidenceAvailabilityContractTest extends TestCase{
 public function testRelationshipAndTransitionReadsPropagateUnavailableEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Repository.php');
  self::assertStringContainsString('public function find(string $type, int $id): array|\\WP_Error|null',$s);
  self::assertStringContainsString('Product Factory entity evidence could not be read.',$s);
  self::assertStringContainsString('if (is_wp_error($parent)) { return $parent; }',$s);
  self::assertGreaterThanOrEqual(3,substr_count($s,'if (is_wp_error($entity)) { return $entity; }'));
  self::assertStringContainsString("return \$this->error('invalid_relationship', 'A valid parent is required.');",$s);
  self::assertStringContainsString("return \$this->error('not_found', 'Entity not found.', 404);",$s);
 }
}
