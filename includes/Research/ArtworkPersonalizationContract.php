<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\POD\{LocalPersonalizationProof, PersonalizationPayloadContract};

/** Customer samples on exact approved design bytes. Never a production/supplier certificate. */
final class ArtworkPersonalizationContract
{
    public static function fields(array $spec, array $fields): array
    {
        $regions = $spec['layout']['native_regions'] ?? [];
        if (!array_is_list($fields) || count($fields) !== count($regions) || !$fields || count($fields)>10) {
            throw new \InvalidArgumentException('Map every native region exactly once.');
        }
        $mapped = [];
        foreach ($fields as $field) {
            if (!is_array($field) || count($field)!==4 || array_diff(array_keys($field), ['key','type','required','max_length'])
                || !is_string($field['key']??null) || !in_array($field['type']??null,['text','date'],true)
                || !is_bool($field['required']??null) || !is_int($field['max_length']??null)
                || $field['max_length']<1 || $field['max_length']>80 || isset($mapped[$field['key']])) {
                throw new \InvalidArgumentException('Bounded text/date fields required; images need manual review.');
            }
            if ($field['type']==='date' && $field['max_length']!==10) {
                throw new \InvalidArgumentException('ISO dates need exactly ten characters.');
            }
            $mapped[$field['key']] = $field;
        }
        $out=[];
        foreach ($regions as $region) {
            if (($region['kind']??null)!=='text' || !isset($mapped[$region['name']])) {
                throw new \InvalidArgumentException('Only exact reserved text regions are supported; image/crop needs manual review.');
            }
            $out[]=$mapped[$region['name']];
        }
        return $out;
    }

    /** Explicit opt-in. Never upgrade an existing schema or infer a policy from sample text. */
    public static function datePolicy(array $spec,array $fields,?string $policy):?string
    {
        if($policy===null)return null;
        $profile=$spec['profile']??null;$cap=['fl03_mug_11oz'=>13,'fl04_poster_8x10'=>16][$profile]??null;
        if($policy!==\DigiForge\POD\OfflineDateDisplayProof::POLICY||$cap===null||count($fields)!==3)throw new \InvalidArgumentException('Supported explicit date policy and profile required.');
        $by=[];foreach(self::fields($spec,$fields)as $field)$by[$field['key']]=$field;
        foreach(['first_name','second_name','date']as $key){$f=$by[$key]??null;if(!is_array($f)||!$f['required']||$f['type']!==($key==='date'?'date':'text')||$f['max_length']>($key==='date'?10:$cap))throw new \InvalidArgumentException('Policy requires exact required name/name/ISO date fields within preserved native caps.');}
        return $policy;
    }

    public static function proof(array $spec, string $artwork, array $fields, array $samples, ?string $datePolicy=null): array
    {
        $fields=self::fields($spec,$fields);
        PersonalizationPayloadContract::validate($fields,$samples);
        if(self::datePolicy($spec,$fields,$datePolicy)!==null){
            $product=$spec['profile']==='fl03_mug_11oz'?'FL-03':'FL-04';
            $display=\DigiForge\POD\OfflineDateDisplayProof::render($product,$spec,$artwork,$samples,$datePolicy);
            $result=$display['proof'];
            $result['date_display']=['policy'=>$display['policy'],'policy_sha256'=>$display['policy_sha256'],
                'canonical_inputs_sha256'=>$display['canonical_inputs_sha256'],'samples'=>$result['samples'],
                'display_inputs_sha256'=>$display['display_inputs_sha256'],'receipt_sha256'=>$display['receipt_sha256']];
            // Existing intake consumers keep canonical input semantics, never generated display text.
            $result['samples']=$samples;$result['fields']=$fields;
            $result['sample_values_sha256']=hash('sha256',CommercialResearchContract::json($samples));
            return $result;
        }

        $values=[];$reasons=[];
        foreach($fields as $field){
            $v=$samples[$field['key']]??'';
            if(trim($v)==='' && $field['required'])throw new \InvalidArgumentException('Required value cannot be whitespace.');
            if(preg_match('/[\p{C}\p{Zl}\p{Zp}]/u',$v))throw new \InvalidArgumentException('Single-line plain values required.');
            if($v!=='' && !preg_match('/^[\p{Latin}\p{M}0-9 .\x{2019}\x{0027}-]+$/u',$v))$reasons[]='GLYPH_COVERAGE_UNVERIFIED';
            if(array_key_exists($field['key'],$samples))$values[$field['key']]=$v;
        }
        $svg=null;$render=null;
        if(!$reasons){
            try{$render=LocalPersonalizationProof::render($spec,$artwork,$values);$svg=$render['svg'];}
            catch(\InvalidArgumentException){$reasons[]='LAYOUT_OR_UNSUPPORTED_FIELD_MANUAL_REVIEW';}
        }
        // Validate the fixed source even for held samples; a hold cannot admit malformed artwork.
        DesignFactoryContract::svg($artwork,$spec);
        return ['state'=>$svg===null?'MANUAL_HOLD':'MANUAL_REVIEW','reasons'=>array_values(array_unique($reasons)),
            'samples'=>$values,'fields'=>$fields,'svg'=>$svg,'proof_sha256'=>$svg===null?null:hash('sha256',$svg),
            'artwork_sha256'=>hash('sha256',$artwork),'specification_sha256'=>hash('sha256',CommercialResearchContract::json($spec)),
            'sample_values_sha256'=>hash('sha256',CommercialResearchContract::json($values)),
            'render_receipt'=>$render['receipt']??null,'native_fields_separate'=>true,'production_file'=>false,
            'provider_font_parity'=>'UNVERIFIED','preview_final_parity'=>'UNVERIFIED',
            'external_execution_authorized'=>false,'release_hold'=>true];
    }
}
