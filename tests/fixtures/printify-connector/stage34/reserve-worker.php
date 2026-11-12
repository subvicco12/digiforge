<?php
declare(strict_types=1);
if(!class_exists("WP_Error")){class WP_Error{public function __construct(...$args){}}}
require dirname(__DIR__,3).'/unit/bootstrap.php';
require __DIR__.'/companion-test-bootstrap.php';
require_once dirname(__DIR__,4).'/includes/Research/DesignFactoryContract.php';
[$bytes,$e,$scope]=(require __DIR__.'/artwork-fixture.php')();
$i=(new DigiForgePrintifyConnector\Preparation\PreparationService())->upload($scope,$bytes,$e,['actor_id'=>1,'logical_reference'=>'core-asset-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);
$r=(new DigiForgePrintifyConnector\Preparation\MutationFence(new SyntheticCoreMutationHost($argv[1])))->reserve($i);
echo is_array($r)?'1':'0';
