<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/PortalAccessFixture.php';

final class ApprovalAttentionNavigationTest extends WP_UnitTestCase
{
    public function testAuthorizedApprovalViewRendersTargetsForAttentionLinks(): void
    {
        DigiForge\Core\Activator::activate();
        PortalAccessFixture::reset();
        PortalAccessFixture::enroll();
        $previous=$_GET['df_view']??null;
        $previousShop=$_GET['df_shop']??null;
        $_GET['df_shop']='personalized_pod';
        $_GET['df_view']='approvals';
        try {
            $html=(new DigiForge\Portal\U3ApprovalInbox())->appendPanel(
                '<main class="df-portal-shell"></main>','digiforge_admin_portal',[],[]
            );
            foreach (['df-approval-personalization','df-approval-fulfillment',
                'df-approval-listing','df-approval-pod','df-approval-product'] as $anchor) {
                self::assertStringContainsString('id="'.$anchor.'"',$html);
            }
            self::assertStringContainsString('NO INFERRED APPROVAL',$html);
            self::assertStringContainsString('External execution authority',$html);
            $portal=new DigiForge\Portal\Portal();
            $url=(new ReflectionMethod($portal,'url'))->invoke($portal,'approvals');
            self::assertStringContainsString('df_view=approvals',$url);
            self::assertSame(wp_parse_url(home_url('/'),PHP_URL_HOST),wp_parse_url($url,PHP_URL_HOST));
        } finally {
            if ($previousShop===null) unset($_GET['df_shop']); else $_GET['df_shop']=$previousShop;
            if ($previous===null) unset($_GET['df_view']);
            else $_GET['df_view']=$previous;
        }
    }

    public function testEveryDownstreamReviewLinkTargetsAViewContainingItsEvidenceTable(): void
    {
        $inbox=new DigiForge\Portal\U3ApprovalInbox();
        $portal=new DigiForge\Portal\Portal();
        $link=new ReflectionMethod($inbox,'evidenceUrl');
        $tables=new ReflectionMethod($portal,'tables');
        $cases=[
            ['Listing / publish decisions (Gate 3)','listings','listing_review',DigiForge\Database\Tables::listing_readiness_reviews()],
            ['Personalization exceptions','orders','personalization',DigiForge\Database\Tables::personalization_submissions()],
            ['POD readiness exceptions','pod_personalized','pod_review',DigiForge\Database\Tables::pod_readiness_reviews()],
            ['Fulfillment exceptions','orders','fulfillment_review',DigiForge\Database\Tables::fulfillment_readiness_reviews()],
        ];
        foreach ($cases as [$group,$view,$type,$table]) {
            $url=$link->invoke($inbox,$group,97);
            $args=[];
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$args);
            self::assertSame($view,$args['df_view']);
            self::assertSame($type,$args['df_focus_type']);
            self::assertSame('97',$args['df_focus_id']);
            self::assertStringEndsWith('#df-evidence-'.$type.'-97',$url);
            self::assertContains($table,array_values($tables->invoke($portal,$view)));
            self::assertSame(wp_parse_url(home_url('/'),PHP_URL_HOST),wp_parse_url($url,PHP_URL_HOST));
        }
    }
}
