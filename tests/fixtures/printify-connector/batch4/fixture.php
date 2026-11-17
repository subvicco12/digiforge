<?php
declare(strict_types=1);
return static function():array {
 $s=new DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',99001,'99002','test',7,new DigiForge\Connector\Printify\CredentialReference(99001),str_repeat('a',64),2000000000,['fixture_image']);
 $input=['product_id'=>'synthetic_product','flags'=>['title'=>true,'description'=>true,'images'=>true,'variants'=>true,'tags'=>true,'keyFeatures'=>false,'shipping_template'=>false]];
 $e=['shop_key'=>$s->shopKey,'integration_id'=>99001,'provider_shop_id'=>'99002','sales_channel'=>'etsy','connected'=>true,'eligible'=>true,'channel_receipt'=>str_repeat('b',64),'product_receipt'=>str_repeat('c',64),'approval_receipt'=>str_repeat('d',64),'authorization_revision'=>7,'product_version'=>1,'expected_product_version'=>1,'current_product_id'=>'synthetic_product','approver_role'=>'GLOBAL_PRIMARY_OWNER'];
 $ctx=['actor_id'=>1,'logical_reference'=>'core-product-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>7,'product_version'=>1,'expected_product_version'=>1];
 $order=['id'=>'synthetic_order','shop_id'=>99002,'status'=>'pending','metadata'=>['shop_order_id'=>'synthetic_external_order'],'address_to'=>['first_name'=>'Synthetic','last_name'=>'Buyer','country'=>'US','region'=>'CA','address1'=>'Private Street','city'=>'Test City','zip'=>'90210','email'=>'synthetic@example.invalid'],'line_items'=>[['id'=>'synthetic_line_item','product_id'=>'synthetic_product','variant_id'=>99005,'print_provider_id'=>99004,'quantity'=>1]]];
 $lineage=[['order_id'=>'synthetic_order','line_item_id'=>'synthetic_line_item','product_id'=>'synthetic_product','variant_id'=>99005,'print_provider_id'=>99004,'product_version'=>1,'personalization_required'=>true,'proof_receipt'=>str_repeat('e',64),'proof_status'=>'APPROVED']];
 $event=['id'=>'11111111-2222-3333-4444-555555555555','type'=>'order:created','created_at'=>gmdate('Y-m-d H:i:sP',time()-5),'resource'=>['id'=>'synthetic_order','type'=>'order','data'=>['shop_id'=>99002]]];
 return [$s,$input,$e,$ctx,$order,$lineage,$event,hash('sha256','synthetic-only-signing-fixture')];
};
