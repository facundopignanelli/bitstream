<?php
/**
 * BitStream PWA Manager
 * 
 * Handles PWA assets, service worker, and manifest functionality
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_PWA_Manager {
    
    public function __construct() {
        add_action('wp_head', [$this, 'pwa_assets']);
        add_action('wp_footer', [$this, 'render_floating_bitstream_button']);
        add_action('init', [$this, 'add_service_worker_rewrite']);
        add_action('init', [$this, 'add_shortcut_rewrite']);
        add_action('template_redirect', [$this, 'serve_service_worker']);
        add_action('template_redirect', [$this, 'handle_shortcut_requests']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_debug_requests']);
    }

    /**
     * Resolve poster page URL
     */
    private function get_poster_url($query_args = []) {
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
     * Add PWA assets for BitStream pages
     */
    public function pwa_assets() {
        global $post;
        
        // Load on archive pages or pages with [bitstream] shortcode
        $is_bit_archive = is_post_type_archive('bit');
        $has_feed_shortcode = is_a($post, 'WP_Post') && 
                     (has_shortcode($post->post_content, 'bitstream') || 
                      has_shortcode($post->post_content, 'bitstream_latest') ||
                      has_shortcode($post->post_content, 'bitstream_poster'));
        $is_bitstream_page = isset($_SERVER['REQUEST_URI']) && 
                            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false;
        
        if ($is_bit_archive || $has_feed_shortcode || $is_bitstream_page) {
            $base = BITSTREAM_PLUGIN_URL;
            $manifest_url = $base . 'manifest.json';
            $sw_url = home_url('/sw.js');
            
            echo '<link rel="manifest" href="'.esc_url($manifest_url).'">';
            echo '<link rel="apple-touch-icon" href="'.esc_url($base . 'assets/images/logo_192.png').'">';
            echo '<meta name="theme-color" content="#2c6e49">';
            echo '<meta name="mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
            echo '<meta name="apple-mobile-web-app-title" content="BitStream">';
            
            echo '<script>
            if("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    // First, unregister any existing service workers with broader scope
                    navigator.serviceWorker.getRegistrations().then(function(registrations) {
                        registrations.forEach(function(registration) {
                            if (registration.scope.includes("/sw.js") || registration.scope === location.origin + "/") {
                                console.log("Unregistering old BitStream SW with scope:", registration.scope);
                                registration.unregister();
                            }
                        });
                    });
                    
                    // Then register the new service worker with correct scope
                    navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                        scope: "/bitstream/",
                        updateViaCache: "none"
                    }).then(function(registration) {
                        console.log("BitStream PWA registered with scope:", registration.scope);
                        
                        // Check for installation prompt
                        window.addEventListener("beforeinstallprompt", function(event) {
                            console.log("BitStream PWA installation available");
                            // Store the event for later use
                            window.deferredPrompt = event;
                        });
                        
                    }).catch(function(error) {
                        console.warn("BitStream SW registration failed:", error);
                    });
                });
            }
            </script>';
        }
    }

    /**
     * Render floating BitStream button for admins
     */
    public function render_floating_bitstream_button() {
        global $post;
        
        // Only show to users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Only show on BitStream-related pages
        $is_bit_archive = is_post_type_archive('bit');
        $has_feed_shortcode = is_a($post, 'WP_Post') && 
                     (has_shortcode($post->post_content, 'bitstream') || 
                      has_shortcode($post->post_content, 'bitstream_latest') ||
                      has_shortcode($post->post_content, 'bitstream_poster'));
        $is_bitstream_page = isset($_SERVER['REQUEST_URI']) && 
                            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false;
        
        // Only show the floating button on BitStream-related pages
        if (!($is_bit_archive || $has_feed_shortcode || $is_bitstream_page)) {
            return;
        }

        $quick_actions_html = '';
        if (class_exists('BitStream_Shortcodes')) {
            $quick_actions_html = BitStream_Shortcodes::render_quick_action_links('bitstream-dropdown-link');
        }

        if (empty($quick_actions_html)) {
            return;
        }
        ?>
        <style>
        /* Mobile-specific fixes for floating BitStream menu */
        @media (min-width: 1024px) {
            #bitstream-floating-menu {
                display: none !important;
            }
        }

        @media (max-width: 768px) {
            #bitstream-floating-menu {
                bottom: 20px !important;
                right: 20px !important;
            }
            .bitstream-toggle {
                width: 56px !important;
                height: 56px !important;
                font-size: 20px !important;
                touch-action: manipulation !important;
                -webkit-touch-callout: none !important;
            }
            .bitstream-dropdown {
                min-width: 160px !important;
                bottom: 65px !important;
                right: -10px !important;
            }
            .bitstream-dropdown a {
                padding: 10px 12px !important;
                font-size: 14px !important;
                touch-action: manipulation !important;
                -webkit-touch-callout: none !important;
            }
        }

        .bitstream-dropdown-link {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            text-decoration: none;
            color: #333;
            -webkit-tap-highlight-color: transparent;
        }

        .bitstream-dropdown-link + .bitstream-dropdown-link {
            border-top: 1px solid #eee;
        }

        .bitstream-dropdown-link i {
            margin-right: 8px;
            color: #2c6e49;
            pointer-events: none;
        }
        
        /* Force proper touch behavior */
        .bitstream-toggle {
            touch-action: manipulation !important;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }
        </style>
        <div id="bitstream-floating-menu" style="position: fixed; bottom: 30px; right: 30px; z-index: 9999;">
            <div class="bitstream-menu">
                <button class="bitstream-toggle" 
                    style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #2c6e49; color: white; border-radius: 15px; border: none; box-shadow: 0 4px 12px rgba(44,110,73,0.25); transition: all 0.3s ease; font-size: 24px; cursor: pointer; -webkit-tap-highlight-color: transparent; user-select: none; touch-action: manipulation;"
                        title="Quick Actions"
                        type="button"
                        aria-label="Open BitStream quick actions menu">
                    <i class="fa-solid fa-plus" style="margin: 0; pointer-events: none;"></i>
                </button>
                <div class="bitstream-dropdown" style="position: absolute; bottom: 70px; right: 0; background: white; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 180px; opacity: 0; visibility: hidden; transform: translateY(10px); transition: all 0.3s ease; pointer-events: none;">
                    <?php echo wp_kses_post($quick_actions_html); ?>
                </div>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.querySelector('.bitstream-toggle');
            const dropdown = document.querySelector('.bitstream-dropdown');
            
            if (toggle && dropdown) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const isVisible = dropdown.style.opacity === '1';
                    
                    if (isVisible) {
                        // Hide dropdown
                        dropdown.style.opacity = '0';
                        dropdown.style.visibility = 'hidden';
                        dropdown.style.transform = 'translateY(10px)';
                        dropdown.style.pointerEvents = 'none';
                        toggle.style.transform = 'rotate(0deg)';
                    } else {
                        // Show dropdown
                        dropdown.style.opacity = '1';
                        dropdown.style.visibility = 'visible';
                        dropdown.style.transform = 'translateY(0)';
                        dropdown.style.pointerEvents = 'auto';
                        toggle.style.transform = 'rotate(45deg)';
                    }
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.style.opacity = '0';
                        dropdown.style.visibility = 'hidden';
                        dropdown.style.transform = 'translateY(10px)';
                        dropdown.style.pointerEvents = 'none';
                        toggle.style.transform = 'rotate(0deg)';
                    }
                });
                
                // Prevent dropdown clicks from closing it
                dropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Add rewrite rules for Service Worker file
     */
    public function add_service_worker_rewrite() {
        add_rewrite_rule('^sw\.js$', 'index.php?bitstream_sw=main', 'top');
        
        // Flush rewrite rules if they haven't been flushed for this version
        if (!get_option('bitstream_sw_rewrite_flushed_v2')) {
            flush_rewrite_rules(false);
            update_option('bitstream_sw_rewrite_flushed_v2', true);
            delete_option('bitstream_sw_rewrite_flushed'); // Remove old flag
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: Service Worker rewrite rules flushed (v2)');
            }
        }
    }

    /**
     * Add rewrite rules for PWA shortcut handling
     */
    public function add_shortcut_rewrite() {
        add_rewrite_rule('^bitstream/new-bit/?$', 'index.php?bitstream_action=new-bit', 'top');
        add_rewrite_rule('^bitstream/new-rebit/?$', 'index.php?bitstream_action=new-rebit', 'top');
        
        // Ensure rewrite rules are flushed when this version loads
        if (!get_option('bitstream_rewrite_flushed_v2.3.0')) {
            flush_rewrite_rules(false);
            update_option('bitstream_rewrite_flushed_v2.3.0', true);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: Rewrite rules flushed for v2.3.0 (share target support)');
            }
        }
    }

    /**
     * Add custom query vars for Service Worker routing
     */
    public function add_query_vars($vars) {
        $vars[] = 'bitstream_sw';
        $vars[] = 'bitstream_action';
        return $vars;
    }

    /**
     * Serve Service Worker files with proper headers
     */
    public function serve_service_worker() {
        $sw_type = get_query_var('bitstream_sw');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: serve_service_worker called');
        }
        
        if (!$sw_type) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: no service worker type found');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: serving service worker response');
        }
        
        // Set proper headers for Service Worker with no caching and CORS
        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Access-Control-Allow-Headers: Content-Type');
        
        // Serve the main Service Worker file
        $file_path = '';
        if ($sw_type === 'main') {
            $file_path = BITSTREAM_PLUGIN_PATH . 'sw.js';
        }
        
        if ($file_path && file_exists($file_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: service worker file found');
            }
            readfile($file_path);
            exit;
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: service worker file not found');
            }
            status_header(404);
            echo '// Service Worker file not found';
            exit;
        }
    }

    /**
     * Handle media sharing from PWA (photos/videos)
     */
    private function extract_share_url($shared_url, $shared_text, $shared_title) {
        $normalized_url = sanitize_url((string) $shared_url);
        $normalized_text = sanitize_text_field((string) $shared_text);
        $normalized_title = sanitize_text_field((string) $shared_title);

        if (!empty($normalized_url) && filter_var($normalized_url, FILTER_VALIDATE_URL)) {
            return $normalized_url;
        }

        if (!empty($normalized_text) && filter_var($normalized_text, FILTER_VALIDATE_URL)) {
            return $normalized_text;
        }

        $all_content = trim($normalized_url . ' ' . $normalized_text . ' ' . $normalized_title);
        if (preg_match('/https?:\/\/[^\s]+/', $all_content, $matches) && !empty($matches[0])) {
            $candidate = sanitize_url($matches[0]);
            if (!empty($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return '';
    }

    private function clean_share_text($shared_text, $final_url) {
        $normalized_text = sanitize_textarea_field((string) $shared_text);

        if (empty($normalized_text)) {
            return '';
        }

        if (!empty($final_url)) {
            $normalized_text = trim(str_replace($final_url, '', $normalized_text));
        }

        return sanitize_textarea_field(preg_replace('/\s+/', ' ', $normalized_text));
    }

    private function handle_media_share() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: handle_media_share called');
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: user not logged in, storing transient handoff for share payload');
            }

            $pending_share = [
                'text' => isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '',
                'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
                'url' => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
                'timestamp' => time(),
            ];

            $transient_key = 'bitstream_shared_' . wp_generate_password(16, false);
            set_transient($transient_key, $pending_share, 15 * MINUTE_IN_SECONDS);

            $login_url = wp_login_url($this->get_poster_url([
                'poster_tab' => 'bit',
                'shared_key' => $transient_key,
            ]));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: redirecting to login with transient token');
            }
            wp_redirect($login_url);
            exit;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: user is logged in, processing media share');
        }
        
        // Check permissions
        if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: user lacks permissions for media share');
            }
            wp_die('You do not have permission to upload media or create posts.');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: user permission check passed for media share');
        }
        
        // Handle file uploads
        $attachment_ids = [];
        $shared_text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
        $shared_title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $shared_url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $final_url = $this->extract_share_url($shared_url, $shared_text, $shared_title);
        $clean_text = $this->clean_share_text($shared_text, $final_url);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: share payload parsed');
        }
        
        // Process uploaded files
        if (!empty($_FILES['media'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: processing uploaded media files');
            }
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            // Increase timeout for large files
            @set_time_limit(300);
            
            // Handle multiple files (media is an array)
            $files = $_FILES['media'];
            
            // Check if it's a single file or multiple files
            if (is_array($files['name'])) {
                // Multiple files
                $file_count = count($files['name']);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream: multiple files detected for media share');
                }
                for ($i = 0; $i < $file_count; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        // Create a single file array for this file
                        $file = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i]
                        ];
                        
                        $attachment_id = $this->handle_single_file_upload($file, $shared_title);
                        if ($attachment_id) {
                            $attachment_ids[] = $attachment_id;
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('BitStream: one shared file had upload error');
                        }
                    }
                }
            } else {
                // Single file
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream: single file detected for media share');
                }
                if ($files['error'] === UPLOAD_ERR_OK) {
                    $attachment_id = $this->handle_single_file_upload($files, $shared_title);
                    if ($attachment_id) {
                        $attachment_ids[] = $attachment_id;
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('BitStream: shared file upload error encountered');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: no media files included in share payload');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: media upload processing complete');
        }
        
        // If no files were uploaded successfully but we have shared text/url, just redirect
        if (empty($attachment_ids) && empty($clean_text) && empty($final_url)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: empty share payload, redirecting to new bit page');
            }
            wp_redirect($this->get_poster_url(['poster_tab' => 'bit']));
            exit;
        }
        
        // Build redirect URL to frontend poster page
        $redirect_url = $this->get_poster_url(['poster_tab' => empty($final_url) ? 'bit' : 'rebit']);
        
        if (!empty($attachment_ids)) {
            $redirect_url = add_query_arg('media_ids', implode(',', $attachment_ids), $redirect_url);
        }
        
        if (!empty($clean_text)) {
            $redirect_url = add_query_arg('shared_text', urlencode($clean_text), $redirect_url);
        }
        
        if (!empty($final_url)) {
            $redirect_url = add_query_arg('shared_url', urlencode($final_url), $redirect_url);
            $redirect_url = add_query_arg('poster_tab', 'rebit', $redirect_url);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: redirecting to poster after media share');
        }
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle a single file upload and return attachment ID
     */
    private function handle_single_file_upload($file, $title = '') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: processing shared file upload');
        }
        
        // Use wp_handle_upload to process the file
        $upload_overrides = [
            'test_form' => false,
            'test_type' => true
        ];
        
        $uploaded_file = wp_handle_upload($file, $upload_overrides);
        
        if (isset($uploaded_file['error'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: upload error during shared file processing');
            }
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: shared file uploaded successfully');
        }
        
        // Prepare attachment data
        $file_type = wp_check_filetype(basename($uploaded_file['file']), null);
        $attachment_title = !empty($title) ? $title : preg_replace('/\.[^.]+$/', '', basename($uploaded_file['file']));
        
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_text_field($attachment_title),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        // Insert the attachment into the media library
        $attachment_id = wp_insert_attachment($attachment, $uploaded_file['file']);
        
        if (is_wp_error($attachment_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: failed creating attachment for shared file');
            }
            return false;
        }
        
        // Generate attachment metadata
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: attachment created for shared file');
        }
        return $attachment_id;
    }

    /**
     * Handle PWA shortcut requests
     */
    public function handle_shortcut_requests() {
        $action = get_query_var('bitstream_action');
        
        // Check for POST share request (media files)
        if ($action === 'new-bit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: detected POST share request to new-bit');
            }
            $this->handle_media_share();
            return;
        }
        
        if ($action) {
            // Check if user is logged in
            if (!is_user_logged_in()) {
                // Redirect to login with return URL
                $return_url = urlencode($_SERVER['REQUEST_URI']);
                wp_redirect(wp_login_url(home_url($_SERVER['REQUEST_URI'])));
                exit;
            }
            
            // Check if user can edit posts
            if (!current_user_can('edit_posts')) {
                wp_redirect(home_url('/bitstream/?error=permission_denied'));
                exit;
            }
            
            // Redirect to appropriate admin page
            switch ($action) {
                case 'new-bit':
                    wp_redirect($this->get_poster_url(['poster_tab' => 'bit']));
                    break;
                case 'new-rebit':
                    // Handle shared content from Android share sheet
                    
                    // Check for debug mode (restored normal mode)
                    $debug_mode = isset($_GET['debug']) || isset($_GET['test']);
                    
                    // Capture shared content parameters
                    $shared_url = isset($_GET['url']) ? sanitize_url($_GET['url']) : '';
                    $shared_title = isset($_GET['title']) ? sanitize_text_field($_GET['title']) : '';
                    $shared_text = isset($_GET['text']) ? sanitize_text_field($_GET['text']) : '';

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('BitStream Share Debug: share query parameters detected');
                    }
                    
                    $final_url = $this->extract_share_url($shared_url, $shared_text, $shared_title);
                    $clean_text = $this->clean_share_text($shared_text, $final_url);

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('BitStream Share Debug: share URL extraction complete');
                    }
                    
                    // Show debug page if requested or if we're testing
                    if ($debug_mode) {
                        $this->show_debug_page($shared_url, $shared_title, $shared_text, $_GET);
                        exit;
                    }
                    
                    // Check if user is logged in
                    if (!is_user_logged_in()) {
                        // Store the shared content in session/transient for after login
                        if ($final_url || $shared_title || $shared_text) {
                            $shared_data = array(
                                'url' => $final_url, // Use the extracted URL
                                'title' => $shared_title,
                                'text' => $clean_text,
                                'timestamp' => time()
                            );
                            // Use a transient that expires in 10 minutes
                            $transient_key = 'bitstream_shared_' . wp_generate_password(12, false);
                            set_transient($transient_key, $shared_data, 10 * MINUTE_IN_SECONDS);
                            
                            // Redirect to login with the shared data key
                            $login_url = wp_login_url($this->get_poster_url(['poster_tab' => 'rebit', 'shared_key' => $transient_key]));
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('BitStream Share Debug: user not logged in, redirecting with transient key');
                            }
                        } else {
                            // No shared data, just redirect to login
                            $login_url = wp_login_url($this->get_poster_url(['poster_tab' => 'rebit']));
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('BitStream Share Debug: user not logged in, redirecting to login');
                            }
                        }
                        wp_redirect($login_url);
                        exit;
                    }
                    
                    $redirect_url = $this->get_poster_url(['poster_tab' => 'rebit']);
                    
                    // Add shared content to redirect URL if available
                    if ($final_url) {
                        $redirect_url = add_query_arg('shared_url', urlencode($final_url), $redirect_url);
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('BitStream Share Debug: added shared_url to redirect');
                        }
                    }
                    if ($shared_title) {
                        $redirect_url = add_query_arg('shared_title', urlencode($shared_title), $redirect_url);
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('BitStream Share Debug: added shared_title to redirect');
                        }
                    }
                    // Note: We use final_url instead of shared_text since shared_text might be the URL
                    if (!empty($clean_text)) {
                        $redirect_url = add_query_arg('shared_text', urlencode($clean_text), $redirect_url);
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('BitStream Share Debug: added shared_text to redirect');
                        }
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('BitStream Share Debug: redirect URL assembled');
                    }
                    wp_redirect($redirect_url);
                    break;
                default:
                    // Default to BitStream feed
                    wp_redirect(home_url('/bitstream/'));
                    break;
            }
            exit;
        }
    }
    
    /**
     * Handle debug requests
     */
    public function handle_debug_requests() {
        if (isset($_GET['bitstream_debug'])) {
            $debug_type = $_GET['bitstream_debug'];
            
            if ($debug_type === 'flush_rewrite') {
                if (current_user_can('manage_options')) {
                    delete_option('bitstream_sw_rewrite_flushed_v2');
                    flush_rewrite_rules(false);
                    update_option('bitstream_sw_rewrite_flushed_v2', true);
                    wp_die('BitStream rewrite rules flushed! Service Worker rewrite rules have been refreshed.');
                } else {
                    wp_die('Access denied');
                }
            }
            
            if ($debug_type === 'test_share') {
                // Simple test page to verify POST handling
                $version_timestamp = date('Y-m-d H:i:s');
                echo '<!DOCTYPE html><html><body style="font-family: sans-serif; padding: 20px;">';
                echo '<h1>BitStream Share Target Test</h1>';
                echo '<div style="background: #e8f5e9; padding: 10px; margin: 10px 0; border-left: 4px solid #4caf50;">';
                echo '<strong>✅ Latest Version Loaded</strong><br>';
                echo '<small>Server Time: ' . $version_timestamp . '</small>';
                echo '</div>';
                echo '<p>This will test if the share target handler is working.</p>';
                
                echo '<div id="upload-progress" style="display: none; background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; border-radius: 4px;">';
                echo '<strong>📤 Uploading...</strong><br>';
                echo '<div style="margin-top: 10px; background: #e0e0e0; height: 30px; border-radius: 15px; overflow: hidden;">';
                echo '<div id="progress-bar" style="background: linear-gradient(90deg, #2c6e49, #4caf50); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;"></div>';
                echo '</div>';
                echo '<p id="progress-text" style="margin-top: 10px; color: #666;">Preparing upload...</p>';
                echo '</div>';
                
                echo '<form id="share-form" method="POST" action="/bitstream/new-bit/?share=1" enctype="multipart/form-data">';
                echo '<p><label>Title:<br><input type="text" name="title" placeholder="Title" style="width: 300px;"></label></p>';
                echo '<p><label>Text:<br><textarea name="text" placeholder="Text content" style="width: 300px; height: 100px;"></textarea></label></p>';
                echo '<p><label>Media Files:<br><input type="file" name="media[]" accept="image/*,video/*" multiple id="media-input"></label></p>';
                echo '<p><button type="submit" style="padding: 10px 20px; background: #2c6e49; color: white; border: none; cursor: pointer; border-radius: 5px;">Test Share</button></p>';
                echo '</form>';
                
                echo '<script>';
                echo 'document.getElementById("share-form").addEventListener("submit", function(e) {';
                echo '  e.preventDefault();';
                echo '  const form = this;';
                echo '  const formData = new FormData(form);';
                echo '  const progressDiv = document.getElementById("upload-progress");';
                echo '  const progressBar = document.getElementById("progress-bar");';
                echo '  const progressText = document.getElementById("progress-text");';
                echo '  progressDiv.style.display = "block";';
                echo '  form.style.display = "none";';
                echo '  const xhr = new XMLHttpRequest();';
                echo '  xhr.upload.addEventListener("progress", function(e) {';
                echo '    if (e.lengthComputable) {';
                echo '      const percentComplete = Math.round((e.loaded / e.total) * 100);';
                echo '      progressBar.style.width = percentComplete + "%";';
                echo '      progressBar.textContent = percentComplete + "%";';
                echo '      const loaded = (e.loaded / (1024 * 1024)).toFixed(2);';
                echo '      const total = (e.total / (1024 * 1024)).toFixed(2);';
                echo '      progressText.textContent = "Uploading: " + loaded + " MB / " + total + " MB";';
                echo '    }';
                echo '  });';
                echo '  xhr.addEventListener("load", function() {';
                echo '    if (xhr.status === 302 || xhr.status === 200) {';
                echo '      progressText.textContent = "Upload complete! Redirecting...";';
                echo '      const redirectUrl = xhr.getResponseHeader("Location") || xhr.responseURL;';
                echo '      if (redirectUrl) {';
                echo '        window.location.href = redirectUrl;';
                echo '      } else {';
                echo '        window.location.href = "/bitstream/?poster_tab=bit";';
                echo '      }';
                echo '    }';
                echo '  });';
                echo '  xhr.addEventListener("error", function() {';
                echo '    progressText.textContent = "Upload failed. Please try again.";';
                echo '    progressBar.style.background = "#dc3545";';
                echo '  });';
                echo '  xhr.open("POST", form.action);';
                echo '  xhr.send(formData);';
                echo '});';
                echo '</script>';
                
                echo '<hr>';
                echo '<p><small>Navigate to this page: <a href="?bitstream_debug=test_share">' . admin_url() . '?bitstream_debug=test_share</a></small></p>';
                echo '</body></html>';
                exit;
            }
        }
    }
    
    /**
     * Show debug page for share target testing
     */
    private function show_debug_page($shared_url, $shared_title, $shared_text, $all_params) {
        // Extract URLs from all content
        $all_content = $shared_url . ' ' . $shared_text . ' ' . $shared_title;
        preg_match_all('/https?:\/\/[^\s]+/', $all_content, $matches);
        $found_urls = $matches[0];
        
        $has_any_params = !empty($all_params);
        $has_share_data = !empty($shared_url) || !empty($shared_title) || !empty($shared_text);
        
        // Log to file
        $log_entry = date('Y-m-d H:i:s') . " - Share Target Debug:\n";
        $log_entry .= "Has URL: " . (!empty($shared_url) ? 'yes' : 'no') . "\n";
        $log_entry .= "Has Title: " . (!empty($shared_title) ? 'yes' : 'no') . "\n";
        $log_entry .= "Has Text: " . (!empty($shared_text) ? 'yes' : 'no') . "\n";
        $log_entry .= "Found URL Count: " . count($found_urls) . "\n";
        $log_entry .= "Param Keys: " . implode(', ', array_keys($all_params)) . "\n";
        $log_entry .= "---\n\n";
        
        $log_file = dirname(__FILE__) . '/../debug-share.log';
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Output HTML
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>BitStream Share Debug</title>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
                .debug-section { background: white; padding: 15px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
                .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                .big-text { font-size: 18px; font-weight: bold; }
                .url-found { background: #28a745; color: white; padding: 10px; border-radius: 4px; margin: 5px 0; }
                pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="debug-section">
                <h1>🔍 BitStream Share Target Debug</h1>
                <p><strong>Timestamp:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            
            <div class="debug-section <?php echo $has_any_params ? 'success' : 'error'; ?>">
                <h2>📊 Share Target Status</h2>
                <?php if ($has_any_params): ?>
                    <div class="big-text">✅ SHARE TARGET TRIGGERED!</div>
                    <p>Parameters were received from the share action.</p>
                <?php else: ?>
                    <div class="big-text">❌ NO PARAMETERS RECEIVED</div>
                    <p>Share target was not triggered or no data was sent.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($has_share_data): ?>
            <div class="debug-section success">
                <h2>📋 Shared Content</h2>
                <div class="big-text">✅ CONTENT DETECTED!</div>
                <ul>
                    <?php if ($shared_url): ?>
                        <li><strong>URL:</strong> <code><?php echo htmlspecialchars($shared_url); ?></code></li>
                    <?php endif; ?>
                    <?php if ($shared_title): ?>
                        <li><strong>Title:</strong> <?php echo htmlspecialchars($shared_title); ?></li>
                    <?php endif; ?>
                    <?php if ($shared_text): ?>
                        <li><strong>Text:</strong> <?php echo htmlspecialchars($shared_text); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="debug-section">
                <h2>🔗 URL Extraction Test</h2>
                <?php if (!empty($found_urls)): ?>
                    <div class="big-text">✅ URLS FOUND: <?php echo count($found_urls); ?></div>
                    <?php foreach ($found_urls as $url): ?>
                        <div class="url-found">
                            <strong>Extracted URL:</strong><br>
                            <?php echo htmlspecialchars($url); ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="big-text">❌ NO URLS DETECTED</div>
                    <p>No URLs found in the shared content.</p>
                <?php endif; ?>
            </div>
            
            <div class="debug-section">
                <h2>� Detailed Parameter Analysis</h2>
                <h3>All GET Parameters (Raw):</h3>
                <pre><?php print_r($all_params); ?></pre>
                
                <h3>Parameter Analysis:</h3>
                <ul>
                    <li><strong>Total parameters:</strong> <?php echo count($all_params); ?></li>
                    <li><strong>Parameter names:</strong> <?php echo implode(', ', array_keys($all_params)); ?></li>
                    <?php foreach ($all_params as $key => $value): ?>
                        <li><strong><?php echo htmlspecialchars($key); ?>:</strong> 
                            <?php if (is_string($value)): ?>
                                <code><?php echo htmlspecialchars(substr($value, 0, 100)) . (strlen($value) > 100 ? '...' : ''); ?></code>
                                <small>(<?php echo strlen($value); ?> chars)</small>
                            <?php else: ?>
                                <code><?php echo htmlspecialchars(print_r($value, true)); ?></code>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <h3>URL Detection in Each Parameter:</h3>
                <?php foreach ($all_params as $key => $value): ?>
                    <?php if (is_string($value)): ?>
                        <?php 
                        preg_match_all('/https?:\/\/[^\s]+/', $value, $param_matches);
                        ?>
                        <p><strong><?php echo htmlspecialchars($key); ?>:</strong> 
                            <?php if (!empty($param_matches[0])): ?>
                                <span style="color: green;">✅ Contains URLs: <?php echo implode(', ', array_map('htmlspecialchars', $param_matches[0])); ?></span>
                            <?php else: ?>
                                <span style="color: red;">❌ No URLs</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <div class="debug-section">
                <h2>�📱 Raw Data</h2>
                <pre><?php print_r($all_params); ?></pre>
            </div>
            
            <div class="debug-section">
                <h2>📝 Next Steps</h2>
                <?php if (!$has_any_params): ?>
                    <div class="error">
                        <p><strong>Issue:</strong> Share target not working</p>
                        <p><strong>Solution:</strong> Reinstall the PWA after clearing browser cache</p>
                    </div>
                <?php elseif (empty($found_urls)): ?>
                    <div class="warning">
                        <p><strong>Issue:</strong> No URLs found in shared content</p>
                        <p><strong>Check:</strong> The app might be sending URLs in a different format</p>
                    </div>
                <?php else: ?>
                    <div class="success">
                        <p><strong>Status:</strong> Everything looks good!</p>
                        <p><strong>Next:</strong> Ready to implement the ReBit functionality</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="debug-section">
                <a href="/bitstream/" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">← Back to BitStream</a>
            </div>
        </body>
        </html>
        <?php
    }
}
