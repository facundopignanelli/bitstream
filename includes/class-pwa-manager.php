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
     * Add PWA assets for BitStream pages
     */
    public function pwa_assets() {
        global $post;
        
        // Load on archive pages or pages with [bitstream] shortcode
        $is_bit_archive = is_post_type_archive('bit');
        $has_feed_shortcode = is_a($post, 'WP_Post') && 
                             (has_shortcode($post->post_content, 'bitstream') || 
                              has_shortcode($post->post_content, 'bitstream_latest'));
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
                              has_shortcode($post->post_content, 'bitstream_latest'));
        $is_bitstream_page = isset($_SERVER['REQUEST_URI']) && 
                            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false;
        
        // Only show the floating button on BitStream-related pages
        if (!($is_bit_archive || $has_feed_shortcode || $is_bitstream_page)) {
            return;
        }

        $new_bit_url = admin_url('post-new.php?post_type=bit');
        $rebit_url = admin_url('post-new.php?post_type=bit&rebit=1');
        $rss_feeds_url = admin_url('edit.php?post_type=bit&page=bitstream-rss-feeds');
        $rebit_mappings_url = admin_url('edit.php?post_type=bit&page=bitstream-rebit-mappings');
        ?>
        <style>
        /* Mobile-specific fixes for floating BitStream menu */
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
                        style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #2c6e49; color: white; border-radius: 50%; border: none; box-shadow: 0 4px 12px rgba(44,110,73,0.25); transition: all 0.3s ease; font-size: 24px; cursor: pointer; -webkit-tap-highlight-color: transparent; user-select: none; touch-action: manipulation;"
                        title="Quick Actions"
                        type="button"
                        aria-label="Open BitStream quick actions menu">
                    <i class="fa-solid fa-plus" style="margin: 0; pointer-events: none;"></i>
                </button>
                <div class="bitstream-dropdown" style="position: absolute; bottom: 70px; right: 0; background: white; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 180px; opacity: 0; visibility: hidden; transform: translateY(10px); transition: all 0.3s ease; pointer-events: none;">
                    <a href="<?php echo esc_url($new_bit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-comment" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        Add New Bit
                    </a>
                    <a href="<?php echo esc_url($rebit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-link" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        Add New ReBit
                    </a>
                    <a href="<?php echo esc_url($rss_feeds_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-rss" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        RSS Feeds
                    </a>
                    <a href="<?php echo esc_url($rebit_mappings_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-sitemap" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        ReBit Mappings
                    </a>
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
     * Add rewrite rules for Service Worker files
     */
    public function add_service_worker_rewrite() {
        add_rewrite_rule('^sw-feed\.js$', 'index.php?bitstream_sw=feed', 'top');
        add_rewrite_rule('^sw\.js$', 'index.php?bitstream_sw=main', 'top');
        
        // Flush rewrite rules if they haven't been flushed for this version
        if (!get_option('bitstream_sw_rewrite_flushed_v2')) {
            flush_rewrite_rules(false);
            update_option('bitstream_sw_rewrite_flushed_v2', true);
            delete_option('bitstream_sw_rewrite_flushed'); // Remove old flag
            error_log('BitStream: Service Worker rewrite rules flushed (v2)');
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
            error_log('BitStream: Rewrite rules flushed for v2.3.0 (share target support)');
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
        
        error_log('BitStream: serve_service_worker called, sw_type: ' . $sw_type);
        error_log('BitStream: REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
        error_log('BitStream: Query vars: ' . print_r($_GET, true));
        
        if (!$sw_type) {
            error_log('BitStream: No sw_type found, returning');
            return;
        }
        
        error_log('BitStream: Serving Service Worker type: ' . $sw_type);
        
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
        
        // Serve the appropriate Service Worker file
        $file_path = '';
        if ($sw_type === 'feed') {
            $file_path = BITSTREAM_PLUGIN_PATH . 'sw-feed.js';
        } elseif ($sw_type === 'main') {
            $file_path = BITSTREAM_PLUGIN_PATH . 'sw.js';
        }
        
        if ($file_path && file_exists($file_path)) {
            error_log('BitStream: Serving SW file: ' . $file_path);
            readfile($file_path);
            exit;
        } else {
            error_log('BitStream: SW file not found: ' . $file_path);
            status_header(404);
            echo '// Service Worker file not found';
            exit;
        }
    }

    /**
     * Handle media sharing from PWA (photos/videos)
     */
    private function handle_media_share() {
        error_log('BitStream: handle_media_share called');
        error_log('BitStream: REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        error_log('BitStream: REQUEST_URI: ' . $_SERVER['REQUEST_URI']);
        error_log('BitStream: POST data: ' . print_r($_POST, true));
        error_log('BitStream: FILES data: ' . print_r($_FILES, true));
        error_log('BitStream: GET data: ' . print_r($_GET, true));
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            error_log('BitStream: User not logged in, redirecting to login');
            // Store shared content in session and redirect to login
            session_start();
            $_SESSION['bitstream_pending_share'] = [
                'files' => $_FILES,
                'text' => isset($_POST['text']) ? $_POST['text'] : '',
                'title' => isset($_POST['title']) ? $_POST['title'] : '',
                'url' => isset($_POST['url']) ? $_POST['url'] : '',
                'timestamp' => time()
            ];
            
            $login_url = wp_login_url(admin_url('post-new.php?post_type=bit&restore_share=1'));
            error_log('BitStream: Redirecting to login: ' . $login_url);
            wp_redirect($login_url);
            exit;
        }
        
        error_log('BitStream: User is logged in');
        
        // Check permissions
        if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
            error_log('BitStream: User lacks permissions');
            wp_die('You do not have permission to upload media or create posts.');
        }
        
        error_log('BitStream: User has permissions');
        
        // Handle file uploads
        $attachment_ids = [];
        $shared_text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
        $shared_title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $shared_url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        
        error_log('BitStream: Shared text: ' . $shared_text);
        error_log('BitStream: Shared title: ' . $shared_title);
        error_log('BitStream: Shared URL: ' . $shared_url);
        
        // Process uploaded files
        if (!empty($_FILES['media'])) {
            error_log('BitStream: Processing files...');
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
                error_log('BitStream: Found ' . $file_count . ' files');
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
                        error_log('BitStream: File ' . $i . ' has upload error: ' . $files['error'][$i]);
                    }
                }
            } else {
                // Single file
                error_log('BitStream: Found single file');
                if ($files['error'] === UPLOAD_ERR_OK) {
                    $attachment_id = $this->handle_single_file_upload($files, $shared_title);
                    if ($attachment_id) {
                        $attachment_ids[] = $attachment_id;
                    }
                } else {
                    error_log('BitStream: File has upload error: ' . $files['error']);
                }
            }
        } else {
            error_log('BitStream: No files in $_FILES[\'media\']');
        }
        
        error_log('BitStream: Uploaded attachment IDs: ' . print_r($attachment_ids, true));
        
        // If no files were uploaded successfully but we have shared text/url, just redirect
        if (empty($attachment_ids) && (empty($shared_text) && empty($shared_url))) {
            error_log('BitStream: No media uploaded and no shared content, redirecting to plain new bit page');
            wp_redirect(admin_url('post-new.php?post_type=bit'));
            exit;
        }
        
        // Build redirect URL to new bit page with uploaded media
        $redirect_url = admin_url('post-new.php?post_type=bit');
        
        if (!empty($attachment_ids)) {
            $redirect_url = add_query_arg('media_ids', implode(',', $attachment_ids), $redirect_url);
        }
        
        if (!empty($shared_text)) {
            $redirect_url = add_query_arg('shared_text', urlencode($shared_text), $redirect_url);
        }
        
        if (!empty($shared_url)) {
            $redirect_url = add_query_arg('shared_url', urlencode($shared_url), $redirect_url);
            $redirect_url = add_query_arg('rebit', '1', $redirect_url);
        }
        
        error_log('BitStream: Redirecting to: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle a single file upload and return attachment ID
     */
    private function handle_single_file_upload($file, $title = '') {
        error_log('BitStream: Processing file: ' . $file['name']);
        
        // Use wp_handle_upload to process the file
        $upload_overrides = [
            'test_form' => false,
            'test_type' => true
        ];
        
        $uploaded_file = wp_handle_upload($file, $upload_overrides);
        
        if (isset($uploaded_file['error'])) {
            error_log('BitStream: Upload error: ' . $uploaded_file['error']);
            return false;
        }
        
        error_log('BitStream: File uploaded successfully: ' . $uploaded_file['file']);
        
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
            error_log('BitStream: Error creating attachment: ' . $attachment_id->get_error_message());
            return false;
        }
        
        // Generate attachment metadata
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        error_log('BitStream: Attachment created with ID: ' . $attachment_id);
        return $attachment_id;
    }

    /**
     * Handle PWA shortcut requests
     */
    public function handle_shortcut_requests() {
        $action = get_query_var('bitstream_action');
        
        // Check for POST share request (media files)
        if ($action === 'new-bit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            error_log('BitStream: Detected POST share request to new-bit');
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
                    wp_redirect(admin_url('post-new.php?post_type=bit'));
                    break;
                case 'new-rebit':
                    // Handle shared content from Android share sheet
                    
                    // Check for debug mode (restored normal mode)
                    $debug_mode = isset($_GET['debug']) || isset($_GET['test']);
                    
                    // Log all incoming parameters for debugging
                    error_log('BitStream Share Debug: All GET parameters: ' . print_r($_GET, true));
                    
                    // Capture shared content parameters
                    $shared_url = isset($_GET['url']) ? sanitize_url($_GET['url']) : '';
                    $shared_title = isset($_GET['title']) ? sanitize_text_field($_GET['title']) : '';
                    $shared_text = isset($_GET['text']) ? sanitize_text_field($_GET['text']) : '';
                    
                    error_log('BitStream Share Debug: Extracted parameters - URL: ' . $shared_url . ', Title: ' . $shared_title . ', Text: ' . $shared_text);
                    
                    // Smart URL extraction - YouTube puts URL in 'text' parameter
                    $final_url = '';
                    if (!empty($shared_url) && filter_var($shared_url, FILTER_VALIDATE_URL)) {
                        $final_url = $shared_url;
                        error_log('BitStream Share Debug: Using URL from url parameter');
                    } elseif (!empty($shared_text) && filter_var($shared_text, FILTER_VALIDATE_URL)) {
                        $final_url = $shared_text;
                        error_log('BitStream Share Debug: Using URL from text parameter (YouTube format)');
                    } else {
                        // Fallback: extract URL from any parameter content
                        $all_content = $shared_url . ' ' . $shared_text . ' ' . $shared_title;
                        preg_match('/https?:\/\/[^\s]+/', $all_content, $matches);
                        if (!empty($matches[0])) {
                            $final_url = $matches[0];
                            error_log('BitStream Share Debug: Extracted URL from content: ' . $final_url);
                        }
                    }
                    
                    error_log('BitStream Share Debug: Final extracted URL: ' . $final_url);
                    
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
                                'text' => $shared_text,
                                'timestamp' => time()
                            );
                            // Use a transient that expires in 10 minutes
                            $transient_key = 'bitstream_shared_' . wp_generate_password(12, false);
                            set_transient($transient_key, $shared_data, 10 * MINUTE_IN_SECONDS);
                            
                            // Redirect to login with the shared data key
                            $login_url = wp_login_url(admin_url('post-new.php?post_type=bit&rebit=1&shared_key=' . $transient_key));
                            error_log('BitStream Share Debug: User not logged in, redirecting to login with shared data key: ' . $transient_key);
                        } else {
                            // No shared data, just redirect to login
                            $login_url = wp_login_url(admin_url('post-new.php?post_type=bit&rebit=1'));
                            error_log('BitStream Share Debug: User not logged in, redirecting to login');
                        }
                        wp_redirect($login_url);
                        exit;
                    }
                    
                    $redirect_url = admin_url('post-new.php?post_type=bit&rebit=1');
                    
                    // Add shared content to redirect URL if available
                    if ($final_url) {
                        $redirect_url = add_query_arg('shared_url', urlencode($final_url), $redirect_url);
                        error_log('BitStream Share Debug: Added final_url to redirect: ' . $final_url);
                    }
                    if ($shared_title) {
                        $redirect_url = add_query_arg('shared_title', urlencode($shared_title), $redirect_url);
                        error_log('BitStream Share Debug: Added shared_title to redirect');
                    }
                    // Note: We use final_url instead of shared_text since shared_text might be the URL
                    if ($shared_text && $shared_text !== $final_url) {
                        $redirect_url = add_query_arg('shared_text', urlencode($shared_text), $redirect_url);
                        error_log('BitStream Share Debug: Added shared_text to redirect');
                    }
                    
                    error_log('BitStream Share Debug: Final redirect URL: ' . $redirect_url);
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
                echo '<form method="POST" action="/bitstream/new-bit/?share=1" enctype="multipart/form-data">';
                echo '<p><label>Title:<br><input type="text" name="title" placeholder="Title" style="width: 300px;"></label></p>';
                echo '<p><label>Text:<br><textarea name="text" placeholder="Text content" style="width: 300px; height: 100px;"></textarea></label></p>';
                echo '<p><label>Media Files:<br><input type="file" name="media[]" accept="image/*,video/*" multiple></label></p>';
                echo '<p><button type="submit" style="padding: 10px 20px; background: #2c6e49; color: white; border: none; cursor: pointer;">Test Share</button></p>';
                echo '</form>';
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
        $log_entry .= "URL: " . $shared_url . "\n";
        $log_entry .= "Title: " . $shared_title . "\n";
        $log_entry .= "Text: " . $shared_text . "\n";
        $log_entry .= "Found URLs: " . implode(', ', $found_urls) . "\n";
        $log_entry .= "All Params: " . print_r($all_params, true) . "\n";
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
