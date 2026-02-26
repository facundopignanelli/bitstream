<?php
/**
 * BitStream Admin Interface Handler
 * 
 * Handles admin menus, settings pages, and admin-only functionality
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_Admin_Interface
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_menu', [$this, 'remove_default_add_new'], 99);
        add_action('admin_notices', [$this, 'permalink_admin_notice']);
        add_action('wp_ajax_bitstream_flush_permalinks', [$this, 'flush_permalinks_ajax']);
        add_filter('post_row_actions', [$this, 'add_quote_action'], 10, 2);
        add_action('edit_form_after_title', [$this, 'show_quoted_preview']);
        add_action('save_post_bit', [$this, 'save_quoted_meta']);
        add_action('admin_init', [$this, 'redirect_new_bit_creation']);
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_weekly_media_cleanup_scheduled']);
        add_action('bitstream_weekly_media_cleanup_event', [$this, 'run_weekly_media_cleanup']);
    }

    /**
     * Register BitStream cron schedules.
     */
    public function register_cron_schedules($schedules)
    {
        if (!isset($schedules['bitstream_weekly'])) {
            $schedules['bitstream_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Once Weekly (BitStream)', 'bitstream'),
            ];
        }

        return $schedules;
    }

    /**
     * Ensure weekly cleanup event is scheduled.
     */
    public function ensure_weekly_media_cleanup_scheduled()
    {
        if (!wp_next_scheduled('bitstream_weekly_media_cleanup_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'bitstream_weekly', 'bitstream_weekly_media_cleanup_event');
        }
    }

    /**
     * Resolve poster page URL
     */
    private function get_poster_url($query_args = [])
    {
        if (class_exists('BitStream_Shortcodes')) {
            return BitStream_Shortcodes::get_poster_page_url($query_args);
        }

        $fallback = home_url('/bitstream/');
        if (!empty($query_args)) {
            return add_query_arg($query_args, $fallback);
        }

        return $fallback;
    }

    /**
     * Force new bit creation to use admin poster
     */
    public function redirect_new_bit_creation()
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'post-new.php') {
            return;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'bit') {
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        $poster_tab = (isset($_GET['rebit']) || isset($_GET['poster_tab']) && $_GET['poster_tab'] === 'rebit') ? 'rebit' : 'bit';
        $query_args = [
            'post_type' => 'bit',
            'page' => 'bitstream-new-bit',
            'poster_tab' => $poster_tab
        ];

        $forward_keys = ['shared_url', 'shared_title', 'shared_text', 'media_ids', 'quote_post_id', 'shared_key'];
        foreach ($forward_keys as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $value = wp_unslash($_GET[$key]);
                if ($key === 'shared_url') {
                    $query_args[$key] = esc_url_raw($value);
                }
                elseif ($key === 'quote_post_id') {
                    $query_args[$key] = intval($value);
                }
                else {
                    $query_args[$key] = sanitize_text_field($value);
                }
            }
        }

        wp_redirect(add_query_arg($query_args, admin_url('edit.php')));
        exit;
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menus()
    {
        // 1. New Bit (Unified Poster)
        $hook = add_submenu_page(
            'edit.php?post_type=bit',
            'New Bit',
            'New Bit',
            'edit_posts',
            'bitstream-new-bit',
        [$this, 'new_bit_admin_page'],
            1
        );

        // Enqueue assets specifically for this admin page
        add_action('admin_print_styles-' . $hook, [$this, 'enqueue_admin_poster_assets']);

        // Feed Intro Text
        add_submenu_page('edit.php?post_type=bit', 'Feed Intro', 'Feed Intro', 'edit_posts', 'bitstream-feed-intro', [$this, 'feed_intro_page']);

        // 3. All Bits is automatically handled by WordPress (All Bits menu item)

        // 4. ReBit Mappings
        add_submenu_page('edit.php?post_type=bit', 'ReBit Mappings', 'ReBit Mappings', 'manage_options', 'bitstream-rebit-mappings', [$this, 'rebit_mappings_page']);

        // 5. RSS Feeds
        add_submenu_page('edit.php?post_type=bit', 'RSS Feeds', 'RSS Feeds', 'read', 'bitstream-rss-feeds', [$this, 'rss_feeds_page']);

        // 6. Media Cleanup
        add_submenu_page('edit.php?post_type=bit', 'Media Cleanup', 'Media Cleanup', 'manage_options', 'bitstream-media-cleanup', [$this, 'media_cleanup_page']);

        // 7. Reset BitStream (dev only)
        add_submenu_page('edit.php?post_type=bit', 'Reset BitStream', 'Reset BitStream', 'manage_options', 'bitstream-reset', [$this, 'reset_bitstream_page']);
    }

    /**
     * Remove the default "Add New" submenu (runs at priority 99 so it fires after WP registers it)
     */
    public function remove_default_add_new()
    {
        remove_submenu_page('edit.php?post_type=bit', 'post-new.php?post_type=bit');
    }

    /**
     * Display the New Bit poster inside the admin area
     */
    public function new_bit_admin_page()
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('New Bit', 'bitstream') . '</h1>';
        echo '<div style="max-width: 600px; margin-top: 20px;">';
        echo do_shortcode('[bitstream_poster]');
        echo '</div>';
        echo '</div>';
    }

    /**
     * Enqueue CSS/JS for the admin poster page
     */
    public function enqueue_admin_poster_assets()
    {
        wp_enqueue_media();
        wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION);
        wp_enqueue_script('bitstream-js', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js', ['jquery'], BITSTREAM_VERSION, true);

        wp_localize_script('bitstream-js', 'bitstream_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bitstream_nonce'),
            'admin_page_redirect' => admin_url('edit.php?post_type=bit')
        ]);
    }

    /**
     * Determine whether an attachment is likely managed by BitStream.
     */
    private function is_bitstream_attachment($attachment_id)
    {
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }

        if (intval(get_post_meta($attachment_id, '_bitstream_uploaded_via_poster', true)) === 1) {
            return true;
        }

        $attachment = get_post($attachment_id);
        if ($attachment && intval($attachment->post_parent) > 0) {
            $parent = get_post(intval($attachment->post_parent));
            if ($parent && $parent->post_type === 'bit') {
                return true;
            }
        }

        $file = get_attached_file($attachment_id);
        if (!empty($file) && strpos(str_replace('\\', '/', $file), '/bitstream-artwork/') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if an attachment is referenced by non-trash content/meta across the site.
     */
    private function attachment_is_used_sitewide($attachment_id)
    {
        global $wpdb;

        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return true;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return true;
        }

        $parent_id = intval($attachment->post_parent);
        if ($parent_id > 0) {
            $parent = get_post($parent_id);
            if ($parent && !in_array($parent->post_status, ['trash', 'auto-draft'], true)) {
                return true;
            }
        }

        $meta_ref = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_value = %d
               AND p.post_status NOT IN ('trash','auto-draft')
             LIMIT 1",
            $attachment_id
        ));
        if (!empty($meta_ref)) {
            return true;
        }

        $audio_meta_like = '%"artwork_id";i:' . intval($attachment_id) . ';%';
        $audio_meta_ref = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_bitstream_audio_meta'
                             AND pm.meta_value LIKE %s
                             AND p.post_status NOT IN ('trash','auto-draft')
                         LIMIT 1",
            $audio_meta_like
        ));
        if (!empty($audio_meta_ref)) {
            return true;
        }

        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $url_like = '%' . $wpdb->esc_like($attachment_url) . '%';
            $url_ref = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status NOT IN ('trash','auto-draft','inherit')
                   AND post_content LIKE %s
                 LIMIT 1",
                $url_like
            ));
            if (!empty($url_ref)) {
                return true;
            }
        }

        $class_like = '%wp-image-' . intval($attachment_id) . '%';
        $class_ref = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash','auto-draft','inherit')
               AND post_content LIKE %s
             LIMIT 1",
            $class_like
        ));
        if (!empty($class_ref)) {
            return true;
        }

        return false;
    }

    /**
     * Scan and optionally delete orphaned BitStream-managed media.
     */
    private function run_bitstream_media_cleanup($perform_delete = false)
    {
        $results = [
            'scanned' => 0,
            'candidates' => 0,
            'deleted' => 0,
            'protected' => 0,
            'errors' => 0,
            'deleted_items' => [],
        ];

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $now = time();
        $grace_seconds = 30 * MINUTE_IN_SECONDS;

        foreach ($query->posts as $attachment_id) {
            $attachment_id = intval($attachment_id);
            if ($attachment_id <= 0) {
                continue;
            }

            if (!$this->is_bitstream_attachment($attachment_id)) {
                continue;
            }

            $results['scanned']++;

            $created_at = intval(get_post_meta($attachment_id, '_bitstream_upload_created_at', true));
            if ($created_at > 0 && ($now - $created_at) < $grace_seconds) {
                $results['protected']++;
                continue;
            }

            if ($this->attachment_is_used_sitewide($attachment_id)) {
                $results['protected']++;
                continue;
            }

            $results['candidates']++;

            if ($perform_delete) {
                $deleted = wp_delete_attachment($attachment_id, true);
                if ($deleted) {
                    $results['deleted']++;
                    if (count($results['deleted_items']) < 20) {
                        $results['deleted_items'][] = $attachment_id;
                    }
                }
                else {
                    $results['errors']++;
                }
            }
        }

        wp_reset_postdata();

        return $results;
    }

    /**
     * Weekly automated cleanup job.
     */
    public function run_weekly_media_cleanup()
    {
        $results = $this->run_bitstream_media_cleanup(true);
        update_option('bitstream_last_weekly_media_cleanup', [
            'timestamp' => time(),
            'scanned' => intval($results['scanned'] ?? 0),
            'candidates' => intval($results['candidates'] ?? 0),
            'deleted' => intval($results['deleted'] ?? 0),
            'protected' => intval($results['protected'] ?? 0),
            'errors' => intval($results['errors'] ?? 0),
        ], false);
    }

    /**
     * Reset BitStream page handler.
     */
    public function reset_bitstream_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (isset($_POST['bitstream_confirm_reset']) && check_admin_referer('bitstream_reset', 'bitstream_reset_nonce')) {
            $this->perform_bitstream_reset();
            echo '<div class="notice notice-success"><p><strong>BitStream Reset Complete.</strong> All posts, media, and settings have been removed. The plugin is now in a virgin state. Redirecting...</p></div>';
            echo '<script>setTimeout(function() { window.location.href = "' . admin_url('edit.php?post_type=bit&page=bitstream-rebit-mappings') . '"; }, 2000);</script>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Reset BitStream</h1>';
        echo '<div class="notice notice-error"><p><strong>WARNING:</strong> This will permanently delete all Bits, ReBits, associated media files, and plugin settings. This cannot be undone!</p></div>';

        echo '<form method="post" style="margin-top: 20px;">';
        wp_nonce_field('bitstream_reset', 'bitstream_reset_nonce');
        echo '<button type="submit" name="bitstream_confirm_reset" class="button button-primary button-large" onclick="return confirm(\'Permanently delete ALL BitStream data and reset to virgin state? This cannot be undone.\');">Reset Everything</button>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Perform the complete BitStream reset.
     */
    private function perform_bitstream_reset()
    {
        global $wpdb;

        // 1. Delete all BitStream posts and their attachments
        $posts = new WP_Query([
            'post_type' => 'bit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
        ]);

        foreach ($posts->posts as $post_id) {
            $post_id = intval($post_id);

            // Get all attachments for this post
            $attachments = get_posts([
                'post_parent' => $post_id,
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'post_status' => 'any',
            ]);

            foreach ($attachments as $attachment_id) {
                wp_delete_attachment(intval($attachment_id), true);
            }

            // Delete post (force delete, skip trash)
            wp_delete_post($post_id, true);
        }

        wp_reset_postdata();

        // 2. Delete orphaned BitStream-related attachments
        $orphaned = new WP_Query([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_bitstream_uploaded_via_poster',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($orphaned->posts as $attachment_id) {
            wp_delete_attachment(intval($attachment_id), true);
        }

        wp_reset_postdata();

        // 3. Delete BitStream postmeta entries
        $meta_keys = [
            '_bitstream_uploaded_via_poster',
            '_bitstream_upload_created_at',
            '_bitstream_attachment_id',
            '_bitstream_generated_artwork_id',
        ];

        foreach ($meta_keys as $meta_key) {
            $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key], ['%s']);
        }

        // 4. Delete BitStream options
        $bitstream_options = [
            'bitstream_permalinks_flushed',
            'bitstream_rebit_mappings',
            'bitstream_last_weekly_media_cleanup',
        ];

        foreach ($bitstream_options as $option) {
            delete_option($option);
        }

        // 5. Delete BitStream artwork directory
        $upload_dir = wp_upload_dir();
        $artwork_dir = $upload_dir['basedir'] . '/bitstream-artwork';

        if (is_dir($artwork_dir)) {
            $files = glob($artwork_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($artwork_dir);
        }

        // 6. Clear scheduled cron events
        wp_clear_scheduled_hook('bitstream_weekly_media_cleanup_event');

        // 7. Re-import default ReBit mappings (virgin install state)
        BitStream_ReBit_Mappings::import_default_mappings();

        // 8. Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Media cleanup admin page.
     */
    public function media_cleanup_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $results = null;
        $did_delete = false;
        $last_weekly = get_option('bitstream_last_weekly_media_cleanup', []);

        if (isset($_POST['bitstream_media_scan']) && check_admin_referer('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce')) {
            $results = $this->run_bitstream_media_cleanup(false);
        }

        if (isset($_POST['bitstream_media_delete']) && check_admin_referer('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce')) {
            $results = $this->run_bitstream_media_cleanup(true);
            $did_delete = true;
        }

        echo '<div class="wrap">';
        echo '<h1>BitStream Media Cleanup</h1>';
        echo '<p>Scan BitStream-managed uploads and generated artwork files, and remove media that is no longer referenced anywhere on your site.</p>';
        echo '<p><strong>Safety checks:</strong> media is only deleted when it is not referenced by active content/meta, and recent uploads (&lt; 30 minutes) are skipped.</p>';

        if (!empty($last_weekly) && !empty($last_weekly['timestamp'])) {
            $run_time = wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($last_weekly['timestamp']));
            echo '<p><strong>Last weekly cleanup run:</strong> ' . esc_html($run_time) . ' | ';
            echo 'Scanned: <strong>' . intval($last_weekly['scanned'] ?? 0) . '</strong> | ';
            echo 'Candidates: <strong>' . intval($last_weekly['candidates'] ?? 0) . '</strong> | ';
            echo 'Deleted: <strong>' . intval($last_weekly['deleted'] ?? 0) . '</strong> | ';
            echo 'Protected/Skipped: <strong>' . intval($last_weekly['protected'] ?? 0) . '</strong> | ';
            echo 'Errors: <strong>' . intval($last_weekly['errors'] ?? 0) . '</strong></p>';
        }
        else {
            echo '<p><strong>Last weekly cleanup run:</strong> Not run yet.</p>';
        }

        if ($results) {
            $notice_class = $did_delete ? 'notice-success' : 'notice-info';
            echo '<div class="notice ' . esc_attr($notice_class) . '"><p>';
            if ($did_delete) {
                echo 'Cleanup complete. ';
            }
            else {
                echo 'Scan complete. ';
            }
            echo 'Scanned: <strong>' . intval($results['scanned']) . '</strong> | ';
            echo 'Candidates: <strong>' . intval($results['candidates']) . '</strong> | ';
            echo 'Deleted: <strong>' . intval($results['deleted']) . '</strong> | ';
            echo 'Protected/Skipped: <strong>' . intval($results['protected']) . '</strong> | ';
            echo 'Errors: <strong>' . intval($results['errors']) . '</strong>';
            echo '</p></div>';

            if (!empty($results['deleted_items'])) {
                echo '<p><strong>Recently deleted attachment IDs:</strong> ' . esc_html(implode(', ', $results['deleted_items'])) . '</p>';
            }
        }

        echo '<form method="post" style="margin-top: 16px;">';
        wp_nonce_field('bitstream_media_cleanup', 'bitstream_media_cleanup_nonce');
        echo '<button type="submit" name="bitstream_media_scan" class="button button-secondary">Scan Only</button> ';
        echo '<button type="submit" name="bitstream_media_delete" class="button button-primary" onclick="return confirm(\'Delete all currently detected orphaned BitStream media? This cannot be undone.\');">Scan and Delete Orphans</button>';
        echo '</form>';

        echo '</div>';
    }

    /**
     * Admin notice for permalink issues
     */
    public function permalink_admin_notice()
    {
        if (!current_user_can('manage_options'))
            return;

        // Check if we need to flush permalinks due to debug request
        if (isset($_GET['bitstream_debug']) && $_GET['bitstream_debug'] === 'flush_rewrite') {
            flush_rewrite_rules();
            update_option('bitstream_permalinks_flushed', BITSTREAM_VERSION);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>BitStream:</strong> Rewrite rules have been flushed. Permalinks should now work properly.</p>';
            echo '</div>';
            return;
        }

        if (get_option('bitstream_permalinks_flushed') !== BITSTREAM_VERSION) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>BitStream:</strong> Permalink issues detected after plugin update. ';
            echo '<button type="button" class="button button-primary" onclick="bitstreamFlushPermalinks()">Fix Permalinks</button> ';
            echo 'or go to <a href="' . admin_url('options-permalink.php') . '">Settings > Permalinks</a> and click "Save Changes".</p>';
            echo '</div>';

            // Add JavaScript for AJAX call
            echo '<script>
            function bitstreamFlushPermalinks() {
                const button = event.target;
                button.disabled = true;
                button.textContent = "Fixing...";
                
                const formData = new FormData();
                formData.append("action", "bitstream_flush_permalinks");
                formData.append("nonce", "' . wp_create_nonce('flush_permalinks') . '");
                
                fetch("' . admin_url('admin-ajax.php') . '", {
                    method: "POST",
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        button.textContent = "Fixed!";
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        button.textContent = "Error - Try Settings > Permalinks";
                        button.disabled = false;
                        console.error("BitStream permalink flush error:", data.data);
                    }
                })
                .catch(error => {
                    button.textContent = "Error - Try Settings > Permalinks";
                    button.disabled = false;
                    console.error("BitStream permalink flush error:", error);
                });
            }
            </script>';
        }
    }

    /**
     * AJAX handler to flush permalinks
     */
    public function flush_permalinks_ajax()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'flush_permalinks')) {
            wp_send_json_error('Unauthorized');
        }

        flush_rewrite_rules();
        update_option('bitstream_permalinks_flushed', BITSTREAM_VERSION);
        wp_send_json_success('Permalinks flushed successfully');
    }

    /**
     * Handle ReBit redirect
     */
    public function handle_post_rebit_redirect()
    {
        wp_redirect($this->get_poster_url(['poster_tab' => 'rebit']));
        exit;
    }

    /**
     * Feed intro admin page.
     */
    public function feed_intro_page()
    {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $default_intro_title = 'About BitStream';
        $default_intro_text = 'BitStream is a lightweight microblog where you can post Bits, share Rebits, and follow updates in one place.';

        $intro_title = get_option('bitstream_feed_intro_title', $default_intro_title);
        $intro_text = get_option('bitstream_feed_intro_text', $default_intro_text);
        $updated = false;

        if (isset($_POST['bitstream_save_feed_intro']) && check_admin_referer('bitstream_feed_intro_save', 'bitstream_feed_intro_nonce')) {
            $new_intro_title = sanitize_text_field(wp_unslash($_POST['bitstream_feed_intro_title'] ?? ''));
            $new_intro_text = sanitize_textarea_field(wp_unslash($_POST['bitstream_feed_intro_text'] ?? ''));

            $intro_title = !empty($new_intro_title) ? $new_intro_title : $default_intro_title;
            $intro_text = !empty($new_intro_text) ? $new_intro_text : $default_intro_text;

            update_option('bitstream_feed_intro_title', $intro_title, false);
            update_option('bitstream_feed_intro_text', $intro_text, false);
            $updated = true;
        }

        echo '<div class="wrap">';
        echo '<h1>Feed Intro</h1>';
        echo '<p>Edit the intro text shown in the BitStream feed sidebar.</p>';

        if ($updated) {
            echo '<div class="notice notice-success is-dismissible"><p>Intro text updated.</p></div>';
        }

        echo '<form method="post" style="max-width: 760px;">';
        wp_nonce_field('bitstream_feed_intro_save', 'bitstream_feed_intro_nonce');

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="bitstream-feed-intro-title">Title</label></th>';
        echo '<td><input id="bitstream-feed-intro-title" name="bitstream_feed_intro_title" type="text" class="regular-text" value="' . esc_attr($intro_title) . '"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="bitstream-feed-intro-text">Description</label></th>';
        echo '<td><textarea id="bitstream-feed-intro-text" name="bitstream_feed_intro_text" rows="6" class="large-text">' . esc_textarea($intro_text) . '</textarea></td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" name="bitstream_save_feed_intro" class="button button-primary">Save Intro</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * RSS Feeds admin page
     */
    public function rss_feeds_page()
    {
        $home_url = home_url();

        // Handle flush rewrite rules request
        if (isset($_POST['flush_feeds']) && check_admin_referer('bitstream_flush_feeds', 'bitstream_flush_feeds_nonce')) {
            flush_rewrite_rules();
            echo '<div class="notice notice-success"><p>Rewrite rules flushed! RSS feeds should now work properly.</p></div>';
        }

        $feeds = [
            'All Content' => [
                'url' => $home_url . '/bitstream/feed/',
                'description' => 'Complete BitStream feed with all Bits and ReBits'
            ],
            'Bits Only' => [
                'url' => $home_url . '/bitstream/feed/bits/',
                'description' => 'Original Bits only (excluding ReBits)'
            ],
            'ReBits Only' => [
                'url' => $home_url . '/bitstream/feed/rebits/',
                'description' => 'ReBits only (shared content from other platforms)'
            ]
        ];

        echo '<style>
        /* Force full width for RSS feeds admin page */
        .wrap {
            max-width: none !important;
            width: 98% !important;
            margin: 0 auto !important;
        }
        #wpwrap, #wpcontent, #wpbody, #wpbody-content {
            max-width: none !important;
        }
        .wp-admin #wpbody-content {
            padding-right: 20px !important;
        }
        </style>';
        echo '<div class="wrap" style="max-width: none !important; width: 98% !important; margin: 0 auto !important;">';
        echo '<h1>RSS Feeds</h1>';
        echo '<p class="description">BitStream provides multiple RSS feeds for different content types. Choose the feed that best fits your needs.</p>';

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-top: 20px;">';

        foreach ($feeds as $name => $feed) {
            echo '<div class="card" style="padding: 20px;">';
            echo '<h2 class="title" style="margin-top: 0;">' . esc_html($name) . '</h2>';
            echo '<p class="description">' . esc_html($feed['description']) . '</p>';

            echo '<div style="margin: 15px 0;">';
            echo '<label><strong>Feed URL:</strong></label><br>';
            echo '<div style="display: flex; gap: 10px; align-items: center;">';
            echo '<input type="text" value="' . esc_attr($feed['url']) . '" readonly style="flex: 1; font-family: monospace; background: #f9f9f9;" onclick="this.select();" />';
            echo '<button type="button" class="button" onclick="copyToClipboard(\'' . esc_js($feed['url']) . '\', this)">Copy</button>';
            echo '<a href="' . esc_url($feed['url']) . '" target="_blank" class="button button-secondary">View Feed</a>';
            echo '</div>';
            echo '</div>';

            // Add subscription options
            echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">';
            echo '<p><strong>Subscribe with:</strong></p>';
            echo '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';

            $feed_url_encoded = urlencode($feed['url']);
            $subscribe_links = [
                'Feedly' => 'https://feedly.com/i/subscription/feed/' . $feed_url_encoded,
                'Inoreader' => 'https://www.inoreader.com/?add_feed=' . $feed_url_encoded,
                'NewsBlur' => 'https://newsblur.com/?url=' . $feed_url_encoded,
                'Pocket' => 'https://getpocket.com/edit?url=' . $feed_url_encoded
            ];

            foreach ($subscribe_links as $service => $link) {
                echo '<a href="' . esc_url($link) . '" target="_blank" class="button button-small">' . esc_html($service) . '</a>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        // Add flush rewrite rules form
        echo '<div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">';
        echo '<h3>Troubleshooting</h3>';
        echo '<p>If the RSS feeds are showing 404 errors, try flushing the rewrite rules:</p>';
        echo '<form method="post" style="margin-top: 15px;">';
        wp_nonce_field('bitstream_flush_feeds', 'bitstream_flush_feeds_nonce');
        echo '<button type="submit" name="flush_feeds" class="button button-secondary">Flush Rewrite Rules</button>';
        echo '</form>';
        echo '</div>';

        // Add JavaScript for copy functionality
        echo '<script>
        function copyToClipboard(text, button) {
            navigator.clipboard.writeText(text).then(function() {
                const originalText = button.textContent;
                button.textContent = "Copied!";
                button.style.background = "#2c6e49";
                button.style.color = "white";
                setTimeout(function() {
                    button.textContent = originalText;
                    button.style.background = "";
                    button.style.color = "";
                }, 2000);
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement("textarea");
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand("copy");
                document.body.removeChild(textArea);
                
                const originalText = button.textContent;
                button.textContent = "Copied!";
                setTimeout(function() {
                    button.textContent = originalText;
                }, 2000);
            });
        }
        </script>';

        echo '</div>';
    }

    /**
     * Enhanced ReBit mappings admin page with improved UX
     */
    public function rebit_mappings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle form submission for saving/editing mappings
        if (isset($_POST['bitstream_rebit_mappings']) && check_admin_referer('bitstream_rebit_mappings_save', 'bitstream_rebit_mappings_nonce')) {
            $posted = $_POST['bitstream_rebit_mappings'];
            $mappings_to_save = [];

            // Handle existing mappings (edits and keeps)
            if (isset($posted['existing'])) {
                foreach ($posted['existing'] as $map) {
                    // Skip if marked for removal
                    if (!empty($map['remove'])) {
                        continue;
                    }

                    $domain = sanitize_text_field($map['domain'] ?? '');
                    $label = sanitize_text_field($map['label'] ?? '');
                    $icon = sanitize_text_field($map['icon'] ?? '');

                    if (!empty($domain) && !empty($label) && !empty($icon)) {
                        $mappings_to_save[] = [
                            'domain' => $domain,
                            'label' => $label,
                            'icon' => $icon,
                        ];
                    }
                }
            }

            // Handle new mapping
            if (isset($posted['new'])) {
                $domain = sanitize_text_field($posted['new']['domain'] ?? '');
                $label = sanitize_text_field($posted['new']['label'] ?? '');
                $icon = sanitize_text_field($posted['new']['icon'] ?? '');

                if (!empty($domain) && !empty($label) && !empty($icon)) {
                    // Check if domain already exists in what we're saving
                    $exists = false;
                    foreach ($mappings_to_save as $mapping) {
                        if ($mapping['domain'] === $domain) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $mappings_to_save[] = [
                            'domain' => $domain,
                            'label' => $label,
                            'icon' => $icon,
                        ];
                    }
                }
            }

            // Save all mappings via the centralized class
            BitStream_ReBit_Mappings::save_mappings($mappings_to_save);
            echo '<div class="updated notice is-dismissible"><p><strong>ReBit mappings saved successfully!</strong></p></div>';
        }

        // Handle preset addition
        if (isset($_POST['add_preset']) && check_admin_referer('bitstream_rebit_mappings_save', 'bitstream_rebit_mappings_nonce')) {
            $preset_key = sanitize_text_field($_POST['preset_selection'] ?? '');
            $presets = BitStream_ReBit_Mappings::get_rebit_presets();

            if (!empty($preset_key) && isset($presets[$preset_key])) {
                $new_mapping = $presets[$preset_key];

                // Check if domain already exists
                $existing_mappings = BitStream_ReBit_Mappings::get_all_mappings();
                $exists = false;
                foreach ($existing_mappings as $mapping) {
                    if ($mapping['domain'] === $new_mapping['domain']) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $existing_mappings[] = $new_mapping;
                    BitStream_ReBit_Mappings::save_mappings($existing_mappings);
                    echo '<div class="updated notice is-dismissible"><p><strong>Preset added successfully!</strong></p></div>';
                }
                else {
                    echo '<div class="error notice is-dismissible"><p><strong>Error:</strong> A mapping for this domain already exists.</p></div>';
                }
            }
        }

        // Handle import all presets
        if (isset($_POST['import_defaults']) && check_admin_referer('bitstream_rebit_mappings_save', 'bitstream_rebit_mappings_nonce')) {
            BitStream_ReBit_Mappings::import_all_presets();
            echo '<div class="updated notice is-dismissible"><p><strong>Default mappings imported successfully!</strong></p></div>';
        }

        // Get current mappings and render the form
        $mappings = BitStream_ReBit_Mappings::get_all_mappings();

        // Include the ReBit Mappings interface
        include_once BITSTREAM_PLUGIN_PATH . 'includes/admin-rebit-mappings-interface.php';
    }

    /**
     * Add quote action to post rows
     */
    public function add_quote_action($actions, $post)
    {
        if ($post->post_type === 'bit') {
            $url = $this->get_poster_url(['poster_tab' => 'bit', 'quote_post_id' => $post->ID]);
            $actions['quote'] = '<a href="' . esc_url($url) . '">Quote</a>';
        }
        return $actions;
    }

    /**
     * Show quoted bit preview in editor
     */
    public function show_quoted_preview($post)
    {
        if ($post->post_type === 'bit' && isset($_GET['quoted_bit'])) {
            $quoted_id = intval($_GET['quoted_bit']);
            $quoted_post = get_post($quoted_id);
            if ($quoted_post && $quoted_post->post_type === 'bit') {
                $content = apply_filters('the_content', $quoted_post->post_content);
                echo '<div class="bitstream-quoted-preview" style="border-radius:13px; box-shadow:0 2px 12px rgba(0,0,0,0.10); padding:16px; background:#fafafa; margin-bottom:20px;">';
                echo '<strong>Quoting Bit #' . $quoted_id . '</strong><br>' . $content;
                echo '</div>';
                echo '<input type="hidden" name="bitstream_quoted_bit" value="' . $quoted_id . '">';
            }
        }
    }

    /**
     * Save quoted bit meta
     */
    public function save_quoted_meta($post_id)
    {
        if (isset($_POST['bitstream_quoted_bit'])) {
            update_post_meta($post_id, '_bitstream_quoted_bit', intval($_POST['bitstream_quoted_bit']));
        }
        else {
            delete_post_meta($post_id, '_bitstream_quoted_bit');
        }
    }
}
