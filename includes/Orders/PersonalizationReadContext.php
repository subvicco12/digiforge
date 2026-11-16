<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\POD\{CustomerPhotoProducerFence,CustomerPhotoSelection};
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Server-issued retained legacy-NULL dependency, never an execution or transaction permit. */
final class PersonalizationReadContext
{
    private bool $closed=false;
    private bool $poisoned=false;
    private function __construct(
        private CustomerPhotoProducerFence $lease,
        private object $database,
        private int $actor,
        private int $orderId,
        private array $material,
        private array $projection,
        private array $selections
    ) {}
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('A server photo context cannot be serialized.');}
    public function __unserialize(array $data):void {throw new \LogicException('A server photo context cannot be supplied by serialized data.');}

    /** The only issuer: authoritative public A reads on the same fresh autocommit connection. */
    public static function capture(string $shop,int $orderId):self|WP_Error
    {
        if($shop!=='personalized_pod'||$orderId<1)return self::error('scope');
        if(!method_exists(CustomerPhotoProducerFence::class,'validateRetainedReadLease')||!defined(CustomerPhotoProducerFence::class.'::RETAINED_READ_LEASE_CONTRACT')||CustomerPhotoProducerFence::RETAINED_READ_LEASE_CONTRACT!=='shop2-photo-retained-read-lease-v1')return self::error('contract_required');
        global $wpdb;$wpdb->last_error='';$session=$wpdb->get_row('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit',ARRAY_A);if($wpdb->last_error!==''||!is_array($session)||(string)($session['active']??'')!=='0'||(string)($session['autocommit']??'')!=='1')return self::error('caller_transaction');
        $actor=get_current_user_id();foreach(['manage_digiforge_orders','manage_digiforge_pod']as $cap){$admitted=ShopAccess::authorize($shop,$cap,$actor);if(is_wp_error($admitted))return $admitted;}
        $lease=CustomerPhotoProducerFence::acquireRead();if(is_wp_error($lease))return $lease;
        $issued=false;
        try{
            $valid=$lease->validateRetainedReadLease();if(is_wp_error($valid))return $valid;
            $database=$wpdb;
            $material=self::readMaterial($orderId,false);if(is_wp_error($material))return $material;
            $selections=[];
            foreach($material['lines']as $line){
                $selected=(new CustomerPhotoSelection())->resolveForOrder($shop,$orderId,(int)$line['id']);if(is_wp_error($selected))return $selected;
                $selections[(int)$line['id']]=$selected;if($selected===null)continue;
                // Producer provenance is public, but B reviewer-authorization retention is not integrated.
                // Preserve the existing managed transaction denial until that protocol is validated.
                return self::error('managed_review_provenance_required');

            }
            $projection=PersonalizationCurrentness::projectLegacyMaterial($material,false);if(is_wp_error($projection))return $projection;
            $valid=$lease->validate();if(is_wp_error($valid))return $valid;
            $context=new self($lease,$database,$actor,$orderId,$material,$projection,$selections);
            $valid=$context->signedActorCurrent();if(is_wp_error($valid))return $valid;
            $issued=true;return $context;
        }finally{if(!$issued){$released=$lease->release();if(is_wp_error($released))return $released;}}
    }

    /** Only B current locking reads may justify the pinned projection inside a B transaction. */
    public function forOrder(int $orderId,bool $requireEveryLine=true):array|WP_Error
    {
        if($orderId!==$this->orderId)return $this->invalidate(self::error('scope'));
        $valid=$this->validate();if(is_wp_error($valid))return $this->invalidate($valid);
        global $wpdb;$wpdb->last_error='';$active=$wpdb->get_var('SELECT @@session.in_transaction');if($wpdb->last_error!==''||!in_array((string)$active,['0','1'],true))return $this->invalidate(self::error('transaction'));
        if((string)$active==='1'){
            $transaction=\DigiForge\Connector\Printify\NativeSourceTransaction::id();if(is_wp_error($transaction))return $this->invalidate($transaction);
            $valid=self::storage($this->material['storage_tables']);if(is_wp_error($valid))return $this->invalidate($valid);
        }
        $current=self::readMaterial($orderId,(string)$active==='1');if(is_wp_error($current))return $this->invalidate($current);
        if($current!==$this->material)return $this->invalidate(self::error('changed'));
        $projection=PersonalizationCurrentness::projectLegacyMaterial($current,$requireEveryLine);if(is_wp_error($projection))return $this->invalidate($projection);if($projection!==$this->projection)return $this->invalidate(self::error('changed'));
        $valid=$this->validate();if(is_wp_error($valid))return $this->invalidate($valid);
        $valid=$this->signedActorCurrent();if(is_wp_error($valid))return $this->invalidate($valid);
        return $projection;
    }

    /** Validates the retained READ lease only; never invokes an A source getter in caller TX. */
    public function validate():true|WP_Error
    {
        global $wpdb;if($this->closed||$this->poisoned||$wpdb!==$this->database)return $this->invalidate(self::error('invalidated'));
        $valid=$this->lease->validateRetainedReadLease();if(is_wp_error($valid))return $this->invalidate($valid);
        $actor=$this->actorCurrent();return is_wp_error($actor)?$this->invalidate($actor):$actor;
    }
    /** The producer's strict autocommit validation still applies immediately before B starts its frame. */
    public function validateBeforeTransaction():true|WP_Error
    {
        global $wpdb;if($this->closed||$this->poisoned||$wpdb!==$this->database)return $this->invalidate(self::error('invalidated'));$valid=$this->lease->validate();if(is_wp_error($valid))return $this->invalidate($valid);$actor=$this->signedActorCurrent();return is_wp_error($actor)?$this->invalidate($actor):$actor;
    }
    /** Current B reads only, under the caller's verified existing transaction. */
    public function currentReadSuffix():string|WP_Error
    {
        $valid=$this->validate();if(is_wp_error($valid))return $valid;global $wpdb;$wpdb->last_error='';$active=$wpdb->get_var('SELECT @@session.in_transaction');if($wpdb->last_error!==''||!in_array((string)$active,['0','1'],true))return $this->invalidate(self::error('transaction'));if((string)$active==='0')return '';
        $transaction=\DigiForge\Connector\Printify\NativeSourceTransaction::id();return is_wp_error($transaction)?$this->invalidate($transaction):' FOR UPDATE';
    }
    public function release():true|WP_Error
    {
        if($this->closed)return true;$this->closed=true;return $this->lease->release();
    }
    public function __destruct(){if(!$this->closed)$this->lease->release();}

    // Preserve the existing signed admission predicates; this is not a claim that
    // arbitrary external authorization writers are locked or linearizable.
    private function signedActorCurrent():true|WP_Error
    {
        foreach(['manage_digiforge_orders','manage_digiforge_pod']as $cap){$valid=ShopAccess::authorize('personalized_pod',$cap,$this->actor);if(is_wp_error($valid))return $valid;}return $this->actorCurrent();
    }
    private function invalidate(WP_Error $failure):WP_Error {$this->poisoned=true;return $failure;}
    private function actorCurrent():true|WP_Error
    {
        if($this->actor<1||!is_user_logged_in()||get_current_user_id()!==$this->actor||!current_user_can('manage_digiforge_orders')||!current_user_can('manage_digiforge_pod'))return self::error('actor');
        if(get_current_user_id()!==$this->actor||!is_user_logged_in())return self::error('actor');return true;
    }
    private static function readMaterial(int $orderId,bool $lock):array|WP_Error
    {
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';$used=[];
        $read=static function(string $table,string $where,array $values=[])use($wpdb,$suffix,&$used):array|WP_Error {$used[$table]=true;$sql='SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id LIMIT 101'.$suffix;return self::rows($values==[]?$sql:$wpdb->prepare($sql,...$values));};
        $used[Tables::orders()]=true;$orders=self::rows($wpdb->prepare('SELECT o.* FROM '.Tables::orders().' o WHERE o.id=%d AND '.EtsyStoredShopScope::orderPredicate('personalized_pod','o').$suffix,$orderId));if(is_wp_error($orders))return $orders;if(count($orders)!==1)return self::error('scope');
        // The workflow separately checks these exact CAS effects; all other order fields stay pinned.
        $order=array_diff_key($orders[0],array_flip(['state','approved_by','approved_at','updated_at']));$graph=[];
        if($order['shop_reference']!=='personalized_pod'){
            $integrations=$read(Tables::integrations(),'BINARY provider=BINARY %s AND BINARY environment=BINARY %s',['etsy',$order['environment']]);if(is_wp_error($integrations))return $integrations;$graph['scope_integrations']=$integrations;
            foreach($integrations as $integration){$used[Tables::integration_secrets()]=true;$metadata=self::rows($wpdb->prepare('SELECT id,integration_id,secret_name,fingerprint FROM '.Tables::integration_secrets().' WHERE integration_id=%d ORDER BY id LIMIT 101'.$suffix,(int)$integration['id']));if(is_wp_error($metadata))return $metadata;$graph['scope_credential_metadata'][(int)$integration['id']]=$metadata;}
        }
        $lines=$read(Tables::order_line_items(),'order_id=%d',[$orderId]);if(is_wp_error($lines))return $lines;if($lines===[])return self::error('missing');
        $schemaIds=[];$mappingIds=[];$versionIds=[];$listingIds=[];
        foreach($lines as $line){
            $rows=$read(Tables::personalization_submissions(),'order_line_item_id=%d',[(int)$line['id']]);if(is_wp_error($rows))return $rows;$graph['submissions'][(int)$line['id']]=$rows;
            foreach($rows as $row)$schemaIds[(int)$row['personalization_schema_id']]=true;
            $versionIds[(int)$line['product_version_id']]=true;if((int)$line['provider_mapping_id']>0)$mappingIds[(int)$line['provider_mapping_id']]=true;if((int)$line['listing_id']>0)$listingIds[(int)$line['listing_id']]=true;
        }
        $digital=false;ksort($versionIds);
        foreach(array_keys($versionIds)as $id){
            $rows=$read(Tables::product_versions(),'id=%d',[$id]);if(is_wp_error($rows))return $rows;if(count($rows)!==1)return self::error('relationship');$graph['versions'][$id]=$rows;
            $rows=$read(Tables::personalization_schemas(),'product_version_id=%d',[$id]);if(is_wp_error($rows))return $rows;$graph['schema_ranges'][$id]=$rows;
            $rows=$read(Tables::digital_products(),'product_version_id=%d',[$id]);if(is_wp_error($rows))return $rows;$graph['digital_classification'][$id]=$rows;$digital=$digital||$rows!==[];
        }
        ksort($schemaIds);foreach(array_keys($schemaIds)as $id){$rows=$read(Tables::personalization_schemas(),'id=%d',[$id]);if(is_wp_error($rows))return $rows;$graph['schemas'][$id]=$rows;}
        ksort($listingIds);foreach(array_keys($listingIds)as $id){
            $rows=$read(Tables::listings(),'id=%d',[$id]);if(is_wp_error($rows))return $rows;if(count($rows)!==1)return self::error('relationship');$graph['listings'][$id]=$rows;
            $rows=$read(Tables::listing_pod_bindings(),'listing_id=%d',[$id]);if(is_wp_error($rows))return $rows;$graph['bindings'][$id]=$rows;foreach($rows as $row)$mappingIds[(int)$row['provider_mapping_id']]=true;
            if($digital){$packages=$read(Tables::etsy_draft_packages(),'listing_id=%d',[$id]);if(is_wp_error($packages))return $packages;$graph['delivery_packages'][$id]=$packages;foreach($packages as $package){$ops=$read($wpdb->prefix.'digiforge_etsy_operations','draft_package_id=%d',[(int)$package['id']]);if(is_wp_error($ops))return $ops;$graph['delivery_operations'][(int)$package['id']]=$ops;}}
        }
        $businessIds=[];$storeIds=[];$programIds=[];$planIds=[];ksort($mappingIds);
        foreach(array_keys($mappingIds)as $id){
            $rows=$read(Tables::pod_mappings(),'id=%d',[$id]);if(is_wp_error($rows))return $rows;if(count($rows)!==1)return self::error('relationship');$graph['mappings'][$id]=$rows;
            $owners=$read(Tables::pod_business_mappings(),'provider_mapping_id=%d',[$id]);if(is_wp_error($owners))return $owners;if($owners===[])return self::error('relationship');$graph['ownership'][$id]=$owners;
            foreach($owners as $owner){$businessIds[(int)$owner['business_id']]=true;$storeIds[(int)$owner['store_id']]=true;$programIds[(int)$owner['product_program_id']]=true;}
            // Listed physical lines use POD readiness's plan/area/bundle/cost graph.
            if($listingIds!==[]){$planIds[(int)$rows[0]['production_plan_id']]=true;foreach(['areas'=>Tables::pod_print_areas(),'costs'=>Tables::pod_cost_snapshots()]as $key=>$table){$linked=$read($table,'provider_mapping_id=%d',[$id]);if(is_wp_error($linked))return $linked;$graph[$key][$id]=$linked;}}
        }
        foreach(['businesses'=>[Tables::businesses(),$businessIds],'stores'=>[Tables::stores(),$storeIds],'programs'=>[Tables::product_programs(),$programIds],'production_plans'=>[Tables::production_plans(),$planIds]]as $key=>[$table,$ids]){ksort($ids);foreach(array_keys($ids)as $id){$rows=$read($table,'id=%d',[$id]);if(is_wp_error($rows))return $rows;if(count($rows)!==1)return self::error('relationship');$graph[$key][$id]=$rows;}}
        ksort($planIds);foreach(array_keys($planIds)as $id){$rows=$read(Tables::release_bundles(),'production_plan_id=%d',[$id]);if(is_wp_error($rows))return $rows;$graph['bundles'][$id]=$rows;}
        $tables=array_keys($used);sort($tables);return ['order'=>$order,'lines'=>$lines,'graph'=>$graph,'storage_tables'=>$tables];
    }
    private static function storage(array $tables):true|WP_Error
    {
        global $wpdb;foreach($tables as $table){$rows=self::rows($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(is_wp_error($rows)||count($rows)!==1||strtoupper((string)$rows[0]['ENGINE'])!=='INNODB')return self::error('storage');$rows=self::rows('SHOW CREATE TABLE '.$table);if(is_wp_error($rows)||count($rows)!==1||!str_starts_with(strtoupper((string)($rows[0]['Create Table']??'')),'CREATE TABLE '))return self::error('storage');}return true;
    }
    private static function rows(string $sql):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);return !is_array($rows)||$wpdb->last_error!==''||count($rows)>100?self::error('unavailable'):$rows;
    }
    private static function error(string $reason):WP_Error{return new WP_Error('order_photo_context_'.$reason,'Current server-issued photo dependency is unavailable or changed.',['status'=>$reason==='unavailable'?503:409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
