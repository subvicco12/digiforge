<?php
declare(strict_types=1);
/** Synthetic engineering evidence only, never commercial/provider approval. */
final class ArtworkPersonalizationFixture
{
 public static function fields():array {return [['key'=>'first_name','type'=>'text','required'=>true,'max_length'=>20],['key'=>'second_name','type'=>'text','required'=>true,'max_length'=>20],['key'=>'date','type'=>'date','required'=>true,'max_length'=>10]];}
 public static function values():array {return ['first_name'=>'Élodie','second_name'=>'José','date'=>'2024-02-29'];}
 public static function approved(string $profile='fl03_mug_11oz',bool $review=true):array {
  $f=json_decode(file_get_contents(__DIR__.'/../../fixtures/research/commercial-research.json'),true);$family=\DigiForge\Research\DesignFactoryContract::PROFILES[$profile]['family'];$f['research']['product_family']=$family;$f['research']['title']='SYNTHETIC '.($family==='mug'?'FL-03':'FL-04').' portal fixture';
  $r=new \DigiForge\Research\CommercialResearchRepository();$a=$r->create($f['research']);$b=$r->importResponse('personalized_pod',$a['hash'],json_encode($f['response']),['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'local-fixture','generated_at'=>'2026-11-03T12:00:00Z']);$s=$r->select('personalized_pod',$b['hash'],1);
  $d=new \DigiForge\Research\DesignFactoryRepository();$a=$d->approveConcept('personalized_pod',$s['hash'],['sources_reviewed'=>true,'buyer_intent_reviewed'=>true,'gifting_occasion_reviewed'=>true,'note'=>'SYNTHETIC source review only']);$regions=[];foreach(['first_name','second_name','date']as $i=>$name)$regions[]=['name'=>$name,'kind'=>'text','x'=>600,'y'=>300+$i*200,'width'=>1200,'height'=>120];
  $p=$d->prepare('personalized_pod',$a['hash'],$profile,['layout_notes'=>'Existing original fixed vector, separate native controls','typography'=>'Local sample font only; provider parity unverified','font_license_reference'=>'Existing synthetic engineering fixture, no commercial approval','palette'=>['#123456'],'native_regions'=>$regions,'asset_requirements'=>['Preserve editable native fields']]);
  $asset=$d->importArtwork('personalized_pod',$p['hash'],['svg'=>file_get_contents(__DIR__.'/../../fixtures/design-factory/'.($family==='mug'?'mug':'poster').'-original.svg'),'raster_uploads'=>[],'rights_note'=>'SYNTHETIC original engineering source only','source_note'=>'Existing committed fixture; not R5B or commercial artwork']);
  return $review?$d->review('personalized_pod',$asset['hash'],'APPROVE',array_fill_keys(\DigiForge\Research\DesignFactoryContract::REVIEW_CRITERIA,true),'SYNTHETIC human-role decision, local engineering only'):$asset;
 }
}
