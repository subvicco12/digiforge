<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Connection-bound fence for A-owned editor/selection mutations, never cross-owner transaction admission. */
final class CustomerPhotoProducerFence
{
 public const RETAINED_READ_LEASE_CONTRACT='shop2-photo-retained-read-lease-v1';
 private static array $held=[];
 private bool $released=false;
 private function __construct(private object $db,private int $connection,private string $name,private string $generation) {}
 /** Acquire before any consumer transaction. Keep until the final supported autocommit consumer completes. */
 public static function acquireRead():self|WP_Error {return self::acquire(false);}
 /** Producer implementation boundary. Consumers must use acquireRead(). */
 public static function acquireMutation():self|WP_Error {return self::acquire(true);}
 private static function acquire(bool $mutation):self|WP_Error {
  global $wpdb;$state=self::state($wpdb);if($state instanceof WP_Error)return $state;$key=spl_object_id($wpdb);$old=self::$held[$key]??null;
  if($old!==null){
   if($old['db']!==$wpdb||$old['connection']!==$state['connection']||!self::owns($wpdb,$old['name'],$state['connection']))return self::error('ownership_lost');
   // Only an A mutation boundary may read its own newly opened transaction. A reader's
   // lease never upgrades a caller-owned transaction into a fresh admission.
   if(($mutation&&!$old['mutation'])||($state['transaction']&&!$old['transaction']))return self::error('caller_transaction_or_mutation');
   self::$held[$key]['depth']++;return new self($wpdb,$state['connection'],$old['name'],$old['generation']);
  }
  if($state['transaction']||!$state['autocommit'])return self::error('caller_transaction');
  $database=$wpdb->get_var('SELECT DATABASE()');if(!is_string($database)||$database==='')return self::error('state_unavailable');
  $name='df-photo-producer-'.substr(hash('sha256',$database.'|'.$wpdb->prefix),0,40);$wpdb->last_error='';$got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));
  if($wpdb->last_error!==''||(string)$got!=='1')return self::error('busy');
  $again=self::state($wpdb);if($again instanceof WP_Error||$again!==$state||!self::owns($wpdb,$name,$state['connection'])){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));return self::error('state_changed');}
  $generation=bin2hex(random_bytes(16));self::$held[$key]=['db'=>$wpdb,'connection'=>$state['connection'],'name'=>$name,'generation'=>$generation,'depth'=>1,'mutation'=>$mutation,'transaction'=>false];
  return new self($wpdb,$state['connection'],$name,$generation);
 }
 /** Start only the producer's own transaction; never replaces a caller transaction. */
 public function startMutationTransaction():true|WP_Error {
  $key=spl_object_id($this->db);$held=self::$held[$key]??null;$state=self::state($this->db);
  if($this->released||$held===null||$held['generation']!==$this->generation||!$held['mutation']||$held['transaction']||$state instanceof WP_Error||$state['transaction']||!$state['autocommit']||$state['connection']!==$this->connection||!self::owns($this->db,$this->name,$this->connection))return self::error('caller_transaction');
  if($this->db->query('START TRANSACTION')===false)return self::error('transaction_unavailable');self::$held[$key]['transaction']=true;return true;
 }
 /** Recheck connection/lock/transaction state immediately before consuming or returning evidence. */
 public function validate():true|WP_Error {
  global $wpdb;$held=self::$held[spl_object_id($this->db)]??null;$state=self::state($this->db);
  if($this->released||$wpdb!==$this->db||$held===null||$held['generation']!==$this->generation||$state instanceof WP_Error||$state['connection']!==$this->connection||!$state['autocommit']||($state['transaction']&&!$held['transaction'])||!self::owns($this->db,$this->name,$this->connection))return self::error('state_changed');return true;
 }
 /** Lease witness only: no photo/identity read, transaction change, actor admission or approval. */
 public function validateRetainedReadLease():true|WP_Error {
  global $wpdb;$held=self::$held[spl_object_id($this->db)]??null;$state=self::state($this->db);
  if($this->released||$wpdb!==$this->db||$held===null||$held['generation']!==$this->generation||$held['mutation']||$held['transaction']||$state instanceof WP_Error||$state['connection']!==$this->connection||!self::owns($this->db,$this->name,$this->connection))return self::error('retained_read_lease_invalid');return true;
 }
 /** No COMMIT, ROLLBACK, isolation change, or connection substitution. */
 public function release():true|WP_Error {
  if($this->released)return true;$key=spl_object_id($this->db);$held=self::$held[$key]??null;
  if($held===null||$held['generation']!==$this->generation)return self::error('ownership_lost');
  if(--self::$held[$key]['depth']>0){$this->released=true;return true;}
  unset(self::$held[$key]);$this->released=true;if(!self::owns($this->db,$this->name,$this->connection))return self::error('ownership_lost');
  $this->db->last_error='';$released=$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$this->name));return $this->db->last_error===''&&(string)$released==='1'?true:self::error('release_uncertain');
 }
 public function __destruct() {if(!$this->released)$this->release();}
 private static function state(object $db):array|WP_Error {
  $db->last_error='';$row=$db->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active_transaction,@@session.autocommit AS autocommit',ARRAY_A);
  if($db->last_error!==''||!is_array($row)||!ctype_digit((string)($row['connection_id']??''))||!in_array((string)($row['active_transaction']??''),['0','1'],true)||!in_array((string)($row['autocommit']??''),['0','1'],true))return self::error('state_unavailable');
  return ['connection'=>(int)$row['connection_id'],'transaction'=>$row['active_transaction']==='1'||$row['active_transaction']===1,'autocommit'=>$row['autocommit']==='1'||$row['autocommit']===1];
 }
 private static function owns(object $db,string $name,int $connection):bool {$db->last_error='';$owner=$db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)',$name));return $db->last_error===''&&(string)$owner===(string)$connection;}
 private static function error(string $reason):WP_Error {return new WP_Error('photo_current_read_'.$reason,'A fresh autocommit producer read and retained connection fence are required.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
