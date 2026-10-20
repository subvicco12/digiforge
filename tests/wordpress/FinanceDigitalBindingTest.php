<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Finance\DigitalSourceBinding;
use DigiForge\Finance\DigitalDeclarationReferences;
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once dirname(__DIR__).'/unit/FinanceDeclarationFixtures.php';

/** Synthetic local declarations only; no provider calls or ownership certification. */
final class FinanceDigitalBindingTest extends WP_UnitTestCase
{
    private function fixture():array
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        global $wpdb;$repo=new DigiForge\Integrations\Repository();
        // Activator DDL can commit outer native-test transactions. Delete only this
        // fixture's named synthetic connectors, including its duplicate-claim case.
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE connection_key LIKE %s OR (provider='etsy' AND environment='test' AND connection_key LIKE %s AND JSON_UNQUOTE(JSON_EXTRACT(config,'$._connection_test.details.user_id'))='123456' AND JSON_UNQUOTE(JSON_EXTRACT(config,'$._connection_test.details.shop_id'))='67757764')",$wpdb->esc_like('synthetic-digital-').'%',$wpdb->esc_like('duplicate-').'%'));
        foreach($ids as$id)self::assertTrue($repo->delete((int)$id));
        $actors=ReviewedLocalFixture::actors('digital');$now=current_time('mysql',true);
        $insert=static function(string $table,array $data)use($wpdb):int{self::assertSame(1,$wpdb->insert($table,$data));return (int)$wpdb->insert_id;};
        $business=$insert(Tables::businesses(),['business_key'=>'digital-'.wp_generate_uuid4(),'status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);
        $store=$insert(Tables::stores(),['business_id'=>$business,'store_key'=>'arbitrary-'.wp_generate_uuid4(),'channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);
        $repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'etsy','environment'=>'test','connection_key'=>'synthetic-digital-'.wp_generate_uuid4(),'status'=>'CONFIGURED','config'=>[]]);self::assertNotWPError($integration);
        $id=$integration['id'];self::assertTrue($repo->storeSecret($id,'access_token','synthetic-only-digital-binding'));
        $version=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($repo->secretMetadata($id));
        $config=['_connection_test'=>['ok'=>true,'credential_evidence_version'=>$version,'details'=>['user_id'=>123456,'shop_id'=>67757764,'shop_name'=>'KinetiqMatrixDesigns']]];
        self::assertNotWPError($repo->update($id,['config'=>$config]));
        $opportunity=$insert(Tables::opportunities(),['title'=>'Synthetic digital','created_at'=>$now,'updated_at'=>$now]);
        $insert(Tables::research_candidates(),['title'=>'Synthetic digital','canonical_title'=>'synthetic digital','fingerprint'=>hash('sha256',wp_generate_uuid4()),'score_version'=>'synthetic','score_inputs'=>wp_json_encode(['shop_key'=>'digital']),'opportunity_id'=>$opportunity,'created_at'=>$now,'updated_at'=>$now]);
        $family=$insert(Tables::product_families(),['opportunity_id'=>$opportunity,'name'=>'Synthetic','created_at'=>$now,'updated_at'=>$now]);
        $product=$insert(Tables::products(),['product_family_id'=>$family,'name'=>'Synthetic','created_at'=>$now,'updated_at'=>$now]);
        $productVersion=$insert(Tables::product_versions(),['product_id'=>$product,'version_label'=>'synthetic','created_at'=>$now,'updated_at'=>$now]);
        return ['actors'=>$actors,'input'=>['version'=>1,'business_id'=>$business,'store_id'=>$store,'integration_id'=>$id,'environment'=>'test','source_sha256'=>str_repeat('a',64),'evidence_reference'=>'local://synthetic/digital-binding','observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-10)],'product_id'=>$product,'product_version_id'=>$productVersion,'opportunity_id'=>$opportunity];
    }
    private function approve(array $fixture,?int $expires=null):array
    {
        $service=new DigitalSourceBinding();$i=$fixture['input'];
        $pending=$service->propose($i,time()-5,$expires??time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($pending);
        wp_set_current_user($fixture['actors']['reviewer']);
        $approved=$service->decide($i['business_id'],$i['store_id'],$i['environment'],$pending['revision'],$pending['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($approved);
        wp_set_current_user($fixture['actors']['author']);return $approved;
    }
    public function testExactReviewedDigitalBindingHasNoPodOrExternalAuthority():void
    {
        self::assertTrue(class_exists(DigitalSourceBinding::class),'Typed non-POD Digital binding is missing.');
        $f=$this->fixture();$i=$f['input'];$service=new DigitalSourceBinding();self::assertWPError($service->current($i['business_id'],$i['store_id'],'test'));
        $approved=$this->approve($f);$current=$service->current($i['business_id'],$i['store_id'],'test');self::assertNotWPError($current);
        self::assertSame($approved['proposal_hash'],$current['ownership_hash']);self::assertSame($approved['revision'],$current['ownership_revision']);
        self::assertFalse($current['provider_identity_verified']);self::assertFalse($current['external_execution_authorized']);self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertStringNotContainsString('synthetic-only-digital-binding',wp_json_encode($current));
        $declaration=['provider'=>'etsy','environment'=>'test','scope'=>['shop_key'=>'digital','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],'product_version_id'=>$f['product_version_id'],'provider_mapping_id'=>0,'variant_ref'=>'','listing_id'=>0,'order_id'=>0]];
        $references=(new DigitalDeclarationReferences())->validate($declaration);self::assertNotWPError($references);self::assertSame(0,$references['ownership_id']);self::assertSame($f['product_id'],$references['product_id']);
        foreach([['shop_key'=>'personalized_pod'],['provider_mapping_id'=>1],['variant_ref'=>'foreign'],['store_id'=>$i['store_id']+999]]as$change)self::assertWPError((new DigitalDeclarationReferences())->validate(array_replace_recursive($declaration,['scope'=>$change])));
        global $wpdb;$wpdb->update(Tables::research_candidates(),['score_inputs'=>wp_json_encode(['shop_key'=>'personalized_pod'])],['opportunity_id'=>$f['opportunity_id']]);self::assertWPError((new DigitalDeclarationReferences())->validate($declaration));
    }
    /** @dataProvider driftCases */
    public function testMissingAmbiguousAndDriftedEvidenceDenies(string $change):void
    {
        $f=$this->fixture();$this->approve($f);$i=$f['input'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->find($i['integration_id']);global$wpdb;
        if($change==='material')$repo->storeSecret($i['integration_id'],'access_token','changed-synthetic-only');
        elseif($change==='store')$wpdb->update(Tables::stores(),['status'=>'INACTIVE'],['id'=>$i['store_id']]);
        elseif($change==='business')$wpdb->update(Tables::businesses(),['status'=>'INACTIVE'],['id'=>$i['business_id']]);
        elseif($change==='foreign_store')$wpdb->update(Tables::stores(),['business_id'=>$i['business_id']+999],['id'=>$i['store_id']]);
        elseif($change==='channel')$wpdb->update(Tables::stores(),['channel'=>'foreign'],['id'=>$i['store_id']]);
        elseif($change==='missing')$wpdb->delete(Tables::integrations(),['id'=>$i['integration_id']]);
        elseif($change==='environment')$wpdb->update(Tables::integrations(),['environment'=>'sandbox'],['id'=>$i['integration_id']]);
        elseif($change==='site')update_option('siteurl','http://synthetic-other.invalid');
        elseif($change==='duplicate'){$other=$repo->create(['provider'=>'etsy','environment'=>'test','connection_key'=>'synthetic-digital-duplicate-'.wp_generate_uuid4(),'status'=>'CONFIGURED','config'=>[]]);self::assertNotWPError($other);self::assertTrue($repo->storeSecret($other['id'],'access_token','synthetic-duplicate'));$c=$integration['config'];$c['_connection_test']['credential_evidence_version']=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($repo->secretMetadata($other['id']));self::assertNotWPError($repo->update($other['id'],['config'=>$c]));}
        elseif($change==='restored_identity'){$c=$integration['config'];$c['_connection_test']['details']['user_id']=999;self::assertNotWPError($repo->update($i['integration_id'],['config'=>$c]));self::assertNotWPError($repo->update($i['integration_id'],['config'=>$integration['config']]));}
        else{$c=$integration['config'];$c['_connection_test']['details'][$change]=$change==='user_id'?999:68031896;$repo->update($i['integration_id'],['config'=>$c]);}
        self::assertWPError((new DigitalSourceBinding())->current($i['business_id'],$i['store_id'],'test'));
    }
    public static function driftCases():array{return array_map(static fn(string$x):array=>[$x],['material','store','business','foreign_store','channel','missing','environment','site','duplicate','restored_identity','user_id','shop_id']);}
    public function testRevokedExpiredAndLostAssignmentsDeny():void
    {
        $f=$this->fixture();$a=$this->approve($f);$i=$f['input'];$service=new DigitalSourceBinding();wp_set_current_user($f['actors']['reviewer']);
        self::assertNotWPError($service->decide($i['business_id'],$i['store_id'],'test',$a['revision'],$a['proposal_hash'],'REVOKED',wp_generate_uuid4()));wp_set_current_user($f['actors']['author']);self::assertWPError($service->current($i['business_id'],$i['store_id'],'test'));
        $f=$this->fixture();$this->approve($f,time()+2);usleep(2100000);self::assertWPError($service->current($f['input']['business_id'],$f['input']['store_id'],'test'));
        $f=$this->fixture();$this->approve($f);ShopAccessFixture::reset();self::assertWPError($service->current($f['input']['business_id'],$f['input']['store_id'],'test'));
    }
    public function testDatabaseFailuresDenyWithoutChangingReviewedEvidence():void
    {
        $f=$this->fixture();$a=$this->approve($f);$i=$f['input'];$service=new DigitalSourceBinding();global$wpdb;
        foreach([Tables::integrations(),Tables::stores(),Tables::audit_log()]as$table){
            $fault=static fn(string$sql):string=>str_contains($sql,'FROM '.$table)?'SELECT FROM /* synthetic Digital evidence fault */':$sql;
            $old=$wpdb->suppress_errors(true);add_filter('query',$fault);
            try{self::assertWPError($service->current($i['business_id'],$i['store_id'],'test'));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($old);}
            $current=$service->current($i['business_id'],$i['store_id'],'test');self::assertNotWPError($current);self::assertSame($a['proposal_hash'],$current['ownership_hash']);
        }
    }
    public function testGenericSignedPayloadAndFutureObservationCannotSubstituteTypedBinding():void
    {
        $f=$this->fixture();$a=$this->approve($f);$i=$f['input'];$key=hash('sha256',DigiForge\Listings\Validator::canonicalJson(['DF-OWN-001','finance-source-binding-v1','digital','etsy','test']));
        foreach([['version'=>999],array_replace($a['payload'],['observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)])]as$payload){
            wp_set_current_user($f['actors']['author']);$p=DigiForge\Security\ReviewedLocalEvidence::propose('ownership','digital',$key,$payload,time()-5,time()+3600,wp_generate_uuid4(),$a['revision']);self::assertNotWPError($p);
            wp_set_current_user($f['actors']['reviewer']);$a=DigiForge\Security\ReviewedLocalEvidence::decide('ownership','digital',$key,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($a);
            wp_set_current_user($f['actors']['author']);self::assertWPError((new DigitalSourceBinding())->current($i['business_id'],$i['store_id'],'test'));
        }
    }
    public function testSelfStaleAndDriftedApprovalCannotEstablishBinding():void
    {
        $f=$this->fixture();$i=$f['input'];$service=new DigitalSourceBinding();$p=$service->propose($i,time()-5,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($p);
        self::assertWPError($service->decide($i['business_id'],$i['store_id'],'test',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));
        wp_set_current_user($f['actors']['reviewer']);self::assertWPError($service->decide($i['business_id'],$i['store_id'],'test',$p['revision']+1,$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));
        $repo=new DigiForge\Integrations\Repository();$c=$repo->find($i['integration_id'])['config'];$c['_connection_test']['details']['user_id']=999;self::assertNotWPError($repo->update($i['integration_id'],['config'=>$c]));
        self::assertWPError($service->decide($i['business_id'],$i['store_id'],'test',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));
    }
    public function testRepositoryPinsDigitalReviewAndNewReviewCannotReviveOldLedgerEvidence():void
    {
        $f=$this->fixture();$a=$this->approve($f);$i=$f['input'];global$wpdb;$table=Tables::finance_ledger();$temporary=$table.'_digital_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$temporary.' LIKE '.$table));$filter=static fn(string$sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$filter,99);
        try{
            $input=DigiForge\Tests\FinanceDeclarationFixtures::input(['provider'=>'etsy','scope'=>['shop_key'=>'digital','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],'product_version_id'=>$f['product_version_id'],'provider_mapping_id'=>0,'variant_ref'=>'','order_id'=>0,'listing_id'=>0]]);
            $repo=new DigiForge\Finance\Repository();$row=$repo->importDeclaration($input);self::assertNotWPError($row);self::assertIsArray($row);self::assertNotWPError($repo->declarationEvidence($row));
            $metadata=json_decode($row['metadata'],true);self::assertSame($a['proposal_hash'],$metadata['ownership_hash']);self::assertSame($a['revision'],$metadata['ownership_revision']);self::assertSame('UNRECONCILED',$row['reconciliation_state']);
            $service=new DigitalSourceBinding();wp_set_current_user($f['actors']['reviewer']);$revoked=$service->decide($i['business_id'],$i['store_id'],'test',$a['revision'],$a['proposal_hash'],'REVOKED',wp_generate_uuid4());self::assertNotWPError($revoked);
            wp_set_current_user($f['actors']['author']);self::assertWPError($repo->declarationEvidence($row));
            $pending=$service->propose($i,time()-5,time()+3600,wp_generate_uuid4(),$revoked['revision']);self::assertNotWPError($pending);wp_set_current_user($f['actors']['reviewer']);$fresh=$service->decide($i['business_id'],$i['store_id'],'test',$pending['revision'],$pending['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($fresh);wp_set_current_user($f['actors']['author']);
            self::assertWPError($repo->declarationEvidence($row));$input['external_transaction_id']='synthetic-digital-fresh-review';$new=$repo->importDeclaration($input);self::assertNotWPError($new);self::assertNotWPError($repo->declarationEvidence($new));self::assertSame($fresh['proposal_hash'],json_decode($new['metadata'],true)['ownership_hash']);
            $bad=$input;$bad['external_transaction_id']='synthetic-digital-missing-fx';$bad['currency']='EUR';$bad['fx_snapshot_id']=PHP_INT_MAX;self::assertWPError($repo->importDeclaration($bad));self::assertSame('2',$wpdb->get_var('SELECT COUNT(*) FROM '.$table));
        }finally{remove_filter('query',$filter,99);$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$temporary);}
    }
    public function testDigitalStoredAllocationUsesExactLineAndCurrentReviewWithoutPodRows():void
    {
        $f=$this->fixture();$a=$this->approve($f);$i=$f['input'];global$wpdb;$now=current_time('mysql',true);
        $insert=static function(string$table,array$data)use($wpdb,$now):int{self::assertSame(1,$wpdb->insert($table,$data+['created_at'=>$now,'updated_at'=>$now]));return(int)$wpdb->insert_id;};
        $listing=$insert(Tables::listings(),['product_version_id'=>$f['product_version_id'],'channel'=>'etsy','environment'=>'test','shop_reference'=>'digital','title'=>'Synthetic Digital allocation']);
        $order=$insert(Tables::orders(),['channel'=>'etsy','environment'=>'test','shop_reference'=>'67757764','currency'=>'USD']);
        $line=$insert(Tables::order_line_items(),['order_id'=>$order,'listing_id'=>$listing,'product_version_id'=>$f['product_version_id'],'provider_mapping_id'=>0,'currency'=>'USD','environment'=>'test']);
        $source=DigiForge\Finance\DeclarationContract::normalize(DigiForge\Tests\FinanceDeclarationFixtures::input(['provider'=>'etsy','scope'=>['shop_key'=>'digital','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],'order_id'=>$order,'product_version_id'=>$f['product_version_id'],'listing_id'=>$listing,'provider_mapping_id'=>0,'variant_ref'=>'']]));
        $target=['shop_key'=>'digital','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],'order_id'=>$order,'order_line_item_id'=>$line,'product_id'=>$f['product_id'],'product_version_id'=>$f['product_version_id'],'listing_id'=>$listing,'provider_mapping_id'=>0,'variant_ref'=>'','environment'=>'test','currency'=>'USD'];
        $build=static fn():array=>DigiForge\Finance\AllocationProposal::build([['ledger_id'=>1,'declaration'=>$source]],$source['declaration_hash'],[['target'=>$target,'amount'=>$source['amount'],'base_amount'=>$source['base_amount']]],new DigiForge\Finance\AllocationStoredTargetReferences());
        $writes=[];$filter=static function(string$sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql))$writes[]=$sql;return$sql;};add_filter('query',$filter);
        try{$references=(new DigitalDeclarationReferences())->validate($source);self::assertNotWPError($references);$packet=$build();self::assertSame($target,$packet['lines'][0]['target']);self::assertSame('PROVISIONAL',$packet['state']);self::assertSame([],$writes);}finally{remove_filter('query',$filter);}
        wp_set_current_user($f['actors']['reviewer']);self::assertNotWPError((new DigitalSourceBinding())->decide($i['business_id'],$i['store_id'],'test',$a['revision'],$a['proposal_hash'],'REVOKED',wp_generate_uuid4()));wp_set_current_user($f['actors']['author']);
        $this->expectException(InvalidArgumentException::class);$build();
    }
    public function testStoreDriftDuringFxLookupInvalidatesDigitalDeclarationReferences():void
    {
        $f=$this->fixture();$this->approve($f);$i=$f['input'];global$wpdb;
        $table=Tables::fx_snapshots();$temporary=$table.'_digital_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$temporary.' LIKE '.$table));
        $isolate=static fn(string$sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$isolate,99);
        try{
            $fx=(new DigiForge\Finance\Repository())->createFx(['environment'=>'test','base_currency'=>'EUR','quote_currency'=>'USD','rate'=>'1.2345678901','as_of_at'=>'2026-10-09 12:00:00',
                'source_metadata'=>['kind'=>'DECLARATION','document_ref'=>'local/synthetic-digital-fx','document_hash'=>str_repeat('b',64)]]);self::assertNotWPError($fx);
            $source=DigiForge\Finance\DeclarationContract::normalize(DigiForge\Tests\FinanceDeclarationFixtures::input(['provider'=>'etsy','currency'=>'EUR','amount'=>'1.0000','base_amount'=>'1.2346','fx_snapshot_id'=>(int)$fx['id'],
                'scope'=>['shop_key'=>'digital','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],'product_version_id'=>$f['product_version_id'],'provider_mapping_id'=>0,'variant_ref'=>'','order_id'=>0,'listing_id'=>0]]));
            self::assertNotWPError((new DigiForge\Finance\DeclarationReferences())->validate($source));
            $fired=false;$drift=static function(string$sql)use(&$fired,$i,$wpdb):string{
                if(!$fired&&str_starts_with($sql,'SELECT * FROM '.Tables::fx_snapshots().' WHERE id=')){
                    $fired=true;self::assertSame(1,$wpdb->update(Tables::stores(),['status'=>'INACTIVE'],['id'=>$i['store_id']]));
                }
                return$sql;
            };
            add_filter('query',$drift,10);
            try{$result=(new DigiForge\Finance\DeclarationReferences())->validate($source);}finally{remove_filter('query',$drift,10);}
            self::assertTrue($fired,'Synthetic store drift must occur inside the common FX lookup.');
            self::assertWPError((new DigitalSourceBinding())->current($i['business_id'],$i['store_id'],'test'));
            self::assertWPError($result,'Digital references must be revalidated after common FX processing.');
        }finally{remove_filter('query',$isolate,99);$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$temporary);}
    }
}
