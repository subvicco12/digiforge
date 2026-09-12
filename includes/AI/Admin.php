<?php
declare(strict_types=1);
namespace DigiForge\AI;

final class Admin {
    public function register():void{add_action('admin_menu',[$this,'menu']);}
    public function menu():void{add_submenu_page('digiforge',__('AI Governance','digiforge'),__('AI Governance','digiforge'),'manage_digiforge_ai','digiforge-ai-governance',[$this,'render']);}
    public function render():void{
        if(!current_user_can('manage_digiforge_ai'))wp_die(esc_html__('You are not allowed to view DigiForge AI governance.','digiforge'));
        $repo=new Repository();$runs=$repo->list('runs',1,25);$usage=$repo->list('usage',1,25);$reviews=$repo->list('reviews',1,25);
        ?>
        <div class="wrap"><h1><?php esc_html_e('DigiForge AI Governance','digiforge'); ?></h1>
        <p><?php esc_html_e('Local governance records only. AI execution and provider calls are disabled in this build.','digiforge'); ?></p>
        <h2><?php esc_html_e('Recent run intents','digiforge'); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th>Task</th><th>Model</th><th>Environment</th><th>State</th></tr></thead><tbody>
        <?php if($runs['items']===[]):?><tr><td colspan="5"><?php esc_html_e('No AI run intents.','digiforge');?></td></tr><?php endif;foreach($runs['items'] as $row):?><tr><td><?php echo esc_html((string)$row['id']);?></td><td><?php echo esc_html((string)$row['task_id']);?></td><td><?php echo esc_html((string)$row['model_id']);?></td><td><?php echo esc_html((string)$row['environment']);?></td><td><?php echo esc_html((string)$row['state']);?></td></tr><?php endforeach;?></tbody></table>
        <p><?php echo esc_html(sprintf(__('Usage entries: %d · Review records: %d','digiforge'),(int)$usage['pagination']['total_items'],(int)$reviews['pagination']['total_items']));?></p>
        </div><?php
    }
}
