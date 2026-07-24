<?php
/**
 * Plugin Name: BitStream
 * Description: A lightweight microblogging platform for WordPress with PWA support, masonry layout, and social sharing.
 * Version: 3.4.0
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BITSTREAM_VERSION', '3.4.0');
define('BITSTREAM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BITSTREAM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main BitStream Plugin Class
 */
class BitStream_Plugin
{

    private $components = [];
    private static $instance = null;

    public static function get_instance()
    {
        return self::$instance;
    }

    public function __construct()
    {
        self::$instance = $this;
        add_action('wp_enqueue_scripts', [$this, 'register_global_assets'], 5);
        add_action('admin_enqueue_scripts', [$this, 'register_global_assets'], 5);
        if (did_action('plugins_loaded')) {
            $this->init();
        } else {
            add_action('plugins_loaded', [$this, 'init']);
        }
    }

    /**
     * Register global CDN assets
     */
    public function register_global_assets()
    {
        wp_register_script('twemoji', 'https://cdn.jsdelivr.net/npm/@twemoji/api@latest/dist/twemoji.min.js', [], null, true);

        // Register separate modular scripts
        wp_register_script('bitstream-lightbox', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-lightbox.js', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-lightbox.js'), true);
        wp_register_script('bitstream-cropper', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-cropper.js', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-cropper.js'), true);
        wp_register_script('bitstream-uploader', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-uploader.js', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-uploader.js'), true);
        wp_register_script('bitstream-composer', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-composer.js', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-composer.js'), true);
        wp_register_script('bitstream-timeline', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-timeline.js', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-timeline.js'), true);

        // Register main bootstrap script with all modules as dependencies
        wp_register_script(
            'bitstream-js',
            BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js',
            ['jquery', 'twemoji', 'bitstream-lightbox', 'bitstream-cropper', 'bitstream-uploader', 'bitstream-composer', 'bitstream-timeline'],
            BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream.js'),
            true
        );

        if (class_exists('BitStream_Ajax_Handlers')) {
            wp_localize_script('bitstream-js', 'bitstream_ajax', BitStream_Ajax_Handlers::get_localized_data());
        }
    }

    /**
     * Initialize the plugin
     */
    public function init()
    {
        // Load includes
        $this->load_includes();

        // Initialize components
        $this->init_components();

        // Strip image metadata on upload
        add_filter('wp_generate_attachment_metadata', [$this, 'strip_image_metadata_on_upload'], 10, 2);
    }

    /**
     * Load required files
     */
    private function load_includes()
    {
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
    private function init_components()
    {
        // Audio support removed: plugin will not add audio MIME types or bypass audio file checks.

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

        // Invalidate hashtag count cache when bits are created/updated/deleted
        add_action('save_post_bit', ['BitStream_Content_Display', 'flush_hashtag_cache']);
        add_action('trashed_post', ['BitStream_Content_Display', 'flush_hashtag_cache']);
        add_action('deleted_post', ['BitStream_Content_Display', 'flush_hashtag_cache']);

    // ReBit mappings is a utility class, no need to instantiate
    }

    /**
     * Get a component instance
     */
    public function get_component($component_name)
    {
        return isset($this->components[$component_name]) ? $this->components[$component_name] : null;
    }

    /**
     * Strip metadata from uploaded images and their generated sub-sizes.
     *
     * @param array $metadata Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public function strip_image_metadata_on_upload($metadata, $attachment_id)
    {
        $mime_type = get_post_mime_type($attachment_id);
        if (empty($mime_type) || strpos($mime_type, 'image/') !== 0) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if ($file && file_exists($file)) {
            $this->strip_metadata_from_file($file);
        }

        // Process all sub-sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dirname = dirname($file);
            foreach ($metadata['sizes'] as $size => $size_info) {
                if (!empty($size_info['file'])) {
                    $subsize_file = path_join($dirname, $size_info['file']);
                    if (file_exists($subsize_file)) {
                        $this->strip_metadata_from_file($subsize_file);
                    }
                }
            }
        }

        return $metadata;
    }

    /**
     * Strip metadata from a single image file.
     *
     * @param string $file_path Absolute path to the file.
     * @return bool True if successful, false otherwise.
     */
    public function strip_metadata_from_file($file_path)
    {
        if (empty($file_path) || !file_exists($file_path)) {
            return false;
        }

        // Try Imagick first if available
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($file_path);
                $imagick->stripImage();
                $result = $imagick->writeImage($file_path);
                $imagick->clear();
                $imagick->destroy();
                if ($result) {
                    return true;
                }
            } catch (Exception $e) {
                // If Imagick fails, we fall back to GD
                if (class_exists('BitStream_Error_Logger')) {
                    BitStream_Error_Logger::log('Imagick metadata stripping failed: ' . $e->getMessage());
                }
            }
        }

        // GD Fallback
        if (function_exists('gd_info')) {
            $image = null;
            $mime_info = wp_check_filetype($file_path);
            $mime_type = $mime_info['type'];

            if ($mime_type === 'image/jpeg' || $mime_type === 'image/jpg') {
                if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
                    $image = @imagecreatefromjpeg($file_path);
                    if ($image) {
                        // GD does not support EXIF, so writing it back strips it
                        @imagejpeg($image, $file_path, 90);
                    }
                }
            } elseif ($mime_type === 'image/png') {
                if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
                    $image = @imagecreatefrompng($file_path);
                    if ($image) {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        @imagepng($image, $file_path);
                    }
                }
            } elseif ($mime_type === 'image/webp') {
                if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
                    $image = @imagecreatefromwebp($file_path);
                    if ($image) {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        @imagewebp($image, $file_path);
                    }
                }
            }

            if ($image) {
                @imagedestroy($image);
                return true;
            }
        }

        return false;
    }
}

