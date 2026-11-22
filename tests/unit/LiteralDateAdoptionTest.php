<?php
declare(strict_types=1);
use DigiForge\Research\ArtworkPersonalizationContract as Contract;
use DigiForge\POD\ProductPackageSpecification;
use PHPUnit\Framework\TestCase;
require_once __DIR__."/OfflineDateDisplayProofTest.php";
require_once __DIR__."/../../includes/POD/ProductPackageSpecification.php";

final class LiteralDateAdoptionTest extends TestCase
{
    public function testLiteralDateProofPreservesInputAndHoldsInvalidCalendarDate():void
    {
        $keys=['first_name','second_name','date'];
        $rectangles=[[688,355,1100,150],[688,630,1100,150],[688,845,1100,110]];
        $regions=[];$fields=[];$native=[];
        foreach($keys as $i=>$key){
            $regions[]=['name'=>$key,'kind'=>'text']+array_combine(['x','y','width','height'],$rectangles[$i]);
            $cap=$key==='date'?18:13;
            $fields[]=['key'=>$key,'type'=>'text','required'=>true,'max_length'=>$cap];
            $native[]=['layer'=>$key,'type'=>'TEXT','required'=>true,'max_characters'=>$cap,'provider_field_id'=>null];
        }
        $spec=\DigiForge\Tests\LocalPersonalizationProofTest::spec();$spec['layout']['native_regions']=$regions;
        $svg=file_get_contents(getenv('DIGIFORGE_RESTART_ARTWORK')?:__DIR__.'/../fixtures/design-factory/mug-original.svg');
        $policy='digiforge-fl03-literal-english-date-v1';
        $normalized=ProductPackageSpecification::normalize(['native_fields'=>$native],$keys,$regions);
        self::assertFalse($normalized['supplier_certified']);
        self::assertSame($native,$normalized['native_fields']);
        $input=['first_name'=>'Élodie','second_name'=>'José','date'=>'September 30, 2026'];
        $historical=Contract::proof($spec,$svg,$fields,$input);
        self::assertSame('MANUAL_HOLD',$historical['state']);
        $proof=Contract::proof($spec,$svg,$fields,$input,$policy);
        self::assertSame('MANUAL_REVIEW',$proof['state']);
        self::assertSame($input,$proof['samples']);
        self::assertStringContainsString($input['date'],$proof['svg']);
        self::assertSame(hash('sha256',$svg),$proof['artwork_sha256']);
        foreach(['February 29, 2023','April 31, 2026'] as $invalid){
            $held=Contract::proof($spec,$svg,$fields,array_replace($input,['date'=>$invalid]),$policy);
            self::assertSame('MANUAL_HOLD',$held['state']);
            self::assertNull($held['svg']);
        }
        foreach(['September 30, 20260','2024-02-29',"June 1,\n2026"] as $invalid){
            try {
                Contract::proof($spec,$svg,$fields,array_replace($input,['date'=>$invalid]),$policy);
                self::fail('Invalid literal date accepted');
            } catch(InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame($historical,Contract::proof($spec,$svg,$fields,$input));
    }
}
