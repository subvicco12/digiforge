<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyLiveTransportInterlockContractTest extends TestCase
{
    public function testInterlockRequiresEveryProductionSafetyControl(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyLiveTransportInterlock.php');
        foreach(['ETSY_CONTROLLED_TRANSPORT_PREPARED','Settings::safety_locked()',"Settings::get('activation_authorized',false)!==true","Settings::get('automation_armed',false)!==true","Settings::get('stop_all',true)!==false","Settings::is_enabled('etsy_draft')!==true"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testAuthorizationDoesNotClaimAttemptOrSentTransition(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyLiveTransportInterlock.php');
        self::assertStringContainsString("'network_request_permitted'=>true",$s);
        self::assertStringContainsString("'network_request_attempted'=>false",$s);
        self::assertStringContainsString("'sent_transition_permitted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testInterlockContainsNoNetworkCredentialOrPersistencePrimitive(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyLiveTransportInterlock.php');
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','CredentialVault','$wpdb','Logger::','error_log('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
