<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\POD\ArtworkGeometryQa;

/** Owner-approved rules over independently reviewed local declarations, never authentic certification. */
final class ApprovedAcceptancePolicy
{
    public static function rules(string $criterion): array
    {
        return match ($criterion) {
            'BP-seam-panel-render' => ['geometry'=>'EXACT_VERSIONED_PANEL_SEAM_SAFE_BLEED','alignment'=>'INDEPENDENT_REVIEW','missing'=>'BLOCKED'],
            'BP-ai-value-routing' => ['priority'=>'DETERMINISTIC_FIRST','ai'=>'PERMITTED_VALUE_ADDING_REVIEWED','missing'=>'FALLBACK'],
            'BP-template-qa-spelling' => ['locale'=>'en-US_PROPOSED','review'=>'AUTOMATED_AND_HUMAN','max_code_points'=>48,'normalization'=>'NONE','auto_shrink'=>false],
            'BP-portal-7-supplier-readiness' => ['states'=>['UNKNOWN','BLOCKED','REVIEW_REQUIRED','READY'],'ready'=>'AUTHENTIC_EVIDENCE_AND_REVIEW','release_authority'=>false],
            'BP-portal-17-conversion' => ['formula'=>'ATTRIBUTABLE_ORDERS/ELIGIBLE_VISITS*100','cohort'=>'EXACT','missing'=>'UNKNOWN'],
            'BP-portal-17-defect-quality' => ['formula'=>'DEFECTIVE_FULFILLED_UNITS/ELIGIBLE_FULFILLED_UNITS*100','deduplicate'=>'UNIT','missing'=>'UNKNOWN'],
            'BP-portal-17-supplier-performance' => ['dimensions'=>['timeliness','defects','exceptions'],'weighted_score'=>false,'attribution'=>'SUPPLIER'],
            'BP-portal-17-product-performance' => ['dimensions'=>['orders','conversion','fulfillment','quality'],'version_aware'=>true,'profit'=>false],
            'BP-portal-17-shop-performance' => ['dimensions'=>['orders','conversion','fulfillment','quality'],'shop_isolation'=>true,'aggregation'=>'EXPLICIT_SCOPE'],
            'BP-quality-feedback' => ['review'=>'INDEPENDENT','attribution'=>['shop','product','version','supplier','order'],'corrections'=>'APPEND_ONLY','privacy'=>'NO_FREE_TEXT','allegation_is_defect'=>false],
            default => throw new \InvalidArgumentException('Unsupported approved policy.'),
        };
    }

