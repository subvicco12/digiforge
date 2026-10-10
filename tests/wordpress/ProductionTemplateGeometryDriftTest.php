<?php
declare(strict_types=1);
use DigiForge\POD\ProductionTemplateRepository;
final class ProductionTemplateGeometryDriftTest extends WP_UnitTestCase {
 public function testSafeZoneDriftCreatesDraftAndPreservesValidatedVersion():void {
  DigiForge\Core\Activator::activate();$repo=new ProductionTemplateRepository();$input=['template_id'=>'safe-zone-'.wp_rand(),'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'PHOTO_TEXT','template_status'=>'VALIDATED','print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600,'safe_zone'=>['x'=>10,'y'=>10,'width'=>1180,'height'=>1580],'bleed_metadata'=>['left'=>5],'panels'=>[['name'=>'front']]]]];
  $saved=$repo->save($input);self::assertFalse(is_wp_error($saved));$input['print_areas'][0]['safe_zone']['x']=20;$drift=$repo->createDriftCandidate($input);self::assertFalse(is_wp_error($drift));self::assertSame(2,$drift['template_version']);self::assertSame('DRAFT',$drift['template_status']);self::assertSame(20,json_decode($drift['print_areas'],true)[0]['safe_zone']['x']);global $wpdb;$prior=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::pod_production_templates().' WHERE id=%d',$saved['id']),ARRAY_A);self::assertSame($saved['fingerprint'],$prior['fingerprint']);self::assertSame('VALIDATED',$prior['template_status']);self::assertSame(10,json_decode($prior['print_areas'],true)[0]['safe_zone']['x']);
 }
}
