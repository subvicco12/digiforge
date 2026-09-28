<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalEvidenceCountConsistencyTest extends TestCase
{
    public function testFocusedEtsyRowDoesNotChangeRecentWindowCount(): void
    {
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        $count = strpos($portal, '$etsyRecentCount=count($etsy);');
        $insertion = strpos($portal, 'array_unshift($etsy,$focusedEtsy)');
        self::assertNotFalse($count);
        self::assertNotFalse($insertion);
        self::assertLessThan($insertion, $count);
        self::assertStringContainsString('Recent Etsy reconciliation (up to 50)', $portal);
        self::assertStringContainsString("esc_html((string)$" . 'etsyRecentCount)', $portal);
    }

    public function testAlertHeaderUsesAuthoritativeTotalAndInvalidProviderIdHasNoLink(): void
    {
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertStringContainsString("$" . "attention['open_operational_alerts']", $portal);
        self::assertStringContainsString('open total', $portal);
        self::assertStringContainsString("$" . "id>0?'<a href=", $portal);
        self::assertStringContainsString('NO RECORD ID', $portal);
        self::assertStringNotContainsString("count(is_array($" . 'alerts) ? $alerts : [])) . \' open', $portal);
    }
}
