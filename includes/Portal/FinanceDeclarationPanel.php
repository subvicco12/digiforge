<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Finance\DeclarationReadModel;

/** Read-only declaration evidence within the portal's existing global finance authorization. */
final class FinanceDeclarationPanel
{
    private const AMOUNTS=['gross_revenue','refunds','fees','cogs','shipping','ads','ai','tax_collected','tax_expense','other_income','other_expense','adjustment','gross_profit','contribution_profit','net_profit','margin_percent'];

    public static function filters(array $query):?array
    {
        $values=['environment'=>'test','period_start'=>gmdate('Y-m-01'),'period_end'=>gmdate('Y-m-t'),'base_currency'=>'USD'];
        foreach(['environment'=>'environment','period_start'=>'start','period_end'=>'end','base_currency'=>'currency'] as $key=>$parameter)if(array_key_exists('df_finance_'.$parameter,$query))$values[$key]=$query['df_finance_'.$parameter];
        foreach($values as $value)if(!is_string($value))return null;
        if(!in_array($values['environment'],['sandbox','test','production'],true)||preg_match('/^[A-Z]{3}$/D',$values['base_currency'])!==1)return null;
        foreach(['period_start','period_end'] as $key){
            if(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$values[$key])!==1)return null;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$values[$key],new \DateTimeZone('UTC'));
            $errors=\DateTimeImmutable::getLastErrors();
            if(!$date||$date->format('Y-m-d')!==$values[$key]||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0)))return null;
        }
        return $values['period_start']<=$values['period_end']?$values:null;
    }

    public static function render(string $shop,string $view,array $query,string $baseUrl):void
    {
        $filters=self::filters($query);
        if($filters===null){
            echo self::html(['query_state'=>'UNAVAILABLE','filter_state'=>'INVALID'],self::filters([])??[],$shop,$view,$baseUrl);
            return;
        }
        $report=(new DeclarationReadModel())->snapshot($shop,$filters['environment'],$filters['period_start'],$filters['period_end'],$filters['base_currency'],100);
        echo self::html($report,$filters,$shop,$view,$baseUrl);
    }

    /** Fixed field projection; no supplied authority flag or raw document becomes an action. */
    public static function html(array $report,array $filters,string $shop,string $view,string $baseUrl):string
    {
        $scope=['shop_key'=>$shop]+$filters;
        $matches=($report['schema']??null)==='finance-declaration-report-v1'&&($report['scope']??null)===$scope;
        // Field order in a decoded object carries no scope meaning.
        if(!$matches&&is_array($report['scope']??null)&&count($report['scope'])===count($scope)){
            $matches=($report['schema']??null)==='finance-declaration-report-v1';
            foreach($scope as $key=>$value)if(($report['scope'][$key]??null)!==$value)$matches=false;
        }
        $available=$matches&&($report['query_state']??null)==='AVAILABLE';
        $state=$available&&in_array($report['state']??null,['PROVISIONAL','BLOCKED','NO_DECLARATIONS'],true)?$report['state']:'UNAVAILABLE';
        $calculated=$state==='PROVISIONAL';
        $out='<section class="df-panel" data-finance-declarations><h2>Provisional finance declarations</h2><p>Local declarations only. Authenticity: UNKNOWN · Source completeness: UNKNOWN. These records do not establish actual provider charges, certified profitability, reconciled accounts or statutory GST/tax treatment.</p><p>Declaration imports require existing Personalized POD ownership or an exact independently reviewed Digital/Etsy financial binding. No actual ownership or deferred-shop readiness is inferred.</p>';
        $out.=self::form($filters,$shop,$view,$baseUrl);
        $out.='<p><strong>'.self::e($state).'</strong> · Shop: '.self::e($shop).' · Environment: '.self::e($filters['environment']??'UNKNOWN').' · UTC dates: '.self::e($filters['period_start']??'UNKNOWN').' to '.self::e($filters['period_end']??'UNKNOWN').' (inclusive) · Base currency: '.self::e($filters['base_currency']??'UNKNOWN').'</p>';
        if($state==='UNAVAILABLE')$out.='<p role="status">'.(($report['filter_state']??null)==='INVALID'?'Invalid exact finance filters; this declaration report was not queried.':'Declaration evidence unavailable for this exact scope. No zero amount or empty reconciliation is inferred.').'</p>';
        elseif($state==='NO_DECLARATIONS')$out.='<p>No declarations in this bounded scope. Amounts remain UNKNOWN.</p>';
        elseif($state==='BLOCKED')$out.='<p>Declaration conflicts block the provisional calculation. Amounts remain UNKNOWN.</p>';
        $out.='<p>Calculation version: '.self::e($available?($report['calculation_version']??'UNKNOWN'):'UNKNOWN').'. AI is included in other expense; adjustment is included in other income. These category subsets must not be counted twice. Margin remains UNKNOWN without a nonzero declared revenue denominator.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Provisional declaration totals"><table class="df-table"><thead><tr><th>Declared metric</th><th>Provisional base amount / percentage</th></tr></thead><tbody>';
        foreach(self::AMOUNTS as $key)$out.='<tr><td>'.self::e(str_replace('_',' ',$key)).'</td><td>'.self::metric($key,$calculated?($report['totals'][$key]??null):null).'</td></tr>';
        $out.='</tbody></table></div>';
        if($available){
            $matching=is_array($report['matching']??null)?$report['matching']:[];
            $out.='<h3>Declared matching evidence</h3><p>'.self::e($matching['state']??'UNKNOWN').'. A declared match does not authenticate a source.</p><ul>';
            foreach(array_slice((array)($matching['issues']??[]),0,200) as $issue)if(is_array($issue))$out.='<li>'.self::e($issue['code']??'UNKNOWN').' '.self::e($issue['message']??'Unknown declaration issue').'</li>';
            $out.='</ul><div class="df-table-wrap" role="region" tabindex="0" aria-label="Declared reconciliation rows"><table class="df-table"><thead><tr><th>Ledger ID</th><th>Declaration SHA-256</th><th>Declared statuses</th></tr></thead><tbody>';
            foreach(array_slice((array)($matching['rows']??[]),0,100) as $row)if(is_array($row))$out.='<tr><td>'.self::e($row['ledger_id']??'UNKNOWN').'</td><td><code>'.self::e($row['declaration_hash']??'UNKNOWN').'</code></td><td>'.self::e(implode(', ',array_filter((array)($row['statuses']??[]),'is_string'))).'</td></tr>';
            $out.='</tbody></table></div>';
            if($calculated)$out.=self::groups((array)($report['groups']??[]));
            $out.=self::gst((array)($report['gst_review']['rows']??[]));
            if(($report['truncated']??false)===true)$out.='<p role="status">TRUNCATED: this is a bounded declaration window. Totals and dimensions do not establish complete population coverage.</p>';
        }
        return $out.'<p class="df-muted">External execution authority: NO · Money movement authority: NO · Tax filing authority: NO · Period approval authority: NO. Changing these read filters never imports, reconciles or approves a record.</p></section>';
    }

    private static function form(array $filters,string $shop,string $view,string $baseUrl):string
    {
        $out='<form method="get" class="df-review-form" action="'.self::e($baseUrl).'">';
        parse_str((string)parse_url($baseUrl,PHP_URL_QUERY),$route);
        foreach(['page_id','p'] as $key)if(is_string($route[$key]??null)&&preg_match('/^[1-9][0-9]*$/D',$route[$key])===1)$out.='<input type="hidden" name="'.$key.'" value="'.self::e($route[$key]).'">';
        $out.='<input type="hidden" name="df_view" value="'.self::e(in_array($view,['finance','analytics'],true)?$view:'finance').'"><input type="hidden" name="df_shop" value="'.self::e($shop).'"><label>Declaration environment (read only) <select name="df_finance_environment">';
        foreach(['sandbox','test','production'] as $environment)$out.='<option value="'.$environment.'"'.(($filters['environment']??null)===$environment?' selected':'').'>'.$environment.'</option>';
        $out.='</select></label> ';
        foreach(['start'=>'UTC start date','end'=>'UTC end date (inclusive)'] as $key=>$label)$out.='<label>'.$label.' <input type="date" name="df_finance_'.$key.'" value="'.self::e($filters['period_'.$key]??'').'" required></label> ';
        return $out.'<label>Base currency <input name="df_finance_currency" value="'.self::e($filters['base_currency']??'USD').'" pattern="[A-Z]{3}" maxlength="3" required></label> <button class="df-button" type="submit">Read declaration evidence</button></form>';
    }

    private static function groups(array $groups):string
    {
        $out='<h3>Direct declared attribution</h3><p>Attribution reflects the supplied local dimensions only. Missing dimensions are not inferred.</p>';
        foreach(['business','shop','order','product','listing','variant'] as $dimension){
            $rows=is_array($groups[$dimension]??null)?$groups[$dimension]:[];
            $out.='<h4>'.ucfirst($dimension).'</h4><p>'.count($rows).' dimension row(s) in this declaration window; showing up to 25.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="'.ucfirst($dimension).' declaration attribution"><table class="df-table"><thead><tr><th>Declared identity</th><th>Entries</th><th>Gross revenue</th><th>Gross profit</th><th>Contribution profit</th><th>Net profit</th><th>Margin</th></tr></thead><tbody>';
            foreach(array_slice($rows,0,25) as $row)if(is_array($row)){
                $out.='<tr><td>'.self::e($row['dimension_id']??'UNKNOWN').'</td><td>'.self::e($row['entry_count']??'UNKNOWN').'</td>';
                foreach(['gross_revenue','gross_profit','contribution_profit','net_profit','margin_percent'] as $key)$out.='<td>'.self::metric($key,$row['metrics'][$key]??null).'</td>';
                $out.='</tr>';
            }
            $out.='</tbody></table></div>';
        }
        return $out;
    }

    private static function gst(array $rows):string
    {
        $out='<h3>GST / tax evidence review</h3><p>UNREVIEWED declarations only. Jurisdiction and statutory treatment remain UNKNOWN. No filing or tax classification is performed.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="GST tax declaration evidence"><table class="df-table"><thead><tr><th>Ledger</th><th>Occurred UTC</th><th>Category</th><th>Declared amount</th><th>Base amount</th><th>Document reference</th><th>Document SHA-256</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,100) as $row)if(is_array($row))$out.='<tr><td>'.self::e($row['ledger_id']??'UNKNOWN').'</td><td>'.self::e($row['occurred_at']??'UNKNOWN').'</td><td>'.self::e($row['category']??'UNKNOWN').'</td><td>'.self::amount($row['amount']??null).' '.self::e($row['currency']??'UNKNOWN').'</td><td>'.self::amount($row['base_amount']??null).' '.self::e($row['base_currency']??'UNKNOWN').'</td><td>'.self::e($row['source_document_ref']??'UNKNOWN').'</td><td><code>'.self::e($row['source_document_hash']??'UNKNOWN').'</code></td></tr>';
        return $out.'</tbody></table></div>';
    }

    private static function amount(mixed $value):string
    {
        return is_string($value)&&preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]{4}$/D',$value)===1?self::e($value):'UNKNOWN';
    }

    private static function metric(string $key,mixed $value):string
    {
        $amount=self::amount($value);
        return $key==='margin_percent'&&$amount!=='UNKNOWN'?$amount.'%':$amount;
    }

    private static function e(mixed $value):string
    {
        return htmlspecialchars(is_string($value)||is_int($value)?(string)$value:'UNKNOWN',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
