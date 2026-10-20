<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Finance\AllocationProfitabilityReadModel;
use WP_Error;

/** Read-only projection of an explicit version-pinned locally reviewed allocation subset. */
final class FinanceAllocationProfitabilityPanel
{
    public const ACTION='digiforge_finance_allocation_profitability';
    public const MAX_JSON_BYTES=16384;
    public const MAX_REQUEST_BYTES=65536;
    private const DIMENSIONS=['business','store','shop','order','product','product_version','listing','variant'];
    private const METRICS=['gross_revenue','refunds','fees','cogs','shipping','ads','ai','tax_collected','tax_expense','other_income','other_expense','adjustment','gross_profit','contribution_profit','net_profit','margin_percent'];

    public static function processBrowser(array $post,string $selectedShop):array|WP_Error
    {
        $access=FinanceAllocationReviewWorkflow::canRead($selectedShop);if($access instanceof WP_Error)return self::error(403);
        if(!is_string($post['_wpnonce']??null)||strlen($post['_wpnonce'])>256||!wp_verify_nonce($post['_wpnonce'],self::ACTION))return self::error(403);
        try{$request=self::normalize($post,$selectedShop);}catch(\InvalidArgumentException){return self::error(400);}
        try{$result=(new AllocationProfitabilityReadModel())->snapshot($request['references'],$request['scope']);}catch(\Throwable){$result=self::error(503);}
        $access=FinanceAllocationReviewWorkflow::canRead($selectedShop);if($access instanceof WP_Error)return self::error(403);
        if($result instanceof WP_Error)return self::error(409);
        try{return self::project($result,$request['scope']);}catch(\Throwable){return self::error(503);}
    }

