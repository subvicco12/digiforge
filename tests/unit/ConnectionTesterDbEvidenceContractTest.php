<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ConnectionTesterDbEvidenceContractTest extends TestCase {
 public function testCredentialAndConnectionEvidenceReadsFailClosed():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/ConnectionTester.php');foreach(['credential_evidence_unavailable',"\$wpdb->last_error=''",'empty($wpdb->last_error)'] as $n)self::assertStringContainsString($n,$s);}
}