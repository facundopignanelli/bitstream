<?php
/**
 * Plugin Name: BitStream
 * Description: A lightweight microblogging platform for WordPress with PWA support, masonry layout, and social sharing.
 * Version: 2.0.1
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
define('BITSTREAM_VERSION', '2.0.1');
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
        $content = apply_filters('the_content', get_post_field('post_content',$post_id));
    }
    
    $timestamp = human_time_diff(get_post_modified_time('U',false,$post_id),current_time('timestamp')).' ago';
    $avatar    = get_avatar(get_post_field('post_author',$post_id),48,'','',['class'=>'bit-avatar-img']);
    $likes     = (int)get_post_meta($post_id,'_bitstream_likes',true);
    $comments  = get_comments_number($post_id);
    $rebit_url = get_post_meta($post_id,'bitstream_rebit_url',true);

    ob_start(); ?>
    <article id="bit-<?php echo esc_attr($post_id); ?>" class="bit-card" style="margin:2rem auto;padding:1.5rem;max-width:720px;border:1px solid #eee;border-radius:16px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="display:flex;align-items:center;margin-bottom:1rem;">
            <div class="bit-avatar" style="width:48px;height:48px;margin-right:0.75rem;border-radius:9999px;overflow:hidden;">
                <?php echo $avatar; ?>
            </div>
            <div class="bit-meta" style="font-size:0.875rem;color:var(--wp--preset--color--secondary,#666);">
                <span class="bit-timestamp"><?php echo esc_html($timestamp); ?></span>
            </div>
        </header>

        <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
            <?php echo $content; ?>
        </div>

        <?php if ($rebit_url):
            $parsed = parse_url($rebit_url);
            $host   = $parsed['host'] ?? '';
            $map = null;
            $mappings = get_option('bitstream_rebit_mappings',[]);
            foreach($mappings as $m) {
                if (stripos($host,$m['domain'])!==false) { $map = $m; break; }
            }
            if ($map) {
                echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
                   . '<i class="'.esc_attr($map['icon']).'" aria-hidden="true" style="margin-right:0.5rem;"></i>'
                   . esc_html($map['label'])
                   . '</div>';
            } else {
                echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">
                <i class="fas fa-link" aria-hidden="true" style="margin-right:0.5rem;"></i> shared a link</div>';
            }

            $is_yt = stripos($host,'youtube.com')!==false||stripos($host,'youtu.be')!==false;
            if ($is_yt) {
                $video_id='';
                if (stripos($host,'youtu.be')!==false) {
                    $video_id = ltrim($parsed['path'],'/');
                } elseif (isset($parsed['query'])){
                    parse_str($parsed['query'],$args);
                    $video_id = $args['v']??'';
                }
                if ($video_id) {
                    echo '<div class="bit-rebit-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1rem 0;">'
                       . '<iframe src="https://www.youtube.com/embed/'.esc_attr($video_id).'" '
                       . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                       . 'allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe></div>';
                } else {
                    echo '<a href="'.esc_url($rebit_url). '" target="_blank" rel="noopener">'.esc_html($rebit_url).'</a>';
                }
            } else {
                echo '<div class="bit-rebit-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;margin-bottom:1rem;">';
                $og_img = get_post_meta($post_id,'_bitstream_og_image',true);
                if ($og_img) echo '<img src="'.esc_url($og_img).'" style="width:100%;display:block;" alt="">';
                echo '<div style="padding:0.75rem;">';
                $og_title = get_post_meta($post_id,'_bitstream_og_title',true);
                $og_desc  = get_post_meta($post_id,'_bitstream_og_desc',true);
                if ($og_title) echo '<h4 style="margin:0 0 0.5rem;font-size:1.1rem;">
                   <a href="'.esc_url($rebit_url).'" target="_blank" rel="noopener">'.esc_html($og_title).'</a></h4>';
                if ($og_desc) echo '<p style="margin:0;font-size:0.95rem;color:#555;">'.esc_html($og_desc).'</p>';
                if (!$og_desc) echo '<a href="'.esc_url($rebit_url).'" target="_blank" rel="noopener">'.esc_html($rebit_url).'</a>';
                echo '</div></div>';
            }
        endif; ?>

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
    
    // Import default ReBit mappings if none exist
    bitstream_import_default_mappings();
    
    // Flush rewrite rules to ensure permalinks work (including PWA shortcuts)
    flush_rewrite_rules();
}

/**
 * Import default ReBit mappings if none exist
 */
function bitstream_import_default_mappings() {
    $existing_mappings = get_option('bitstream_rebit_mappings', []);
    
    // Only import if no mappings exist
    if (empty($existing_mappings)) {
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
        
        update_option('bitstream_rebit_mappings', $default_mappings);
    }
}

/**
 * Plugin deactivation callback  
 */
function bitstream_plugin_deactivate() {
    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