    public static function render(string $shop):void
    {
        if(FinanceAllocationReviewWorkflow::canRead($shop) instanceof WP_Error){echo '<section class="df-panel"><h2>Reviewed allocation subset profitability</h2><p>BLOCKED: current Finance assignments for all four shops and one exact selected shop are required. Totals remain UNKNOWN.</p></section>';return;}
        $result=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)===self::ACTION){
            $length=$_SERVER['CONTENT_LENGTH']??'';
            $result=!is_string($_GET['df_shop']??null)||$_GET['df_shop']!==$shop?self::error(400)
                :(is_string($length)&&ctype_digit($length)&&(float)$length>self::MAX_REQUEST_BYTES?self::error(413):self::processBrowser(wp_unslash($_POST),$shop));
        }
        echo '<section class="df-panel" data-finance-allocation-profitability><h2>Reviewed allocation subset profitability</h2><p>Read provisional profitability for explicit version-pinned local allocation review references. Available results are labeled EXPLICIT_REVIEWED_SUBSET. A retained original review is included only with its exact linked current reviewed reversal in the same selected period. A reversal in a later period may be selected alone. Historical linkage grants no financial authority. This selection does not establish complete period coverage or actual financial approval. Authenticity: UNKNOWN · Source completeness: UNKNOWN.</p>';
        echo self::result($result);
        $url=add_query_arg(['df_view'=>'finance','df_shop'=>$shop],OperatorReturnUrl::current());
        echo '<form method="post" action="'.esc_url($url).'" class="df-review-form"><input type="hidden" name="action" value="'.self::ACTION.'"><input type="hidden" name="_wpnonce" value="'.self::e(wp_create_nonce(self::ACTION)).'"><input type="hidden" name="shop_key" value="'.self::e($shop).'"><p>Selected shop: '.self::e($shop).'</p><label>Evidence environment <select name="environment" required><option value="">Choose environment</option><option value="test">test</option><option value="sandbox">sandbox</option><option value="production">production</option></select></label>';
        foreach(['business_id'=>'Exact business ID','store_id'=>'Exact store ID'] as $name=>$label)echo '<label>'.$label.' <input type="number" name="'.$name.'" min="1" step="1" required></label>';
        foreach(['period_start'=>'Inclusive UTC start date','period_end'=>'Inclusive UTC end date'] as $name=>$label)echo '<label>'.$label.' <input type="date" name="'.$name.'" required></label>';
        echo '<label>Exact base currency <input name="base_currency" pattern="[A-Z]{3}" maxlength="3" required></label><label>Exact local review references JSON <textarea name="references_json" rows="7" maxlength="16384" required></textarea></label><p>Supply one to 20 distinct references. Each has exactly source_hash, revision, state_hash and proposal_hash from the current local allocation review status or the exact retained original linked by an included current reversal. Hashes are lowercase SHA-256 strings and revision is a positive JSON integer. JSON limit: 16 KiB. No source packet or provider document is accepted. Raw form inputs are cleared after every response.</p><button type="submit" class="df-button">Read reviewed allocation subset</button></form><p class="df-muted">Money movement authority: NO · Tax filing authority: NO · Period approval authority: NO · External execution authority: NO. This read never saves, approves a financial period, reconciles or executes a provider action.</p></section>';
    }

    private static function normalize(array $post,string $selectedShop):array
    {
        self::keys($post,['_wpnonce','action','shop_key','environment','business_id','store_id','period_start','period_end','base_currency','references_json']);
        $bytes=0;foreach($post as $value){if(!is_string($value))self::invalid();$bytes+=strlen($value);}
        if($bytes>self::MAX_REQUEST_BYTES||strlen($post['references_json'])>self::MAX_JSON_BYTES||$post['action']!==self::ACTION
            ||$post['shop_key']!==$selectedShop||!in_array($selectedShop,['digital','personalized_pod','standard_pod','jewelry'],true)
            ||!in_array($post['environment'],['test','sandbox','production'],true)||preg_match('/^[A-Z]{3}$/D',$post['base_currency'])!==1
            ||$post['_wpnonce']===''||strlen($post['_wpnonce'])>256)self::invalid();
        $scope=['shop_key'=>$selectedShop];
        foreach(['business_id','store_id'] as $key){if(preg_match('/^[1-9][0-9]{0,18}$/D',$post[$key])!==1||(string)(int)$post[$key]!==$post[$key])self::invalid();$scope[$key]=(int)$post[$key];}
        $scope['environment']=$post['environment'];
        foreach(['period_start','period_end'] as $key){
            if(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$post[$key])!==1)self::invalid();
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$post[$key],new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();
            if(!$date||$date->format('Y-m-d')!==$post[$key]||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0)))self::invalid();$scope[$key]=$post[$key];
        }
        if($scope['period_start']>$scope['period_end'])self::invalid();$scope['base_currency']=$post['base_currency'];
        try{$references=json_decode($post['references_json'],true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}
        if(!is_array($references)||!array_is_list($references)||$references===[]||count($references)>20)self::invalid();$sources=[];$states=[];
        foreach($references as $reference){
            if(!is_array($reference))self::invalid();self::keys($reference,['source_hash','revision','state_hash','proposal_hash']);
            if(!is_int($reference['revision'])||$reference['revision']<1)self::invalid();
            foreach(['source_hash','state_hash','proposal_hash'] as $key)if(!is_string($reference[$key])||preg_match('/^[a-f0-9]{64}$/D',$reference[$key])!==1)self::invalid();
            if(isset($sources[$reference['source_hash']])||isset($states[$reference['state_hash']]))self::invalid();$sources[$reference['source_hash']]=true;$states[$reference['state_hash']]=true;
        }
        return ['scope'=>$scope,'references'=>$references];
    }

    private static function project(array $report,array $scope):array
    {
        if(($report['schema']??null)!=='finance-reviewed-allocation-profitability-v1'||($report['scope']??null)!==$scope
            ||($report['query_state']??null)!=='AVAILABLE'||($report['state']??null)!=='PROVISIONAL'
            ||($report['authenticity']??null)!=='UNKNOWN'||($report['source_completeness']??null)!=='UNKNOWN')self::invalid();
        $coverage=$report['coverage']??null;
        if(!is_array($coverage)||($coverage['state']??null)!=='EXPLICIT_REVIEWED_SUBSET'||($coverage['full_period_coverage_verified']??null)!==false
            ||!is_int($coverage['source_count']??null)||$coverage['source_count']<1||$coverage['source_count']>20
            ||!is_int($coverage['line_count']??null)||$coverage['line_count']<1||$coverage['line_count']>200)self::invalid();
        $out=['scope'=>$scope,'state'=>'PROVISIONAL','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','coverage'=>array_intersect_key($coverage,array_flip(['state','source_count','line_count','full_period_coverage_verified']))];
        foreach(['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $flag){if(($report[$flag]??null)!==false)self::invalid();$out[$flag]=false;}
        $out['totals']=self::metrics($report['totals']??null);$out['groups']=[];
        foreach(self::DIMENSIONS as $dimension){
            $groups=$report['groups'][$dimension]??null;if(!is_array($groups)||!array_is_list($groups)||count($groups)>200)self::invalid();$out['groups'][$dimension]=[];
            foreach($groups as $group){
                if(!is_array($group)||!is_int($group['line_count']??null)||$group['line_count']<1||$group['line_count']>200)self::invalid();$id=$group['dimension_id']??null;
                if($dimension==='shop'){$valid=is_string($id)&&$id===$scope['shop_key'];}
                elseif($dimension==='variant'){$valid=is_string($id)&&strlen($id)<=212&&preg_match('/^[1-9][0-9]*:[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D',$id)===1;}
                else{$valid=is_int($id)&&$id>0;}
                if(!$valid)self::invalid();
                $out['groups'][$dimension][]=['dimension_id'=>$id,'line_count'=>$group['line_count'],'metrics'=>self::metrics($group['metrics']??null)];
            }
        }
        return $out;
    }
    private static function metrics(mixed $metrics):array
    {
        if(!is_array($metrics))self::invalid();$out=[];
        foreach(self::METRICS as $key){
            if(!array_key_exists($key,$metrics))self::invalid();$value=$metrics[$key];
            if($key==='margin_percent'&&$value===null){$out[$key]=null;continue;}
            if(!is_string($value)||strlen($value)>80||preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]{4}$/D',$value)!==1)self::invalid();$out[$key]=$value;
        }
        return $out;
    }
    private static function result(array|WP_Error|null $result):string
    {
        if($result===null)return '<p role="status">No reviewed subset requested. Totals and margin remain UNKNOWN.</p>';
        if($result instanceof WP_Error)return '<p role="status" class="df-notice df-notice-error">BLOCKED: a complete current explicitly pinned reviewed subset is unavailable. Totals and margin remain UNKNOWN. Raw inputs and source diagnostics are withheld.</p>';
        $scope=$result['scope'];$coverage=$result['coverage'];
        $out='<div data-finance-allocation-profitability-result><p role="status"><strong>PROVISIONAL · EXPLICIT_REVIEWED_SUBSET</strong> · '.self::e($coverage['source_count']).' source(s) · '.self::e($coverage['line_count']).' allocation line(s). Full period coverage verified: NO.</p><p>Shop: '.self::e($scope['shop_key']).' · Environment: '.self::e($scope['environment']).' · Business: '.self::e($scope['business_id']).' · Store: '.self::e($scope['store_id']).' · UTC dates: '.self::e($scope['period_start']).' to '.self::e($scope['period_end']).' inclusive · Base currency: '.self::e($scope['base_currency']).'</p><p>AI is included in other expense; adjustment is included in other income. Do not count these subsets twice. Margin remains UNKNOWN without nonzero declared revenue. Actual settlement remains UNKNOWN.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Reviewed allocation subset totals"><table class="df-table"><thead><tr><th>Declared metric</th><th>Provisional base amount / percentage</th></tr></thead><tbody>';
        foreach(self::METRICS as $key)$out.='<tr><td>'.self::e(str_replace('_',' ',$key)).'</td><td>'.self::metric($key,$result['totals'][$key]).'</td></tr>';
        $out.='</tbody></table></div><h3>Explicit reviewed subset dimensions</h3>';
        foreach(self::DIMENSIONS as $dimension){
            $groups=$result['groups'][$dimension];$out.='<h4>'.self::e(str_replace('_',' ',$dimension)).'</h4><p>'.count($groups).' dimension row(s) in this selected subset.</p>';
            if($groups===[]){$out.='<p>No applicable dimension in the selected evidence. No attribution is inferred.</p>';continue;}
            $out.='<div class="df-table-wrap" role="region" tabindex="0" aria-label="'.self::e($dimension).' reviewed allocation dimensions"><table class="df-table"><thead><tr><th>Identity</th><th>Lines</th><th>Revenue</th><th>Contribution</th><th>Net</th><th>Margin</th></tr></thead><tbody>';
            foreach($groups as $group){$out.='<tr><td>'.self::e($group['dimension_id']).'</td><td>'.self::e($group['line_count']).'</td>';foreach(['gross_revenue','contribution_profit','net_profit','margin_percent'] as $key)$out.='<td>'.self::metric($key,$group['metrics'][$key]).'</td>';$out.='</tr>';}
            $out.='</tbody></table></div>';
        }
        return $out.'</div>';
    }
    private static function metric(string $key,?string $value):string {return $value===null?'UNKNOWN':self::e($value).($key==='margin_percent'?'%':'');}
    private static function keys(array $value,array $expected):void {$keys=array_keys($value);sort($keys);sort($expected);if($keys!==$expected)self::invalid();}
    private static function invalid():never {throw new \InvalidArgumentException('Exact bounded reviewed subset required.');}
    private static function error(int $status):WP_Error
    {
        return new WP_Error('finance_allocation_profitability_portal_blocked','A bounded current authorized reviewed subset is unavailable.',['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','totals'=>null,'actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false]);
    }
    private static function e(mixed $value):string {return htmlspecialchars(is_string($value)||is_int($value)?(string)$value:'UNKNOWN',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
