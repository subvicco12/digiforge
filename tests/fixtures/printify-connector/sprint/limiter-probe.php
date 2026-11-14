<?php
declare(strict_types=1);
// Test-only child. Fixed local fixture bootstrap, no provider or secret output.
$bootstrap=getenv('DF_LIMITER_PROBE_BOOTSTRAP');if(!in_array($bootstrap,['/private/core10-bootstrap.php','/companionrepo/tests/contracts/native-isolated-bootstrap.php'],true))exit(2);
require_once '/vendor/autoload.php';
putenv('WP_TESTS_SKIP_INSTALL=0');ob_start();require $bootstrap;ob_end_clean();
echo DigiForge\PortalAccess\Limiter::reserve('email_send_global',1)?'ADMITTED':'DENIED';
