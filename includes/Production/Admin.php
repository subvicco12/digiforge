<?php

declare(strict_types=1);

namespace DigiForge\Production;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('digiforge','Production','Production','manage_digiforge_production','digiforge-production',[$this,'render']);
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You do not have permission to view DigiForge Production.','digiforge'));
        }
        $repo=new Repository();
        $plans=$repo->list('plans',1,20)['items'];
        $intents=$repo->list('intents',1,20)['items'];
        $bundles=$repo->list('bundles',1,20)['items'];
        echo '<div class="wrap"><h1>'.esc_html__('DigiForge Production','digiforge').'</h1>';
        echo '<p>'.esc_html__('Read-only Batch 6 governance view. No provider, AI, publishing, fulfillment, worker, or schedule action is available here.','digiforge').'</p>';
        $this->table('Production Plans',$plans,['id','plan_key','version_label','channel','state']);
        $this->table('Blocked Production Intents',$intents,['id','production_plan_id','asset_spec_id','intent_type','provider_class','state']);
        $this->table('Release Bundles',$bundles,['id','production_plan_id','bundle_key','version_label','state','checksum_sha256']);
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
