<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use WP_Error;

/** Produces customer/product and marketing assets locally inside protected WordPress storage. */
final class LocalAssetProducer
{
    private const MAX_ASSET_BYTES = 8388608;
    private const FORMATS = ['txt','html','csv','json','svg','pdf'];

    /** @param mixed $content @return array<string,mixed>|WP_Error */
    public function write(int $productVersionId, string $filename, string $format, $content, string $storageVariant = ''): array|WP_Error
    {
        if ($productVersionId < 1) { return $this->error('invalid_product_version', 'A valid product version is required.'); }
        if (! AssetStorage::ensureProtectedRoot()) { return $this->error('asset_storage_protection', 'Protected DigiForge asset storage could not be established.', 500); }
        $format = strtolower(sanitize_key($format));
        if (! in_array($format, self::FORMATS, true)) { return $this->error('unsupported_format', 'Unsupported local asset format.'); }
        $filename = $this->filename($filename, $format);
        if ($filename === '') { return $this->error('invalid_filename', 'Filename and declared format must be safe and consistent.'); }
        $storageVariant = sanitize_key($storageVariant);
        $evidence = [];
        if ($format === 'pdf') {
            $rendered = $this->pdf($content, $filename);
            if (is_wp_error($rendered)) { return $rendered; }
            $bytes = $rendered['bytes'];
            $evidence = $rendered['evidence'];
        } else {
            $bytes = $this->text($format, $content);
            if (is_wp_error($bytes)) { return $bytes; }
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_ASSET_BYTES) { return $this->error('invalid_asset_size', 'Generated asset is empty or too large.'); }
        $stored = $this->commitBytes($productVersionId, $filename, $format, $bytes, $storageVariant);
        return is_wp_error($stored) ? $stored : $stored + $evidence;
    }


