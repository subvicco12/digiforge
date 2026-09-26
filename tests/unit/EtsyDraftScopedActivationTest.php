<?php
declare(strict_types=1);

use DigiForge\Launch\EtsyDraftActivationPreflight;
use PHPUnit\Framework\TestCase;

final class EtsyDraftScopedActivationTest extends TestCase
{
    public function testPreflightIsReadOnlyAndRequiresStageThree(): void
    {
        $ready=EtsyDraftActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'product_development_effective'=>true,'etsy_draft_configured'=>true,
            'etsy_draft_authorized'=>false,'etsy_draft_effective'=>false,
        ]);
        self::assertSame('READY_FOR_CONTROLLED_ETSY_DRAFT_ACTIVATION',$ready['status']);
        self::assertFalse($ready['network_requests_performed']);
        self::assertFalse($ready['external_actions_performed']);

        $blocked=EtsyDraftActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'product_development_effective'=>false,'etsy_draft_configured'=>true,
            'etsy_draft_authorized'=>false,'etsy_draft_effective'=>false,
        ]);
        self::assertSame('BLOCKED',$blocked['status']);
        self::assertContains('product_development_effective',$blocked['blockers']);
    }

    public function testEtsyDraftAuthorizationRemainsSeparateFromPublishing(): void
    {
        $settings=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
        $config=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Config.php');
        self::assertStringContainsString('etsy_draft_activation_authorized',$config);
        self::assertStringContainsString('activateEtsyDraft',$settings);
        self::assertStringContainsString("(\$switch !== 'etsy_draft' || self::get('etsy_draft_activation_authorized', false) === true)",$settings);
        self::assertStringContainsString('etsy_publish_activation_authorized',$settings);
        self::assertStringContainsString('activateEtsyPublish',$settings);
    }
}
