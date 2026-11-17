<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Orders\{PersonalizationReadContext,Repository};
use DigiForge\POD\CustomerPhotoSelection;
use DigiForge\Portal\OrderLocalWorkflow;
use DigiForge\PortalAccess\Session;

/** Actual managed identities and signed browser acknowledgements; no caller claims become grants. */
trait ManagedScopeCases
{
    private function observeManagedPrivateReads(callable $operation):array {
        $queries=[];
        $observe=static function(string $sql)use(&$queries):string {
            if(preg_match('/^\s*SELECT\b/i',$sql))foreach([Tables::orders(),Tables::order_line_items(),Tables::personalization_submissions(),Tables::pod_render_evidence(),Tables::pod_authorization_packages()]as $table)if(str_contains($sql,$table)){$queries[]=$sql;break;}
            return $sql;
        };
        add_filter('query',$observe);try{$result=$operation();}finally{remove_filter('query',$observe);}
        return [$result,$queries];
    }
    public function testManagedForeignActorShopOrderLineAndSignedCallerClaimsDenyBeforePrivateExport():void {
        $f=$this->managedApprovedPhoto();$ownerCookies=$_COOKIE;$repo=new Repository();
        $sentinel='private-foreign-order-'.wp_generate_uuid4();
        $foreign=PrintifyApiApprovalFixture::checked($repo->createOrder(['channel'=>'etsy','environment'=>'test','shop_reference'=>'standard_pod','external_order_reference'=>$sentinel,'personalization_required'=>true]));
        $foreignLine=PrintifyApiApprovalFixture::checked($repo->addLineItem(['order_id'=>$foreign['id'],'product_version_id'=>$f['selection']['snapshot']['product_version_id'],'provider_mapping_id'=>$f['selection']['snapshot']['provider_mapping_id'],'quantity'=>1]));
        [$wrongShop,$reads]=$this->observeManagedPrivateReads(static fn()=>PersonalizationReadContext::capture('standard_pod',$f['order']));
        self::assertWPError($wrongShop);self::assertSame([],$reads,'Wrong retained shop is denied before any order/private artifact query.');
        $wrongOrder=PersonalizationReadContext::capture('personalized_pod',(int)$foreign['id']);self::assertWPError($wrongOrder);
        self::assertStringNotContainsString($sentinel,wp_json_encode($wrongOrder));
        $wrongLine=(new CustomerPhotoSelection())->resolveForOrder('personalized_pod',$f['order'],(int)$foreignLine['id']);self::assertWPError($wrongLine);
        self::assertStringNotContainsString($sentinel,wp_json_encode($wrongLine));
        $context=PrintifyApiApprovalFixture::checked(PersonalizationReadContext::capture('personalized_pod',$f['order']));
        try{
            [$substitution,$reads]=$this->observeManagedPrivateReads(static fn()=>$context->forOrder((int)$foreign['id']));
            self::assertWPError($substitution);self::assertSame([],$reads,'An opaque context cannot be retargeted before source lookup.');
            self::assertWPError($context->forOrder($f['order']),'A rejected retarget permanently poisons this decision.');
        }finally{self::assertTrue($context->release(false));}
        $context=PrintifyApiApprovalFixture::checked(PersonalizationReadContext::capture('personalized_pod',$f['order']));
        try{
            PortalAccessFixture::login($f['customer']);self::assertTrue(Session::start());
            [$actorDenied,$reads]=$this->observeManagedPrivateReads(static fn()=>$context->forOrder($f['order']));
            self::assertWPError($actorDenied);self::assertSame([],$reads,'A real different native session cannot export retained managed source.');
        }finally{wp_set_current_user(1);$_COOKIE=$ownerCookies;self::assertTrue($context->release(false));}
        $confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        foreach(['foreign_order','foreign_shop','forged_shop_ack','forged_token']as $claim){
            $bad=$confirm;
            if($claim==='foreign_order')$bad['order_id']=(string)$foreign['id'];
            elseif($claim==='foreign_shop')$bad['shop_key']='standard_pod';
            elseif($claim==='forged_shop_ack')$bad['df_confirmation_shop']='standard_pod';
            else $bad['df_confirmation_tokens']['REVIEW']='caller-supplied-approval';
            $denied=(new OrderLocalWorkflow())->processBrowser($bad);self::assertWPError($denied,'Misleading signed caller claim denies: '.$claim);
            self::assertStringNotContainsString($sentinel,wp_json_encode($denied));
            global $wpdb;self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])));
        }
        // Denied caller substitutions cannot consume the genuine reviewed acknowledgement.
        $accepted=PrintifyApiApprovalFixture::checked((new OrderLocalWorkflow())->processBrowser($confirm));
        self::assertSame('DRAFT',$accepted['result']['state']);self::assertFalse($accepted['external_execution_authorized']);
    }
}
