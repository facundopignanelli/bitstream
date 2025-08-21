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
            echo '<meta name="theme-color" content="#2c6e49">';
            echo '<meta name="mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
            echo '<meta name="apple-mobile-web-app-title" content="BitStream">';
            
            echo '<script>
            if("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                        scope: "/",
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
        // Only show to users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        $new_bit_url = admin_url('post-new.php?post_type=bit');
        $rebit_url = admin_url('post-new.php?post_type=bit&rebit=1');
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
            }
            .bitstream-dropdown {
                min-width: 140px !important;
                bottom: 65px !important;
            }
            .bitstream-dropdown a {
                padding: 10px 12px !important;
                font-size: 14px !important;
            }
        }
        </style>
        <div id="bitstream-floating-menu" style="position: fixed; bottom: 30px; right: 30px; z-index: 9999;">
            <div class="bitstream-menu">
                <button class="bitstream-toggle" 
                        style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #2c6e49; color: white; border-radius: 50%; border: none; box-shadow: 0 4px 12px rgba(44,110,73,0.25); transition: all 0.3s ease; font-size: 24px; cursor: pointer; -webkit-tap-highlight-color: transparent; user-select: none;"
                        title="Quick Actions"
                        type="button">
                    <i class="fa-solid fa-plus" style="margin: 0; pointer-events: none;"></i>
                </button>
                <div class="bitstream-dropdown" style="position: absolute; bottom: 70px; right: 0; background: white; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 160px; opacity: 0; visibility: hidden; transform: translateY(10px); transition: all 0.3s ease; pointer-events: none;">
                    <a href="<?php echo esc_url($new_bit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-comment" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        Add New Bit
                    </a>
                    <a href="<?php echo esc_url($rebit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; -webkit-tap-highlight-color: transparent;"
                       class="bitstream-dropdown-link">
                        <i class="fa-solid fa-link" style="margin-right: 8px; color: #2c6e49; pointer-events: none;"></i>
                        Add New ReBit
                    </a>
                </div>
            </div>
        </div>
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
     * Handle PWA shortcut requests
     */
    public function handle_shortcut_requests() {
        $action = get_query_var('bitstream_action');
        
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
                    wp_redirect(admin_url('post-new.php?post_type=bit&rebit=1'));
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
        if (isset($_GET['bitstream_debug']) && $_GET['bitstream_debug'] === 'flush_rewrite') {
            if (current_user_can('manage_options')) {
                delete_option('bitstream_sw_rewrite_flushed_v2');
                flush_rewrite_rules(false);
                update_option('bitstream_sw_rewrite_flushed_v2', true);
                wp_die('BitStream rewrite rules flushed! Service Worker rewrite rules have been refreshed.');
            } else {
                wp_die('Access denied');
            }
        }
    }
}
