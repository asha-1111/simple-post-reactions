<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('spr_settings', []);
$cleanup = !empty($settings['cleanup_on_uninstall']);

delete_option('spr_settings');

if ($cleanup) {
    // Remove all reaction meta (optional wipe)
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);
    foreach ($posts as $pid) {
        delete_post_meta($pid, '_spr_likes');
        delete_post_meta($pid, '_spr_dislikes');
    }
    // Also remove user flags
    $users = get_users(['fields' => ['ID']]);
    foreach ($users as $u) {
        delete_user_meta($u->ID, '_spr_voted_posts');
    }
}
