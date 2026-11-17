<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Orders\{PersonalizationReadContext,Repository};
use DigiForge\NativeAuthority\WordPress\{OfflineSchema,Runtime};

/** Real stock native-session expiry; no clock filter or synthetic authority result. */
trait ManagedExpiryCases
{
    private function awaitManagedNativeExpiry(int $expires):void {
        while(time()<$expires)usleep(5000); // At most two seconds of deliberate clock waiting.
    }
    public function testManagedNaturalExpiryAtPrepinPrecommitAndPostcommitAcknowledgement():void {
        $f=$this->managedApprovedPhoto();global $wpdb;
        $manager=WP_Session_Tokens::get_instance(1);$token=wp_get_session_token();$session=$manager->get($token);
        self::assertIsArray($session);self::assertTrue($manager->verify($token));
        foreach(['before_pin','precommit','postcommit_ack']as $phase){
            $context=null;$plan=null;$expires=time()+2;
            // Actual session fixture writer, preserving the genuine authenticated actor/proof.
            $manager->update($token,array_replace($session,['expiration'=>$expires]));
            try{
                $context=PersonalizationReadContext::capture('personalized_pod',$f['order']);self::assertNotWPError($context,'Actual managed authority must be live before clock expiry: '.$phase);
                self::assertTrue($context->validateBeforeTransaction());
                if($phase!=='before_pin'){
                    self::assertNotFalse($wpdb->query('START TRANSACTION'));
                    $plan=(new Repository($context))->createPlan(['order_id'=>$f['order'],'plan_version'=>'managed-natural-expiry-'.$phase,'provider'=>'printify']);
                    self::assertNotWPError($plan);self::assertTrue($context->validate(),'The chosen precommit linearization point is valid before expiry.');
                    if($phase==='postcommit_ack'){self::assertNotFalse($wpdb->query('COMMIT'));self::assertTrue($context->release(true));}
                }
                $generation=Runtime::generation($wpdb);self::assertIsString($generation);$mutations=[];
                $observe=static function(string $sql)use(&$mutations,$wpdb):string {
                    if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql))foreach([$wpdb->users,$wpdb->usermeta,$wpdb->options,Tables::audit_log()]as $table)if(str_contains($sql,$table)){$mutations[]=$table;break;}
                    return $sql;
                };
                add_filter('query',$observe);
                try{
                    $this->awaitManagedNativeExpiry($expires);
                    $denied=match($phase){'before_pin'=>$context->validateBeforeTransaction(),'precommit'=>$context->validate(),default=>$context->finalAdmission(true)};
                    self::assertWPError($denied,'Natural expiry must deny the actual managed boundary: '.$phase);
                    self::assertSame([],$mutations,'No authority DML supplies the expiry invalidation.');
                    self::assertSame($generation,Runtime::generation($wpdb),'Permission generation remains unchanged while the clock expires.');
                }finally{remove_filter('query',$observe);}
                if($phase==='precommit'){
                    self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'),'Expiry validation does not end the caller frame.');
                    self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE id=%d',$plan['id'])));
                    self::assertNotFalse($wpdb->query('ROLLBACK'));self::assertTrue($context->release(false));
                    self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE id=%d',$plan['id'])));
                }elseif($phase==='postcommit_ack'){
                    self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
                    self::assertSame('DRAFT',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::fulfillment_plans().' WHERE id=%d',$plan['id'])),'An expired private acknowledgement does not pretend to undo the known committed local plan.');
                }else self::assertTrue($context->release(false));
            }finally{
                $wpdb->query('ROLLBACK');if($context instanceof PersonalizationReadContext)$context->release(false);
                $manager->update($token,$session); // Restore only this disposable test's genuine session.
            }
        }
        self::assertSame([],WP_Session_Tokens::get_instance($f['reviewer'])->get_all());self::assertSame(1,get_current_user_id());
    }
}
