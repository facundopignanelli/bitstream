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
     * Resolve composer page URL
     */
    private function get_composer_url($query_args = [])
    {
        if (class_exists('BitStream_Shortcodes')) {
            return BitStream_Shortcodes::get_composer_page_url($query_args);
        }

        $fallback = home_url('/bitstream/');
        if (!empty($query_args)) {
            return add_query_arg($query_args, $fallback);
        }

        return $fallback;
    }

    /**
     * Force new bit creation to use admin composer
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

        $composer_tab = (isset($_GET['rebit']) || isset($_GET['composer_tab']) && $_GET['composer_tab'] === 'rebit') ? 'rebit' : 'bit';
        $query_args = [
            'post_type' => 'bit',
            'page' => 'bitstream-new-bit',
            'composer_tab' => $composer_tab
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
        $composer_hook = add_submenu_page(
            'edit.php?post_type=bit',
            'New Bit',
            'New Bit',
            'edit_posts',
            'bitstream-new-bit',
        [$this, 'new_bit_admin_page'],
            1
        );
        add_action('admin_print_styles-' . $composer_hook, [$this, 'enqueue_admin_composer_assets']);
    }

    /**
     * Remove the default "Add New" submenu (runs at priority 99 so it fires after WP registers it)
     */
    public function remove_default_add_new()
    {
        remove_submenu_page('edit.php?post_type=bit', 'post-new.php?post_type=bit');
    }

    /**
     * Display the New Bit composer inside the admin area
     */
    public function new_bit_admin_page()
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('New Bit', 'bitstream') . '</h1>';
        echo '<div style="max-width: 600px; margin-top: 20px;">';
        if (class_exists('BitStream_Shortcodes')) {
            echo BitStream_Shortcodes::render_composer_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</div>';
        echo '</div>';
    }


    /**
     * Enqueue CSS/JS for the admin composer page
     */
    public function enqueue_admin_composer_assets()
    {
        wp_enqueue_media();
        wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/css/bitstream.css'));
        wp_enqueue_script('bitstream-js', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js', ['jquery', 'twemoji'], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream.js'), true);

        wp_localize_script('bitstream-js', 'bitstream_ajax', array_merge(BitStream_Ajax_Handlers::get_localized_data(), [
            'admin_page_redirect' => admin_url('edit.php?post_type=bit')
        ]));
    }



    /**
     * Determine whether an attachment is likely managed by BitStream.
     */
    private function is_bitstream_attachment($attachment_id)
    {
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }

        if (intval(get_post_meta($attachment_id, '_bitstream_uploaded_via_composer', true)) === 1) {
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
    public function run_bitstream_media_cleanup($perform_delete = false)
    {
        $results = [
            'scanned' => 0,
            'candidates' => 0,
            'deleted' => 0,
            'protected' => 0,
            'errors' => 0,
            'deleted_items' => [],
        ];

        global $wpdb;

        // Query only BitStream-managed attachments to prevent PHP execution timeouts on sites with large media libraries
        $attachments_composer = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_bitstream_uploaded_via_composer',
                    'compare' => 'EXISTS',
                ]
            ],
            'no_found_rows'  => true,
        ]);

        $bit_post_ids = get_posts([
            'post_type'      => 'bit',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $attachments_parent = [];
        if (!empty($bit_post_ids)) {
            $attachments_parent = get_posts([
                'post_type'       => 'attachment',
                'post_status'     => 'any',
                'posts_per_page'  => -1,
                'fields'          => 'ids',
                'post_parent__in' => $bit_post_ids,
                'no_found_rows'   => true,
            ]);
        }

        $artwork_ids = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
              AND meta_value LIKE '%/bitstream-artwork/%'
        ");
        $artwork_ids = !empty($artwork_ids) ? array_map('intval', $artwork_ids) : [];

        $attachment_ids = array_unique(array_merge($attachments_composer, $attachments_parent, $artwork_ids));

        $now = time();
        $grace_seconds = 30 * MINUTE_IN_SECONDS;

        foreach ($attachment_ids as $attachment_id) {
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
     * Perform the complete BitStream reset.
     */
    public function perform_bitstream_reset()
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
                    'key' => '_bitstream_uploaded_via_composer',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($orphaned->posts as $attachment_id) {
            wp_delete_attachment(intval($attachment_id), true);
        }

        wp_reset_postdata();

        $meta_keys = [
            '_bitstream_uploaded_via_composer',
            '_bitstream_upload_created_at',
            '_bitstream_attachment_id',
            '_bitstream_attachment_ids',
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
            // Link to the feed page with ?quote_post_id=N so the Composer modal JS handler
            // detects the param on page load and opens the quote flow automatically.
            $feed_url = class_exists('BitStream_Shortcodes')
                ? BitStream_Shortcodes::get_feed_page_url(['quote_post_id' => $post->ID])
                : add_query_arg(['quote_post_id' => $post->ID], home_url('/bitstream/'));
            $actions['quote'] = '<a href="' . esc_url($feed_url) . '">Quote</a>';
        }
        return $actions;
    }

    /**
     * Show quoted bit preview in editor
     */
    public function show_quoted_preview($post)
    {
        if ($post->post_type === 'bit') {
            wp_nonce_field('bitstream_quoted_meta_save', 'bitstream_quoted_meta_nonce');
        }

        if ($post->post_type === 'bit' && isset($_GET['quoted_bit'])) {
            $quoted_id = intval($_GET['quoted_bit']);
            $quoted_post = get_post($quoted_id);
            if ($quoted_post && $quoted_post->post_type === 'bit') {
                $content = apply_filters('the_content', $quoted_post->post_content);
                if (strpos($content, '<p>') === false && strpos($content, '<p ') === false) {
                    $content = wpautop($content);
                }
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
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!isset($_POST['bitstream_quoted_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bitstream_quoted_meta_nonce'])), 'bitstream_quoted_meta_save')) {
            return;
        }

        if (isset($_POST['bitstream_quoted_bit']) && $_POST['bitstream_quoted_bit'] !== '') {
            update_post_meta($post_id, '_bitstream_quoted_bit', intval($_POST['bitstream_quoted_bit']));
        }
        else {
            delete_post_meta($post_id, '_bitstream_quoted_bit');
        }
    }
}
