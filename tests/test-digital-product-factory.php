<?php
declare(strict_types=1);
require __DIR__.'/../includes/DigitalFactory/Lifecycle.php';
if (! class_exists('WP_Error')) { class WP_Error { public function __construct(public string $code, public string $message, public array $data=[]) {} public function get_error_data(): array { return $this->data; } } }
if (! function_exists('is_wp_error')) { function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; } }
if (! function_exists('__')) { function __(string $value,string $domain=''): string { return $value; } }
if (! function_exists('sanitize_key')) { function sanitize_key(string $value): string { return strtolower((string)preg_replace('/[^a-z0-9_\-]/i','',$value)); } }
if (! function_exists('sanitize_text_field')) { function sanitize_text_field(string $value): string { return trim(strip_tags($value)); } }
if (! function_exists('sanitize_textarea_field')) { function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); } }
if (! function_exists('absint')) { function absint(mixed $value): int { return abs((int)$value); } }
if (! function_exists('wp_json_encode')) { function wp_json_encode(mixed $value): string|false { return json_encode($value); } }
require __DIR__.'/../includes/Database/Tables.php';
require __DIR__.'/../includes/DigitalFactory/Validator.php';
require __DIR__.'/../includes/DigitalFactory/Repository.php';
function df_expect(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function df_source(string $p):string{return (string)file_get_contents(__DIR__.'/../'.$p);}
use DigiForge\DigitalFactory\Lifecycle;
use DigiForge\DigitalFactory\Repository;
df_expect(Lifecycle::can_transition('DRAFT','FILES_PENDING'),'digital workflow starts with files');
df_expect(Lifecycle::can_transition('QA_PENDING','QA_FAILED'),'technical QA may fail');
df_expect(!Lifecycle::can_transition('DRAFT','PUBLISH_READY'),'workflow cannot skip QA and approval');
df_expect(count(Lifecycle::readiness())===12,'all readiness gates are represented');
$readiness=Lifecycle::readiness();
$readiness=Lifecycle::advance_readiness($readiness,'QA_PENDING');
df_expect($readiness['files']===true&&$readiness['technical_qa']===false,'QA pending marks files ready only');
$readiness=Lifecycle::advance_readiness($readiness,'QA_PASSED');
df_expect($readiness['technical_qa']===true,'QA pass synchronizes technical readiness');
$readiness=Lifecycle::advance_readiness($readiness,'VISUAL_REVIEW');
df_expect($readiness['content_qa']===true,'visual review synchronizes completed content QA');
$readiness=Lifecycle::advance_readiness($readiness,'QA_FAILED');
df_expect($readiness['technical_qa']===false,'QA failure clears technical readiness');
$m=df_source('includes/Database/Migrator.php');
foreach(['digital_products()','digital_files()','digital_file_versions()','digital_packages()','digital_previews()','digital_templates()','digital_licenses()','digital_download_checks()'] as $t){df_expect(str_contains($m,$t),"$t schema exists");}
foreach(['UNIQUE KEY product_version','UNIQUE KEY file_version','UNIQUE KEY idempotency_key','checksum_sha256','validation_result','failure_reason','review_status'] as $rule){df_expect(str_contains($m,$rule),"$rule schema contract exists");}
df_expect(str_contains($m,"digiforge_db_schema_version', 4"),'schema version 4 is installed');
df_expect(str_contains($m,'Capabilities::addDigital()'),'versioned upgrade grants only the digital capability');
df_expect(!str_contains($m,'Capabilities::add();'),'versioned migration does not restore unrelated capabilities');
$capabilities=df_source('includes/Core/Capabilities.php');
df_expect(str_contains($capabilities,'public static function addDigital()'),'targeted digital capability helper exists');
df_expect(str_contains($capabilities,"add_cap('manage_digiforge_digital')"),'targeted helper grants only digital capability');
df_expect(str_contains($capabilities,'foreach (self::ALL as $cap)'),'fresh activation retains full DigiForge capability grant');
$r=df_source('includes/DigitalFactory/Repository.php');
foreach(['invalid_relationship','Product version must belong to the product','Preview must match the product and file','QA target must belong','sanitize_text_field','sanitize_textarea_field','find_by_key','idempotent_replay','Logger::audit','LIMIT %d OFFSET %d','MAX_PAGE_SIZE = 100','license_code_hash'] as $rule){df_expect(str_contains($r,$rule),"repository provides $rule");}
df_expect(str_contains($r,"\$target_type === 'digital_file_version'")&&str_contains($r,"\$target['digital_file_id']"),'file-version QA resolves its parent file before ownership validation');
df_expect(str_contains($r,'Lifecycle::advance_readiness')&&str_contains($r,"'readiness' => wp_json_encode(\$readiness)"),'lifecycle transitions persist readiness');
$repository=new Repository();
$structured=new ReflectionMethod($repository,'structured_json');
$encoded=$structured->invoke($repository,['files'=>[['name'=>'<b>Guide.pdf</b>','pages'=>12]],'required'=>true]);
df_expect(is_string($encoded)&&json_decode($encoded,true)===['files'=>[['name'=>'Guide.pdf','pages'=>12]],'required'=>true],'nested structured JSON is sanitized and round-trips');
$object_encoded=$structured->invoke($repository,(object)['result'=>(object)['reason'=>'<i>Valid</i>','attempts'=>2]]);
df_expect(is_string($object_encoded)&&json_decode($object_encoded,true)===['result'=>['reason'=>'Valid','attempts'=>2]],'nested JSON objects round-trip as structured data');
$keyed=$structured->invoke($repository,['a.b'=>1,'ab'=>2,'Case.Key'=>'<b>Value</b>']);
df_expect(is_string($keyed)&&json_decode($keyed,true)===['a.b'=>1,'ab'=>2,'Case.Key'=>'Value'],'structured JSON preserves punctuation and case-sensitive keys while sanitizing values');
$invalid_json=$structured->invoke($repository,'not-json');
df_expect(is_wp_error($invalid_json)&&$invalid_json->get_error_data()['status']===400,'invalid structured JSON returns a validation response');
$sanitize=new ReflectionMethod($repository,'sanitize');
$text=$sanitize->invoke($repository,['terms'=>" <b>Personal</b> use\nonly "]);
df_expect($text['terms']==="Personal use\nonly",'genuinely textual long fields use textarea sanitization');
$negative_id=$sanitize->invoke($repository,['target_id'=>-7]);
df_expect(is_wp_error($negative_id)&&$negative_id->get_error_data()['status']===400,'negative relationship identifiers are rejected instead of coerced');
$junk_id=$sanitize->invoke($repository,['target_id'=>'7junk']);
df_expect(is_wp_error($junk_id)&&$junk_id->get_error_data()['status']===400,'mixed relationship identifiers are rejected instead of coerced');
$float_id=$sanitize->invoke($repository,['target_id'=>7.2]);
df_expect(is_wp_error($float_id)&&$float_id->get_error_data()['status']===400,'floating relationship identifiers are rejected');
$valid_id=$sanitize->invoke($repository,['target_id'=>'7']);
df_expect($valid_id['target_id']===7,'canonical positive integer strings are accepted as relationship identifiers');
$valid_zero_size=$sanitize->invoke($repository,['byte_size'=>'0']);
df_expect($valid_zero_size['byte_size']===0,'byte size remains a non-negative integer and may be zero');
$invalid_size=$sanitize->invoke($repository,['byte_size'=>'7junk']);
df_expect(is_wp_error($invalid_size)&&$invalid_size->get_error_data()['status']===400,'malformed byte sizes are rejected instead of coerced');
$field_validation=new ReflectionMethod($repository,'validate_fields');
$unknown=$field_validation->invoke($repository,['manifest'=>[]],['name']);
df_expect(is_wp_error($unknown)&&$unknown->get_error_data()['status']===400,'cross-entity and unknown fields return a REST-compatible 400 validation error');
df_expect($field_validation->invoke($repository,['name'=>'Valid'],['name'])===true,'valid create and update fields remain accepted');
$required_validation=new ReflectionMethod($repository,'validate_required');
$empty_required=$required_validation->invoke($repository,['required'=>['name','category']],['name'=>'','category'=>'planner']);
df_expect(is_wp_error($empty_required)&&$empty_required->get_error_data()['status']===400,'updates cannot empty required fields');
$zero_required=$required_validation->invoke($repository,['required'=>['digital_product_id']],['digital_product_id'=>0]);
df_expect(is_wp_error($zero_required)&&$zero_required->get_error_data()['status']===400,'updates cannot zero required identifiers');
df_expect($required_validation->invoke($repository,['required'=>['name','category']],['name'=>'Planner','category'=>'planner'])===true,'valid prospective required fields remain accepted');
df_expect(str_contains($r,'$required = $this->validate_required($definition, $prospective);'),'updates validate prospective required fields before writing');
$special_validation=new ReflectionMethod($repository,'validate_special_fields');
$partial_check=$special_validation->invoke($repository,'digital_download_check',['details'=>'{"note":"kept"}'],['details'=>['note'=>'kept']],false);
df_expect(!array_key_exists('validation_result',$partial_check)&&!array_key_exists('review_status',$partial_check),'partial QA updates preserve omitted validation and review statuses');
$created_check=$special_validation->invoke($repository,'digital_download_check',[],[],true);
df_expect($created_check['validation_result']==='PENDING'&&$created_check['review_status']==='UNREVIEWED','new QA checks still receive default statuses');
df_expect(str_contains($r,'validate_special_fields($type, $data, $input, true)')&&str_contains($r,'validate_special_fields($type, $data, $input, false)'),'create applies QA defaults while update does not');
df_expect(str_contains($r,"'update' => ['digital_file_id', 'storage_reference'")&&!str_contains($r,"'update' => ['version_label', 'digital_file_id'"),'file-version labels remain immutable through explicit allowlists');
df_expect(str_contains($r,'validate_descendants')&&str_contains($r,'Parent reassignment would invalidate existing descendants.'),'parent reassignment validates descendants and returns a conflict');
foreach(['digital_files()','digital_packages()','digital_previews()','digital_templates()','digital_download_checks()'] as $descendant){df_expect(str_contains($r,$descendant),"descendant ownership validates through $descendant");}
class DigitalFactoryWpdbStub {
    public string $prefix='wp_';
    public bool $has_mismatch=true;
    public function prepare(string $query,mixed ...$args): string { return $query; }
    public function get_var(string $query): int { return $this->has_mismatch&&str_contains($query,'product_version_id <>')?1:0; }
}
$wpdb=new DigitalFactoryWpdbStub();
$descendant_validation=new ReflectionMethod($repository,'validate_descendants');
$blocked=$descendant_validation->invoke($repository,'digital_product',10,['product_version_id'=>22]);
df_expect(is_wp_error($blocked)&&$blocked->get_error_data()['status']===409,'parent reassignment with inconsistent descendants is rejected');
$wpdb->has_mismatch=false;
df_expect($descendant_validation->invoke($repository,'digital_product',10,['product_version_id'=>22])===true,'ownership reassignment is accepted when descendants remain consistent');
foreach(['pdf_integrity','pdf_page_count','pdf_dimensions','pdf_resolution','pdf_fonts','pdf_rendering','pdf_blank_pages','pdf_links','pdf_file_size','image_dimensions','image_transparency','image_corruption','archive_integrity','archive_required_files','archive_folder_structure','archive_file_naming','archive_package_size','template_reference','template_access_instructions','template_preview_relationship','preview_relationship','checksum','package_generation'] as $check){df_expect(str_contains(df_source('includes/DigitalFactory/Validator.php'),$check),"$check is supported");}
$api=df_source('includes/REST/DigitalFactoryController.php'); foreach(['digital-products','digital-files','digital-file-versions','digital-packages','digital-previews','digital-templates','digital-licenses','digital-download-checks','permission_callback','manage_digiforge_digital','Idempotency-Key','X-WP-Total','X-WP-TotalPages'] as $v){df_expect(str_contains($api,$v),"REST exposes $v");}
$admin=df_source('includes/DigitalFactory/Admin.php');foreach(['Digital Products','Digital Files','Digital Packages','Digital Templates','Digital Licenses','Digital QA / Download Checks','manage_digiforge_digital'] as $v){df_expect(str_contains($admin,$v),"admin exposes $v");}
df_expect(str_contains($admin,"\$_GET['paged']")&&str_contains($admin,'all($type,$page)')&&str_contains($admin,'paginate_links')&&str_contains($admin,"['total_pages']"),'admin lists provide page navigation beyond the first repository page');
$bootstrap=df_source('digiforge.php');df_expect(str_contains($bootstrap,'* Version: 0.4.0')&&str_contains($bootstrap,"DIGIFORGE_VERSION = '0.4.0'")&&str_contains($bootstrap,"DIGIFORGE_DB_VERSION = '5'"),'versions synchronized');
require_once __DIR__.'/../includes/Core/Config.php';
$defaults=\DigiForge\Core\Config::default_settings();
df_expect($defaults['stop_all']===true&&$defaults['automation_armed']===false,'automation safety defaults are fail-closed');
foreach(\DigiForge\Core\Config::SWITCHES as $switch){if($switch!=='stop_all'){df_expect($defaults[$switch]===false,"$switch remains OFF");}}
echo "DigiForge Digital Product Factory tests passed.\n";
