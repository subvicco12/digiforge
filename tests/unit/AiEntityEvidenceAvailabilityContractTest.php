<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiEntityEvidenceAvailabilityContractTest extends TestCase{
 public function testEntityEvidenceFailuresPropagateAcrossMutationRelationshipsAndReadbacks():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString('public function find(string $table, int $id): array|\\WP_Error|null',$s);
  self::assertStringContainsString('AI entity evidence could not be read.',$s);
  self::assertStringContainsString('$promptParent = $this->find(Tables::ai_prompts(), $promptId);',$s);
  self::assertStringContainsString('if (is_wp_error($promptParent)) { return $promptParent; }',$s);
  self::assertStringContainsString('if (is_wp_error($task)) { return $task; }',$s);
  self::assertStringContainsString('if (is_wp_error($prompt)) { return $prompt; }',$s);
  self::assertStringContainsString('if (is_wp_error($model)) { return $model; }',$s);
  self::assertStringContainsString('if (is_wp_error($target)) { return $target; }',$s);
  self::assertStringContainsString('if (is_wp_error($updated)) { return $updated; }',$s);
  self::assertStringContainsString('if (is_wp_error($created)) { return $created; }',$s);
  self::assertGreaterThanOrEqual(4,substr_count($s,'if (is_wp_error($run)) { return $run; }'));
 }
}
