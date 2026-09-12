<?php

declare(strict_types=1);

namespace DigiForge\POD;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('digiforge','POD & Personalization','POD & Personalization','manage_digiforge_pod','digiforge-pod',[$this,'render']);
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_pod')) {
            wp_die(esc_html__('You do not have permission to view DigiForge POD.','digiforge'));
        }
        $repo=new Repository();
        $mappings=$repo->list('mappings',1,20)['items'];
        $personalization=$repo->list('personalization',1,20)['items'];
        $intents=$repo->list('intents',1,20)['items'];
        echo '<div class="wrap"><h1>'.esc_html__('DigiForge POD & Personalization','digiforge').'</h1>';
        echo '<p>'.esc_html__('Read-only Batch 7 governance view. No provider call, upload, mockup, order, auto-approval, fulfillment, Etsy action, worker, or schedule is available here.','digiforge').'</p>';
        $this->table('Provider Mappings',$mappings,['id','provider','environment','provider_product_key','provider_variant_key','mapping_version','state']);
        $this->table('Personalization Schemas',$personalization,['id','product_version_id','schema_key','version_label','state']);
        $this->table('Blocked Provider Intents',$intents,['id','provider','environment','intent_type','state']);
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $rows @param list<string> $columns */
    private function table(string $title,array $rows,array $columns):void
    {
        echo '<h2>'.esc_html($title).'</h2><table class="widefat striped"><thead><tr>';
        foreach($columns as $column)echo '<th>'.esc_html($column).'</th>';
        echo '</tr></thead><tbody>';
        if($rows===[])echo '<tr><td colspan="'.esc_attr((string)count($columns)).'">'.esc_html__('No records.','digiforge').'</td></tr>';
        foreach($rows as $row){echo '<tr>';foreach($columns as $column){$value=$row[$column]??'';if(is_array($value))$value=wp_json_encode($value);echo '<td>'.esc_html((string)$value).'</td>';}echo '</tr>';}
        echo '</tbody></table>';
    }
}
