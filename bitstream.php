<?php
/**
 * Plugin Name: BitStream
 * Description: A lightweight microblogging platform for WordPress with PWA support, masonry layout, and social sharing.
 * Version: 3.0.0
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
define('BITSTREAM_VERSION', '3.0.0');
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
        // Allow audio uploads
        add_filter('upload_mimes', function($mimes) {
            $mimes['mp3'] = 'audio/mpeg';
            $mimes['m4a'] = 'audio/mp4';
            $mimes['ogg'] = 'audio/ogg';
            $mimes['wav'] = 'audio/wav';
            $mimes['flac'] = 'audio/flac';
            return $mimes;
        }, 1, 1);
        
        // Bypass file type checks for audio files
        add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (empty($data['type']) && in_array($ext, ['mp3', 'm4a', 'ogg', 'wav', 'flac'], true)) {
                $mime_map = [
                    'mp3' => 'audio/mpeg',
                    'm4a' => 'audio/mp4',
                    'ogg' => 'audio/ogg',
                    'wav' => 'audio/wav',
                    'flac' => 'audio/flac',
                ];
                $data['ext'] = $ext;
                $data['type'] = $mime_map[$ext];
            }
            return $data;
        }, 10, 4);
        
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

/**
 * Render ReBit media/preview section for a card
 *
 * @param int $post_id
 * @return string
 */
function bitstream_render_rebit_section($post_id) {
    $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
    if (empty($rebit_url)) {
        return '';
    }

    $parsed = parse_url($rebit_url);
    $host = $parsed['host'] ?? '';

    ob_start();

    $map = BitStream_ReBit_Mappings::get_mapping_for_domain($host);
    if ($map) {
        echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
            . '<i class="' . esc_attr($map['icon']) . '" aria-hidden="true" style="margin-right:0.5rem;"></i>'
            . esc_html($map['label'])
            . '</div>';
    } else {
        echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
            . '<i class="fas fa-link" aria-hidden="true" style="margin-right:0.5rem;"></i> shared a link</div>';
    }

    $is_yt = stripos($host, 'youtube.com') !== false || stripos($host, 'youtu.be') !== false;
    if ($is_yt) {
        $video_id = '';
        if (stripos($host, 'youtu.be') !== false) {
            $video_id = ltrim($parsed['path'] ?? '', '/');
        } elseif (isset($parsed['query'])) {
            parse_str($parsed['query'], $args);
            $video_id = $args['v'] ?? '';
        }

        if ($video_id) {
            echo '<div class="bit-rebit-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1rem 0;">'
                . '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" '
                . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                . 'allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe></div>';
        } else {
            echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener">' . esc_html($rebit_url) . '</a>';
        }
    } else {
        echo '<div class="bit-rebit-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;margin-bottom:1rem;">';
        $og_img = get_post_meta($post_id, '_bitstream_og_image', true);
        if ($og_img) {
            echo '<img src="' . esc_url($og_img) . '" style="width:100%;display:block;" alt="">';
        }

        echo '<div style="padding:0.75rem;">';
        $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
        $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
        if ($og_title) {
            echo '<h4 style="margin:0 0 0.5rem;font-size:1.1rem;">'
                . '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener">' . esc_html($og_title) . '</a></h4>';
        }
        if ($og_desc) {
            echo '<p style="margin:0;font-size:0.95rem;color:#555;">' . esc_html($og_desc) . '</p>';
        }
        if (!$og_desc) {
            echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener">' . esc_html($rebit_url) . '</a>';
        }
        echo '</div></div>';
    }

    return ob_get_clean();
}

/**
 * Render a nested quoted Bit card without action buttons/comments
 *
 * @param int $post_id
 * @return string
 */
