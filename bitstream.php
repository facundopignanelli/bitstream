<?php
/**
 * Plugin Name: BitStream
 * Description: A microblogging plugin for sharing Bits and ReBits.
 * Version: 2.0.3
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

define('BITSTREAM_VERSION', '2.0.3');
define('BITSTREAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BITSTREAM_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main BitStream Plugin Class
 */
class BitStream_Plugin {
    
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
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_includes() {
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-post-type.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-shortcodes.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-og-fetcher.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-error-logger.php';
    }
    
    /**
     * Initialize hooks and filters
     */
    private function init_hooks() {
        add_action('init', [$this, 'register_meta_and_block']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_notices', [$this, 'permalink_admin_notice']);
        add_action('wp_ajax_bitstream_flush_permalinks', [$this, 'flush_permalinks_ajax']);
        add_filter('default_content', [$this, 'default_rebit_content'], 10, 2);
        add_filter('post_row_actions', [$this, 'add_quote_action'], 10, 2);
        add_action('edit_form_after_title', [$this, 'show_quoted_preview']);
        add_action('save_post_bit', [$this, 'save_quoted_meta']);
        add_filter('the_content', [$this, 'display_quoted_content']);
        add_action('template_redirect', [$this, 'handle_single_bit_display']);
        add_action('wp_head', [$this, 'pwa_assets']);
        add_action('wp_head', [$this, 'pwa_feed_assets']);
    }
    
    /**
     * Register meta fields and blocks
     */
    public function register_meta_and_block() {
        register_post_meta('bit', 'bitstream_rebit_url', [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ]);
        register_block_type('bitstream/rebit-url', ['editor_script' => 'bitstream-block']);
    }
    
    /**
     * Enqueue block editor assets
     */
    public function enqueue_block_editor_assets() {
        wp_register_script(
            'bitstream-block',
            BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js',
            ['wp-blocks','wp-element','wp-editor','wp-components','wp-data'],
            BITSTREAM_VERSION, true
        );
        
        $inline_js = <<<'JS'
(function(){
    const {registerBlockType,createBlock} = wp.blocks;
    const {dispatch,select} = wp.data;
    const {InspectorControls} = wp.blockEditor||wp.editor;
    const {PanelBody,TextControl} = wp.components;
    registerBlockType('bitstream/rebit-url',{title:'ReBit URL',icon:'admin-links',category:'widgets',attributes:{bitstream_rebit_url:{type:'string',source:'meta',meta:'bitstream_rebit_url'}},edit({attributes,setAttributes}){return[
        wp.element.createElement(InspectorControls,null,
            wp.element.createElement(PanelBody,{title:'ReBit Settings',initialOpen:true},
                wp.element.createElement(TextControl,{label:'ReBit URL',value:attributes.bitstream_rebit_url,onChange:value=>setAttributes({bitstream_rebit_url:value})})
            )
        )
    ];},save(){return null;}});
    if(window.location.search.includes('rebit=1')&&select('core/editor')&&select('core/editor').isEditedPostNew()){
        dispatch('core/block-editor').insertBlock(createBlock('bitstream/rebit-url'));
    }
})();
JS;
        wp_add_inline_script('bitstream-block', $inline_js);
        wp_enqueue_script('bitstream-block');
    }
    
    /**
     * Enqueue frontend assets with optimizations
     */
    public function enqueue_frontend_assets() {
        // Only load media scripts when needed
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'bitstream_quick_post')) {
            wp_enqueue_media();
        }
        
        wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION);
        
        // Cache-busting version for JS
        wp_enqueue_script('bitstream-js', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js', ['jquery'], BITSTREAM_VERSION, true);
        wp_localize_script('bitstream-js', 'bitstream_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'like_nonce' => wp_create_nonce('bitstream_like_nonce'),
            'load_more_nonce' => wp_create_nonce('bitstream_load_more_nonce')
        ]);
        
        // Ensure $ is available globally
        add_action('wp_print_footer_scripts', function() {
            echo '<script type="text/javascript">window.$ = window.jQuery;</script>';
        });
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menus() {
        add_submenu_page('edit.php?post_type=bit', 'Post ReBit', 'Post ReBit', 'edit_posts', 'bitstream-post-rebit', [$this, 'handle_post_rebit_redirect']);
        add_submenu_page('edit.php?post_type=bit', 'ReBit Mappings', 'ReBit Mappings', 'manage_options', 'bitstream-rebit-mappings', [$this, 'rebit_mappings_page']);
    }
    
    /**
     * Admin notice for permalink issues
     */
    public function permalink_admin_notice() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'bit') return;
        
        if (get_option('bitstream_permalinks_flushed') !== BITSTREAM_VERSION) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>BitStream:</strong> Permalink issues detected. ';
            echo '<button type="button" class="button button-primary" onclick="bitstreamFlushPermalinks()">Fix Permalinks</button></p>';
            echo '</div>';
            
            // Add JavaScript for AJAX call
            echo '<script>
            function bitstreamFlushPermalinks() {
                fetch("' . admin_url('admin-ajax.php') . '", {
                    method: "POST",
                    body: new FormData(Object.assign(document.createElement("form"), {
                        innerHTML: `<input name="action" value="bitstream_flush_permalinks">
                                   <input name="nonce" value="' . wp_create_nonce('flush_permalinks') . '">`
                    }))
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert("Error: " + (data.data || "Unknown error"));
                    }
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
     * Handle single bit post display
     */
    public function handle_single_bit_display() {
        global $post;
        
        if (is_single() && $post && $post->post_type === 'bit') {
            // Ensure assets are loaded
            wp_enqueue_style('bitstream-css');
            wp_enqueue_script('bitstream-js');
            
            // Use WordPress theme header and footer
            get_header(); ?>
            
            <style>
                .bitstream-single-wrapper { max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
                .bitstream-back-link { 
                    display: inline-block; 
                    margin-bottom: 2rem; 
                    padding: 0.5rem 1rem; 
                    background: var(--wp--preset--color--accent-1, #2c6e49); 
                    color: white; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    transition: background-color 0.2s ease;
                }
                .bitstream-back-link:hover { 
                    background: var(--wp--preset--color--accent-2, #044389); 
                    color: white; 
                    text-decoration: none;
                }
            </style>
            
            <main class="site-main" role="main">
                <div class="bitstream-single-wrapper">
                    <a href="<?php echo esc_url(home_url('/bitstream/')); ?>" class="bitstream-back-link">← Back to BitStream</a>
                    <?php echo bitstream_render_card($post->ID); ?>
                </div>
            </main>
            
            <?php get_footer();
            exit;
        }
    }
    
    /**
     * Handle ReBit redirect
     */
    public function handle_post_rebit_redirect() {
        wp_redirect(admin_url('post-new.php?post_type=bit&rebit=1'));
        exit;
    }
    
    /**
     * ReBit mappings admin page with enhanced security
     */
    public function rebit_mappings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        if (isset($_POST['bitstream_rebit_mappings']) && check_admin_referer('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce')) {
            $posted = $_POST['bitstream_rebit_mappings'];
            $new = [];
            foreach ($posted as $map) {
                if (isset($map['remove']) && $map['remove']) continue;
                $domain = sanitize_text_field($map['domain'] ?? '');
                $label  = sanitize_text_field($map['label'] ?? '');
                $icon   = sanitize_text_field($map['icon'] ?? '');
                if (!$domain || !$label || !$icon) continue;
                $new[] = compact('domain','label','icon');
            }
            update_option('bitstream_rebit_mappings', $new);
            echo '<div class="updated notice is-dismissible"><p>ReBit mappings saved.</p></div>';
        }
        
        $mappings = get_option('bitstream_rebit_mappings', []);
        echo '<div class="wrap"><h1>ReBit Mappings</h1><form method="post">';
        wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce');
        echo '<table class="widefat fixed"><thead><tr><th>Domain</th><th>Label</th><th>Icon Class</th><th>Remove</th></tr></thead><tbody>';
        foreach ($mappings as $i => $map) {
            echo '<tr>';
            echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][domain]" value="'.esc_attr($map['domain']).'" /></td>';
            echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][label]"  value="'.esc_attr($map['label']).'"  /></td>';
            echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][icon]"   value="'.esc_attr($map['icon']).'"   /></td>';
            echo '<td><input type="checkbox" name="bitstream_rebit_mappings['.$i.'][remove]" value="1" /></td>';
            echo '</tr>';
        }
        echo '<tr><td><input type="text" name="bitstream_rebit_mappings[new][domain]" /></td>';
        echo '<td><input type="text" name="bitstream_rebit_mappings[new][label]" /></td>';
        echo '<td><input type="text" name="bitstream_rebit_mappings[new][icon]" /></td>';
        echo '<td></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Mappings');
        echo '</form></div>';
    }
    
    /**
     * Default content for ReBit posts
     */
    public function default_rebit_content($content, $post) {
        if ($post->post_type === 'bit' && !empty($_GET['rebit']) && $_GET['rebit'] === '1') {
            return '<!-- wp:bitstream/rebit-url /-->'."\n";
        }
        return $content;
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
    
    /**
     * Display quoted content in posts
     */
    public function display_quoted_content($content) {
        global $post;
        static $already_rendered = [];
        
        if (!isset($post) || !is_object($post) || $post->post_type !== 'bit') return $content;
        
        if (!empty($already_rendered[$post->ID])) return $content;
        if (!empty($GLOBALS['bitstream_is_rendering_quote'])) return $content;
        
        $quoted_id = get_post_meta($post->ID, '_bitstream_quoted_bit', true);
        if ($quoted_id) {
            $quoted_post = get_post($quoted_id);
            if ($quoted_post) {
                $header = '<div style="color:var(--wp--preset--color--accent-1,#2c6e49);font-weight:600;margin-bottom:8px;">'
                        . $this->format_quoted_date($quoted_id) . '</div>';
                $quoted_content = wpautop($quoted_post->post_content);
                $quoted_content = preg_replace('/<!--\s*wp:.*?\/-->/s', '', $quoted_content);
                $rich_preview = $this->render_og_card($quoted_id);
                $quoted_box = '<div class="bitstream-quoted-preview">'
                    . $header . $quoted_content . $rich_preview . '</div>';
                $GLOBALS['bitstream_is_rendering_quote'] = true;
                $content = $quoted_box . $content;
                unset($GLOBALS['bitstream_is_rendering_quote']);
            }
        }
        $already_rendered[$post->ID] = true;
        return $content;
    }
    
    /**
     * Format quoted date
     */
    private function format_quoted_date($post_id) {
        $date = get_the_date('', $post_id);
        $time = get_the_time('', $post_id);
        $author = get_the_author_meta('display_name', get_post_field('post_author', $post_id));
        return sprintf(esc_html__('%s · Posted on %s at %s', 'bitstream'), esc_html($author), $date, $time);
    }
    
    /**
     * Render OG card for quoted content
     */
    private function render_og_card($post_id) {
        $url   = get_post_meta($post_id, 'bitstream_rebit_url', true);
        $title = get_post_meta($post_id, '_bitstream_og_title', true);
        $desc  = get_post_meta($post_id, '_bitstream_og_desc', true);
        $img   = get_post_meta($post_id, '_bitstream_og_image', true);
        
        if (!$url && !$title && !$desc && !$img) return '';
        
        $card = '<div class="bitstream-og-card" style="display:flex;gap:16px;align-items:flex-start;margin-top:14px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.09);padding:14px;">';
        
        if ($img) {
            $card .= '<div class="bitstream-og-thumb" style="min-width:72px;width:72px;height:72px;background:#eee;border-radius:8px;overflow:hidden;display:flex;align-items:center;"><img src="'.esc_url($img).'" alt="" style="width:100%;height:auto;max-height:72px;object-fit:cover;display:block;"></div>';
        }
        
        $card .= '<div class="bitstream-og-meta" style="flex:1 1 0%;">';
        if ($title) {
            $card .= '<div class="bitstream-og-title" style="font-weight:600;font-size:1em;line-height:1.25;margin-bottom:4px;"><a href="'.esc_url($url).'" target="_blank" style="color:inherit;text-decoration:none;">'.esc_html($title).'</a></div>';
        } elseif ($url) {
            $card .= '<div class="bitstream-og-title"><a href="'.esc_url($url).'" target="_blank" style="color:inherit;text-decoration:none;">'.esc_html($url).'</a></div>';
        }
        if ($desc) {
            $card .= '<div class="bitstream-og-desc" style="font-size:0.97em;line-height:1.5;color:#444;margin-top:2px;">'.esc_html($desc).'</div>';
        }
        $card .= '<div class="bitstream-og-url" style="font-size:0.92em;color:var(--wp--preset--color--accent-1,#2c6e49);overflow-wrap:anywhere;word-break:break-all;margin-top:6px;"><a href="'.esc_url($url).'" target="_blank" style="color:var(--wp--preset--color--accent-1,#2c6e49);text-decoration:underline;word-break:break-all;">'.esc_html($url).'</a></div>';
        $card .= '</div></div>';
        
        return $card;
    }
    
    /**
     * Add PWA assets for quick post pages
     */
    public function pwa_assets() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'bitstream_quick_post')) {
            $base = BITSTREAM_PLUGIN_URL;
            $manifest_url = $base . 'manifest.json';
            $sw_url = $base . 'sw.js';
            
            // Only register if not already registered and within BitStream scope
            echo '<link rel="manifest" href="'.esc_url($manifest_url).'">';
            echo '<meta name="theme-color" content="#2c6e49">';
            echo '<script>
            if("serviceWorker" in navigator && window.location.pathname.includes("/bitstream/")) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    // Check if BitStream SW is already registered
                    let bitstreamRegistered = false;
                    for(let registration of registrations) {
                        if(registration.scope.includes("/bitstream/quickbit/")) {
                            bitstreamRegistered = true;
                            break;
                        }
                    }
                    // Only register if not already registered
                    if(!bitstreamRegistered) {
                        navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                            scope: "/bitstream/quickbit/",
                            updateViaCache: "none"
                        }).catch(function(error) {
                            console.warn("BitStream SW registration failed:", error);
                        });
                    }
                });
            }
            </script>';
        }
    }
    
    /**
     * Add PWA assets for BitStream feed pages
     */
    public function pwa_feed_assets() {
        global $post;
        
        // Load on archive pages or pages with [bitstream] shortcode
        $is_bit_archive = is_post_type_archive('bit');
        $has_feed_shortcode = is_a($post, 'WP_Post') && 
                             (has_shortcode($post->post_content, 'bitstream') || 
                              has_shortcode($post->post_content, 'bitstream_latest'));
        $is_bitstream_page = isset($_SERVER['REQUEST_URI']) && 
                            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false &&
                            strpos($_SERVER['REQUEST_URI'], '/quickbit/') === false;
        
        if ($is_bit_archive || $has_feed_shortcode || $is_bitstream_page) {
            $base = BITSTREAM_PLUGIN_URL;
            $manifest_url = $base . 'manifest-feed.json';
            $sw_url = $base . 'sw-feed.js';
            
            echo '<link rel="manifest" href="'.esc_url($manifest_url).'">';
            echo '<meta name="theme-color" content="#2c6e49">';
            echo '<script>
            if("serviceWorker" in navigator && window.location.pathname.includes("/bitstream/") && !window.location.pathname.includes("/quickbit/")) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    // Check if BitStream Feed SW is already registered
                    let feedRegistered = false;
                    for(let registration of registrations) {
                        if((registration.scope.includes("/bitstream/feed/") || registration.scope.includes("/bitstream/")) && registration.active && registration.active.scriptURL.includes("sw-feed.js")) {
                            feedRegistered = true;
                            break;
                        }
                    }
                    // Only register if not already registered
                    if(!feedRegistered) {
                        navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                            scope: "/bitstream/feed/",
                            updateViaCache: "none"
                        }).then(function(registration) {
                            console.log("BitStream Feed PWA registered successfully");
                        }).catch(function(error) {
                            console.warn("BitStream Feed SW registration failed:", error);
                        });
                    }
                });
            }
            </script>';
        }
    }
}

// Bit card rendering function (kept global for compatibility)
function bitstream_render_card($post_id) {
    $content   = apply_filters('the_content', get_post_field('post_content',$post_id));
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
