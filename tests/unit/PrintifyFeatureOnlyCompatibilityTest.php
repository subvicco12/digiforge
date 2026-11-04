<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;

final class PrintifyFeatureOnlyCompatibilityTest extends TestCase
{
    public function testPresentButUnmappedGlobalGuardsRemainAnInstallationGate(): void
    {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../fixtures/printify-feature-missing-owner.php').' unmapped-guards';
        exec($command,$lines,$status);self::assertSame(0,$status);
        $report=json_decode(implode("\n",$lines),true,32,JSON_THROW_ON_ERROR);
        self::assertSame([],$report['missing']);self::assertFalse($report['portal_controls_ready']);self::assertFalse($report['rest_admission_mapped']);
        self::assertSame('pod_feature_compatibility_required',$report['error']);self::assertSame(0,$report['hooks']);self::assertSame(0,$report['http_calls']);
    }
    public function testMissingInstalledOwnerHelperDeniesBeforeRegistrationOrHttp(): void
    {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../fixtures/printify-feature-missing-owner.php');
        exec($command,$lines,$status);self::assertSame(0,$status);
        $report=json_decode(implode("\n",$lines),true,32,JSON_THROW_ON_ERROR);
        self::assertSame(['FirstPartyOwnerPolicy'],$report['missing']);
        self::assertSame('pod_feature_compatibility_required',$report['error']);self::assertSame(0,$report['hooks']);self::assertSame(0,$report['http_calls']);
    }
}
