<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class PortalShopAiPolicyContractTest extends TestCase
{
    public function testPortalPolicyMutationUsesAuthoritativeRepositoryAndRemainsNonExecuting(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
        self::assertStringContainsString("SAVE_AI_POLICY_ACTION = 'digiforge_portal_save_ai_policy'",$s);
        self::assertStringContainsString("guard('manage_digiforge_ai')",$s);
        self::assertStringContainsString('check_admin_referer(self::SAVE_AI_POLICY_ACTION)',$s);
        self::assertStringContainsString('(new ShopAiGovernanceRepository())->savePolicy($policy, \'production\')',$s);
        self::assertStringContainsString('foreach (ShopAiPlan::STAGES as $stage)',$s);
        self::assertStringContainsString("sanitize_text_field(wp_unslash(\$_POST[\$key]))",$s);
        self::assertStringContainsString("(new ShopAiGovernanceRepository())->evaluate(\$shop,'production')",$s);
        self::assertStringContainsString("get_error_code()==='ai_policy_missing'",$s);
        self::assertStringContainsString('Editing is blocked to avoid replacing an unknown policy with defaults.',$s);
        self::assertStringContainsString("\$editor['budgets']['run']",$s);
        self::assertStringContainsString("\$editor['stages'][\$stage]",$s);
        self::assertStringContainsString("'external_execution_authorized' => false",$s);
        self::assertStringContainsString("'external_execution_performed' => false",$s);
        self::assertStringContainsString('No AI run, automation activation or external execution was authorized.',$s);
    }

    public function testPolicyEditorCannotWriteAllShopsOrChangeActivationControls(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
        self::assertStringContainsString('$shop === ShopOperationsReadModel::ALL',$s);
        self::assertStringContainsString('Choose one concrete shop before saving an AI policy.',$s);
        self::assertStringContainsString("savePolicy(\$policy, 'production')",$s);
        self::assertStringNotContainsString("SAVE_AI_POLICY_ACTION, [\$this, 'saveAiSecret']",$s);
        $start=strpos($s,'public function saveAiPolicy(): void');
        $end=strpos($s,'public function saveAiSecret(): void',$start);
        self::assertNotFalse($start); self::assertNotFalse($end);
        $handler=substr($s,$start,$end-$start);
        self::assertStringNotContainsString('ExecutionEngine',$handler);
        self::assertStringNotContainsString('storeSecret',$handler);
        self::assertStringNotContainsString('automation_armed',$handler);
        self::assertStringNotContainsString('activation_authorized',$handler);
    }
}
