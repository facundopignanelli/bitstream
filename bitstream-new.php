<?php
/**
 * Plugin Name: BitStream
 * Description: A microblogging plugin for sharing Bits and ReBits.
 * Version: 2.1.1
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BITSTREAM_VERSION', '2.1.1');
define('BITSTREAM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BITSTREAM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main BitStream Plugin Class
 */
class BitStream_Plugin {
    
    private $components = [];
    
    public function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load includes
        $this->load_includes();
        
        // Initialize components
        $this->init_components();
    }
    
    /**
     * Load required files
     */
    private function load_includes() {
        // Core functionality classes
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-post-type.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-shortcodes.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-og-fetcher.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-error-logger.php';
        
        // New modular classes
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-admin-interface.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-block-editor.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-pwa-manager.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-rss-feeds.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-content-display.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-rebit-mappings.php';
    }
    
    /**
     * Initialize all plugin components
     */
    private function init_components() {
        // Initialize core components
        $this->components['post_type'] = new BitStream_Post_Type();
        $this->components['ajax_handlers'] = new BitStream_Ajax_Handlers();
        $this->components['shortcodes'] = new BitStream_Shortcodes();
        $this->components['og_fetcher'] = new BitStream_OG_Fetcher();
        $this->components['error_logger'] = new BitStream_Error_Logger();
        
        // Initialize new modular components
        $this->components['admin_interface'] = new BitStream_Admin_Interface();
        $this->components['block_editor'] = new BitStream_Block_Editor();
        $this->components['pwa_manager'] = new BitStream_PWA_Manager();
        $this->components['rss_feeds'] = new BitStream_RSS_Feeds();
        $this->components['content_display'] = new BitStream_Content_Display();
        
        // ReBit mappings is a utility class, no need to instantiate
    }
    
    /**
     * Get a component instance
     */
    public function get_component($component_name) {
        return isset($this->components[$component_name]) ? $this->components[$component_name] : null;
    }
}

// Initialize the plugin
new BitStream_Plugin();

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'bitstream_plugin_activate');
register_deactivation_hook(__FILE__, 'bitstream_plugin_deactivate');

/**
 * Plugin activation callback
 */
function bitstream_plugin_activate() {
    // Ensure post type is registered before flushing
    $plugin = new BitStream_Plugin();
    $plugin->init();
    
    // Flush rewrite rules to ensure permalinks work
    flush_rewrite_rules();
}

/**
 * Plugin deactivation callback  
 */
function bitstream_plugin_deactivate() {
    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
