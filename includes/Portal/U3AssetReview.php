<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\ProductFactory\AssetStorage;

/** Serves generated U3 assets only to authenticated DigiForge reviewers. */
final class U3AssetReview
{
    public const ACTION = 'digiforge_u3_asset_review';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'serve']);
    }

    public static function nonceAction(int $revisionId): string
    {
        return self::ACTION . '_' . $revisionId;
    }

    public function serve(): never
    {
        if (! is_user_logged_in()
            || ! current_user_can('manage_digiforge_products')
            || ! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You are not authorized to review DigiForge assets.', 'digiforge'), '', ['response' => 403]);
        }
        $revisionId = isset($_GET['revision_id']) ? absint($_GET['revision_id']) : 0;
        if ($revisionId < 1) {
            wp_die(esc_html__('Invalid DigiForge asset revision.', 'digiforge'), '', ['response' => 400]);
        }
        check_admin_referer(self::nonceAction($revisionId));

        global $wpdb;
        $revision = $wpdb->get_row($wpdb->prepare(
            'SELECT ar.*,s.asset_type,s.asset_key,s.purpose FROM ' . Tables::asset_revisions() . ' ar '
            . 'INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=ar.asset_spec_id '
            . 'WHERE ar.id=%d LIMIT 1',
            $revisionId
        ), ARRAY_A);
        if (! is_array($revision)) {
            wp_die(esc_html__('DigiForge asset revision was not found.', 'digiforge'), '', ['response' => 404]);
        }

        $storage = (string) ($revision['storage_reference'] ?? '');
        $path = AssetStorage::absolutePath($storage);
        if ($path === null) {
            wp_die(esc_html__('DigiForge asset file is unavailable.', 'digiforge'), '', ['response' => 404]);
        }

        $mime = sanitize_text_field((string) ($revision['mime_type'] ?? 'application/octet-stream'));
        $filename = sanitize_file_name(basename($path));
        $forceAttachment = in_array($mime, ['text/html', 'application/zip'], true);

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox");
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($forceAttachment ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $filename) . '"');
        readfile($path);
        exit;
    }
}
