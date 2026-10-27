<?php
declare(strict_types=1);

use DigiForge\POD\PersonalizationSubmissionNormalizer;
use PHPUnit\Framework\TestCase;

/** Exact buyer text at the intake boundary; no provider request or glyph-support claim. */
final class PersonalizationIntakeExactTextTest extends TestCase
{
    private function questions(bool $required=true): array
    {
        return [['question_id'=>54,'question_type'=>'text_input','question_text'=>'Family name','required'=>$required,'max_allowed_characters'=>48]];
    }
    public function testPreservesExactWhitespaceAndUnicodeBytesInCanonicalHash(): void
    {
        $text="  José e\u{0301}  ";
        $result=PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>$text]);
        self::assertIsArray($result);
        self::assertSame($text,$result['answers'][0]['value']);
        self::assertSame(hash('sha256',wp_json_encode(['schema_version'=>1,'answers'=>$result['answers']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),$result['canonical_hash']);
        $different=PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>trim($text)]);
        self::assertIsArray($different);
        self::assertNotSame($result['canonical_hash'],$different['canonical_hash']);
    }
    public function testFortyEightUnicodeCodePointsAreAcceptedAndFortyNineRejected(): void
    {
        $result=PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>str_repeat('é',48)]);
        self::assertIsArray($result);
        self::assertSame(str_repeat('é',48),$result['answers'][0]['value']);
        self::assertInstanceOf(WP_Error::class,PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>str_repeat('é',49)]));
        // Intake supports exact valid Unicode; each renderer still enforces its own glyph set.
        self::assertIsArray(PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>'家族 🐈']));
    }
    /** @dataProvider malformedText */
    public function testMalformedAndControlTextCannotProduceAnApprovedLookingHash(string $text): void
    {
        self::assertInstanceOf(WP_Error::class,PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>$text]));
    }
    public static function malformedText(): array {return [["\xc3\x28"],["Ada\0"],["Ada\n"],["Ada\x7f"]];}
    public function testOptionalBlankOmitsWholeAnswerAndRequiredBlankDenies(): void
    {
        foreach([''," \u{00a0}\u{2003} "] as $text){
            $result=PersonalizationSubmissionNormalizer::normalize($this->questions(false),['question_54'=>$text]);
            self::assertIsArray($result);self::assertSame([],$result['answers']);
            self::assertInstanceOf(WP_Error::class,PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>$text]));
        }
    }
    public function testUndeclaredAnswerAndDuplicateQuestionIdentityFailClosed(): void
    {
        self::assertInstanceOf(WP_Error::class,PersonalizationSubmissionNormalizer::normalize($this->questions(),['question_54'=>'Ada','question_55'=>'discarded']));
        self::assertInstanceOf(WP_Error::class,PersonalizationSubmissionNormalizer::normalize(array_merge($this->questions(),$this->questions()),['question_54'=>'Ada']));
    }
    public function testPersonalizationCanonicalizationPreservesBytesWithoutChangingGeneralOrderHashes(): void
    {
        $class=DigiForge\Orders\Validator::class;
        self::assertTrue(is_callable([$class,'personalizationJson']),'Exact personalization canonicalization missing');
        $payload=['z'=>'  José  ','a'=>['name'=>" e\u{0301} "]];
        $json=$class::personalizationJson($payload);
        self::assertSame('{"a":{"name":" é "},"z":"  José  "}',$json);
        self::assertSame($payload['z'],json_decode($json,true)['z']);
        self::assertSame('{"name":"Ada"}',$class::canonicalJson(['name'=>'  Ada  ']));
        self::assertNotSame($class::personalizationJson(['name'=>'Ada']),$class::personalizationJson(['name'=>' Ada ']));
    }
    public function testEditorImmutableRecordSealDistinguishesExactBuyerWhitespace(): void
    {
        require_once __DIR__.'/../../includes/POD/CustomerEditorRepository.php';
        $seal=new ReflectionMethod(DigiForge\POD\CustomerEditorRepository::class,'seal');
        $sealed=new ReflectionMethod(DigiForge\POD\CustomerEditorRepository::class,'sealed');
        $record=$seal->invoke(null,['fields'=>['name'=>"  José e\u{0301}  "]]);
        self::assertTrue($sealed->invoke(null,$record));
        $trimmed=$seal->invoke(null,['fields'=>['name'=>trim($record['fields']['name'])]]);
        self::assertNotSame($record['sha256'],$trimmed['sha256']);
        $record['fields']['name']=trim($record['fields']['name']);
        self::assertFalse($sealed->invoke(null,$record));
    }
}
