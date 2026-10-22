<?php

declare(strict_types=1);
namespace DigiForge\ProductFactory;

/** Bounded plain-text PDF completeness; never commercial or linguistic approval. */
final class PdfTextIntegrity
{
    public const PROFILE = 'digiforge-local-text-pdf-v2';
    public const MAX_PAGES = 100;
    public const MAX_BYTES = 8388608;

    /** @return array{pages:list<string>,pdf_source_sha256:string,pdf_text_sha256:string,pdf_generator_profile:string}|\WP_Error */
    public static function prepare(mixed $content,array $geometry): array|\WP_Error
    {
        if(!is_string($content)&&(!is_array($content)||!array_is_list($content)))return self::error('invalid_pdf_content','PDF content must be plain text or an ordered list of text pages.');
        if(is_string($content)&&strlen($content)>self::MAX_BYTES)return self::error('pdf_document_limit','PDF source exceeds the 8 MiB document limit.');
        $raw=is_string($content)?explode('[[PAGE_BREAK]]',$content):$content;
        if(count($raw)>self::MAX_PAGES)return self::error('pdf_document_limit','PDF supports at most 100 explicit page sections.');
        $bytes=0;
        foreach($raw as $page){
            if(!is_string($page))return self::error('invalid_pdf_content','Every PDF page must be plain text.');
            $bytes+=strlen($page);
            if($bytes>self::MAX_BYTES)return self::error('pdf_document_limit','PDF source exceeds the 8 MiB document limit.');
            // The built-in font has no Unicode mapping. Never transliterate, normalize,
            // substitute glyphs, strip markup-like text or silently expand control bytes.
            if(preg_match('/[^\x20-\x7e\r\n]/',$page))return self::error('pdf_unsupported_text','This PDF profile supports printable ASCII and explicit line breaks only. Unsupported text requires a separately reviewed renderer.');
        }
        $pages=[];
        foreach($raw as $page){
            if(trim($page)==='')continue; // Optional absent/blank sections contain no required text.
            $page=str_replace(["\r\n","\r"],"\n",$page);
            $lines=explode("\n",$page);
            $maxLines=(int)floor(((int)$geometry['y']-(int)$geometry['x'])/14)+1;
            if(count($lines)>$maxLines)return self::error('pdf_page_overflow','PDF page exceeds its complete visible line capacity. Supply explicitly reviewed page breaks; no content was omitted.');
            // Conservative 12-point advance bound for the fixed 11-point standard
            // Helvetica font. No automatic wrapping, shrinking or content rewriting.
            $maxColumns=(int)floor(((int)$geometry['width']-2*(int)$geometry['x'])/12);
            foreach($lines as $line)if(strlen($line)>$maxColumns)return self::error('pdf_line_overflow','PDF line exceeds the bounded visible width. Supply explicitly reviewed line breaks; no content was omitted.');
            $pages[]=$page;
        }
        if($pages===[])return self::error('invalid_pdf_content','PDF requires at least one nonblank text page.');
        $source=json_encode(['kind'=>is_string($content)?'text':'pages','content'=>$content],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $text=json_encode($pages,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($source)||!is_string($text))return self::error('invalid_pdf_content','PDF source cannot be encoded without loss.');
        return ['pages'=>$pages,'pdf_source_sha256'=>hash('sha256',$source),'pdf_text_sha256'=>hash('sha256',$text),'pdf_generator_profile'=>self::PROFILE];
    }

    /** Only verifies this exact local profile against separately supplied expected text. */
    public static function matches(string $pdf,array $asset): bool
    {
        if(strlen($pdf)>self::MAX_BYTES||!str_starts_with($pdf,"%PDF-1.4\n% ".self::PROFILE."\n")||($asset['pdf_generator_profile']??null)!==self::PROFILE)return false;
        foreach(['pdf_source_sha256','pdf_text_sha256'] as $key)if(!is_string($asset[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$asset[$key])!==1)return false;
        preg_match_all('/stream\n(.*?)\nendstream/s',$pdf,$streams);
        preg_match_all('/^[0-9]+ 0 obj\n<< \/Type \/Page \/Parent 2 0 R \/MediaBox \[0 0 ([0-9]+) ([0-9]+)\]/m',$pdf,$boxes,PREG_SET_ORDER);
        if(count($streams[1])<1||count($streams[1])>self::MAX_PAGES||count($boxes)!==count($streams[1]))return false;
        if(!str_contains($pdf,"3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj"))return false;
        $pages=[];
        foreach($streams[1] as $index=>$stream){
            if(!preg_match('/\ABT \/F1 11 Tf ([0-9]+) ([0-9]+) Td 14 TL\n(.*)ET\z/s',$stream,$body))return false;
            preg_match_all('/\(((?:\\\\[\\\\()]|[^\\\\()\r\n])*)\) Tj T\*\n/',$body[3],$operators,PREG_SET_ORDER);
            if($operators===[]||implode('',array_column($operators,0))!==$body[3])return false;
            $x=(int)$body[1];$y=(int)$body[2];$width=(int)$boxes[$index][1];$height=(int)$boxes[$index][2];
            if($x<1||2*$x>=$width||$y+11>$height||$y-14*(count($operators)-1)<$x)return false;
            $maxColumns=(int)floor(($width-2*$x)/12);
            $lines=[];
            foreach($operators as $operator){
                $line=preg_replace_callback('/\\\\([\\\\()])/',static fn(array $match):string=>$match[1],$operator[1]);
                if(!is_string($line)||strlen($line)>$maxColumns||preg_match('/[^\x20-\x7e]/',$line))return false;
                $lines[]=$line;
            }
            $pages[]=implode("\n",$lines);
        }
        $canonical=json_encode($pages,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($canonical)&&hash_equals($asset['pdf_text_sha256'],hash('sha256',$canonical));
    }

    /** Rechecks local-generator evidence before Gate 2 or release validation. */
    public static function validateRevision(array $revision): true|\WP_Error
    {
        if(($revision['mime_type']??'')!=='application/pdf')return true;
        $provenance=$revision['provenance']??null;
        if(is_string($provenance))$provenance=json_decode($provenance,true);
        if(!is_array($provenance))return self::error('pdf_review_evidence','PDF provenance is unavailable; source-complete review is required.');
        if(($provenance['generator']??null)!=='digiforge_u3')return true; // Other governed PDF adapters keep their own contracts.
        $hash=$revision['checksum_sha256']??null;
        if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||($provenance['pdf_generator_profile']??null)!==self::PROFILE)return self::error('pdf_review_evidence','Local PDF requires current source-completeness evidence before approval or release.');
        foreach(['pdf_source_sha256','pdf_text_sha256'] as $key)if(!is_string($provenance[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$provenance[$key])!==1)return self::error('pdf_review_evidence','Local PDF source identity is missing or invalid.');
        global $wpdb;$wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT status,details FROM ".\DigiForge\Database\Tables::production_qa()." WHERE target_type='revision' AND target_id=%d AND check_type='pdf_source_text_completeness'",(int)($revision['id']??0)),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($rows))return new \WP_Error('digiforge_u3_pdf_review_unavailable','PDF source-completeness evidence is unavailable.',['status'=>503]);
        if(count($rows)!==1||($rows[0]['status']??null)!=='PASS')return self::error('pdf_review_evidence','One unambiguous passing PDF source-completeness check is required.');
        $details=json_decode((string)$rows[0]['details'],true);
        if(!is_array($details)||($details['generator_profile']??null)!==self::PROFILE)return self::error('pdf_review_evidence','PDF completeness profile is invalid.');
        foreach(['artifact_sha256'=>$hash,'source_sha256'=>$provenance['pdf_source_sha256'],'expected_text_sha256'=>$provenance['pdf_text_sha256']] as $key=>$expected)if(!is_string($details[$key]??null)||!hash_equals($expected,$details[$key]))return self::error('pdf_review_evidence','PDF completeness evidence does not match the immutable revision.');
        $path=AssetStorage::absolutePath((string)($revision['storage_reference']??''));
        if($path===null||!is_readable($path)||filesize($path)>self::MAX_BYTES)return self::error('pdf_review_evidence','PDF source-bound artifact is unavailable.');
        $bytes=@file_get_contents($path,false,null,0,self::MAX_BYTES+1);
        if(!is_string($bytes)||!hash_equals($hash,hash('sha256',$bytes))||!self::matches($bytes,$provenance))return self::error('pdf_review_evidence','PDF artifact integrity or source completeness does not match its review evidence.');
        return true;
    }

    private static function error(string $code,string $message): \WP_Error
    {
        return new \WP_Error('digiforge_u3_'.$code,__($message,'digiforge'),['status'=>400]);
    }
}
