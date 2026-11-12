<?php
declare(strict_types=1);
return static function():array {
 [$s,,,,$raw,$lineage]=(require __DIR__.'/../batch4/fixture.php')();$raw['line_items'][0]['quantity']=2;
 $o=DigiForgePrintifyConnector\Preparation\OrderReadModel::fromProvider($s,$raw,$lineage,str_repeat('a',64));
 $ship=[['carrier'=>'usps','number'=>'TRACK123','url'=>'https://example.invalid/track/private','line_item_ids'=>['synthetic_line_item']]];
 $alloc=[['carrier'=>'usps','number'=>'TRACK123','items'=>[['line_item_id'=>'synthetic_line_item','quantity'=>1]]]];
 $ctx=['order_version'=>1,'expected_order_version'=>1,'product_versions'=>['synthetic_line_item'=>1],'read_receipt'=>str_repeat('b',64),'observed_at'=>time()];return [$s,$o,$ship,$alloc,$ctx,$raw,$lineage];
};
