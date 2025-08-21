<?php
/**
 * BitStream Admin Interface Handler
 * 
 * Handles admin menus, settings pages, and admin-only functionality
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Admin_Interface {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_notices', [$this, 'permalink_admin_notice']);
        add_action('wp_ajax_bitstream_flush_permalinks', [$this, 'flush_permalinks_ajax']);
        add_filter('post_row_actions', [$this, 'add_quote_action'], 10, 2);
        add_action('edit_form_after_title', [$this, 'show_quoted_preview']);
        add_action('save_post_bit', [$this, 'save_quoted_meta']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menus() {
        // 1. Add New Bit - WordPress handles this automatically as "Add New Bit"
        // 2. Add New ReBit 
        add_submenu_page('edit.php?post_type=bit', 'Add New ReBit', 'Add New ReBit', 'edit_posts', 'bitstream-post-rebit', [$this, 'handle_post_rebit_redirect']);
        
        // 3. All Bits is automatically handled by WordPress (All Bits menu item)
        
        // 4. ReBit Mappings
        add_submenu_page('edit.php?post_type=bit', 'ReBit Mappings', 'ReBit Mappings', 'manage_options', 'bitstream-rebit-mappings', [$this, 'rebit_mappings_page']);
        
        // 5. RSS Feeds
        add_submenu_page('edit.php?post_type=bit', 'RSS Feeds', 'RSS Feeds', 'read', 'bitstream-rss-feeds', [$this, 'rss_feeds_page']);
    }
    
    /**
     * Admin notice for permalink issues
     */
    public function permalink_admin_notice() {
        if (!current_user_can('manage_options')) return;
        
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
    public function flush_permalinks_ajax() {
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
    public function handle_post_rebit_redirect() {
        wp_redirect(admin_url('post-new.php?post_type=bit&rebit=1'));
        exit;
    }
    
    /**
     * RSS Feeds admin page
     */
    public function rss_feeds_page() {
        $home_url = home_url();
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
    public function rebit_mappings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle form submission
        if (isset($_POST['bitstream_rebit_mappings']) && check_admin_referer('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce')) {
            $posted = $_POST['bitstream_rebit_mappings'];
            $current_mappings = get_option('bitstream_rebit_mappings', []);
            $new = [];
            
            // Handle existing mappings
            if (isset($posted['existing'])) {
                foreach ($posted['existing'] as $map) {
                    if (isset($map['remove']) && $map['remove']) continue;
                    $domain = sanitize_text_field($map['domain'] ?? '');
                    $label  = sanitize_text_field($map['label'] ?? '');
                    $icon   = sanitize_text_field($map['icon'] ?? '');
                    if (!$domain || !$label || !$icon) continue;
                    $new[] = compact('domain','label','icon');
                }
            }
            
            // Handle new mapping
            if (isset($posted['new'])) {
                $domain = sanitize_text_field($posted['new']['domain'] ?? '');
                $label  = sanitize_text_field($posted['new']['label'] ?? '');
                $icon   = sanitize_text_field($posted['new']['icon'] ?? '');
                if ($domain && $label && $icon) {
                    // Check if domain already exists
                    $exists = false;
                    foreach ($new as $mapping) {
                        if ($mapping['domain'] === $domain) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $new[] = compact('domain','label','icon');
                        echo '<div class="updated notice is-dismissible"><p><strong>New mapping added successfully!</strong></p></div>';
                    } else {
                        echo '<div class="error notice is-dismissible"><p><strong>Error:</strong> A mapping for this domain already exists.</p></div>';
                    }
                }
            }
            
            update_option('bitstream_rebit_mappings', $new);
            if (!isset($posted['new']) || empty($posted['new']['domain'])) {
                echo '<div class="updated notice is-dismissible"><p><strong>ReBit mappings saved successfully!</strong></p></div>';
            }
        }
        
        // Handle preset addition
        if (isset($_POST['add_preset']) && check_admin_referer('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce')) {
            $preset = sanitize_text_field($_POST['preset_selection']);
            $mappings = get_option('bitstream_rebit_mappings', []);
            
            $presets = $this->get_rebit_presets();
            if (isset($presets[$preset])) {
                $new_mapping = $presets[$preset];
                // Check if domain already exists
                $exists = false;
                foreach ($mappings as $mapping) {
                    if ($mapping['domain'] === $new_mapping['domain']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $mappings[] = $new_mapping;
                    update_option('bitstream_rebit_mappings', $mappings);
                    echo '<div class="updated notice is-dismissible"><p><strong>Preset added successfully!</strong></p></div>';
                } else {
                    echo '<div class="error notice is-dismissible"><p><strong>Error:</strong> A mapping for this domain already exists.</p></div>';
                }
            }
        }
        
        // Handle import default mappings
        if (isset($_POST['import_defaults']) && check_admin_referer('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce')) {
            $this->import_default_mappings();
            echo '<div class="updated notice is-dismissible"><p><strong>Default mappings imported successfully!</strong></p></div>';
        }
        
        $mappings = get_option('bitstream_rebit_mappings', []);
        
        // Include the ReBit Mappings interface
        include_once BITSTREAM_PLUGIN_PATH . 'includes/admin-rebit-mappings-interface.php';
    }
    
    /**
     * Import default mappings based on the updated style
     */
    private function import_default_mappings() {
        $default_mappings = [
            ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fab fa-twitter'],
            ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fab fa-x-twitter'],
            ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fab fa-youtube'],
            ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fab fa-github'],
            ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fab fa-linkedin'],
            ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fab fa-facebook'],
            ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fab fa-instagram'],
            ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fab fa-reddit'],
            ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fab fa-medium'],
        ];
        
        $existing_mappings = get_option('bitstream_rebit_mappings', []);
        $existing_domains = array_column($existing_mappings, 'domain');
        
        foreach ($default_mappings as $mapping) {
            if (!in_array($mapping['domain'], $existing_domains)) {
                $existing_mappings[] = $mapping;
            }
        }
        
        update_option('bitstream_rebit_mappings', $existing_mappings);
    }
    
    /**
     * Get preset ReBit mappings for popular sites
     */
    private function get_rebit_presets() {
        return [
            'twitter' => ['domain' => 'twitter.com', 'label' => 'shared a Tweet', 'icon' => 'fab fa-twitter'],
            'x' => ['domain' => 'x.com', 'label' => 'shared a post', 'icon' => 'fab fa-x-twitter'],
            'youtube' => ['domain' => 'youtube.com', 'label' => 'shared a video', 'icon' => 'fab fa-youtube'],
            'github' => ['domain' => 'github.com', 'label' => 'shared a repository', 'icon' => 'fab fa-github'],
            'linkedin' => ['domain' => 'linkedin.com', 'label' => 'shared a post', 'icon' => 'fab fa-linkedin'],
            'facebook' => ['domain' => 'facebook.com', 'label' => 'shared a post', 'icon' => 'fab fa-facebook'],
            'instagram' => ['domain' => 'instagram.com', 'label' => 'shared a photo', 'icon' => 'fab fa-instagram'],
            'tiktok' => ['domain' => 'tiktok.com', 'label' => 'shared a video', 'icon' => 'fab fa-tiktok'],
            'reddit' => ['domain' => 'reddit.com', 'label' => 'shared a post', 'icon' => 'fab fa-reddit'],
            'medium' => ['domain' => 'medium.com', 'label' => 'shared an article', 'icon' => 'fab fa-medium'],
            'dev' => ['domain' => 'dev.to', 'label' => 'shared an article', 'icon' => 'fab fa-dev'],
            'hackernews' => ['domain' => 'news.ycombinator.com', 'label' => 'shared a story', 'icon' => 'fab fa-hacker-news'],
            'stackoverflow' => ['domain' => 'stackoverflow.com', 'label' => 'shared a question', 'icon' => 'fab fa-stack-overflow'],
            'wikipedia' => ['domain' => 'wikipedia.org', 'label' => 'shared an article', 'icon' => 'fab fa-wikipedia-w'],
            'bbc' => ['domain' => 'bbc.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'cnn' => ['domain' => 'cnn.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'nytimes' => ['domain' => 'nytimes.com', 'label' => 'shared a news article', 'icon' => 'fas fa-newspaper'],
            'spotify' => ['domain' => 'spotify.com', 'label' => 'shared a song', 'icon' => 'fab fa-spotify'],
            'twitch' => ['domain' => 'twitch.tv', 'label' => 'shared a stream', 'icon' => 'fab fa-twitch'],
            'discord' => ['domain' => 'discord.com', 'label' => 'shared a message', 'icon' => 'fab fa-discord'],
        ];
    }
    
    /**
     * Add quote action to post rows
     */
    public function add_quote_action($actions, $post) {
        if ($post->post_type === 'bit') {
            $url = admin_url('post-new.php?post_type=bit&quoted_bit=' . $post->ID);
            $actions['quote'] = '<a href="' . esc_url($url) . '">Quote</a>';
        }
        return $actions;
    }
    
    /**
     * Show quoted bit preview in editor
     */
    public function show_quoted_preview($post) {
        if ($post->post_type === 'bit' && isset($_GET['quoted_bit'])) {
            $quoted_id = intval($_GET['quoted_bit']);
            $quoted_post = get_post($quoted_id);
            if ($quoted_post && $quoted_post->post_type === 'bit') {
                $content = apply_filters('the_content', $quoted_post->post_content);
                echo '<div class="bitstream-quoted-preview" style="border-radius:13px; box-shadow:0 2px 12px rgba(0,0,0,0.10); padding:16px; background:#fafafa; margin-bottom:20px;">';
                echo '<strong>Quoting Bit #'.$quoted_id.'</strong><br>' . $content;
                echo '</div>';
                echo '<input type="hidden" name="bitstream_quoted_bit" value="'.$quoted_id.'">';
            }
        }
    }
    
    /**
     * Save quoted bit meta
     */
    public function save_quoted_meta($post_id) {
        if (isset($_POST['bitstream_quoted_bit'])) {
            update_post_meta($post_id, '_bitstream_quoted_bit', intval($_POST['bitstream_quoted_bit']));
        } else {
            delete_post_meta($post_id, '_bitstream_quoted_bit');
        }
    }
}
