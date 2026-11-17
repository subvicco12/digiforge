<?php
declare(strict_types=1);

/** Actual managed fixtures; this trait adds cases to the existing composition class only. */
trait ManagedCallerFrameCases
{
    public static function managedCallerFrameConsumers():array
    {
        return ['operator'=>['operator'],'queue'=>['queue']];
    }

    /** @dataProvider managedCallerFrameConsumers */
    public function testManagedConsumerDenialPreservesExistingCallerFrameAndPendingBusinessWrite(string $consumer):void
    {
        [$f,$invoke]=$this->managedCallerFrameTarget($consumer);
        global $wpdb;$original=$wpdb;$handle=\DigiForge\NativeAuthority\WordPress\Runtime::handle($original);
        self::assertInstanceOf(mysqli::class,$handle);$thread=$handle->thread_id;
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($original->prefix);
        self::assertNotSame((string)$thread,(string)$other->get_var('SELECT CONNECTION_ID()'));
        $table=\DigiForge\Database\Tables::audit_log();$pending=wp_generate_uuid4();$context=(string)wp_json_encode(['managed_caller_pending'=>$pending]);
        $plansSql=$original->prepare('SELECT COUNT(*) FROM '.\DigiForge\Database\Tables::fulfillment_plans().' WHERE order_id=%d',$f['order']);
        $jobsSql='SELECT COUNT(*) FROM '.\DigiForge\Database\Tables::jobs();
        $beforePlans=$original->get_var($plansSql);$beforeJobs=$original->get_var($jobsSql);
        $statements=[];$observe=static function(string $sql)use(&$statements):string{
            $command=strtoupper(trim($sql));if(str_starts_with($command,'START TRANSACTION')||$command==='COMMIT'||$command==='ROLLBACK')$statements[]=$command;return $sql;
        };
        try{
            self::assertNotFalse($original->query('START TRANSACTION'));
            self::assertSame(1,$original->insert($table,['event_type'=>'managed_caller_pending_business','actor_id'=>get_current_user_id(),'object_type'=>'order','object_id'=>$pending,'context'=>$context,'created_at'=>current_time('mysql',true)],['%s','%d','%s','%s','%s','%s']));$id=(int)$original->insert_id;
            add_filter('query',$observe);
            try{$result=$invoke();}finally{remove_filter('query',$observe);}
            self::assertWPError($result);self::assertSame([],$statements,'The denied consumer must neither start nor end a caller-owned frame.');
            if($consumer==='operator')self::assertSame('order_photo_context_caller_transaction',$result->get_error_code());
            else{self::assertSame('NOT_ATTEMPTED',$result->get_error_data()['local_commit_state']);self::assertFalse($result->get_error_data()['local_metadata_committed']);}
            self::assertTrue($GLOBALS['wpdb']===$original,'The consumer must retain the actual caller database object.');
            self::assertTrue(\DigiForge\NativeAuthority\WordPress\Runtime::physical($original,$handle,$thread));
            self::assertSame((string)$thread,(string)$original->get_var('SELECT CONNECTION_ID()'));self::assertSame('1',(string)$original->get_var('SELECT @@session.in_transaction'));
            self::assertSame($context,$original->get_var($original->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)));
            self::assertNull($other->get_var($other->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)),'A genuinely pending write must be invisible on the independent connection.');
            self::assertSame($beforePlans,$original->get_var($plansSql));self::assertSame($beforeJobs,$original->get_var($jobsSql));
            self::assertNotFalse($original->query('ROLLBACK'));
            self::assertNull($other->get_var($other->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)));
            // Explicitly retry only after this test rolls back its untouched caller frame.
            // The same real confirmation/key must work in the valid autocommit environment.
            $after=$invoke();self::assertNotWPError($after,is_wp_error($after)?$after->get_error_code():'');
            self::assertSame($consumer==='operator'?'DRAFT':'BLOCKED',$consumer==='operator'?$after['result']['state']:$after['job']['state']);
            self::assertFalse($after['external_execution_authorized']);
        }finally{remove_filter('query',$observe);$GLOBALS['wpdb']=$original;$original->query('ROLLBACK');$other->close();}
    }

    public function testManagedContextPhysicalReconnectDenialNeverResetsTheNewCallerFrame():void
    {
        $f=$this->managedApprovedPhoto();$context=\DigiForge\Orders\PersonalizationReadContext::capture('personalized_pod',$f['order']);self::assertNotWPError($context);
        global $wpdb;$original=$wpdb;$old=\DigiForge\NativeAuthority\WordPress\Runtime::handle($original);self::assertInstanceOf(mysqli::class,$old);$oldThread=$old->thread_id;
        self::assertTrue($original->close());self::assertTrue($original->db_connect());
        $current=\DigiForge\NativeAuthority\WordPress\Runtime::handle($original);self::assertInstanceOf(mysqli::class,$current);self::assertTrue($old!==$current);self::assertNotSame($oldThread,$current->thread_id);$thread=$current->thread_id;
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($original->prefix);
        $table=\DigiForge\Database\Tables::audit_log();$marker=wp_generate_uuid4();$payload=(string)wp_json_encode(['managed_reconnected_caller'=>$marker]);
        $statements=[];$observe=static function(string $sql)use(&$statements):string{$command=strtoupper(trim($sql));if(str_starts_with($command,'START TRANSACTION')||$command==='COMMIT'||$command==='ROLLBACK')$statements[]=$command;return $sql;};
        try{
            self::assertNotFalse($original->query('START TRANSACTION'));
            self::assertSame(1,$original->insert($table,['event_type'=>'managed_reconnected_pending_business','actor_id'=>get_current_user_id(),'object_type'=>'order','object_id'=>$marker,'context'=>$payload,'created_at'=>current_time('mysql',true)],['%s','%d','%s','%s','%s','%s']));$id=(int)$original->insert_id;
            add_filter('query',$observe);
            try{self::assertWPError($context->forOrder($f['order']));self::assertWPError($context->validate());self::assertWPError($context->release(false));self::assertWPError($context->finalAdmission(true));}finally{remove_filter('query',$observe);}
            self::assertSame([],$statements);self::assertTrue($GLOBALS['wpdb']===$original);self::assertTrue(\DigiForge\NativeAuthority\WordPress\Runtime::physical($original,$current,$thread));
            self::assertSame('1',(string)$original->get_var('SELECT @@session.in_transaction'));self::assertSame($payload,$original->get_var($original->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)));self::assertNull($other->get_var($other->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)));
            self::assertNotFalse($original->query('ROLLBACK'));self::assertNull($other->get_var($other->prepare('SELECT context FROM '.$table.' WHERE id=%d',$id)));
            $fresh=\DigiForge\Orders\PersonalizationReadContext::capture('personalized_pod',$f['order']);self::assertNotWPError($fresh);self::assertNotWPError($fresh->forOrder($f['order']));self::assertTrue($fresh->release(false));
        }finally{remove_filter('query',$observe);$GLOBALS['wpdb']=$original;$original->query('ROLLBACK');$other->close();}
    }

    /** Uses the existing class's genuine approved photo/helper; no duplicate fixture or inherited tests. */
    private function managedCallerFrameTarget(string $consumer):array
    {
        $f=$this->managedApprovedPhoto();
        if($consumer==='operator'){
            $confirm=$this->confirmation(\DigiForge\Portal\OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
            return [$f,static fn()=>(new \DigiForge\Portal\OrderLocalWorkflow())->processBrowser($confirm)];
        }
        $repo=new \DigiForge\Orders\Repository();$plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$f['order'],'plan_version'=>'managed-caller-frame','provider'=>'printify','shipping_method_metadata'=>['method'=>'standard'],'cost_snapshot_metadata'=>['base_production_cost'=>10,'shipping_estimate'=>4]]));
        foreach(['REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));
        $intent=PrintifyApiApprovalFixture::checked($repo->createIntent(['order_id'=>$f['order'],'fulfillment_plan_id'=>(int)$plan['id'],'intent_type'=>'PREPARE_FULFILLMENT']));
        $prepared=PrintifyApiApprovalFixture::checked((new \DigiForge\Orders\HeldFulfillmentPreparation())->prepare('personalized_pod',$f['order'],(int)$intent['id']));
        $key=\DigiForge\Queue\Shop2HeldJobRecovery::enqueueKey('personalized_pod',$f['order'],(int)$intent['id'],$prepared['plan_payload_hash'],$prepared['current_evidence_hash'],get_current_user_id());
        return [$f,static fn()=>(new \DigiForge\Queue\Shop2HeldJobRecovery())->enqueue('personalized_pod',$f['order'],(int)$intent['id'],$prepared['current_evidence_hash'],$key)];
    }
}