    /** Version 2 is additive; v1 declarations retain their existing meaning. */
    public static function valid(string $shop, string $criterion, array $d): bool
    {
        try {
            self::exact($d,['policy','environment','subject','period_start','period_end','source_records','observations']);
            if ($d['policy'] != self::rules($criterion) || !self::sameKeysRecursive($d['policy'],self::rules($criterion))
                || !in_array($d['environment'],['sandbox','production'],true)) return false;
            self::subject($d['subject'],$criterion);
            $start=self::instant($d['period_start']);$end=self::instant($d['period_end']);
            if ($start >= $end) return false;
            if (!is_array($d['source_records']) || !array_is_list($d['source_records']) || count($d['source_records'])>32) return false;
            $refs=[];
            foreach ($d['source_records'] as $ref) {
                self::exact($ref,['shop_key','reference','sha256']);
                if ($ref['shop_key']!==$shop || !is_string($ref['reference']) || preg_match('#^local://[A-Za-z0-9][A-Za-z0-9/_.:-]{0,230}$#D',$ref['reference'])!==1) return false;
                foreach (explode('/',substr($ref['reference'],8)) as $part) if ($part==='.'||$part==='..') return false;
                self::digest($ref['sha256']);
                if (isset($refs[$ref['reference']])) return false;
                $refs[$ref['reference']]=$ref['sha256'];
            }
            if ($d['observations']===null) return true;
            if (!is_array($d['observations'])) return false;
            $o=$d['observations'];
            if (self::metric($criterion)) self::records($shop,$d,$refs);
            elseif ($criterion==='BP-quality-feedback') self::feedback($shop,$d,$refs);
            elseif ($criterion==='BP-portal-7-supplier-readiness') {
                self::exact($o,['product','supplier','variant','geometry','rights','fulfillment','review']);
                foreach ($o as $hash) if ($hash!==null) self::digest($hash);
            } elseif ($criterion==='BP-ai-value-routing') {
                self::exact($o,['deterministic_available','requested','permitted','provenance_sha256','benefit_review_sha256']);
                if (!is_bool($o['deterministic_available'])||!is_bool($o['permitted'])||!in_array($o['requested'],['ORIGINAL','DETERMINISTIC','AI'],true)) return false;
                foreach (['provenance_sha256','benefit_review_sha256'] as $key) if ($o[$key]!==null) self::digest($o[$key]);
            } elseif ($criterion==='BP-template-qa-spelling') {
                self::exact($o,['template_sha256','locale','text_sha256','automated_review_sha256','human_review_sha256','family_name_code_points','established_code_points','glyphs_supported','overflow','auto_shrink','normalization']);
                foreach (['template_sha256','text_sha256','automated_review_sha256','human_review_sha256'] as $key) if ($o[$key]!==null) self::digest($o[$key]);
                if ($o['locale']!=='en-US'||$o['normalization']!=='NONE'||$o['auto_shrink']!==false||!is_bool($o['glyphs_supported'])||!is_bool($o['overflow'])) return false;
                foreach (['family_name_code_points','established_code_points'] as $key) if (!is_int($o[$key])||$o[$key]<($key==='family_name_code_points'?1:0)||$o[$key]>48) return false;
            } elseif ($criterion==='BP-seam-panel-render') {
                self::exact($o,['template_sha256','geometry_sha256','panels','alignment_review_sha256']);
                foreach (['template_sha256','geometry_sha256','alignment_review_sha256'] as $key) if ($o[$key]!==null) self::digest($o[$key]);
                if (!is_array($o['panels'])||!array_is_list($o['panels'])||count($o['panels'])>32) return false;
                $ids=[];$geometry=[];
                foreach ($o['panels'] as $panel) {
                    self::exact($panel,['id','artifact_sha256','preview_sha256','geometry']);self::id($panel['id']);
                    if (isset($ids[$panel['id']])) return false;$ids[$panel['id']]=true;
                    self::digest($panel['artifact_sha256']);self::digest($panel['preview_sha256']);
                    $geometry[$panel['id']]=$panel['geometry'];
                    $qa=ArtworkGeometryQa::policy($panel['geometry']);
                    if ($qa['state']!=='VERIFIED_LOCAL_GEOMETRY'||$qa['bleed_state']!=='VERIFIED_LOCAL_BLEED_GEOMETRY') return false;
                }
                ksort($geometry);
                if ($o['geometry_sha256']!==null && $o['geometry_sha256']!==hash('sha256',\DigiForge\Listings\Validator::canonicalJson($geometry))) return false;
            } else return false;
            return true;
        } catch (\Throwable) { return false; }
    }

