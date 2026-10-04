<?php declare(strict_types=1); use PHPUnit\Framework\TestCase;
final class DigitalOrderDeliveryEvidenceContractTest extends TestCase {
 public function testReadinessRequiresConfirmedImmutableEtsyFileIdentityForDigitalLines():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');self::assertStringContainsString("'digital_delivery_configured'=>\$digitalDeliveryMissing===0",$s);self::assertStringContainsString("operation_type='UPLOAD_FILE'",$s);self::assertStringContainsString("op.state='CONFIRMED_SUCCESS'",$s);self::assertStringContainsString('op.external_asset_reference REGEXP',$s);self::assertStringContainsString('op.resource_reference=op.external_reference',$s);self::assertStringContainsString('pkg.listing_id=li.listing_id',$s);}
}