function bitstream_render_nested_quoted_card($post_id) {
    $quoted_post = get_post($post_id);
    if (!($quoted_post instanceof WP_Post) || $quoted_post->post_type !== 'bit' || $quoted_post->post_status !== 'publish') {
        ob_start();
        ?>
        <article id="bit-quoted-missing-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested bit-card-quoted-unavailable" style="margin:0;padding:1rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #ddd;border-radius:15px;background:#fff;">
            <div class="bit-card-content" style="font-size:0.95rem;line-height:1.5;margin:0;">
                <p style="margin:0;color:var(--wp--preset--color--secondary,#666);">Original Bit unavailable.</p>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    $content = wpautop(get_post_field('post_content', $post_id));
    $timestamp = human_time_diff(get_post_modified_time('U', false, $post_id), current_time('timestamp')) . ' ago';
    $rebit_markup = bitstream_render_rebit_section($post_id);

    ob_start();
    ?>
    <article id="bit-quoted-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested" style="margin:0;padding:1.5rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #ddd;border-radius:15px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="display:flex;align-items:center;margin-bottom:1rem;">
            <div class="bit-meta" style="font-size:0.875rem;color:var(--wp--preset--color--secondary,#666);">
                <span class="bit-timestamp"><?php echo esc_html($timestamp); ?></span>
            </div>
        </header>

        <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
            <?php echo $content; ?>
        </div>

        <?php echo $rebit_markup; ?>
    </article>
    <?php

    return ob_get_clean();
}

/**
 * Render a bit card
 * 
 * @param int $post_id The post ID to render
 * @param bool $skip_content_filter Whether to skip the content filter to avoid infinite loops
 * @return string The rendered HTML
 */
function bitstream_render_card($post_id, $skip_content_filter = false) {
    // Avoid infinite loop by skipping content filter when rendering in single bit context
    if ($skip_content_filter) {
        $content = get_post_field('post_content', $post_id);
        $content = wpautop($content); // Basic paragraph formatting
    } else {
        $GLOBALS['bitstream_is_rendering_card'] = true;
        $content = apply_filters('the_content', get_post_field('post_content',$post_id));
        unset($GLOBALS['bitstream_is_rendering_card']);
    }
    
    $timestamp = human_time_diff(get_post_modified_time('U',false,$post_id),current_time('timestamp')).' ago';
    $avatar    = get_avatar(get_post_field('post_author',$post_id),48,'','',['class'=>'bit-avatar-img']);
    $likes     = (int)get_post_meta($post_id,'_bitstream_likes',true);
    $comments  = get_comments_number($post_id);
    $quoted_id = (int) get_post_meta($post_id, '_bitstream_quoted_bit', true);
    $rebit_markup = bitstream_render_rebit_section($post_id);
    $quoted_markup = '';
    if ($quoted_id > 0) {
        $quoted_markup = bitstream_render_nested_quoted_card($quoted_id);
    }

    ob_start(); ?>
    <article id="bit-<?php echo esc_attr($post_id); ?>" class="bit-card" style="margin:0;padding:1.5rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #eee;border-radius:15px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="display:flex;align-items:center;margin-bottom:1rem;">
            <div class="bit-avatar" style="width:48px;height:48px;margin-right:0.75rem;border-radius:15px;overflow:hidden;">
                <?php echo $avatar; ?>
            </div>
            <div class="bit-meta" style="font-size:0.875rem;color:var(--wp--preset--color--secondary,#666);">
                <span class="bit-timestamp"><?php echo esc_html($timestamp); ?></span>
            </div>
        </header>

        <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
            <?php echo $content; ?>
        </div>

        <?php echo $rebit_markup; ?>

        <?php if (!empty($quoted_markup)): ?>
            <div class="bitstream-quoted-preview">
                <?php echo $quoted_markup; ?>
            </div>
        <?php endif; ?>

        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee;">

        <footer class="bit-card-footer" style="display:flex;gap:1rem;font-size:0.875rem;align-items:center;">
            <button class="bit-comment-toggle bit-action" data-target="comments-<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;">
                <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
            </button>
            <button class="bit-like bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;">
                <i class="fas fa-heart"></i> <span class="bit-like-count"><?php echo esc_html($likes); ?></span>
            </button>
            <button class="bit-permalink bit-action" data-url="<?php echo esc_url(get_permalink($post_id)); ?>" style="background:none;border:none;cursor:pointer;" title="Copy link: <?php echo esc_attr(get_permalink($post_id)); ?>">
                <i class="fa-solid fa-up-right-from-square"></i>
            </button>
            <?php if (current_user_can('edit_posts')): ?>
            <button class="bit-quote bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="Quote this bit">
                <i class="fa-solid fa-retweet"></i>
            </button>
            <?php endif; ?>
            <?php if (is_user_logged_in() && current_user_can('delete_post', $post_id)): ?>
            <button class="bit-delete bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="Delete this bit">
                <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif; ?>
        </footer>

        <div id="comments-<?php echo $post_id; ?>" class="bit-comments">
            <div class="bit-comments-list">
                <?php wp_list_comments(['style'=>'div','short_ping'=>true,'avatar_size'=>32],get_comments(['post_id'=>$post_id,'status'=>'approve'])); ?>
            </div>
            <div class="bit-comment-form">
                <?php comment_form([
                    'comment_notes_after'   => '',
                    'title_reply'           => 'Leave a Comment',
                    'logged_in_as'          => '',
                    'comment_field'         => '<p><textarea name="comment" required></textarea></p>',
                    'form_action'           => esc_url( $_SERVER['REQUEST_URI'] ),
                    'comment_post_redirect' => esc_url( $_SERVER['REQUEST_URI'] ),
                ], $post_id ); ?>
            </div>
        </div>
    </article>
    <?php
    
    return ob_get_clean();
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
function bitstream_schedule_weekly_media_cleanup() {
    if (!wp_next_scheduled('bitstream_weekly_media_cleanup_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'bitstream_weekly', 'bitstream_weekly_media_cleanup_event');
    }
}

/**
 * Plugin deactivation callback  
 */
function bitstream_plugin_deactivate() {
    wp_clear_scheduled_hook('bitstream_weekly_media_cleanup_event');

    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
