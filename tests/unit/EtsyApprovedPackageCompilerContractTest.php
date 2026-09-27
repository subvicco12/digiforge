<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyApprovedPackageCompilerContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyApprovedPackageCompiler.php'); }
    public function testCompilerRequiresImmutableApprovedPackageAndExplicitClassification(): void
    {
        $s=$this->source();
        foreach(["canonical_payload","payload_hash","hash('sha256',","hash_equals","json_decode","approvedListing","readiness_hash","taxonomy_evidence","verified","taxonomy_id","who_made","when_made","quantity","i_did","someone_else","collective","made_to_order","'is_supply'=>false","'type'=>'download'","compiled_from_approved_package"] as $n) self::assertStringContainsString($n,$s);
        self::assertStringNotContainsString("max(1,(int)(\$classification['quantity']??1))",$s);
        self::assertStringContainsString("!array_key_exists('quantity',\$classification)",$s);
    }
    public function testCompilerUsesCanonicalListingForExternalContent(): void
    {
        $s=$this->source();
        foreach(["\$approvedListing['title']","\$approvedListing['description']","\$approvedListing['price_amount']"] as $n) self::assertStringContainsString($n,$s);
        self::assertStringNotContainsString("\$listing['title']",$s);
        self::assertStringNotContainsString("\$listing['description']",$s);
        self::assertStringNotContainsString("\$listing['price_amount']",$s);
    }
    public function testCompilerDoesNotGuessClassificationFromSeoOrTaxonomyMetadata(): void
    {
        $s=$this->source();
        foreach(["taxonomy_metadata","keywords","tags","materials","audience_metadata"] as $n) self::assertStringNotContainsString($n,$s);
    }
    public function testCompilerRequiresGovernedAiComplianceAmendment(): void
    {
        $s=$this->source();
        foreach(["seller_attestation","ai_assisted","ai_disclosure_approved","AI_DISCLOSURE","compliance_evidence","compliance_hash","approved_description_unchanged"] as $n) self::assertStringContainsString($n,$s);
        self::assertStringContainsString("rtrim(\$description)",$s);
        self::assertStringContainsString("seller’s creative direction, prompts, inputs, editing, and approval",$s);
    }
    public function testDraftOperationStripsInternalMetadataBeforeExternalPayload(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftListingOperations.php');
        self::assertStringContainsString("unset(\$external['_digiforge'])",$s);
        self::assertStringContainsString("self::sanitize(\$external)",$s);
    }
}
