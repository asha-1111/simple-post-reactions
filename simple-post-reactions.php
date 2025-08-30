<?php
/**
 * Plugin Name: Simple Post Reactions (REST, WP 6+)
 * Description: Lightweight Like / Like–Dislike reactions with REST API, nonces, and a tiny admin dashboard. Compatible with WordPress 6+.
 * Version: 1.1.0
 * Author:Nadiya Rahman
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: simple-post-reactions
 */

if (!defined('ABSPATH')) exit;

define('SPR_VERSION', '1.1.0');
define('SPR_DIR', plugin_dir_path(__FILE__));
define('SPR_URL', plugin_dir_url(__FILE__));
define('SPR_OPT_KEY', 'spr_settings');

if (!class_exists('SPR_Plugin')):
final class SPR_Plugin {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_filter('the_content', [$this, 'maybe_inject_bar']);
        add_shortcode('post_reactions', [$this, 'shortcode_reactions']);

        // REST
        add_action('rest_api_init', [$this, 'rest_routes']);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    }

    public function activate() {
        $defaults = [
            'mode'        => 'like_dislike', // like_only | like_dislike
            'auto_insert' => 1,
            'cleanup_on_uninstall' => 0,     // keep data by default
        ];
        $curr = get_option(SPR_OPT_KEY);
        if (!is_array($curr)) {
            update_option(SPR_OPT_KEY, $defaults);
        } else {
            update_option(SPR_OPT_KEY, array_merge($defaults, $curr));
        }
    }

    // ───────────────── Frontend ─────────────────
    public function enqueue_front() {
        if (!is_singular()) return;

        wp_enqueue_style('spr-css', SPR_URL.'assets/css/spr.css', [], SPR_VERSION);
        wp_enqueue_script('spr-js', SPR_URL.'assets/js/spr-frontend.js', ['jquery'], SPR_VERSION, true);

        $post_id = is_singular() ? get_the_ID() : 0;
        $nonce   = wp_create_nonce('wp_rest'); // for REST requests

        wp_localize_script('spr-js', 'sprData', [
            'restUrl'   => esc_url_raw(rest_url('spr/v1/react')),
            'nonce'     => $nonce,
            'postId'    => $post_id,
            'mode'      => $this->get_mode(),
            'hasVoted'  => $this->has_voted($post_id),
            'strings'   => [
                'thanks'  => __('Thanks for your feedback!', 'simple-post-reactions'),
                'voted'   => __('You already voted on this post.', 'simple-post-reactions'),
                'error'   => __('Something went wrong. Please try again.', 'simple-post-reactions'),
            ]
        ]);
    }

    public function maybe_inject_bar($content) {
        if (is_admin() || !is_singular('post')) return $content;
        $o = get_option(SPR_OPT_KEY, []);
        if (empty($o['auto_insert'])) return $content;
        return $content . $this->render_bar(get_the_ID());
    }

    public function shortcode_reactions($atts) {
        $post_id = get_the_ID();
        if (!$post_id) return '';
        return $this->render_bar($post_id);
    }

    private function render_bar($post_id) {
        $mode = $this->get_mode();
        $likes = (int) get_post_meta($post_id, '_spr_likes', true);
        $dislikes = (int) get_post_meta($post_id, '_spr_dislikes', true);
        $has_voted = $this->has_voted($post_id);

        ob_start(); ?>
        <div class="spr-reactions" data-postid="<?php echo esc_attr($post_id); ?>">
            <div class="spr-buttons">
                <button class="spr-btn spr-like" <?php disabled($has_voted); ?> aria-pressed="false">
                    <span class="spr-icon" aria-hidden="true">👍</span>
                    <span class="spr-label"><?php _e('Like', 'simple-post-reactions'); ?></span>
                    <span class="spr-count" data-type="like"><?php echo esc_html($likes); ?></span>
                </button>

                <?php if ($mode === 'like_dislike'): ?>
                <button class="spr-btn spr-dislike" <?php disabled($has_voted); ?> aria-pressed="false">
                    <span class="spr-icon" aria-hidden="true">👎</span>
                    <span class="spr-label"><?php _e('Dislike', 'simple-post-reactions'); ?></span>
                    <span class="spr-count" data-type="dislike"><?php echo esc_html($dislikes); ?></span>
                </button>
                <?php endif; ?>
            </div>
            <div class="spr-msg" role="status" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ───────────────── REST API ─────────────────
    public function rest_routes() {
        register_rest_route('spr/v1', '/react', [
            'methods'  => 'POST',
            'callback' => [$this, 'rest_react'],
            'permission_callback' => function($request){
                // Require REST nonce
                return wp_verify_nonce($request->get_header('x-wp-nonce'), 'wp_rest');
            },
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($value){ return $value > 0; }
                ],
                'reaction' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => ['like','dislike'],
                    'sanitize_callback' => 'sanitize_key'
                ],
            ],
        ]);
    }

    public function rest_react(WP_REST_Request $req) {
        $post_id  = (int) $req->get_param('post_id');
        $reaction = sanitize_key($req->get_param('reaction'));

        if (!$post_id || !in_array($reaction, ['like','dislike'], true)) {
            return new WP_REST_Response(['message' => __('Invalid request.', 'simple-post-reactions')], 400);
        }

        // Honor mode
        if ($this->get_mode() === 'like_only' && $reaction === 'dislike') {
            return new WP_REST_Response(['message' => __('Dislike is disabled.', 'simple-post-reactions')], 403);
        }

        if ($this->has_voted($post_id)) {
            return new WP_REST_Response(['message' => __('You already voted on this post.', 'simple-post-reactions'), 'already' => true], 409);
        }

        if ($reaction === 'like') {
            $likes = (int) get_post_meta($post_id, '_spr_likes', true);
            update_post_meta($post_id, '_spr_likes', ++$likes);
        } else {
            $dislikes = (int) get_post_meta($post_id, '_spr_dislikes', true);
            update_post_meta($post_id, '_spr_dislikes', ++$dislikes);
        }

        $this->mark_voted($post_id);

        return new WP_REST_Response([
            'likes'    => (int) get_post_meta($post_id, '_spr_likes', true),
            'dislikes' => (int) get_post_meta($post_id, '_spr_dislikes', true),
            'message'  => __('Thanks for your feedback!', 'simple-post-reactions')
        ], 200);
    }

    // ───────────────── Utilities ─────────────────
    private function get_mode() {
        $o = get_option(SPR_OPT_KEY, []);
        return $o['mode'] ?? 'like_dislike';
    }

    private function cookie_name($post_id) {
        return 'spr_voted_' . intval($post_id);
    }

    private function has_voted($post_id) {
        if (!$post_id) return false;

        if (is_user_logged_in()) {
            $voted = get_user_meta(get_current_user_id(), '_spr_voted_posts', true);
            if (is_array($voted) && in_array((int)$post_id, $voted, true)) return true;
        }

        $c = isset($_COOKIE[$this->cookie_name($post_id)]) ? sanitize_text_field(wp_unslash($_COOKIE[$this->cookie_name($post_id)])) : '';
        return $c === '1';
    }

    private function mark_voted($post_id) {
        // Guest: cookie for a year (SameSite=Lax by WP default cookie params)
        setcookie(
            $this->cookie_name($post_id),
            '1',
            [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            $voted = get_user_meta($uid, '_spr_voted_posts', true);
            if (!is_array($voted)) $voted = [];
            if (!in_array((int)$post_id, $voted, true)) {
                $voted[] = (int)$post_id;
                update_user_meta($uid, '_spr_voted_posts', $voted);
            }
        }
    }

    // ───────────────── Admin ─────────────────
    public function admin_menu() {
        add_options_page(
            __('Post Reactions', 'simple-post-reactions'),
            __('Post Reactions', 'simple-post-reactions'),
            'manage_options',
            'spr-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('spr_settings_group', SPR_OPT_KEY, function($input){
            $out = [];
            $out['mode'] = in_array($input['mode'] ?? '', ['like_only','like_dislike'], true) ? $input['mode'] : 'like_dislike';
            $out['auto_insert'] = !empty($input['auto_insert']) ? 1 : 0;
            $out['cleanup_on_uninstall'] = !empty($input['cleanup_on_uninstall']) ? 1 : 0;
            return $out;
        });

        add_settings_section('spr_main', __('Main Settings', 'simple-post-reactions'), '__return_false', 'spr-settings');

        add_settings_field('spr_mode', __('Reaction Mode', 'simple-post-reactions'), function(){
            $o = get_option(SPR_OPT_KEY, []);
            $mode = $o['mode'] ?? 'like_dislike'; ?>
            <label><input type="radio" name="spr_settings[mode]" value="like_only" <?php checked($mode, 'like_only'); ?> />
                <?php esc_html_e('Like only', 'simple-post-reactions'); ?></label><br/>
            <label><input type="radio" name="spr_settings[mode]" value="like_dislike" <?php checked($mode, 'like_dislike'); ?> />
                <?php esc_html_e('Like & Dislike', 'simple-post-reactions'); ?></label>
        <?php }, 'spr-settings', 'spr_main');

        add_settings_field('spr_auto_insert', __('Auto Insert', 'simple-post-reactions'), function(){
            $o = get_option(SPR_OPT_KEY, []);
            $auto = !empty($o['auto_insert']); ?>
            <label><input type="checkbox" name="spr_settings[auto_insert]" value="1" <?php checked($auto, true); ?> />
                <?php esc_html_e('Automatically show the reaction bar under post content', 'simple-post-reactions'); ?></label>
        <?php }, 'spr-settings', 'spr_main');

        add_settings_field('spr_cleanup', __('Data Cleanup', 'simple-post-reactions'), function(){
            $o = get_option(SPR_OPT_KEY, []);
            $clean = !empty($o['cleanup_on_uninstall']); ?>
            <label><input type="checkbox" name="spr_settings[cleanup_on_uninstall]" value="1" <?php checked($clean, true); ?> />
                <?php esc_html_e('On uninstall, remove plugin settings and reaction counts', 'simple-post-reactions'); ?></label>
            <p class="description"><?php esc_html_e('Keep this unchecked if you want to preserve data after uninstall.', 'simple-post-reactions'); ?></p>
        <?php }, 'spr-settings', 'spr_main');
    }

    public function enqueue_admin($hook) {
        if ($hook !== 'settings_page_spr-settings') return;
        wp_enqueue_style('spr-admin', SPR_URL . 'assets/css/spr.css', [], SPR_VERSION);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return; ?>
        <div class="wrap">
            <h1><?php esc_html_e('Simple Post Reactions', 'simple-post-reactions'); ?></h1>
            <form method="post" action="options.php" style="margin-top:12px;">
                <?php
                settings_fields('spr_settings_group');
                do_settings_sections('spr-settings');
                submit_button();
                ?>
            </form>

            <hr/>
            <h2><?php esc_html_e('Reactions Dashboard', 'simple-post-reactions'); ?></h2>
            <p><?php esc_html_e('Quick view of reaction counts by post.', 'simple-post-reactions'); ?></p>
            <?php $this->render_dashboard_table(); ?>

            <hr/>
            <h2><?php esc_html_e('How to Use', 'simple-post-reactions'); ?></h2>
            <ol>
                <li><?php esc_html_e('Choose your mode (Like only or Like & Dislike).', 'simple-post-reactions'); ?></li>
                <li><?php esc_html_e('Enable Auto Insert or use the shortcode:', 'simple-post-reactions'); ?> <code>[post_reactions]</code></li>
                <li><?php esc_html_e('Counts update instantly via the REST API.', 'simple-post-reactions'); ?></li>
            </ol>
        </div>
        <?php
    }

    private function render_dashboard_table() {
        $posts = get_posts([
            'post_type'      => 'post',
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if (!$posts) {
            echo '<p>'.esc_html__('No posts found.', 'simple-post-reactions').'</p>';
            return;
        }

        echo '<table class="widefat striped spr-table" style="max-width:900px">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Post', 'simple-post-reactions').'</th>';
        echo '<th style="width:120px">'.esc_html__('Likes', 'simple-post-reactions').'</th>';
        echo '<th style="width:120px">'.esc_html__('Dislikes', 'simple-post-reactions').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($posts as $p) {
            $likes = (int) get_post_meta($p->ID, '_spr_likes', true);
            $dislikes = (int) get_post_meta($p->ID, '_spr_dislikes', true);
            echo '<tr>';
            echo '<td><a href="'.esc_url(get_edit_post_link($p->ID)).'">'.esc_html(get_the_title($p)).'</a></td>';
            echo '<td>'.esc_html($likes).'</td>';
            echo '<td>'.esc_html($dislikes).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
endif;

new SPR_Plugin();



