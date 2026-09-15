<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F2ListingGateHardeningStructureTest extends TestCase
{
    public function testPodReadinessIsNotVacuouslyTrue(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
        self::assertIsString($repo);
        self::assertStringNotContainsString("'pod_binding_valid' => $podRequired >= 0",$repo);
        self::assertStringContainsString("$productMode=$digital&&$pod?'hybrid':($pod?'pod':'digital')",$repo);
        self::assertStringContainsString("hash_equals((string)($binding['readiness_hash']??''),hash('sha256',Validator::canonicalJson($current)))",$repo);
    }

    public function testListingMediaRequiresApprovedSameProductProvenance(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
        self::assertIsString($repo);
        self::assertStringContainsString("Asset revision must exist and be approved.",$repo);
        self::assertStringContainsString("Asset revision must belong to the listing product version.",$repo);
        self::assertStringContainsString("(string)($revision['state']??'')==='APPROVED'",$repo);
    }

    public function testGate3PackageAndIntentRemainHumanControlledAndBlocked(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
        self::assertIsString($repo);
        self::assertStringContainsString("Authenticated human reviewer required to create a Gate 3 draft package.",$repo);
        self::assertStringContainsString("'state'=>'BLOCKED'",$repo);
        self::assertStringContainsString("Draft package must belong to the listing.",$repo);
    }

    public function testListingWritesRejectChangedIdempotentPayloads(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
        self::assertIsString($repo);
        self::assertStringContainsString('idempotency_payload_conflict',$repo);
        self::assertStringContainsString('replayCompatible',$repo);
        self::assertStringContainsString("['idempotent_replay'=>true]",$repo);
    }
}