    /** All source facts are reviewed declarations. This method has no record loader or authority. */
    public static function evaluate(string $shop, string $criterion, array $d): array
    {
        $out=self::unknown();
        if (!self::valid($shop,$criterion,$d)) return $out;
        $out['source_records']=$d['source_records'];$out['subject']=$d['subject'];
        $out['period']=['start'=>$d['period_start'],'end_exclusive'=>$d['period_end']];
        $o=$d['observations'];
        if ($o===null || $d['source_records']===[]) return $out;
        if (self::metric($criterion)) return array_replace($out,self::metrics($o));
        if ($criterion==='BP-portal-7-supplier-readiness') {
            $out['state']=in_array(null,$o,true)?'BLOCKED':'REVIEW_REQUIRED';
            $out['reason']='AUTHENTIC_RECORD_RESOLUTION_REQUIRED'; // A signed assertion alone cannot produce READY.
        } elseif ($criterion==='BP-ai-value-routing') {
            $out['recommendation']=$o['deterministic_available']?'DETERMINISTIC':'ORIGINAL';
            $out['state']='LOCAL_POLICY_EVALUATED';
            if (!$o['deterministic_available']&&$o['requested']==='AI'&&$o['permitted']&&$o['provenance_sha256']!==null&&$o['benefit_review_sha256']!==null) {
                $out['state']='REVIEW_REQUIRED';$out['reason']='MEASURED_BENEFIT_RULE_AND_PERMISSION_MUST_RESOLVE';
            }
        } elseif ($criterion==='BP-template-qa-spelling') {
            if ($o['overflow']||!$o['glyphs_supported']) $out['state']='BLOCKED';
            elseif (!in_array(null,[$o['template_sha256'],$o['text_sha256'],$o['automated_review_sha256'],$o['human_review_sha256']],true)) $out['state']='MATCHED_REVIEWED_DECLARATION';
            $out['linguistic_approved']=false;
        } elseif ($criterion==='BP-seam-panel-render') {
            if ($o['panels']===[]||in_array(null,[$o['template_sha256'],$o['geometry_sha256'],$o['alignment_review_sha256']],true)) $out['state']='BLOCKED';
            else {
                $out['state']='MATCHED_REVIEWED_DECLARATION';
                foreach ($o['panels'] as $panel) if ($panel['artifact_sha256']!==$panel['preview_sha256']) $out['state']='BLOCKED';
            }
        } elseif ($criterion==='BP-quality-feedback') {
            $heads=[];
            foreach ($o as $record) $heads[$record['feedback_id']]=$record;
            $out['state']='LOCAL_POLICY_EVALUATED';$out['feedback']=array_map(static fn(array $r):array=>['feedback_id'=>$r['feedback_id'],'order_id'=>$r['order_id'],'product_id'=>$r['product_id'],'product_version'=>$r['product_version'],'supplier_id'=>$r['supplier_id'],'status'=>$r['status'],'source_reference'=>$r['source_reference'],'correction_revision'=>$r['revision']],array_values($heads));
        }
        return $out;
    }

    public static function unknown(): array
    {
        return ['state'=>'UNKNOWN','value_state'=>'UNKNOWN','evidence_state'=>'UNVERIFIED','purpose_verified'=>false,'source_records'=>[],
            'read_only'=>true,'external_execution_authorized'=>false,'supplier_approved'=>false,'production_authorized'=>false,'publishing_allowed'=>false,'ai_execution_allowed'=>false];
    }

