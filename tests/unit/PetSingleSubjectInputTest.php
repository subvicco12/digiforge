<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PetSingleSubjectInput;
foreach(['Orders/Validator','POD/RasterArtworkCodec','POD/TransformationEngineContract','POD/PetSingleSubjectInput','POD/PersonalizationPayloadContract']as$f){$p=dirname(__DIR__,2).'/includes/'.$f.'.php';if(is_file($p))require_once $p;}
final class PetSingleSubjectInputTest extends TestCase
{
    public static function limits():array{return ['max_input_bytes'=>16777216,'max_output_bytes'=>16777216,'max_pixels'=>1048576,'max_execution_seconds'=>10,'max_variants'=>1,'max_retries'=>0];}
    public static function fixture(string $name):string{return file_get_contents(dirname(__DIR__).'/fixtures/pet-single-subject/'.$name);}
    public function testProposalPreservesTwentyOneIdsWithoutActivatingStylesOrCatalog():void
    {
        self::assertTrue(class_exists(PetSingleSubjectInput::class),'Supported pet technical preparation is missing.');$p=PetSingleSubjectInput::proposal();
        self::assertSame('PROPOSED',$p['approval_state']);self::assertCount(21,$p['catalog_ids']);self::assertNotContains('DG2-048',$p['catalog_ids']);self::assertSame('DG2-046',$p['catalog_ids'][0]);self::assertSame('DG2-486',$p['catalog_ids'][20]);self::assertFalse($p['catalog_activation_allowed']);self::assertFalse($p['artistic_transform_available']);
        $handle=fopen(dirname(__DIR__).'/fixtures/pet-single-subject/concept-review-mapping.csv','rb');$header=fgetcsv($handle,0,',','"','');$ids=[];while(($row=fgetcsv($handle,0,',','"',''))!==false){$mapped=array_combine($header,$row);self::assertSame('PET_PORTRAIT',$mapped['engine_preserved']);self::assertSame('pet_single_subject',$mapped['proposed_profile']);$ids[]=$mapped['catalog_id'];}fclose($handle);self::assertSame($p['catalog_ids'],$ids);
    }
    /** @dataProvider codecCases */
    public function testOwnerSuppliedSyntheticBytesAreFullyDecodedAndBounded(string $file,string $hash,bool $accept):void
    {
        self::assertTrue(class_exists(PetSingleSubjectInput::class),'Supported pet technical preparation is missing.');$raw=self::fixture($file);self::assertSame($hash,hash('sha256',$raw));
        if(!$accept){$this->expectException(InvalidArgumentException::class);PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits());return;}
        $p=PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits());self::assertSame(hash('sha256',$raw),$p['receipt']['source_sha256']);self::assertSame(hash('sha256',$p['png']),$p['receipt']['prepared_sha256']);self::assertFalse($p['receipt']['artistic_transform_executed']);self::assertSame('UNKNOWN',$p['receipt']['semantic_subject_count']);
        self::assertSame($p,PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits()));self::assertTrue(PetSingleSubjectInput::verify($raw,$p['png'],$p['receipt'],self::limits()));
    }
    public static function codecCases():array{$rows=json_decode(self::fixture('SYNTHETIC_CASES.json'),true);return array_map(static fn($x)=>[$x['file'],$x['sha256'],$x['expected_codec']==='ACCEPT'],$rows);}
    public function testLyingMimeUnsupportedPhotosAndDeclaredMultiplePetsDeny():void
    {
        self::assertTrue(class_exists(PetSingleSubjectInput::class),'Supported pet technical preparation is missing.');
        foreach([['image/jpeg',1],['image/heic',1],['image/png',2],['image/png',0]]as[$mime,$count]){try{PetSingleSubjectInput::prepare(self::fixture('synthetic/single_shape.png'),$mime,$count,self::limits());self::fail('Unsupported photo/count admitted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
    public function testActualOutputDriftAndForgedReceiptsAreRejected():void
    {
        self::assertTrue(class_exists(PetSingleSubjectInput::class),'Supported pet technical preparation is missing.');$raw=self::fixture('synthetic/single_shape.png');$p=PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits());$this->expectException(InvalidArgumentException::class);PetSingleSubjectInput::verify($raw,$p['png'].'x',$p['receipt'],self::limits());
    }
    public function testJobCannotRaisePixelLimitAndPreparationCannotNormalizeUnsupportedMetadata():void
    {
        self::assertTrue(class_exists(PetSingleSubjectInput::class),'Supported pet technical preparation is missing.');$limits=self::limits();$limits['max_pixels']=1048577;$this->expectException(InvalidArgumentException::class);PetSingleSubjectInput::prepare(self::fixture('synthetic/single_shape.png'),'image/png',1,$limits);
    }
    public function testReceiptForgeryAndLowerJobBoundsFailClosed():void
    {
        $raw=self::fixture('synthetic/single_shape.png');$p=PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits());
        $forged=$p['receipt'];$forged['artistic_transform_executed']=true;
        try{PetSingleSubjectInput::verify($raw,$p['png'],$forged,self::limits());self::fail('Forged receipt admitted.');}catch(InvalidArgumentException){self::assertTrue(true);}
        foreach(['max_input_bytes'=>strlen($raw)-1,'max_output_bytes'=>strlen($p['png'])-1,'max_pixels'=>1]as$key=>$value){$limits=self::limits();$limits[$key]=$value;try{PetSingleSubjectInput::prepare($raw,'image/png',1,$limits);self::fail('Lower job bound bypassed.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
    public function testSupportedGrayscaleIsLosslesslyPreparedWithoutChangingOriginal():void
    {
        $chunk=static fn($kind,$data)=>pack('N',strlen($data)).$kind.$data.pack('N',crc32($kind.$data));
        $raw="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',2,1,8,0,0,0,0)).$chunk('IDAT',gzcompress("\x00\x00\xff",9)).$chunk('IEND','');$before=$raw;
        $p=PetSingleSubjectInput::prepare($raw,'image/png',1,self::limits());self::assertSame($before,$raw);self::assertNotSame($raw,$p['png']);self::assertSame("\x00\x00\x00\xff\xff\xff\xff\xff",DigiForge\POD\RasterArtworkCodec::decode($p['png'])['rgba']);self::assertTrue(PetSingleSubjectInput::verify($raw,$p['png'],$p['receipt'],self::limits()));
    }
    public function testTypedTextRetainsExistingSchemaBoundsAndDoesNotAcceptNewPromptFields():void
    {
        $fields=[['key'=>'name','type'=>'text','required'=>true,'max_length'=>5],['key'=>'caption','type'=>'text','max_length'=>8]];DigiForge\POD\PersonalizationPayloadContract::validate($fields,['name'=>"Zoé"]);self::assertTrue(true);
        foreach([['name'=>'123456'],['name'=>"name\n"],['name'=>'ok','buyer_prompt'=>'arbitrary']]as$payload){try{DigiForge\POD\PersonalizationPayloadContract::validate($fields,$payload);self::fail('Unapproved text accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