    /** @return array<string,mixed>|WP_Error */
    public function rasterizeSvg(int $productVersionId, string $sourceStorageReference, string $filename, int $width = 2000, int $height = 2000, string $storageVariant = 'etsy-listing'): array|WP_Error
    {
        if ($productVersionId < 1) { return $this->error('invalid_product_version', 'A valid product version is required.'); }
        if ($width < 500 || $height < 500 || $width > 4000 || $height > 4000) { return $this->error('invalid_raster_dimensions', 'Raster dimensions must be between 500 and 4000 pixels.'); }
        $filename = $this->filename($filename, 'png'); if ($filename === '') { return $this->error('invalid_filename', 'A safe PNG filename is required.'); }
        $source = AssetStorage::absolutePath($sourceStorageReference); if ($source === null || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'svg') { return $this->error('invalid_raster_source', 'Raster source must be an existing protected SVG asset.'); }
        $svg = (string) @file_get_contents($source); if ($svg === '' || ! str_contains(strtolower($svg), '<svg') || $this->containsActiveMarkup($svg) || $this->containsExternalSvgReference($svg) || ! $this->safeSvgGeometry($svg)) { return $this->error('unsafe_svg', 'Raster source SVG is invalid or unsafe.'); }
        if (! class_exists('\Imagick')) { return $this->error('rasterizer_unavailable', 'ImageMagick is required for governed SVG rasterization.', 503); }
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 67108864);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 134217728);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 134217728);
            $image = new \Imagick();
            $image->setBackgroundColor(new \ImagickPixel('white'));
            $image->readImageBlob($svg);
            $image->setImageFormat('png');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $image->thumbnailImage($width, $height, true, true);
            $bytes = $image->getImagesBlob();
            $actualWidth = (int) $image->getImageWidth(); $actualHeight = (int) $image->getImageHeight();
            $image->clear(); $image->destroy();
        } catch (\Throwable $e) { return $this->error('rasterize_failed', 'Unable to rasterize the approved SVG asset.', 500); }
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_ASSET_BYTES || $actualWidth < 1 || $actualHeight < 1) { return $this->error('invalid_asset_size', 'Generated raster asset is empty, invalid, or too large.'); }
        $stored = $this->commitBytes($productVersionId, $filename, 'png', $bytes, sanitize_key($storageVariant)); if (is_wp_error($stored)) { return $stored; }
        $stored['width_px']=$actualWidth; $stored['height_px']=$actualHeight; $stored['source_storage_reference']=$sourceStorageReference; $stored['source_checksum_sha256']=hash_file('sha256',$source);
        return $stored;
    }

    /** @return array<string,mixed>|WP_Error */
    private function commitBytes(int $productVersionId,string $filename,string $format,string $bytes,string $storageVariant): array|WP_Error
    {
        if (! AssetStorage::ensureProtectedRoot()) { return $this->error('asset_storage_protection', 'Protected DigiForge asset storage could not be established.', 500); }
        $uploads=wp_upload_dir(); if(!empty($uploads['error'])||empty($uploads['basedir']))return $this->error('upload_directory','WordPress uploads directory is unavailable.',500);
        $relativeDir=AssetStorage::RELATIVE_ROOT.'/'.$productVersionId.($storageVariant !== '' ? '/' . $storageVariant : ''); $directory=trailingslashit((string)$uploads['basedir']).$relativeDir;
        if(!wp_mkdir_p($directory))return $this->error('asset_directory','Unable to create DigiForge asset directory.',500);
        $path=trailingslashit($directory).$filename; $newHash=hash('sha256', $bytes);
        if(is_link($directory)||is_link($path))return $this->error('asset_directory','Generated asset paths must not be symlinks.',500);
        if (is_file($path)){ $existingHash=hash_file('sha256',$path); if(is_string($existingHash)&&hash_equals($existingHash,$newHash))return $this->metadata($path,$filename,$format,$relativeDir); return $this->error('asset_replay_conflict','Existing generated asset differs from replay payload.',409); }
        if(!function_exists('link'))return $this->error('asset_commit_capability','Atomic no-replace asset commit is unavailable on this host.',503);
        $temp=$path.'.tmp-'.wp_generate_password(8,false,false);
        try {
            if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes))return $this->error('asset_write','Unable to write generated asset.',500);
            $written=hash_file('sha256', $temp);
            if(!is_string($written)||!hash_equals($newHash,$written))return $this->error('asset_write','Generated asset readback differs from its source.',500);
            if(!@link($temp,$path)){
                if(!is_file($path))return $this->error('asset_commit','Unable to finalize generated asset atomically.',503);
                $winner=is_link($path)?false:hash_file('sha256',$path);
                if(!is_string($winner)||!hash_equals($newHash,$winner))return $this->error('asset_replay_conflict','Another generated asset owns this immutable output identity.',409);
            }
            return $this->metadata($path,$filename,$format,$relativeDir);
        } finally { if(is_file($temp))@unlink($temp); }
    }


    private function containsExternalSvgReference(string $svg): bool
    {
        if (preg_match_all('/\b(?:href|xlink:href)\s*=\s*["\']([^"\']*)["\']/i',$svg,$matches)) { foreach($matches[1] as $ref){$ref=trim((string)$ref);if($ref!==''&&!str_starts_with($ref,'#'))return true;} }
        if (preg_match_all('/url\s*\(\s*([^)]+)\s*\)/i',$svg,$matches)) { foreach($matches[1] as $ref){$ref=trim((string)$ref," \t\n\r\0\x0B\"'");if($ref!==''&&!str_starts_with($ref,'#'))return true;} }
        return false;
    }

    private function safeSvgGeometry(string $svg): bool
    {
        $max=4000.0;$dimension=static function(string $name)use($svg):?float{if(!preg_match('/\b'.preg_quote($name,'/').'\s*=\s*["\']\s*([0-9]+(?:\.[0-9]+)?)\s*(?:px)?\s*["\']/i',$svg,$m))return null;return(float)$m[1];};
        $w=$dimension('width');$h=$dimension('height');if(($w!==null&&($w<=0||$w>$max))||($h!==null&&($h<=0||$h>$max)))return false;
        if(preg_match('/\bviewBox\s*=\s*["\']\s*[-+]?[0-9.]+\s+[-+]?[0-9.]+\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)\s*["\']/i',$svg,$m)){if((float)$m[1]<=0||(float)$m[2]<=0||(float)$m[1]>$max||(float)$m[2]>$max)return false;}
        return true;
    }

    /** @param array<int,array<string,mixed>> $assets @return array<string,mixed>|WP_Error */
    public function package(int $productVersionId, array $assets, string $filename = 'customer-package.zip', string $storageVariant = ''): array|WP_Error
    {
        if ($productVersionId < 1 || $assets === []) { return $this->error('zip_asset', 'A product version and nonempty customer selection are required.'); }
        if (! AssetStorage::ensureProtectedRoot()) { return $this->error('asset_storage_protection', 'Protected DigiForge asset storage could not be established.', 500); }
        if (! class_exists('ZipArchive')) { return $this->error('zip_unavailable', 'ZIP support is unavailable on this server.', 500); }
        $members=[];$names=[];
        foreach($assets as $asset){
            if(!is_array($asset))return $this->error('zip_asset','Exact source metadata is required.');
            $source=(string)($asset['absolute_path']??'');$name = basename((string) ($asset['filename'] ?? ''));$hash=$asset['checksum_sha256']??null;$size=$asset['byte_size']??null;
            if($source===''||str_contains($source,'://')||is_link($source)||!is_file($source)||$name===''||$name!==sanitize_file_name($name)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_int($size)||$size<1)return $this->error('zip_asset','Safe regular sources with exact checksums and sizes are required.');
            if(isset($names[$name]))return $this->error('zip_duplicate_name','Customer ZIP member names must be unique after flattening.');$names[$name]=true;
            clearstatcache(true,$source);$actual=hash_file('sha256',$source);if(filesize($source)!==$size||!is_string($actual)||!hash_equals($hash,$actual))return $this->error('zip_source_drift','A customer source differs from its reviewed byte identity.',409);
            $members[]=['name'=>$name,'sha256'=>$hash,'byte_size'=>$size,'source'=>$source];
        }
        $uploads=wp_upload_dir();if(!empty($uploads['error'])||empty($uploads['basedir']))return $this->error('upload_directory','WordPress uploads directory is unavailable.',500);
        $storageVariant=sanitize_key($storageVariant);$relativeDir=AssetStorage::RELATIVE_ROOT.'/'.$productVersionId.($storageVariant !== '' ? '/' . $storageVariant : '');$directory=trailingslashit((string)$uploads['basedir']).$relativeDir;
        if(!wp_mkdir_p($directory)||is_link($directory))return $this->error('asset_directory','Protected archive directory is unavailable.',500);
        $filename=$this->filename($filename,'zip');if($filename==='')return $this->error('invalid_filename','A safe ZIP filename is required.');$path=trailingslashit($directory).$filename;
        $publicMembers=array_map(static fn(array $m):array=>array_diff_key($m,['source'=>true]),$members);
        // Preserve previously retained bytes, including legacy ZIP timestamps/compression.
        if(is_file($path))return $this->packageMatches($path,$members)?$this->metadata($path,$filename,'zip',$relativeDir)+['package_members'=>$publicMembers]:$this->error('asset_replay_conflict','Existing customer archive differs from the exact selected members.',409);
        if(!function_exists('link'))return $this->error('asset_commit_capability','Atomic no-replace archive commit is unavailable on this host.',503);
        $temp=$path.'.tmp-'.wp_generate_password(8,false,false);$zip=new \ZipArchive();if($zip->open($temp,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true)return $this->error('zip_create','Unable to create product ZIP.',500);
        $open=true;
        try{
            foreach($members as$m){
                $source=$m['source'];$name=$m['name'];
                // ZIP epoch is container metadata, never a source/business timestamp.
                if(!$zip->addFile($source, $name)||!$zip->setMtimeName($m['name'],315532800)||!$zip->setCompressionName($m['name'],\ZipArchive::CM_DEFLATE,9)||!$zip->setExternalAttributesName($m['name'],\ZipArchive::OPSYS_UNIX,0100644<<16))return $this->error('zip_asset','Unable to bind required archive content.',500);
            }
            $closed=$zip->close();$open=false;if(!$closed||!$this->packageMatches($temp,$members))return $this->error('zip_source_drift','Archive member readback differs from the exact reviewed selection.',409);
            // Same-directory hard link is atomic no-replace. Never overwrite a competing winner.
            if(!@link($temp,$path)){if(!is_file($path))return $this->error('asset_commit','Unable to commit customer archive atomically.',503);if(!$this->packageMatches($path,$members))return $this->error('asset_replay_conflict','Another archive owns this immutable output identity.',409);}
            return $this->metadata($path,$filename,'zip',$relativeDir)+['package_members'=>$publicMembers];
        }finally{if($open)$zip->close();if(is_file($temp))@unlink($temp);}
    }

    /** Verify exact ordered membership/length/hash using bounded streaming reads. */
    private function packageMatches(string $path,array $members):bool
    {
        if(is_link($path)||!is_file($path))return false;$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CHECKCONS)!==true)return false;
        try{if($zip->numFiles!==count($members))return false;
            foreach($members as$i=>$m){if($zip->getNameIndex($i)!==$m['name'])return false;$stream=$zip->getStream($m['name']);if($stream===false)return false;
                try{$hash=hash_init('sha256');$read=hash_update_stream($hash,$stream,$m['byte_size']+1);$actual=hash_final($hash);}finally{fclose($stream);}
                if($read!==$m['byte_size']||!hash_equals($m['sha256'],$actual))return false;
            }return true;
        }finally{$zip->close();}
    }

    /** @param mixed $content */
    private function text(string $format, $content): string|WP_Error
    {
        if ($format === 'json') { $encoded = wp_json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); return is_string($encoded) ? $encoded : $this->error('json_encode', 'Unable to encode JSON asset.'); }
        if (! is_string($content)) { return $this->error('invalid_content', 'Asset content must be text.'); }
        if ($format === 'svg') { if (! str_contains(strtolower($content), '<svg')) { return $this->error('invalid_svg', 'SVG asset must contain an SVG root element.'); } if ($this->containsActiveMarkup($content)) { return $this->error('unsafe_svg', 'Generated SVG contains active or external content.'); } }
        if ($format === 'html') { if (! preg_match('/<(?:html|main|section|article|div|body)\b/i', $content)) { return $this->error('invalid_html', 'HTML asset does not contain usable markup.'); } $content = wp_kses_post($content); if ($content === '') { return $this->error('unsafe_html', 'Generated HTML was removed by safety sanitization.'); } }
        return $content;
    }

    private function containsActiveMarkup(string $content): bool
    {
        $patterns = ['/<\s*(?:script|iframe|object|embed|foreignObject)\b/i','/\bon[a-z]+\s*=/i','/\b(?:href|xlink:href)\s*=\s*["\']\s*(?:https?:|javascript:|data:)/i','/<!DOCTYPE\b/i','/<!ENTITY\b/i','/javascript\s*:/i'];
        foreach ($patterns as $pattern) { if (preg_match($pattern, $content) === 1) { return true; } }
        return false;
    }

    /** @param mixed $content @return array{bytes:string,evidence:array<string,mixed>}|WP_Error */
    private function pdf($content, string $filename = ''): array|WP_Error
    {
        $geometry=$this->pdfGeometry($filename);
        $prepared=PdfTextIntegrity::prepare($content,$geometry);
        if(is_wp_error($prepared))return $prepared;
        $objects=[];$pageIds=[];$fontId=3;$nextId=4;
        foreach($prepared['pages'] as $pageText){
            $pageId=$nextId++;$contentId=$nextId++;$pageIds[]=$pageId;
            $stream="BT /F1 11 Tf ".(int)$geometry['x']." ".(int)$geometry['y']." Td 14 TL\n";
            foreach(explode("\n",$pageText) as $line){
                $safe=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line);
                $stream.='('.$safe.") Tj T*\n";
            }
            $stream.="ET";
            $objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ".(int)$geometry['width']." ".(int)$geometry['height']."] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $kids=implode(' ',array_map(static fn(int $id):string=>$id.' 0 R',$pageIds));$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';ksort($objects);$pdf="%PDF-1.4\n% ".PdfTextIntegrity::PROFILE."\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($id=1;$id<=$max;$id++){$pdf.=sprintf('%010d 00000 n ',$offsets[$id]??0)."\n";}$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";unset($prepared['pages']);return ['bytes'=>$pdf,'evidence'=>$prepared];
    }

    /** @return array{width:int,height:int,x:int,y:int,wrap:int} */
    private function pdfGeometry(string $filename): array
    {
        $name = strtolower(sanitize_file_name($filename));
        if (str_contains($name, 'mobile')) { return ['width'=>360,'height'=>640,'x'=>28,'y'=>602,'wrap'=>48]; }
        if (preg_match('/(?:^|[_-])a4(?:[_-]|\.)/i', $name)) { return ['width'=>595,'height'=>842,'x'=>48,'y'=>790,'wrap'=>88]; }
        return ['width'=>612,'height'=>792,'x'=>50,'y'=>742,'wrap'=>92];
    }

    private function filename(string $filename,string $format):string{$filename=sanitize_file_name($filename);if($filename===''){return '';}$extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));if($extension!==$format){return '';}return substr($filename,0,160);}
    /** @return array<string,mixed> */
    private function metadata(string $path,string $filename,string $format,string $relativeDir):array{return['filename'=>$filename,'format'=>$format,'absolute_path'=>$path,'storage_reference'=>$relativeDir.'/'.$filename,'checksum_sha256'=>hash_file('sha256',$path),'byte_size'=>(int)filesize($path),'mime_type'=>$format==='zip'?'application/zip':$this->mime($format)];}
    private function mime(string $format):string{return match($format){'pdf'=>'application/pdf','svg'=>'image/svg+xml','html'=>'text/html','csv'=>'text/csv','json'=>'application/json','png'=>'image/png',default=>'text/plain'};}
    private function error(string $code,string $message,int $status=400):WP_Error{return new WP_Error('digiforge_u3_'.$code,__($message,'digiforge'),['status'=>$status]);}
}
