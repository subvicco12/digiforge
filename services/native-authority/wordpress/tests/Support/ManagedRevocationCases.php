<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\NativeAuthority\WordPress\OfflineSchema;
use DigiForge\Portal\OrderLocalWorkflow;
use DigiForge\PortalAccess\{Store,OwnerBootstrap};
use DigiForge\Security\ShopAccessRepository;

/** Actual mutable writers, inserted between retained capture and the owned frame. */
trait ManagedRevocationCases
{
    public static function managedRevocationKinds():array
    {
        $kinds=['native_cap','role_option','profile','acl','owner','grantor_native','authority_audit'];
        return array_combine($kinds,array_map(static fn(string $kind):array=>[$kind],$kinds));
    }

    /** @dataProvider managedRevocationKinds */
    public function testManagedCapturedPermissionMutationCannotPrepareAPlan(string $kind):void
    {
        $f=$this->managedApprovedPhoto();
        $confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        global $wpdb;$primary=$wpdb;
        $other=new \wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$other->suppress_errors(true);
        self::assertNotSame($primary->get_var('SELECT CONNECTION_ID()'),$other->get_var('SELECT CONNECTION_ID()'));
        $captured=false;$revoked=false;
        $race=function(string $sql)use($primary,$other,$kind,$f,&$captured,&$revoked):string{
            global $wpdb;
            if(str_starts_with($sql,'INSERT INTO `'.OfflineSchema::leasesTable($primary).'`'))$captured=true;
            if($captured&&!$revoked&&$wpdb===$primary&&$sql==='START TRANSACTION'){
                $revoked=true;$wpdb=$other;
                try{$this->managedRevokeUsingActualWriter($kind,$f['reviewer'],$other);}
                finally{$wpdb=$primary;}
            }
            return $sql;
        };
        add_filter('query',$race);
        try{
            $result=(new OrderLocalWorkflow())->processBrowser($confirm);
            self::assertTrue($captured,'The real reviewer lease must have been captured.');
            self::assertTrue($revoked,'The independent writer must run before the original START.');
            self::assertWPError($result);
            self::assertNotSame('COMMITTED',$result->get_error_data()['transaction_outcome']??null);
            self::assertNotSame(true,$result->get_error_data()['committed']??null);
            self::assertSame('0',(string)$primary->get_var('SELECT @@session.in_transaction'));
            self::assertSame('0',(string)$primary->get_var($primary->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])));
            self::assertWPError((new OrderLocalWorkflow())->processBrowser($confirm),'A rejected original decision cannot silently become a private successful replay.');
            self::assertSame('0',(string)$primary->get_var($primary->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])));
            self::assertSame([],\WP_Session_Tokens::get_instance($f['reviewer'])->get_all());
        }finally{
            remove_filter('query',$race);$wpdb=$primary;$primary->query('ROLLBACK');$other->close();
        }
    }

    private function managedRevokeUsingActualWriter(string $kind,int $reviewer,\wpdb $other):void
    {
        if($kind==='native_cap'){(new \WP_User($reviewer))->add_cap('manage_digiforge_orders',false);return;}
        if($kind==='role_option'){get_role('administrator')->remove_cap('manage_digiforge_orders');return;}
        if($kind==='grantor_native'){(new \WP_User(1))->add_cap('manage_digiforge_settings',false);return;}
        if($kind==='profile'){
            $profile=Store::read($reviewer);self::assertIsArray($profile);
            self::assertIsArray(Store::change($reviewer,'Operator',['personalized_pod'=>['orders','personalized_pod']],false,$profile['revision'],wp_generate_uuid4()));return;
        }
        if($kind==='acl'){
            $repo=new ShopAccessRepository();$state=$repo->read('personalized_pod');self::assertIsArray($state);$id=null;
            foreach($state['grants']as $key=>$grant)if($grant['actor_id']===$reviewer&&!$grant['revoked']){$id=$key;break;}
            self::assertIsString($id);self::assertIsArray($repo->revoke('personalized_pod',$id,wp_generate_uuid4(),$state['revision']));return;
        }
        if($kind==='owner'){self::assertIsArray(OwnerBootstrap::revoke(wp_generate_uuid4()));return;}
        self::assertSame('authority_audit',$kind);
        self::assertSame(1,$other->delete(Tables::audit_log(),['object_type'=>'portal_auth','event_type'=>'portal_global_owner_claimed'],['%s','%s']));
    }
}
