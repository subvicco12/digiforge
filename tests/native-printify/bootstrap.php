<?php
/** Standalone synthetic WordPress error surface; real-session tests run under WordPress separately. */
declare(strict_types=1);
class WP_Error {
 public function __construct(private string $code='',private string $message='',private mixed $data=null){}
 public function get_error_code():string{return $this->code;}
 public function get_error_data():mixed{return $this->data;}
}
function is_wp_error(mixed $value):bool{return $value instanceof WP_Error;}
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
