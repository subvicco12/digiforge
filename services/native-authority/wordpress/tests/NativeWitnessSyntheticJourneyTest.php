<?php
declare(strict_types=1);

require_once dirname(__DIR__,4).'/tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
require_once dirname(__DIR__,4).'/tests/support/NativePrintifyContractFixture.php';

use DigiForge\Connector\Printify\{CredentialReference, NativeAuthorityAdapter, NativeBridge, NativeHeldJournal, NativeHeldJournalSchema, NativePortContract, ShopBinding};

final class NativeWitnessSyntheticJourneyTest extends WP_UnitTestCase
{
    public function testDisposableWordPressReceiptAllowsOneSyntheticSenderAttemptThatStaysUnknown(): void
    {
        global $wpdb;
        remove_filter('query',[$this,'_create_temporary_tables']);
        remove_filter('query',[$this,'_drop_temporary_tables']);
        $fixture=new PrintifyApiApprovalFixture();
        $fixture->boot();
        $fixture->setting('stop_all',true);
        $fixture->setting('automation_armed',false);
        $fixture->setting('activation_authorized',false);
        $http=0;
        $deny=static function() use (&$http) { ++$http; throw new RuntimeException('WordPress HTTP is disabled in this fixture'); };
        add_filter('pre_http_request',$deny,PHP_INT_MAX);
        $process=null;
        $pipes=[];
        try {
            foreach (NativeHeldJournalSchema::statements() as $sql) {
                self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));
            }
            $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())
                ->verified('personalized_pod',$fixture->integration,'printify.connection.read'));
            $scope=new ShopBinding(2,'personalized_pod',$fixture->integration,$v['external_shop_reference'],$v['environment'],
                $v['review_revision'],new CredentialReference($fixture->integration),$v['binding_hash'],$v['expires_at']);
            $current=PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::scope($scope,1));
            $process=proc_open(['node',dirname(__DIR__,2).'/test/core-journey-fixture.mjs'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process);
            stream_set_timeout($pipes[1],10);
            $exchange=static function(array $body)use($wpdb,$pipes):array {
                self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
                self::assertNotFalse(fwrite($pipes[0],json_encode($body,JSON_THROW_ON_ERROR)."\n"));
                fflush($pipes[0]);
                $line=fgets($pipes[1],131073);
                self::assertIsString($line,'Synthetic fixture response unavailable');
                return json_decode($line,true,24,JSON_THROW_ON_ERROR);
            };
            $setup=$exchange(['action'=>'setup','scope'=>['account'=>$current['scope']['external_account_reference'],
                'integration'=>$scope->integrationId,'providerShop'=>$scope->providerShopId]]);
            $intent=$setup['intent'];
            $binding=array_replace(NativePrintifyContractFixture::binding(),array_intersect_key($intent,
                array_flip(['operation_id','payload_fingerprint','approval_reference','product_version','expected_product_version'])),[
                'intent_fingerprint'=>NativePortContract::hash($intent),'scope_fingerprint'=>$scope->fingerprint(),
                'provider_shop_id'=>$scope->providerShopId,'integration_id'=>$scope->integrationId,
                'credential_reference'=>$scope->integrationId,'authorization_revision'=>$scope->revision,'environment'=>'test',
            ]);
            $journal=new NativeHeldJournal();
            $key=hash('sha256','synthetic-order:'.$binding['operation_id']);
            $staged=PrintifyApiApprovalFixture::checked($journal->prepareWitnessCorrelation($binding,$key,NativePortContract::hash($intent)));
            $proof=$exchange(['action'=>'reserve','correlation'=>$staged['record']['witness_correlation']]);
            $receipt=PrintifyApiApprovalFixture::checked($journal->bindWitnessReceipt(
                $binding,$key,$staged['event_hash'],$intent,$proof['raw'],$proof['pins']));
            self::assertSame('HELD',$receipt['record']['state']);
            self::assertFalse($receipt['record']['dispatch_permitted']);
            $command=['action'=>'send','core_head'=>$receipt['event_hash'],'receipt'=>$receipt['record']['witness_receipt']];
            $rejected=$exchange($command+['reject'=>true]);
            self::assertSame([503],$rejected['statuses']);
            self::assertSame('ADMITTED',$rejected['state']);
            self::assertSame(0,$rejected['requests']);
            $result=$exchange($command);
            self::assertSame([200,503],$result['statuses']);
            self::assertSame('UNKNOWN',$result['state']);
            self::assertSame(1,$result['requests']);
            $duplicate=$exchange($command);
            self::assertSame([503,503],$duplicate['statuses']);
            self::assertSame('UNKNOWN',$duplicate['state']);
            self::assertSame(1,$duplicate['requests']);
            $readback=$exchange(['action'=>'read']);
            $unknown=PrintifyApiApprovalFixture::checked($journal->bindWitnessReceipt(
                $binding,$key,$receipt['event_hash'],$intent,$readback['raw'],$proof['pins']));
            self::assertSame('UNKNOWN',$unknown['record']['state']);
            self::assertSame(3,$unknown['record']['revision']);
            self::assertFalse($unknown['record']['dispatch_permitted']);
            self::assertWPError($journal->bindWitnessReceipt($binding,$key,$receipt['event_hash'],$intent,$readback['raw'],$proof['pins']));
            $port=PrintifyApiApprovalFixture::checked(NativeBridge::connect('1.0.0'));
            $request=NativePrintifyContractFixture::mutation('RECEIPT_READ');
            $request['binding']=$binding;
            $request['context']=array_replace($request['context'],array_intersect_key($binding,array_flip([
                'actor_id','authorization_revision','approval_reference','product_version','expected_product_version',
            ])));
            $request['duplicate_key']=$key;
            $request['data']=['receipt_reference'=>$unknown['event_hash']];
            $observed=$port->exchange($request);
            self::assertWPError($observed);
            self::assertSame('printify_native_held_outcome_unknown',$observed->get_error_code());
            self::assertFalse($observed->get_error_data()['retry_permitted']);
            $stop=$exchange(['action'=>'stop']);
            self::assertSame('PENDING',$stop['disposition']);
            self::assertSame(1,$stop['requests']);
            self::assertSame($unknown,$journal->read($binding['operation_id'],$binding['shop_key'],1));
            self::assertSame(0,$http);
            fclose($pipes[0]);unset($pipes[0]);
            self::assertSame(0,proc_close($process));$process=null;
        } finally {
            if (is_resource($process)) {
                if (isset($pipes[0])) fclose($pipes[0]);
                proc_terminate($process);
                proc_close($process);
            }
            remove_filter('pre_http_request',$deny,PHP_INT_MAX);
            $fixture->restore();
        }
    }
}
