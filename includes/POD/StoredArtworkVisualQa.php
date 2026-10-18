<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\ProductFactory\AssetStorage;
use WP_Error;

/** Pixel QA reads retained protected artifacts; caller hashes or flags cannot certify them. */
final class StoredArtworkVisualQa {
 public function verify(array $areas,array $artifacts):array|WP_Error {
  try{
   $required=[];$total=0;foreach($areas as $area){$policy=ArtworkGeometryQa::policy($area);if(isset($policy['bleed'])||array_key_exists('panel_edge_matches',$area)){$required[$area['position']]=true;foreach((array)($area['panel_edge_matches']??[])as $edge)if(is_array($edge)&&is_string($edge['other_panel']??null))$required[$edge['other_panel']]=true;}}
   $images=[];foreach(array_keys($required)as $position){$metadata=$artifacts[$position]??null;if(!is_array($metadata))throw new \InvalidArgumentException('Exact complete retained panel evidence required.');$verified=(new RenderArtifactStore())->verify($metadata);if($verified instanceof WP_Error)return $verified;$total+=$metadata['width_px']*$metadata['height_px'];if($total>RasterArtworkCodec::MAX_PIXELS*8)throw new \InvalidArgumentException('Aggregate pixel QA exceeds work bound.');RasterArtworkCodec::geometry($metadata['width_px'],$metadata['height_px']);
    $path=AssetStorage::absolutePath($metadata['storage_reference']);if($path===null)throw new \InvalidArgumentException('Protected retained artifact unavailable.');$bytes=@file_get_contents($path,false,null,0,16777217);if(!is_string($bytes)||strlen($bytes)>16777216||!hash_equals($metadata['sha256'],hash('sha256',$bytes)))throw new \InvalidArgumentException('Retained pixel evidence changed during QA.');
    if($metadata['mime']==='image/svg+xml'){$raster=LocalArtworkAdapter::convert($bytes,'png');if($raster instanceof WP_Error)return $raster;$images[$position]=RasterArtworkCodec::decode($raster['bytes']);}
    elseif($metadata['mime']==='image/png')$images[$position]=RasterArtworkCodec::decode($bytes);
    elseif($metadata['mime']==='application/pdf')$images[$position]=RasterPdfAdapter::pixels($bytes);
    else throw new \InvalidArgumentException('Exact pixel QA adapter unavailable for this format; supplier checks cannot be inferred.');
   }
   return ArtworkPixelQa::verify($areas,$images);
  }catch(\InvalidArgumentException $e){return new WP_Error('render_visual_qa_failed',$e->getMessage(),['status'=>409,'supplier_certified'=>false,'external_execution_authorized'=>false]);}
 }
}
