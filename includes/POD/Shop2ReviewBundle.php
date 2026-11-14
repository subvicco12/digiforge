<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use WP_Error;
/** Downloadable local review files. No provider API payload or remote asset fetch. */
final class Shop2ReviewBundle {
 public static function build(array $link,string $package):string|WP_Error {
  $workflow=new Shop2ProductWorkflow();$version=(new ProductPackageRepository())->read('personalized_pod',$package);if(is_wp_error($version))return $version;
  $bound=isset($version['record']['evidence']['shop2_binding']);$json=$bound?Shop2HeldPackageBinding::export($link,$version['record']['commercial_design_metadata']['commercial_hash'],$version['record']['commercial_design_metadata']['design_hash'],$package):$workflow->export($link,$package);if(is_wp_error($json))return $json;
  $out=json_decode($json,true,32,JSON_THROW_ON_ERROR);$repo=new ProductPackageRepository();$art=$repo->artwork('personalized_pod',$package);if(is_wp_error($art))return $art;
  $files=['held-package.json'=>$json,'artwork/fixed-artwork.svg'=>$art['bytes'],'README.txt'=>"LOCAL REVIEW ONLY\nSTOP ALL ON. External safety lock ON. Automation OFF. RELEASE HOLD.\nNo Printify product, Etsy draft, publication or order was submitted.\nNative layers require manual provider setup. Imported mockups are not supplier certification.\nReview all remaining_gates in held-package.json. Historical technical product IDs are not new live product IDs.\n"];
  $design=$out['commercial_design_metadata']['design_hash'];
  foreach($out['package']['media'] as $m){if(!preg_match('#^shop2-mockups/([a-f0-9]{64})\.png$#D',$m['reference'],$match))return Shop2ProductWorkflow::error('Exact imported mockup reference required.');$r=(new Shop2MockupRepository())->read($link,$design,$match[1]);if(is_wp_error($r))return $r;$files['mockups/'.$match[1].'.png']=base64_decode($r['base64'],true);}
  $h=(new \DigiForge\Research\DesignFactoryRepository())->handoff('personalized_pod',$design);if(is_wp_error($h))return $h;
  if($bound){
   $reference=$out['proof_business_binding']['personalization_intake'];$selected=(new \DigiForge\Research\ArtworkPersonalizationRepository())->read('personalized_pod',$reference['versions']['proof_hash']);if(is_wp_error($selected))return $selected;
   $svg=$selected['record']['data']['result']['svg']??null;if(!is_string($svg)||strlen($svg)>160000||!hash_equals($reference['proof']['proof_sha256'],hash('sha256',$svg)))return Shop2ProductWorkflow::error('Exact selected local proof bytes unavailable.');
   $files['proof/local-sample.svg']=$svg;$files['proof/receipt.json']=Validator::canonicalJson(['schema'=>'shop2-bound-local-proof-v1','proof_hash'=>$reference['versions']['proof_hash'],'proof_sha256'=>hash('sha256',$svg),'schema_hash'=>$reference['versions']['schema_hash'],'review_hash'=>$reference['versions']['review_hash'],'intake_hash'=>$reference['intake_hash'],'production_file'=>false,'provider_certified'=>false,'external_execution_authorized'=>false,'release_hold'=>true]);
  }else{
  $samples=[];foreach($h['design_metadata']['specification']['layout']['native_regions'] as $region)$samples[$region['name']]='SAMPLE';
  $proof=$workflow->proof($link,$design,$samples);
  if(is_wp_error($proof)){$files['proof/status.json']=Validator::canonicalJson(['state'=>'MANUAL_PROOF_REQUIRED','external_execution_authorized'=>false,'release_hold'=>true]);}
  else{$files['proof/local-sample.svg']=$proof['svg'];$files['proof/receipt.json']=Validator::canonicalJson($proof['receipt']);}
  }
  if($bound)$files['proof/business-binding.json']=Validator::canonicalJson($out['proof_business_binding']);
  $manifest=['schema'=>'shop2-held-review-bundle-v1','supplier_workflow_hash'=>$link['hash'],'package_hash'=>$package,'external_execution_authorized'=>false,'files'=>[]];
  foreach($files as $name=>$raw)$manifest['files'][]=['path'=>$name,'sha256'=>hash('sha256',$raw),'size'=>strlen($raw)];$files['manifest.json']=Validator::canonicalJson($manifest);
  $body='';$directory='';foreach($files as $name=>$raw){$offset=strlen($body);$size=strlen($raw);if($offset+$size>25100000)return Shop2ProductWorkflow::error('Review bundle exceeds its bound.');$crc=crc32($raw);$length=strlen($name);$body.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$name.$raw;$directory.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$name;}
  return $body.$directory.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($directory),strlen($body),0);
 }
}
