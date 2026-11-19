<?php
declare(strict_types=1);
use DigiForge\Finance\DigitalSourceBinding;
use PHPUnit\Framework\TestCase;

final class FinanceDigitalBindingContractTest extends TestCase
{
    private static function input():array
    {
        return ['version'=>1,'business_id'=>1,'store_id'=>2,'integration_id'=>3,'environment'=>'test','source_sha256'=>str_repeat('a',64),'evidence_reference'=>'local://synthetic/digital','observed_at'=>'2026-10-10T12:00:00Z'];
    }
    protected function setUp():void
    {
        $path=dirname(__DIR__,2).'/includes/Finance/DigitalSourceBinding.php';
        if(is_file($path))require_once $path;
        require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
        require_once dirname(__DIR__,2).'/includes/Integrations/Repository.php';
    }
    public function testExactTypedInputIsPreserved():void
    {
        self::assertTrue(class_exists(DigitalSourceBinding::class),'Typed non-POD Digital binding is missing.');
        self::assertSame(self::input(),DigitalSourceBinding::normalizeInput(self::input()));
    }
    /** @dataProvider malformed */
    public function testMalformedEvidenceIsRejected(array $change):void
    {
        $this->expectException(InvalidArgumentException::class);
        DigitalSourceBinding::normalizeInput(array_replace(self::input(),$change));
    }
    public static function malformed():array
    {
        return [[['version'=>2]],[['version'=>'1']],[['business_id'=>'1']],[['store_id'=>0]],[['integration_id'=>true]],
            [['environment'=>'TEST']],[['source_sha256'=>str_repeat('A',64)]],[['evidence_reference'=>'https://example.invalid/source']],
            [['evidence_reference'=>'local://synthetic/../foreign']],[['evidence_reference'=>'local://synthetic/./foreign']],
            [['observed_at'=>'2026-02-30T12:00:00Z']],[['observed_at'=>'2026-10-10 12:00:00']],
            [['observed_at'=>'2026-10-10T12:00:00+00:00']],[['provider'=>'printify']],[['credential'=>'forbidden']]];
    }
}
