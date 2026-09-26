<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyControlledRuntimeEndpointContractTest extends TestCase
{
    public function testEndpointComposesOnlyCertifiedDraftBoundaries(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
        foreach([
            "manage_digiforge_connections","Idempotency-Key","ConnectionTester",
            "EtsyVerifiedShopIdentity::resolve","EtsyDraftListingOperations::create",
            "ExecutionAuthorization::issue","ETSY_DRAFT_CREATE","createFromPayload",
            "EtsyOperationPreparationService","EtsyTokenMetadataBridge",
            "EtsyControlledDraftExecutionCoordinator","publish_permitted'=>false"
        ] as $needle) self::assertStringContainsString($needle,$s);
        foreach(['ETSY_PUBLISH','PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $needle) self::assertStringNotContainsString($needle,$s);
    }

    public function testVerifiedNumericShopIdentityIsRequiredAtPersistenceAndPreparation(): void
    {
        $identity=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyVerifiedShopIdentity.php');
        $repository=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');
        $preparation=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
        self::assertStringContainsString("'_connection_test'",$identity);
        self::assertStringContainsString("'shop_id'",$identity);
        self::assertStringContainsString("'shop_name'",$identity);
        self::assertStringContainsString('EtsyVerifiedShopIdentity::resolveAny',$repository);
        self::assertStringContainsString('EtsyVerifiedShopIdentity::resolveAny',$preparation);
    }

    public function testPluginRegistersEndpoint(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Plugin.php');
        self::assertStringContainsString('EtsyControlledExecutionController',$s);
    }
}