    private static function metric(string $criterion): bool { return str_starts_with($criterion,'BP-portal-17-'); }
    private static function subject(mixed $s,string $c): void
    {
        self::exact($s,['scope','product_id','product_version','supplier_id']);
        if (!in_array($s['scope'],['SHOP','PRODUCT_VERSION','SUPPLIER'],true)) throw new \InvalidArgumentException();
        foreach (['product_id','product_version','supplier_id'] as $key) if ($s[$key]!==null) self::id($s[$key]);
        if (($s['scope']==='SHOP'&&($s['product_id']!==null||$s['product_version']!==null||$s['supplier_id']!==null))
            ||($s['scope']==='PRODUCT_VERSION'&&($s['product_id']===null||$s['product_version']===null))
            ||($s['scope']==='SUPPLIER'&&($s['supplier_id']===null||$s['product_id']!==null||$s['product_version']!==null))
            ||(in_array($c,['BP-portal-17-product-performance','BP-template-qa-spelling','BP-seam-panel-render','BP-portal-7-supplier-readiness'],true)&&$s['scope']!=='PRODUCT_VERSION')
            ||($c==='BP-portal-17-supplier-performance'&&$s['scope']!=='SUPPLIER')
            ||($c==='BP-portal-17-shop-performance'&&$s['scope']!=='SHOP')) throw new \InvalidArgumentException();
    }
    private static function scopedRecord(array $r,string $shop,array $d,array $refs): void
    {
        if ($r['shop_key']!==$shop||!isset($refs[$r['source_reference']])) throw new \InvalidArgumentException();
        foreach (['product_id','product_version'] as $key) self::id($r[$key]);
        if ($r['supplier_id']!==null) self::id($r['supplier_id']);
        foreach (['product_id','product_version','supplier_id'] as $key) if ($d['subject'][$key]!==null&&$d['subject'][$key]!==$r[$key]) throw new \InvalidArgumentException();
        $t=self::instant($r['occurred_at']);
        if ($t<self::instant($d['period_start'])||$t>=self::instant($d['period_end'])) throw new \InvalidArgumentException();
    }
    private static function records(string $shop,array $d,array $refs): void
    {
        $o=$d['observations'];self::exact($o,['coverage','records']);self::exact($o['coverage'],['visits','orders','units','defects']);
        foreach ($o['coverage'] as $complete) if (!is_bool($complete)) throw new \InvalidArgumentException();
        if (!is_array($o['records'])||!array_is_list($o['records'])||count($o['records'])>1000) throw new \InvalidArgumentException();
        $seen=[];$visits=[];
        foreach ($o['records'] as $r) {
            if (!is_array($r)) throw new \InvalidArgumentException();
            $extra=match($r['kind']??null){'VISIT'=>[],'ORDER'=>['visit_id'],'UNIT'=>['order_id','on_time','exception','defect'],default=>throw new \InvalidArgumentException()};
            self::exact($r,array_merge(['kind','id','shop_key','product_id','product_version','supplier_id','occurred_at','source_reference'],$extra));
            self::id($r['id']);self::scopedRecord($r,$shop,$d,$refs);
            $key=$r['kind'].':'.$r['id'];
            if (isset($seen[$key])&&$seen[$key]!=$r) throw new \InvalidArgumentException();$seen[$key]=$r;
            if ($r['kind']==='VISIT') $visits[$r['id']]=$r;
            if ($r['kind']==='ORDER'&&$r['visit_id']!==null) self::id($r['visit_id']);
            if ($r['kind']==='UNIT') {
                self::id($r['order_id']);
                if (($r['on_time']!==null&&!is_bool($r['on_time']))||($r['exception']!==null&&!is_bool($r['exception']))||!in_array($r['defect'],['CONFIRMED','CLEARED','UNREVIEWED','UNKNOWN'],true)) throw new \InvalidArgumentException();
            }
        }
        foreach ($seen as $r) {
            if ($r['kind']==='ORDER'&&$r['visit_id']!==null) {
                $v=$visits[$r['visit_id']]??null;
                if ($v===null||$v['product_id']!==$r['product_id']||$v['product_version']!==$r['product_version']||self::instant($v['occurred_at'])>self::instant($r['occurred_at'])) throw new \InvalidArgumentException();
            }
            if ($r['kind']==='UNIT') {
                $order=$seen['ORDER:'.$r['order_id']]??null;
                if ($order===null||$order['product_id']!==$r['product_id']||$order['product_version']!==$r['product_version']||$order['supplier_id']!==$r['supplier_id']||self::instant($order['occurred_at'])>self::instant($r['occurred_at'])) throw new \InvalidArgumentException();
            }
        }
    }
    private static function metrics(array $o): array
    {
        $rows=[];foreach ($o['records'] as $r) $rows[$r['kind']][$r['id']]=$r;
        $visits=$rows['VISIT']??[];$orders=$rows['ORDER']??[];$units=$rows['UNIT']??[];$coverage=$o['coverage'];
        $attributed=count(array_filter($orders,static fn(array $r):bool=>$r['visit_id']!==null));
        $defects=count(array_filter($units,static fn(array $r):bool=>$r['defect']==='CONFIRMED'));
        $defectsComplete=$coverage['defects']&&count(array_filter($units,static fn(array $r):bool=>in_array($r['defect'],['UNKNOWN','UNREVIEWED'],true)))===0;
        $metric=static fn(bool $known,int|float $value):array=>['state'=>$known?'LOCAL_CALCULATED':'UNKNOWN','value'=>$known?$value:null];
        $rate=static fn(bool $known,int $n,int $den):array=>['state'=>$known&&$den>0?'LOCAL_CALCULATED':'UNKNOWN','value'=>$known&&$den>0?(float)$n/$den*100.0:null,'numerator'=>$known?$n:null,'denominator'=>$known?$den:null];
        $onTime=count(array_filter($units,static fn(array $r):bool=>$r['on_time']===true));$exceptions=count(array_filter($units,static fn(array $r):bool=>$r['exception']===true));
        return ['state'=>'LOCAL_CALCULATED','value_state'=>'LOCAL_CALCULATED','metrics'=>[
            'orders'=>$metric($coverage['orders'],count($orders)),
            'fulfilled_units'=>$metric($coverage['units'],count($units)),
            'conversion_percent'=>$rate($coverage['visits']&&$coverage['orders'],$attributed,count($visits)),
            'defect_percent'=>$rate($coverage['units']&&$defectsComplete,$defects,count($units)),
            'timeliness'=>['on_time_units'=>$coverage['units']?$onTime:null,'eligible_units'=>$coverage['units']?count($units):null,'state'=>$coverage['units']&&count(array_filter($units,static fn(array $r):bool=>$r['on_time']===null))===0?'LOCAL_COUNTS':'UNKNOWN'],
            'exceptions'=>['exception_units'=>$coverage['units']?$exceptions:null,'eligible_units'=>$coverage['units']?count($units):null,'state'=>$coverage['units']&&count(array_filter($units,static fn(array $r):bool=>$r['exception']===null))===0?'LOCAL_COUNTS':'UNKNOWN'],
        ]];
    }
    private static function feedback(string $shop,array $d,array $refs): void
    {
        $records=$d['observations'];if (!array_is_list($records)||count($records)>1000) throw new \InvalidArgumentException();$heads=[];
        foreach ($records as $r) {
            self::exact($r,['feedback_id','order_id','shop_key','product_id','product_version','supplier_id','occurred_at','source_reference','revision','previous_sha256','status']);
            foreach (['feedback_id','order_id'] as $key) self::id($r[$key]);self::scopedRecord($r,$shop,$d,$refs);
            if (!is_int($r['revision'])||!in_array($r['status'],['ALLEGED','CONFIRMED','REJECTED'],true)) throw new \InvalidArgumentException();
            $prev=$heads[$r['feedback_id']]??null;
            if ($r['revision']!==($prev===null?1:$prev['revision']+1)||$r['previous_sha256']!==($prev===null?null:hash('sha256',\DigiForge\Listings\Validator::canonicalJson($prev)))) throw new \InvalidArgumentException();
            if ($prev!==null&&self::instant($r['occurred_at'])<self::instant($prev['occurred_at'])) throw new \InvalidArgumentException();
            if ($prev!==null) foreach (['order_id','shop_key','product_id','product_version','supplier_id'] as $key) if ($prev[$key]!==$r[$key]) throw new \InvalidArgumentException();
            $heads[$r['feedback_id']]=$r;
        }
    }
    private static function exact(mixed $value,array $keys): void {if (!is_array($value)) throw new \InvalidArgumentException();$actual=array_keys($value);sort($actual);sort($keys);if ($actual!==$keys) throw new \InvalidArgumentException();}
    private static function id(mixed $value): void {if (!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D',$value)!==1) throw new \InvalidArgumentException();}
    private static function digest(mixed $value): void {if (!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1) throw new \InvalidArgumentException();}
    private static function instant(mixed $value): int {
        if (!is_string($value)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D',$value)!==1) throw new \InvalidArgumentException();
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$value));$errors=\DateTimeImmutable::getLastErrors();
        if (!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))) throw new \InvalidArgumentException();return $date->getTimestamp();
    }
    private static function sameKeysRecursive(array $a,array $b): bool {
        if (count($a)!==count($b)||array_diff(array_keys($a),array_keys($b))) return false;
        foreach ($a as $k=>$v) {if (is_array($v)){if (!is_array($b[$k])||!self::sameKeysRecursive($v,$b[$k])) return false;}elseif($v!==$b[$k])return false;}return true;
    }
}
