<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class PodRepositoryEvidenceAvailabilityContractTest extends TestCase
{
    private function repository(): string
    {
        return (string)file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
    }

    public function testGenericEntityAndParentReadsFailClosed(): void
    {
        $s=$this->repository();
        self::assertStringContainsString('private function find(string $table,int $id):array|WP_Error|null',$s);
        self::assertStringContainsString('pod_entity_evidence_unavailable',$s);
        self::assertStringContainsString('private function exists(string $table,int $id):bool|WP_Error',$s);
        self::assertStringContainsString('pod_parent_evidence_unavailable',$s);
        self::assertGreaterThanOrEqual(2,substr_count($s,"\$wpdb->last_error=''"));
    }

    public function testParentCallersPropagateUnavailableEvidenceBeforeMissingSemantics(): void
    {
        $s=$this->repository();
        foreach(['$plan','$pvExists','$mapping','$spec','$schema','$mappingExists'] as $var) {
            self::assertStringContainsString('is_wp_error('.$var.')',$s,$var);
        }
        self::assertStringContainsString('is_wp_error($integrationExists)',$s);
    }

    public function testReadinessClearsStaleDatabaseErrorsAndPropagatesPlanReadFailure(): void
    {
        $s=$this->repository();
        $start=strpos($s,'public function readiness(int $mappingId)');
        $end=strpos($s,'public function list(',$start);
        $method=substr($s,$start,$end-$start);
        self::assertStringContainsString("\$count=function(string \$sql)use(\$wpdb):int|WP_Error{\$wpdb->last_error='';",$method);
        self::assertStringContainsString("if(is_wp_error(\$plan))return \$plan;",$method);
        self::assertStringContainsString("\$wpdb->last_error='';\$cost=\$wpdb->get_row",$method);
    }

    public function testIdempotencyAndPostWriteReadbacksCannotFlattenDatabaseFailures(): void
    {
        $s=$this->repository();
        self::assertGreaterThanOrEqual(2,substr_count($s,'pod_idempotency_evidence_unavailable'));
        self::assertStringContainsString('$created=$this->find($table,$id);if(is_wp_error($created))return $created;',$s);
        self::assertStringContainsString('$current=$this->find($table,$id);if(is_wp_error($current))return $current;',$s);
    }

    public function testListReadFailuresReachRestCallerAsErrors(): void
    {
        $s=$this->repository();
        self::assertStringContainsString('public function list(string $entity,int $page=1,int $perPage=20):array|WP_Error',$s);
        self::assertStringContainsString('pod_list_evidence_unavailable',$s);
        $controller=(string)file_get_contents(__DIR__.'/../../includes/REST/PodController.php');
        self::assertStringContainsString('if(is_wp_error($result))return $result;',$controller);
    }
}
