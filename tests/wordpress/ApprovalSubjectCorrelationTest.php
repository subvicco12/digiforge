<?php
declare(strict_types=1);

final class ApprovalSubjectCorrelationTest extends WP_UnitTestCase
{
    public function testPersonalizationEvidenceFlagsMissingAndConflictingSubjects(): void
    {
        $method=new ReflectionMethod(DigiForge\Portal\U3ApprovalInbox::class,'subjectEvidenceState');
        $inbox=new DigiForge\Portal\U3ApprovalInbox();
        $row=[
            'subject_id'=>17,'personalization_schema_id'=>8,'schema_subject_id'=>8,
            'line_product_version_id'=>4,'schema_product_version_id'=>4,
            'environment'=>'production','line_environment'=>'production',
            'schema_state'=>'APPROVED',
        ];
        self::assertSame('RECORDED — VERIFY IN WORKFLOW',$method->invoke($inbox,$row));
        self::assertSame('MISSING SUBJECT — REVIEW',$method->invoke($inbox,array_replace($row,['subject_id'=>null])));
        self::assertSame('MISSING SCHEMA — REVIEW',$method->invoke($inbox,array_replace($row,['schema_subject_id'=>null])));
        self::assertSame('SCHEMA PRODUCT MISMATCH — REVIEW',$method->invoke($inbox,array_replace($row,['schema_product_version_id'=>5])));
        self::assertSame('ENVIRONMENT MISMATCH — REVIEW',$method->invoke($inbox,array_replace($row,['line_environment'=>'sandbox'])));
        self::assertSame('SCHEMA NOT APPROVED — REVIEW',$method->invoke($inbox,array_replace($row,['schema_state'=>'DRAFT'])));
    }
}
