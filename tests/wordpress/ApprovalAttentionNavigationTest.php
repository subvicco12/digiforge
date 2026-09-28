<?php
declare(strict_types=1);

final class ApprovalAttentionNavigationTest extends WP_UnitTestCase
{
    public function testAuthorizedApprovalViewRendersTargetsForAttentionLinks(): void
    {
        DigiForge\Core\Activator::activate();
        $userId=self::factory()->user->create(['role'=>'administrator']);
        $user=get_user_by('id',$userId);
        $user->add_cap('manage_digiforge_products');
        $user->add_cap('manage_digiforge_research');
        wp_set_current_user($userId);
        $previous=$_GET['df_view']??null;
        $_GET['df_view']='approvals';
        try {
            $html=(new DigiForge\Portal\U3ApprovalInbox())->appendPanel(
                '<main></main>','digiforge_admin_portal',[],[]
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
            if ($previous===null) unset($_GET['df_view']);
            else $_GET['df_view']=$previous;
        }
    }
}
