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
        $bytes = $format === 'pdf' ? $this->pdf($content) : $this->text($format, $content);
        if (is_wp_error($bytes)) { return $bytes; }
        if ($bytes === '' || strlen($bytes) > self::MAX_ASSET_BYTES) { return $this->error('invalid_asset_size', 'Generated asset is empty or too large.'); }
        $uploads = wp_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir'])) { return $this->error('upload_directory', 'WordPress uploads directory is unavailable.', 500); }
        $relativeDir = AssetStorage::RELATIVE_ROOT . '/' . $productVersionId . ($storageVariant !== '' ? '/' . $storageVariant : '');
        $directory = trailingslashit((string) $uploads['basedir']) . $relativeDir;
        if (! wp_mkdir_p($directory)) { return $this->error('asset_directory', 'Unable to create DigiForge asset directory.', 500); }
        $path = trailingslashit($directory) . $filename;
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            $newHash = hash('sha256', $bytes);
            if (is_string($existingHash) && hash_equals($existingHash, $newHash)) { return $this->metadata($path, $filename, $format, $relativeDir); }
            return $this->error('asset_replay_conflict', 'Existing generated asset differs from replay payload.', 409);
        }
        $temp = $path . '.tmp-' . wp_generate_password(8, false, false);
        if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($temp); return $this->error('asset_write', 'Unable to write generated asset.', 500); }
        if (! @rename($temp, $path)) { @unlink($temp); return $this->error('asset_commit', 'Unable to finalize generated asset.', 500); }
        return $this->metadata($path, $filename, $format, $relativeDir);
    }

    /** @param array<int,array<string,mixed>> $assets @return array<string,mixed>|WP_Error */
    public function package(int $productVersionId, array $assets, string $filename = 'customer-package.zip', string $storageVariant = ''): array|WP_Error
    {
        if (! AssetStorage::ensureProtectedRoot()) { return $this->error('asset_storage_protection', 'Protected DigiForge asset storage could not be established.', 500); }
        if (! class_exists('ZipArchive')) { return $this->error('zip_unavailable', 'ZIP support is unavailable on this server.', 500); }
        $uploads = wp_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir'])) { return $this->error('upload_directory', 'WordPress uploads directory is unavailable.', 500); }
        $storageVariant = sanitize_key($storageVariant);
        $relativeDir = AssetStorage::RELATIVE_ROOT . '/' . $productVersionId . ($storageVariant !== '' ? '/' . $storageVariant : '');
        $directory = trailingslashit((string) $uploads['basedir']) . $relativeDir;
        if (! wp_mkdir_p($directory)) { return $this->error('asset_directory', 'Unable to create DigiForge asset directory.', 500); }
        $filename = $this->filename($filename, 'zip');
        if ($filename === '') { return $this->error('invalid_filename', 'A safe ZIP filename is required.'); }
        $path = trailingslashit($directory) . $filename;
        $temp = $path . '.tmp-' . wp_generate_password(8, false, false);
        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) { return $this->error('zip_create', 'Unable to create product ZIP package.', 500); }
        foreach ($assets as $asset) {
            $source = (string) ($asset['absolute_path'] ?? '');
            $name = basename((string) ($asset['filename'] ?? ''));
            if ($source === '' || $name === '' || ! is_file($source) || ! $zip->addFile($source, $name)) { $zip->close(); @unlink($temp); return $this->error('zip_asset', 'Unable to add a required product asset to ZIP.', 500); }
        }
        $zip->close();
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            $newHash = hash_file('sha256', $temp);
            if (is_string($existingHash) && is_string($newHash) && hash_equals($existingHash, $newHash)) { @unlink($temp); return $this->metadata($path, $filename, 'zip', $relativeDir); }
            @unlink($temp);
            return $this->error('asset_replay_conflict', 'Existing generated asset differs from replay payload.', 409);
        }
        if (! @rename($temp, $path)) { @unlink($temp); return $this->error('asset_commit', 'Unable to finalize generated asset.', 500); }
        return $this->metadata($path, $filename, 'zip', $relativeDir);
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

    /** @param mixed $content */
    private function pdf($content): string|WP_Error
    {
        $pages = is_array($content) ? $content : [$content];
        $pages = array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $pages), static fn(string $v): bool => $v !== ''));
        if ($pages === []) { return $this->error('invalid_pdf_content', 'PDF requires at least one text page.'); }
        $objects=[];$pageIds=[];$fontId=3;$nextId=4;
        foreach($pages as $pageText){$pageId=$nextId++;$contentId=$nextId++;$pageIds[]=$pageId;$lines=$this->wrap(strip_tags($pageText),92);$stream="BT /F1 11 Tf 50 790 Td 14 TL\n";foreach($lines as $line){if(function_exists('iconv')){$converted=iconv('UTF-8','Windows-1252//TRANSLIT',$line);if(is_string($converted)){$line=$converted;}}$safe=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line);$stream.='('.$safe.") Tj T*\n";}$stream.="ET";$objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$contentId} 0 R >>";$objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";}
        $kids=implode(' ',array_map(static fn(int $id):string=>$id.' 0 R',$pageIds));$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($id=1;$id<=$max;$id++){$pdf.=sprintf('%010d 00000 n ',$offsets[$id]??0)."\n";}$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";return $pdf;
    }

    /** @return list<string> */
    private function wrap(string $text,int $width):array{$lines=[];foreach(preg_split('/\R/u',$text)?:[] as $paragraph){$wrapped=wordwrap(trim($paragraph),$width,"\n",true);foreach(explode("\n",$wrapped) as $line){$lines[]=function_exists('mb_substr')?mb_substr($line,0,180):substr($line,0,180);}$lines[]='';}return array_slice($lines,0,54);}
    private function filename(string $filename,string $format):string{$filename=sanitize_file_name($filename);if($filename===''){return '';}$extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));if($extension!==$format){return '';}return substr($filename,0,160);}
    /** @return array<string,mixed> */
    private function metadata(string $path,string $filename,string $format,string $relativeDir):array{return['filename'=>$filename,'format'=>$format,'absolute_path'=>$path,'storage_reference'=>$relativeDir.'/'.$filename,'checksum_sha256'=>hash_file('sha256',$path),'byte_size'=>(int)filesize($path),'mime_type'=>$format==='zip'?'application/zip':$this->mime($format)];}
    private function mime(string $format):string{return match($format){'pdf'=>'application/pdf','svg'=>'image/svg+xml','html'=>'text/html','csv'=>'text/csv','json'=>'application/json',default=>'text/plain'};}
    private function error(string $code,string $message,int $status=400):WP_Error{return new WP_Error('digiforge_u3_'.$code,__($message,'digiforge'),['status'=>$status]);}
}
