<?php
declare(strict_types=1);
namespace DigiForge\Research;

/** Bounded operator-supplied evidence. Never fetches sources or certifies supplier output. */
final class OpportunityDesignContract
{
    public static function normalize(array $input): array
    {
        self::keys($input, ['shop_key','title','sources','briefs','artwork']);
        if (($input['shop_key'] ?? null) !== 'personalized_pod') self::invalid();
        self::text($input['title'] ?? null, 300);
        $sources = $input['sources'] ?? null;
        if (!is_array($sources) || !array_is_list($sources) || count($sources)<1 || count($sources)>10) self::invalid();
        foreach ($sources as $source) {
            if (!is_array($source)) self::invalid();
            self::keys($source, ['url','observed_at','excerpt']);
            self::text($source['url'] ?? null, 2048);
            if (!filter_var($source['url'], FILTER_VALIDATE_URL) || parse_url($source['url'], PHP_URL_SCHEME)!=='https' || parse_url($source['url'], PHP_URL_USER)!==null) self::invalid();
            $date=$source['observed_at'] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $date)) self::invalid();
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, new \DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z')!==$date || $parsed->getTimestamp()>time()) self::invalid();
            self::text($source['excerpt'] ?? null, 4000);
        }
        $briefs=$input['briefs'] ?? null;
        if (!is_array($briefs) || !array_is_list($briefs) || count($briefs)!==3) self::invalid();
        $directions=[];
        foreach ($briefs as $brief) {
            if (!is_array($brief)) self::invalid();
            self::keys($brief, ['audience','visual_direction','rationale']);
            foreach (['audience','visual_direction','rationale'] as $field) self::text($brief[$field] ?? null, 4000);
            $directions[]=strtolower(trim((string)preg_replace('/\s+/', ' ', $brief['visual_direction'])));
        }
        if (count(array_unique($directions))!==3) self::invalid();
        $art=$input['artwork'] ?? null;
        if (!is_array($art)) self::invalid();
        self::keys($art, ['svg','width_px','height_px','dpi','selected_brief','native_layers','rights_note','safe_area_reviewed','font_license_reviewed','mockup_reviewed']);
        foreach (['width_px','height_px'] as $field) if (!is_int($art[$field] ?? null) || $art[$field]<1 || $art[$field]>20000) self::invalid();
        if (($art['dpi'] ?? null)!==300 || !in_array($art['selected_brief'] ?? null, [1,2,3], true)) self::invalid();
        foreach (['safe_area_reviewed','font_license_reviewed','mockup_reviewed'] as $field) if (($art[$field] ?? null)!==true) self::invalid();
        self::text($art['rights_note'] ?? null, 4000);
        if (!is_array($art['native_layers'] ?? null) || !array_is_list($art['native_layers']) || count($art['native_layers'])>10) self::invalid();
        foreach ($art['native_layers'] as $layer) self::text($layer, 300);
        self::svg($art);
        return $input;
    }

    private static function svg(array $art): void
    {
        $svg=$art['svg'] ?? null;
        if (!is_string($svg) || strlen($svg)>262144 || str_contains($svg,'<!') || str_contains($svg,'<?')) self::invalid();
        $doc=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { $loaded=$doc->loadXML($svg, LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $root=$doc->documentElement;
        if (!$loaded || !$root || $root->tagName!=='svg' || $root->namespaceURI!=='http://www.w3.org/2000/svg' || $root->getAttribute('width')!==(string)$art['width_px'] || $root->getAttribute('height')!==(string)$art['height_px']) self::invalid();
        $elements=['svg','g','path','rect','circle','ellipse','line','polyline','polygon','text','tspan'];
        $attributes=['width','height','viewBox','x','y','x1','x2','y1','y2','cx','cy','r','rx','ry','d','points','fill','stroke','stroke-width','opacity','transform','font-size','text-anchor'];
        foreach ($doc->getElementsByTagName('*') as $node) {
            if (!in_array($node->tagName,$elements,true) || $node->namespaceURI!=='http://www.w3.org/2000/svg') self::invalid();
            foreach ($node->attributes as $attribute) {
                if (!in_array($attribute->name,$attributes,true) || $attribute->namespaceURI!==null || preg_match('/url\s*\(|[<>]|javascript:|data:/i',$attribute->value)) self::invalid();
                if (in_array($attribute->name,['fill','stroke'],true) && !preg_match('/^(none|#[a-fA-F0-9]{3,8}|[a-zA-Z]+)$/D',$attribute->value)) self::invalid();
            }
        }
    }
    private static function keys(array $input,array $keys): void { if (array_diff(array_keys($input),$keys) || array_diff($keys,array_keys($input))) self::invalid(); }
    private static function text(mixed $value,int $max): void { if (!is_string($value) || trim($value)==='' || strlen($value)>$max || preg_match('//u',$value)!==1) self::invalid(); }
    private static function invalid(): never { throw new \InvalidArgumentException('Exact shop, source dates, three distinct briefs and reviewed 300-DPI editable SVG required.'); }
}
