<?php
require '/workspace/lane-a-photo-http-20261116/site/wp-load.php';
$s=[];foreach(['stop_all','automation_armed','activation_authorized','printify_activation_authorized','gelato_activation_authorized','etsy_publish_activation_authorized','order_automation_activation_authorized']as $k)$s[$k]=DigiForge\Core\Settings::get($k);$s['external_safety_locked']=DigiForge\Core\Settings::safety_locked();echo json_encode($s,JSON_PRETTY_PRINT),"\n";
