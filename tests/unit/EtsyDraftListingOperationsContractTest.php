<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyDraftListingOperationsContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftListingOperations.php'); }

    public function testDraftSurfaceCoversCreateUpdateInventoryAndExistingImageAttachment(): void
    {
        $s=$this->source();
        foreach(['CREATE_DRAFT','UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE','/application/shops/','/inventory',"'listing_image_id'"] as $needle) self::assertStringContainsString($needle,$s);
    }

    public function testPublishAndCredentialsAreFailClosed(): void
    {
        $s=$this->source();
        foreach(["'is_published'","'access_token'","'refresh_token'","'authorization'","'publish_permitted'=>false","'network_request_permitted'=>false"] as $needle) self::assertStringContainsString($needle,$s);
    }

    public function testPlannerContainsNoNetworkOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','CredentialVault','EtsyScopedCredentialRetriever','EtsyOperationRepository','->transition(','api.etsy','openapi.etsy'] as $needle) self::assertStringNotContainsString($needle,$s);
    }
}
