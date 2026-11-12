<?php
declare(strict_types=1);
return static function():DigiForgePrintifyConnector\Preparation\MutationIntent {
 require_once dirname(__DIR__,4).'/includes/Research/DesignFactoryContract.php';
 [$b,$e,$s]=(require __DIR__.'/../stage34/artwork-fixture.php')();
 return (new DigiForgePrintifyConnector\Preparation\PreparationService())->upload($s,$b,$e,['actor_id'=>1,'logical_reference'=>'core-asset-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);
};
