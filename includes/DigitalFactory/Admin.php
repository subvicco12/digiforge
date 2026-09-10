<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** WordPress-native, read-only admin foundations for digital records. */
final class Admin {
    private const PAGES=['digital_product'=>'Digital Products','digital_file'=>'Digital Files','digital_package'=>'Digital Packages','digital_template'=>'Digital Templates','digital_license'=>'Digital Licenses','digital_download_check'=>'Digital QA / Download Checks'];
    public function register(): void { add_action('admin_menu',[$this,'menu']); }
    public function menu(): void { foreach(self::PAGES as $type=>$label){ add_submenu_page('digiforge',__($label,'digiforge'),__($label,'digiforge'),'manage_digiforge_digital','digiforge-'.str_replace('_','-',$type),fn()=>$this->render($type,$label)); } }
    public function render(string $type,string $label): void {
        if(!current_user_can('manage_digiforge_digital')){wp_die(esc_html__('You are not allowed to manage digital products.','digiforge'));}
        $items=(new Repository())->all($type)['items']; ?>
        <div class="wrap"><h1><?php echo esc_html($label); ?></h1><p><?php esc_html_e('Local Digital Product Factory records. External processing, automation, and publishing are OFF.','digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('ID','digiforge'); ?></th><th><?php esc_html_e('Name / check','digiforge'); ?></th><th><?php esc_html_e('Status','digiforge'); ?></th><th><?php esc_html_e('Updated (UTC)','digiforge'); ?></th></tr></thead><tbody>
        <?php if($items===[]): ?><tr><td colspan="4"><?php esc_html_e('No records found.','digiforge'); ?></td></tr><?php endif; foreach($items as $item): ?><tr><td><?php echo esc_html((string)$item['id']); ?></td><td><?php echo esc_html((string)($item['name']??$item['check_type']??'')); ?></td><td><?php echo esc_html((string)($item['state']??$item['status']??$item['generation_status']??$item['validation_result']??'')); ?></td><td><?php echo esc_html((string)$item['updated_at']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }
}
