<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyDraftMutationAuthorizationContractTest extends TestCase
{
    public function testPreparationMapsAllControlledDraftMutations(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
        foreach(['ETSY_DRAFT_CREATE','ETSY_DRAFT_UPDATE','ETSY_DRAFT_INVENTORY','ETSY_DRAFT_IMAGE','UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE'] as $needle) self::assertStringContainsString($needle,$s);
        foreach(['includes/POD/ExecutionAuthorization.php','includes/POD/ExecutionAuthorizationVerifier.php'] as $file) {
            $boundary=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
            foreach(['ETSY_DRAFT_CREATE','ETSY_DRAFT_UPDATE','ETSY_DRAFT_INVENTORY','ETSY_DRAFT_IMAGE'] as $action) self::assertStringContainsString($action,$boundary);
        }
    }

    public function testPipelineRequiresExactOperationBindingAndCanonicalTargets(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftOperationPipeline.php');
        self::assertStringContainsString('$operationType!==$draftType',$s);
        foreach(['CREATE_DRAFT','UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE','/inventory','/images'] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringContainsString('EtsyRequestFingerprint::fromPayload',$s);
        self::assertStringContainsString('hash_equals($preparedFingerprint,$draftFingerprint)',$s);
        self::assertStringContainsString('hash_equals($expectedEndpoint,$endpoint)',$s);
        self::assertStringContainsString("$operation['external_reference']",$s);
    }

    public function testMutationAuthorizationDoesNotAddExecutionPrimitive(): void
    {
        foreach(['includes/Listings/EtsyOperationPreparationService.php','includes/Listings/EtsyDraftOperationPipeline.php'] as $file) {
            $s=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
            foreach(['wp_remote_','curl_exec(','api.etsy','openapi.etsy','CredentialVault'] as $needle) self::assertStringNotContainsString($needle,$s);
        }
    }
}
