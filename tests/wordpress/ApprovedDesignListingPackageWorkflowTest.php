<?php

declare(strict_types=1);

use DigiForge\POD\ApprovedDesignListingPackage;
use DigiForge\Tests\ApprovedDesignListingPackageTest;

require_once __DIR__ . '/../unit/ApprovedDesignListingPackageTest.php';

final class ApprovedDesignListingPackageWorkflowTest extends \WP_UnitTestCase
{
    private function fixtures(): ApprovedDesignListingPackageTest
    {
        return new ApprovedDesignListingPackageTest('testTechnicalFixturesProduceHonestCompleteReviewArtifacts');
    }
    private function saved(bool $approve): array
    {
        $user=self::factory()->user->create();
        $actor=new \WP_User($user);
        $actor->add_cap('manage_digiforge_pod'); $actor->add_cap('manage_digiforge_research');
        wp_set_current_user($user);
        $input=$this->fixtures()->design()['record']['input'];
        foreach ($input['briefs'] as $i=>&$brief) $brief['visual_direction']='Original direction '.$i;
        unset($brief);
        $input['artwork']['svg']='<svg xmlns="http://www.w3.org/2000/svg" width="2418" height="3018"><rect width="2418" height="3018" fill="#fff"/></svg>';
        $repo=new \DigiForge\Research\OpportunityDesignRepository();
        $saved=$repo->save($input); self::assertIsArray($saved);
        if ($approve) $saved=$repo->decide('personalized_pod',$saved['version_hash'],'APPROVE');
        self::assertIsArray($saved); return $saved;
    }
    public function testActualApprovedVersionProducesPackageWithNoHttpOrProviderSideEffects(): void
    {
        $saved=$this->saved(true); $calls=0;
        $deny=static function () use (&$calls) { ++$calls; return new \WP_Error('no_http'); };
        add_filter('pre_http_request',$deny);
        try {
            $package=ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$saved['version_hash'],$this->fixtures()->evidence());
            self::assertIsArray($package);
            self::assertSame($saved,$package['source_approval']);
            self::assertSame(0,$calls);
            self::assertFalse($package['external_execution_authorized']);
            self::assertSame('MANUAL',$package['controls']['order_approval']);
        } finally { remove_filter('pre_http_request',$deny); }
    }
    public function testCurrentAuthenticationAndCapabilityCannotBeSpoofedBySavedApproval(): void
    {
        $saved=$this->saved(true); $e=$this->fixtures()->evidence();
        wp_get_current_user()->remove_cap('manage_digiforge_pod');
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$saved['version_hash'],$e));
        wp_set_current_user(0);
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$saved['version_hash'],$e));
    }
    public function testPendingApprovalAndForeignShopAreDenied(): void
    {
        $saved=$this->saved(false); $e=$this->fixtures()->evidence();
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('personalized_pod',$saved['version_hash'],$e));
        self::assertWPError(ApprovedDesignListingPackage::fromApprovedVersion('digital',$saved['version_hash'],$e));
    }
}
