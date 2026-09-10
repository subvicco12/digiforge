<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Extensible local validation vocabulary. It records results; it never processes a file externally. */
final class Validator {
    public const CHECKS = [
        'pdf_integrity','pdf_page_count','pdf_dimensions','pdf_resolution','pdf_fonts','pdf_rendering','pdf_blank_pages','pdf_links','pdf_file_size',
        'image_dimensions','image_resolution','image_format','image_transparency','image_corruption','image_aspect_ratio',
        'archive_integrity','archive_required_files','archive_folder_structure','archive_file_naming','archive_package_size',
        'template_reference','template_access_instructions','template_preview_relationship','preview_relationship','checksum','package_generation',
    ];
    public const RESULTS = ['PENDING','PASS','FAIL','WARNING','NOT_APPLICABLE'];
    public const REVIEWS = ['UNREVIEWED','REVIEW_REQUIRED','REVIEWED','APPROVED','ACCEPTED','REJECTED'];
    public static function check(string $value): string { $value=sanitize_key($value); return in_array($value,self::CHECKS,true)||str_starts_with($value,'custom_')?$value:''; }
    public static function result(string $value): string { $value=strtoupper(sanitize_key($value)); return in_array($value,self::RESULTS,true)?$value:''; }
    public static function review(string $value): string { $value=strtoupper(sanitize_key($value)); return in_array($value,self::REVIEWS,true)?$value:''; }
    public static function checksum(string $value): string { $value=strtolower(trim($value)); return $value===''||preg_match('/^[a-f0-9]{64}$/',$value)===1?$value:''; }
}
