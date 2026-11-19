<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyApiOutcome;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyApiOutcome.php';
final class PrintifyApiOutcomeTest extends TestCase
{
 public function testMutationUncertaintyNeverRetries():void {foreach([0,500,503] as $status){$r=PrintifyApiOutcome::classify($status,[],1,'POST',100);self::assertSame('UNKNOWN',$r['category']);self::assertFalse($r['retry_permitted']);}}
 public function testSafeReadsHaveBoundedAttempts():void {self::assertTrue(PrintifyApiOutcome::classify(503,[],1,'GET',100)['retry_permitted']);self::assertFalse(PrintifyApiOutcome::classify(503,[],3,'GET',100)['retry_permitted']);self::assertSame('DEFINITIVE_ERROR',PrintifyApiOutcome::classify(403,[],1,'GET',100)['category']);}
 public function testRetryAfterBoundsAndDate():void {self::assertSame(3600,PrintifyApiOutcome::retryAfter(['Retry-After'=>'999999'],100));self::assertSame(60,PrintifyApiOutcome::retryAfter(['retry-after'=>'garbage'],100));self::assertSame(120,PrintifyApiOutcome::retryAfter(['retry-after'=>gmdate('D, d M Y H:i:s \G\M\T',220)],100));}
 public function testReasonCodesNeverExposeProviderMessages():void{self::assertSame('AUTHORIZATION',PrintifyApiOutcome::classify(403)['reason_code']);self::assertSame('NOT_FOUND',PrintifyApiOutcome::classify(404)['reason_code']);self::assertSame('REDIRECT_REJECTED',PrintifyApiOutcome::classify(302)['reason_code']);}
}
