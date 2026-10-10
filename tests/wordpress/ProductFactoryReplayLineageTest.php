<?php
declare(strict_types=1);

final class ProductFactoryReplayLineageTest extends WP_UnitTestCase {
 private DigiForge\ProductFactory\Repository $repo;
 public function setUp():void { parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$this->repo=new DigiForge\ProductFactory\Repository(); }
 public function testReplayCannotReturnAnotherParentLineage():void {
  $one=$this->repo->create('opportunity',['title'=>'First'],'opp-one-'.wp_generate_uuid4());$two=$this->repo->create('opportunity',['title'=>'Second'],'opp-two-'.wp_generate_uuid4());$key='family-'.wp_generate_uuid4();
  $saved=$this->repo->create('product_family',['name'=>'Shared family','opportunity_id'=>$one['id']],$key);self::assertFalse(is_wp_error($saved));
  $conflict=$this->repo->create('product_family',['name'=>'Shared family','opportunity_id'=>$two['id']],$key);self::assertTrue(is_wp_error($conflict));self::assertSame('digiforge_idempotency_conflict',$conflict->get_error_code());self::assertSame($one['id'],$this->repo->find('product_family',$saved['id'])['opportunity_id']);
 }
 public function testProductVersionReplayRequiresExactImmutableContent():void {
  $opp=$this->repo->create('opportunity',['title'=>'Immutable'],'opp-'.wp_generate_uuid4());$family=$this->repo->create('product_family',['name'=>'Family','opportunity_id'=>$opp['id']],'family-'.wp_generate_uuid4());$product=$this->repo->create('product',['name'=>'Product','product_family_id'=>$family['id']],'product-'.wp_generate_uuid4());$key='version-'.wp_generate_uuid4();$input=['product_id'=>$product['id'],'version_label'=>'v1','notes'=>'Original specification'];
  $saved=$this->repo->create('product_version',$input,$key);self::assertFalse(is_wp_error($saved));$replay=$this->repo->create('product_version',$input,$key);self::assertSame($saved['id'],$replay['id']);self::assertTrue($replay['idempotent_replay']);
  $input['notes']='Changed specification';$changed=$this->repo->create('product_version',$input,$key);self::assertTrue(is_wp_error($changed));self::assertSame('digiforge_idempotency_conflict',$changed->get_error_code());self::assertSame('Original specification',$this->repo->find('product_version',$saved['id'])['notes']);
 }
 public function testReplayCannotOmitRequiredParentAndSanitizedEquivalentIsAccepted():void {
  $opp=$this->repo->create('opportunity',['title'=>'Equivalent'],'opp-'.wp_generate_uuid4());$key='family-'.wp_generate_uuid4();$saved=$this->repo->create('product_family',['name'=>'Family','description'=>'same','opportunity_id'=>$opp['id']],$key);
  $missing=$this->repo->create('product_family',['name'=>'Family','description'=>'same'],$key);self::assertTrue(is_wp_error($missing));
  $same=$this->repo->create('product_family',['name'=>'<b>Family</b>','description'=>'same','opportunity_id'=>(string)$opp['id']],$key);self::assertFalse(is_wp_error($same));self::assertSame($saved['id'],$same['id']);
 }
 public function testInsertRaceWinnerMustMatchImmutableLineage():void {
  global $wpdb;$original=$wpdb;$table=DigiForge\Database\Tables::product_families();
  $racing=new class(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST) extends wpdb {
   public string $target='';public int $winningParent=0;public bool $fired=false;
   public function insert($table,$data,$format=null) {
    if ($table===$this->target && ! $this->fired) { $this->fired=true;$data['opportunity_id']=$this->winningParent;$winner=parent::insert($table,$data,$format);if ($winner!==1) { throw new RuntimeException('Unable to persist race winner fixture.'); }return false; }
    return parent::insert($table,$data,$format);
   }
  };
  $racing->set_prefix($original->prefix);$wpdb=$racing;$parents=[];
  try {
   $one=$this->repo->create('opportunity',['title'=>'Race one'],'race-one-'.wp_generate_uuid4());$two=$this->repo->create('opportunity',['title'=>'Race two'],'race-two-'.wp_generate_uuid4());self::assertFalse(is_wp_error($one));self::assertFalse(is_wp_error($two));$parents=[(int)$one['id'],(int)$two['id']];$racing->target=$table;
   foreach ([true,false] as $same) {
    $key='race-family-'.wp_generate_uuid4();$racing->winningParent=(int)($same?$one['id']:$two['id']);$racing->fired=false;
    $result=$this->repo->create('product_family',['name'=>'Race family','opportunity_id'=>$one['id']],$key);self::assertTrue($racing->fired);
    if ($same) { self::assertFalse(is_wp_error($result));self::assertTrue($result['idempotent_replay']);self::assertSame($one['id'],$result['opportunity_id']); }
    else { self::assertTrue(is_wp_error($result));self::assertSame('digiforge_idempotency_conflict',$result->get_error_code()); }
   }
  } finally {
   foreach ($parents as $parent) { $racing->delete($table,['opportunity_id'=>$parent]);$racing->delete(DigiForge\Database\Tables::opportunities(),['id'=>$parent]); }
   $wpdb=$original;$racing->close();
  }
 }

}
