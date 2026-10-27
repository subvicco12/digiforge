<?php
// Synthetic standalone WordPress facade: no database, secrets or HTTP implementation exists.
declare(strict_types=1);
final class WP_Error {public function __construct(public string $code,public string $message,array $data=[]) {}}
function get_current_user_id():int {return 1;}
function current_user_can(string $cap):bool {return true;}
function wp_get_environment_type():string {return 'development';}
require_once dirname(__DIR__).'/includes/POD/PrintifyCatalogReadRepository.php';
$result=(new DigiForge\POD\PrintifyCatalogReadRepository())->research(str_repeat('a',64),0,'synthetic-env-read');
if(!$result instanceof WP_Error||$result->code!=='catalog_read_main_site_required')throw new RuntimeException('Non-production research path was not denied before storage access.');
echo "Non-production research denied before storage, credential or transport access.\n";
