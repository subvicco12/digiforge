<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/editor') {require '/workspace/lane-a-photo-http-20261116/site/wp-load.php';echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">';wp_head();echo '</head><body>';if(is_user_logged_in())echo '<meta data-auth-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'">';echo do_shortcode('[digiforge_personalization_editor]');wp_footer();echo '</body></html>';return true;}
return false;
