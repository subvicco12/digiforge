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
        $page=isset($_GET['paged'])?max(1,absint(wp_unslash($_GET['paged']))):1;
        $result=(new Repository())->all($type,$page);
        $items=$result['items'];
        $pagination=$result['pagination'];
        $qaSummary=$type==='digital_download_check' && class_exists(QaAdminSummary::class) ? QaAdminSummary::summarize($items) : null;
        $operationalField=$type==='digital_package'?'generation_status':(in_array($type,['digital_file','digital_template'],true)?'status':'');
        $operationalSummary=$operationalField!=='' && class_exists(OperationalAdminSummary::class) ? OperationalAdminSummary::summarize($items,$operationalField) : null; ?>
        <div class="wrap"><h1><?php echo esc_html($label); ?></h1><p><?php esc_html_e('Local Digital Product Factory records. External processing, automation, and publishing are OFF.','digiforge'); ?></p>
        <?php if(is_array($qaSummary)): ?><p><strong><?php esc_html_e('Current page QA snapshot:','digiforge'); ?></strong> <?php echo esc_html(sprintf(__('sampled %1$d; invalid/unknown %2$d; readiness inferred: NO; external actions performed: NO','digiforge'),(int)$qaSummary['total'],(int)$qaSummary['invalid'])); ?></p><?php endif; ?>
        <?php if(is_array($operationalSummary)): ?><p><strong><?php esc_html_e('Current page operational snapshot:','digiforge'); ?></strong> <?php echo esc_html(sprintf(__('sampled %1$d; malformed %2$d; persisted states only; readiness inferred: NO; external actions performed: NO','digiforge'),(int)$operationalSummary['total'],(int)$operationalSummary['invalid'])); ?></p><?php endif; ?>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('ID','digiforge'); ?></th><th><?php esc_html_e('Name / check','digiforge'); ?></th><th><?php esc_html_e('Status','digiforge'); ?></th><th><?php esc_html_e('Updated (UTC)','digiforge'); ?></th></tr></thead><tbody>
        <?php if($items===[]): ?><tr><td colspan="4"><?php esc_html_e('No records found.','digiforge'); ?></td></tr><?php endif; foreach($items as $item): ?><tr><td><?php echo esc_html((string)$item['id']); ?></td><td><?php echo esc_html((string)($item['name']??$item['check_type']??'')); ?></td><td><?php echo esc_html((string)($item['state']??$item['status']??$item['generation_status']??$item['validation_result']??'')); ?></td><td><?php echo esc_html((string)$item['updated_at']); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if((int)$pagination['total_pages']>1): ?>
            <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>(int)$pagination['page'],'total'=>(int)$pagination['total_pages'],'type'=>'list'])); ?></div></div>
        <?php endif; ?>
        </div><?php
    }
}
