<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyApprovedPackageCompilerContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyApprovedPackageCompiler.php'); }
    public function testCompilerRequiresApprovalReadinessAndExplicitEtsyClassification(): void
    {
        $s=$this->source();
        foreach(["approved_by","approved_at","readiness_hash","taxonomy_id","who_made","when_made","i_did","someone_else","collective","made_to_order","2020_2026","'is_supply'=>false","compiled_from_approved_package"] as $n) self::assertStringContainsString($n,$s);
    }
    public function testCompilerDoesNotGuessClassificationFromSeoOrTaxonomyMetadata(): void
    {
        $s=$this->source();
        foreach(["taxonomy_metadata","keywords","tags","materials","audience_metadata"] as $n) self::assertStringNotContainsString($n,$s);
    }
}
