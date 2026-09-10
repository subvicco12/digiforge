<?php
declare(strict_types=1);
require __DIR__.'/../includes/DigitalFactory/Lifecycle.php';
function df_expect(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function df_source(string $p):string{return (string)file_get_contents(__DIR__.'/../'.$p);}
use DigiForge\DigitalFactory\Lifecycle;
df_expect(Lifecycle::can_transition('DRAFT','FILES_PENDING'),'digital workflow starts with files');
df_expect(Lifecycle::can_transition('QA_PENDING','QA_FAILED'),'technical QA may fail');
df_expect(!Lifecycle::can_transition('DRAFT','PUBLISH_READY'),'workflow cannot skip QA and approval');
df_expect(count(Lifecycle::readiness())===12,'all readiness gates are represented');
$m=df_source('includes/Database/Migrator.php');
foreach(['digital_products()','digital_files()','digital_file_versions()','digital_packages()','digital_previews()','digital_templates()','digital_licenses()','digital_download_checks()'] as $t){df_expect(str_contains($m,$t),"$t schema exists");}
foreach(['UNIQUE KEY product_version','UNIQUE KEY file_version','UNIQUE KEY idempotency_key','checksum_sha256','validation_result','failure_reason','review_status'] as $rule){df_expect(str_contains($m,$rule),"$rule schema contract exists");}
df_expect(str_contains($m,"digiforge_db_schema_version', 4"),'schema version 4 is installed');
$r=df_source('includes/DigitalFactory/Repository.php');
foreach(['invalid_relationship','Product version must belong to the product','Preview must match the product and file','QA target must belong','sanitize_text_field','sanitize_textarea_field','absint','find_by_key','idempotent_replay','Logger::audit','LIMIT %d OFFSET %d','MAX_PAGE_SIZE = 100','license_code_hash'] as $rule){df_expect(str_contains($r,$rule),"repository provides $rule");}
foreach(['pdf_integrity','pdf_page_count','pdf_dimensions','pdf_resolution','pdf_fonts','pdf_rendering','pdf_blank_pages','pdf_links','pdf_file_size','image_dimensions','image_transparency','image_corruption','archive_integrity','archive_required_files','archive_folder_structure','archive_file_naming','archive_package_size','template_reference','template_access_instructions','template_preview_relationship','preview_relationship','checksum','package_generation'] as $check){df_expect(str_contains(df_source('includes/DigitalFactory/Validator.php'),$check),"$check is supported");}
$api=df_source('includes/REST/DigitalFactoryController.php'); foreach(['digital-products','digital-files','digital-file-versions','digital-packages','digital-previews','digital-templates','digital-licenses','digital-download-checks','permission_callback','manage_digiforge_digital','Idempotency-Key','X-WP-Total','X-WP-TotalPages'] as $v){df_expect(str_contains($api,$v),"REST exposes $v");}
$admin=df_source('includes/DigitalFactory/Admin.php');foreach(['Digital Products','Digital Files','Digital Packages','Digital Templates','Digital Licenses','Digital QA / Download Checks','manage_digiforge_digital'] as $v){df_expect(str_contains($admin,$v),"admin exposes $v");}
$bootstrap=df_source('digiforge.php');df_expect(str_contains($bootstrap,'* Version: 0.3.0')&&str_contains($bootstrap,"DIGIFORGE_VERSION = '0.3.0'")&&str_contains($bootstrap,"DIGIFORGE_DB_VERSION = '4'"),'versions synchronized');
$config=df_source('includes/Core/Config.php');df_expect(!str_contains($config,"=> true"),'automation remains OFF');
echo "DigiForge Digital Product Factory tests passed.\n";