/**
 * Render ReBit media/preview section for a card
 *
 * @param int $post_id
 * @return string
 */
function bitstream_render_rebit_section($post_id)
{
    return BitStream_Content_Display::render_rebit_section($post_id);
}

/**
 * Render a nested quoted Bit card without action buttons/comments
 *
 * @param int $post_id
 * @param int $depth
 * @return string
 */
function bitstream_render_nested_quoted_card($post_id, $depth = 0)
{
    return BitStream_Content_Display::render_nested_quoted_card($post_id, $depth);
}

/**
 * Custom walker callback for modern, semantic comment layout rendering on frontend posts.
 */
if (!function_exists('bitstream_comment_callback')) {
    function bitstream_comment_callback($comment, $args, $depth)
    {
        BitStream_Content_Display::comment_callback($comment, $args, $depth);
    }
}

/**
 * Render a bit card
 * 
 * @param int $post_id The post ID to render
 * @param bool $skip_content_filter Whether to skip the content filter to avoid infinite loops
 * @param array $options
 * @return string The rendered HTML
 */
if (!function_exists('bitstream_render_card')) {
    function bitstream_render_card($post_id, $skip_content_filter = false, $options = [])
    {
        return BitStream_Content_Display::render_card($post_id, $skip_content_filter, $options);
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
function bitstream_plugin_activate()
{
    // Ensure post type is registered before flushing
    $plugin = new BitStream_Plugin();
    $plugin->init();

    // Import default ReBit mappings if none exist (via centralized class)
    BitStream_ReBit_Mappings::import_default_mappings();

    // Ensure weekly BitStream media cleanup is scheduled
    bitstream_schedule_weekly_media_cleanup();

    // Flush rewrite rules to ensure permalinks work (including PWA shortcuts)
    flush_rewrite_rules();
}

/**
 * Schedule weekly media cleanup cron event.
 */
function bitstream_schedule_weekly_media_cleanup()
{
    if (!wp_next_scheduled('bitstream_weekly_media_cleanup_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'bitstream_weekly', 'bitstream_weekly_media_cleanup_event');
    }
}

/**
 * Plugin deactivation callback  
 */
function bitstream_plugin_deactivate()
{
    wp_clear_scheduled_hook('bitstream_weekly_media_cleanup_event');

    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
