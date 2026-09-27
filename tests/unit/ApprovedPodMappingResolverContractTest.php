<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ApprovedPodMappingResolverContractTest extends TestCase {
 public function testResolverRequiresUniqueHumanApprovedMapping():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/ApprovedPodMappingResolver.php');foreach(["state='APPROVED'",'approved_by>0','approved_at IS NOT NULL','LIMIT 2','count($rows)===1'] as $n)self::assertStringContainsString($n,$s);}
 public function testWebhookNeverTrustsProviderMappingFromEtsyPayload():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');self::assertStringContainsString('ApprovedPodMappingResolver::resolve',$s);self::assertStringContainsString('ApprovedPodMappingResolver::isDigital',$s);self::assertStringNotContainsString("(int)(\$item['provider_mapping_id']??0)",$s);}
}
