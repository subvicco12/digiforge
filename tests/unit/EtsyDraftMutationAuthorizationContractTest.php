<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyDraftMutationAuthorizationContractTest extends TestCase
{
    public function testPreparationMapsAllControlledDraftMutations(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
        foreach(['ETSY_DRAFT_CREATE','ETSY_DRAFT_UPDATE','ETSY_DRAFT_INVENTORY','ETSY_DRAFT_IMAGE','ETSY_DRAFT_FILE','UPLOAD_FILE','UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE'] as $needle) self::assertStringContainsString($needle,$s);
        foreach(['includes/POD/ExecutionAuthorization.php','includes/POD/ExecutionAuthorizationVerifier.php'] as $file) {
            $boundary=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
            foreach(['ETSY_DRAFT_CREATE','ETSY_DRAFT_UPDATE','ETSY_DRAFT_INVENTORY','ETSY_DRAFT_IMAGE','ETSY_DRAFT_FILE'] as $action) self::assertStringContainsString($action,$boundary);
        }
    }

    public function testDigitalFileAuthorizationRemainsDraftOnlyAndPayloadBound(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
        $start=strpos($controller,'ETSY_DRAFT_FILE');
        self::assertNotFalse($start);
        $segment=substr($controller,$start,2200);
        self::assertStringContainsString('time(),$payload)',$segment);
        self::assertStringNotContainsString('time(),$externalPayload)',$segment);
        foreach(['includes/POD/ExecutionAuthorization.php','includes/POD/ExecutionAuthorizationVerifier.php'] as $file) {
            $s=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
            self::assertStringContainsString('ETSY_DRAFT_FILE',$s);
            self::assertStringNotContainsString('ETSY_PUBLISH',$s);
        }
    }

    public function testPipelineRequiresExactOperationBindingAndCanonicalTargets(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftOperationPipeline.php');
        self::assertStringContainsString('$operationType!==$draftType',$s);
        foreach(['CREATE_DRAFT','UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE','UPLOAD_FILE','/inventory','/images','/files'] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringContainsString('EtsyRequestFingerprint::fromPayload',$s);
        self::assertStringContainsString('hash_equals($preparedFingerprint,$draftFingerprint)',$s);
        self::assertStringContainsString('hash_equals($expectedEndpoint,$endpoint)',$s);
        self::assertStringContainsString('$operation[\'external_reference\']',$s);
        self::assertStringContainsString('$operation[\'resource_reference\']',$s);
        self::assertStringContainsString("\$operationType==='UPLOAD_FILE'?\$resourceReference:\$externalReference",$s);
        self::assertStringContainsString("\$operationType==='UPLOAD_FILE'",$s);
    }

    public function testMutationAuthorizationDoesNotAddExecutionPrimitive(): void
    {
        foreach(['includes/Listings/EtsyOperationPreparationService.php','includes/Listings/EtsyDraftOperationPipeline.php'] as $file) {
            $s=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
            foreach(['wp_remote_','curl_exec(','api.etsy','openapi.etsy','CredentialVault'] as $needle) self::assertStringNotContainsString($needle,$s);
        }
    }
}
