<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityCoverageTest extends TestCase
{
    public function testCandidateCoverageDetectsWindowPressureWithoutClaimingExactOpenCount(): void
    {
        require_once __DIR__.'/../../includes/Database/Tables.php';
        require_once __DIR__.'/../../includes/POD/ProductionProvenanceIntegrityCoverageReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $db=new class {
            public string $prefix='wp_';
            public array $queries=[];
            public bool $fail=false;
            public function get_var(string $sql): ?int {
                $this->queries[]=$sql;
                if($this->fail&&str_contains($sql,'pod_execution_nonces'))return null;
                if(str_contains($sql,'pod_provenance_integrity_evidence'))return 205;
                if(str_contains($sql,'b LEFT JOIN')&&str_contains($sql,'pod_authorization_packages'))return 201;
                return 0;
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $model=new \DigiForge\POD\ProductionProvenanceIntegrityCoverageReadModel();
            $coverage=$model->inspect(200,0);
            self::assertSame(201,$coverage['candidate_rows']);
            self::assertSame(205,$coverage['recorded_evidence_rows']);
            self::assertSame('POSSIBLY_TRUNCATED',$coverage['coverage_state']);
            self::assertTrue($coverage['window_may_omit_candidates']);
            self::assertFalse($coverage['exhaustive_open_count']);
            self::assertFalse($coverage['retry_permitted']);
            self::assertFalse($coverage['external_execution_authorized']);
            self::assertCount(5,$db->queries);
            foreach($db->queries as $query)self::assertStringNotContainsString('LIMIT',$query);
            $db->fail=true;
            $unknown=$model->inspect(200,0);
            self::assertSame('UNKNOWN',$unknown['coverage_state']);
            self::assertNull($unknown['candidate_rows']);
            self::assertNull($unknown['window_may_omit_candidates']);
        } finally { $GLOBALS['wpdb']=$previous; }
    }
}
