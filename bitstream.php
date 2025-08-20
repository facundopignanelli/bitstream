<?php
/**
 * Plugin Name: BitStream
 * Description: A microblogging plugin for sharing Bits and ReBits.
 * Version: 2.1.1
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

define('BITSTREAM_VERSION', '2.1.1');
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
        add_action('save_post_bit', [$this, 'save_rebit_og_data']);
        add_filter('the_content', [$this, 'display_quoted_content']);
        add_action('template_redirect', [$this, 'handle_single_bit_display']);
        add_action('wp_head', [$this, 'pwa_assets']);
        add_action('wp_head', [$this, 'pwa_feed_assets']);
        add_action('wp_footer', [$this, 'render_floating_quickbit_button']);
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
        
        // Get post ID for editor context
        $current_post_id = 0;
        if (is_admin() && isset($_GET['post'])) {
            $current_post_id = intval($_GET['post']);
        } elseif (is_admin() && isset($_POST['post_ID'])) {
            $current_post_id = intval($_POST['post_ID']);
        }

        // Localize script for block editor
        wp_localize_script('bitstream-block', 'bitstream_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url(),
            'like_nonce' => wp_create_nonce('bitstream_like_nonce'),
            'load_more_nonce' => wp_create_nonce('bitstream_load_more_nonce'),
            'og_fetch_nonce' => wp_create_nonce('bitstream_og_fetch_nonce'),
            'post_id' => $current_post_id
        ]);
        
        $inline_js = <<<'JS'
(function(){
    const {registerBlockType,createBlock} = wp.blocks;
    const {dispatch,select,useSelect} = wp.data;
    const {InspectorControls,useBlockProps} = wp.blockEditor||wp.editor;
    const {PanelBody,TextControl,Placeholder,Spinner} = wp.components;
    const {useState,useEffect} = wp.element;
    
    registerBlockType('bitstream/rebit-url',{
        title:'ReBit URL',
        icon:'admin-links',
        category:'widgets',
        attributes:{
            bitstream_rebit_url:{
                type:'string',
                source:'meta',
                meta:'bitstream_rebit_url'
            }
        },
        edit({attributes,setAttributes}){
            const [preview,setPreview] = useState(null);
            const [loading,setLoading] = useState(false);
            const [error,setError] = useState(null);
            const blockProps = useBlockProps ? useBlockProps() : {};
            
            const fetchPreview = async (url) => {
                if (!url || !url.trim()) {
                    setPreview(null);
                    return;
                }
                
                // Check if bitstream_ajax is available
                if (!window.bitstream_ajax) {
                    console.error('bitstream_ajax not available');
                    setError('Configuration error: AJAX not available');
                    return;
                }
                
                setLoading(true);
                setError(null);
                
                console.log('Fetching preview for:', url);
                console.log('Post ID:', bitstream_ajax.post_id);
                console.log('AJAX URL:', bitstream_ajax.ajax_url);
                console.log('Nonce:', bitstream_ajax.og_fetch_nonce);
                
                try {
                    const response = await fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'bitstream_fetch_og_data',
                            url: url,
                            post_id: bitstream_ajax.post_id || 0,
                            nonce: bitstream_ajax.og_fetch_nonce
                        })
                    });
                    
                    console.log('Response status:', response.status);
                    const data = await response.json();
                    console.log('Response data:', data);
                    
                    if (data.success) {
                        setPreview(data.data);
                        setError(null);
                    } else {
                        setError(data.data || 'Failed to fetch preview');
                        setPreview(null);
                    }
                } catch (err) {
                    console.error('Fetch error:', err);
                    setError('Failed to fetch preview');
                    setPreview(null);
                } finally {
                    setLoading(false);
                }
            };
            
            useEffect(() => {
                if (attributes.bitstream_rebit_url) {
                    fetchPreview(attributes.bitstream_rebit_url);
                }
            }, [attributes.bitstream_rebit_url]);
            
            const onURLChange = (value) => {
                setAttributes({bitstream_rebit_url: value});
            };
            
            return [
                wp.element.createElement(InspectorControls,null,
                    wp.element.createElement(PanelBody,{title:'ReBit Settings',initialOpen:true},
                        wp.element.createElement(TextControl,{
                            label:'ReBit URL',
                            value:attributes.bitstream_rebit_url || '',
                            onChange:onURLChange,
                            help:'Enter the URL you want to share'
                        })
                    )
                ),
                wp.element.createElement('div',blockProps,
                    !attributes.bitstream_rebit_url ? 
                        wp.element.createElement(Placeholder,{
                            icon:'admin-links',
                            label:'ReBit URL',
                            instructions:'Paste or type a URL to share external content'
                        },
                            wp.element.createElement(TextControl,{
                                placeholder:'https://example.com',
                                value:'',
                                onChange:onURLChange,
                                style:{marginBottom:'10px'}
                            })
                        ) :
                        wp.element.createElement('div',{
                            style:{
                                border:'1px solid #ddd',
                                borderRadius:'8px',
                                padding:'16px',
                                backgroundColor:'#f9f9f9'
                            }
                        },
                            wp.element.createElement('div',{
                                style:{
                                    marginBottom:'12px',
                                    fontSize:'14px',
                                    fontWeight:'600',
                                    color:'#2c6e49'
                                }
                            },'🔗 ReBit URL'),
                            wp.element.createElement(TextControl,{
                                value:attributes.bitstream_rebit_url || '',
                                onChange:onURLChange,
                                placeholder:'https://example.com',
                                style:{marginBottom:'12px'}
                            }),
                            loading && wp.element.createElement('div',{
                                style:{
                                    display:'flex',
                                    alignItems:'center',
                                    gap:'8px',
                                    color:'#666',
                                    fontSize:'14px'
                                }
                            },
                                wp.element.createElement(Spinner),
                                'Loading preview...'
                            ),
                            error && wp.element.createElement('div',{
                                style:{
                                    color:'#d63638',
                                    fontSize:'14px',
                                    marginTop:'8px'
                                }
                            }, error),
                            preview && !loading && wp.element.createElement('div',{
                                style:{
                                    border:'1px solid #ccc',
                                    borderRadius:'6px',
                                    overflow:'hidden',
                                    backgroundColor:'white',
                                    marginTop:'12px'
                                }
                            },
                                preview.image && wp.element.createElement('img',{
                                    src:preview.image,
                                    alt:'',
                                    style:{
                                        width:'100%',
                                        height:'auto',
                                        display:'block'
                                    }
                                }),
                                wp.element.createElement('div',{
                                    style:{padding:'12px'}
                                },
                                    preview.title && wp.element.createElement('h4',{
                                        style:{
                                            margin:'0 0 8px 0',
                                            fontSize:'16px',
                                            fontWeight:'600'
                                        }
                                    }, preview.title),
                                    preview.description && wp.element.createElement('p',{
                                        style:{
                                            margin:'0',
                                            fontSize:'14px',
                                            color:'#666',
                                            lineHeight:'1.4'
                                        }
                                    }, preview.description)
                                )
                            )
                        )
                )
            ];
        },
        save(){return null;}
    });
    
    if(window.location.search.includes('rebit=1')&&select('core/editor')&&select('core/editor').isEditedPostNew()){
        dispatch('core/block-editor').insertBlock(createBlock('bitstream/rebit-url'));
    }
    
    // Handle quoted bit display in block editor
    if(window.location.search.includes('quoted_bit=')&&select('core/editor')&&select('core/editor').isEditedPostNew()){
        const urlParams = new URLSearchParams(window.location.search);
        const quotedBitId = urlParams.get('quoted_bit');
        
        if(quotedBitId) {
            // Add debugging
            console.log('BitStream: Looking for quoted bit ID:', quotedBitId);
            console.log('BitStream: bitstream_ajax available:', typeof window.bitstream_ajax);
            
            // Check if bitstream_ajax is available
            if (!window.bitstream_ajax) {
                console.error('BitStream: bitstream_ajax not available, trying fallback...');
                // Fallback: try to find AJAX URL from WordPress
                const ajaxUrl = '/wp-admin/admin-ajax.php';
                window.bitstream_ajax = {
                    ajax_url: ajaxUrl,
                    og_fetch_nonce: 'fallback'
                };
            }
            
            // Wait for editor to be ready - try multiple approaches
            let attempts = 0;
            const maxAttempts = 50;
            
            const waitForEditor = () => {
                attempts++;
                console.log('BitStream: Attempt', attempts, 'looking for editor...');
                
                const editorElement = document.querySelector('.edit-post-visual-editor') || 
                                    document.querySelector('.block-editor-writing-flow') ||
                                    document.querySelector('.editor-styles-wrapper') ||
                                    document.querySelector('[data-type="core/post-content"]') ||
                                    document.querySelector('.wp-block-post-content') ||
                                    document.querySelector('.edit-post-layout__content');
                                    
                console.log('BitStream: Editor element found:', editorElement);
                
                if (!editorElement && attempts < maxAttempts) {
                    setTimeout(waitForEditor, 200);
                    return;
                }
                
                if (!editorElement) {
                    console.error('BitStream: Could not find editor after', maxAttempts, 'attempts');
                    // Try to show quote info in a different way
                    showQuoteInAlternativeWay(quotedBitId);
                    return;
                }
                
                showQuoteInEditor(editorElement, quotedBitId);
            };
            
            const showQuoteInEditor = (editorElement, quotedBitId) => {
                // Create quoted bit preview element
                const quotedPreview = document.createElement('div');
                quotedPreview.id = 'bitstream-quoted-preview';
                quotedPreview.style.cssText = \`
                    margin: 16px 0;
                    padding: 16px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    background: #f9f9f9;
                    border-left: 4px solid #2c6e49;
                    position: relative;
                    z-index: 1000;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                \`;
                quotedPreview.innerHTML = '<div style="color: #666; margin-bottom: 8px;">Loading quoted bit...</div>';
                
                // Try multiple insertion points
                const contentArea = document.querySelector('.editor-styles-wrapper') || 
                                  document.querySelector('.block-editor-writing-flow') ||
                                  document.querySelector('.edit-post-visual-editor') ||
                                  editorElement;
                                  
                if (contentArea) {
                    contentArea.insertBefore(quotedPreview, contentArea.firstChild);
                    console.log('BitStream: Preview element inserted');
                } else {
                    // Fallback: insert after the editor toolbar
                    const toolbar = document.querySelector('.edit-post-header') || 
                                  document.querySelector('.block-editor-header');
                    if (toolbar && toolbar.parentNode) {
                        toolbar.parentNode.insertBefore(quotedPreview, toolbar.nextSibling);
                        console.log('BitStream: Preview element inserted after toolbar');
                    } else {
                        // Last resort: append to body
                        document.body.insertBefore(quotedPreview, document.body.firstChild);
                        console.log('BitStream: Preview element inserted at top of body');
                    }
                }
                
                // Fetch the quoted bit content
                console.log('BitStream: Fetching quoted bit content...');
                fetchQuotedBitContent(quotedBitId, quotedPreview);
            };
            
            const showQuoteInAlternativeWay = (quotedBitId) => {
                // Show at the very top of the page if editor isn't found
                const notice = document.createElement('div');
                notice.style.cssText = \`
                    position: fixed;
                    top: 32px;
                    left: 0;
                    right: 0;
                    background: #2c6e49;
                    color: white;
                    padding: 12px;
                    text-align: center;
                    z-index: 10000;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                \`;
                notice.innerHTML = \`You are quoting Bit #\${quotedBitId}. Loading quote content...\`;
                document.body.appendChild(notice);
                
                fetchQuotedBitContent(quotedBitId, notice);
            };
            
            const fetchQuotedBitContent = (quotedBitId, container) => {
                fetch(window.bitstream_ajax.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'bitstream_get_quoted_bit',
                        quoted_bit_id: quotedBitId,
                        nonce: window.bitstream_ajax.og_fetch_nonce
                    })
                })
                .then(response => {
                    console.log('BitStream: AJAX response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('BitStream: AJAX response:', data);
                    if(data.success) {
                        container.innerHTML = `
                            <div style="display: flex; align-items: center; margin-bottom: 12px; color: #2c6e49; font-weight: 600;">
                                <i class="fa-solid fa-quote-left" style="margin-right: 8px;"></i>
                                Quoting Bit #${quotedBitId} by ${data.data.author} • ${data.data.timestamp}
                            </div>
                            <div style="border-left: 3px solid #ccc; padding-left: 12px; color: #555;">
                                ${data.data.content}
                            </div>
                        `;
                        console.log('BitStream: Quote content loaded successfully');
                        
                        // Store the quoted bit ID for saving
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'bitstream_quoted_bit';
                        hiddenInput.value = quotedBitId;
                        
                        // Find the form and append the hidden input
                        const form = document.querySelector('form[name="post"]') || document.querySelector('#post');
                        if (form) {
                            form.appendChild(hiddenInput);
                        } else {
                            // Fallback: append to body and ensure it gets submitted
                            document.body.appendChild(hiddenInput);
                            
                            // Listen for form submissions to ensure our data is included
                            document.addEventListener('submit', function(e) {
                                const submitForm = e.target;
                                if (submitForm && (submitForm.name === 'post' || submitForm.id === 'post')) {
                                    const existingInput = submitForm.querySelector('input[name="bitstream_quoted_bit"]');
                                    if (!existingInput) {
                                        const clonedInput = hiddenInput.cloneNode(true);
                                        submitForm.appendChild(clonedInput);
                                    }
                                }
                            });
                        }
                        
                    } else {
                        container.innerHTML = '<div style="color: #d63638;">Failed to load quoted bit: ' + (data.data || 'Unknown error') + '</div>';
                    }
                })
                .catch(err => {
                    console.error('BitStream: Failed to fetch quoted bit:', err);
                    container.innerHTML = '<div style="color: #d63638;">Failed to load quoted bit content. Error: ' + err.message + '</div>';
                });
            };
            
            waitForEditor();
        }
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
        // Get post ID for editor context
        $current_post_id = 0;
        if (is_admin() && isset($_GET['post'])) {
            $current_post_id = intval($_GET['post']);
        } elseif (is_admin() && isset($_POST['post_ID'])) {
            $current_post_id = intval($_POST['post_ID']);
        } elseif (!is_admin()) {
            $current_post_id = get_the_ID();
        }

        wp_localize_script('bitstream-js', 'bitstream_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url(),
            'like_nonce' => wp_create_nonce('bitstream_like_nonce'),
            'load_more_nonce' => wp_create_nonce('bitstream_load_more_nonce'),
            'og_fetch_nonce' => wp_create_nonce('bitstream_og_fetch_nonce'),
            'post_id' => $current_post_id
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
     * Handle single bit post display using theme template
     */
    public function handle_single_bit_display() {
        global $post;
        
        if (is_single() && $post && $post->post_type === 'bit') {
            // Ensure assets are loaded
            wp_enqueue_style('bitstream-css');
            wp_enqueue_script('bitstream-js');
            
            // Add body class for better targeting
            add_filter('body_class', function($classes) {
                $classes[] = 'bitstream-single-bit';
                return $classes;
            });
            
            // Use the theme's page template by filtering the content
            add_filter('the_content', [$this, 'single_bit_content']);
            add_action('wp_head', [$this, 'single_bit_styles']);
        }
    }
    
    /**
     * Filter content for single bit posts
     */
    public function single_bit_content($content) {
        global $post;
        
        // Prevent infinite loop by checking if we're already processing
        static $processing = false;
        if ($processing) {
            return $content;
        }
        
        if (is_single() && $post && $post->post_type === 'bit') {
            $processing = true;
            
            // Temporarily remove our filter to prevent infinite loop
            remove_filter('the_content', [$this, 'single_bit_content']);
            
            ob_start(); ?>
            <div class="bitstream-single-wrapper">
                <a href="<?php echo esc_url(home_url('/bitstream/')); ?>" class="bitstream-back-link">← Back to BitStream</a>
                <?php echo bitstream_render_card($post->ID, true); ?>
            </div>
            <?php
            $output = ob_get_clean();
            
            // Re-add our filter
            add_filter('the_content', [$this, 'single_bit_content']);
            
            $processing = false;
            return $output;
        }
        
        return $content;
    }
    
    /**
     * Add styles for single bit display
     */
    public function single_bit_styles() {
        global $post;

        if (is_single() && $post && $post->post_type === 'bit') { ?>
            <style>
                /* Hide theme's default post elements that might conflict with our bit card */
                .bitstream-single-bit .entry-header .entry-title,
                .bitstream-single-bit .entry-meta:not(.bitstream-single-wrapper .entry-meta),
                .bitstream-single-bit .entry-footer:not(.bitstream-single-wrapper .entry-footer),
                .bitstream-single-bit .post-navigation,
                .bitstream-single-bit .author-info,
                .bitstream-single-bit .post-header .post-title,
                .bitstream-single-bit .wp-block-post-title,
                .bitstream-single-bit .wp-block-post-date,
                .bitstream-single-bit .wp-block-post-author,
                .bitstream-single-bit .wp-block-post-terms {
                    display: none !important;
                }
                
                /* Hide only empty content that's not our wrapper */
                .bitstream-single-bit .entry-content > p:empty,
                .bitstream-single-bit .entry-content > div:empty:not(.bitstream-single-wrapper) {
                    display: none !important;
                }
                
                /* Clean layout for BitStream content */
                .bitstream-single-bit .entry-content {
                    min-height: auto;
                }
                
                .bitstream-single-bit .site-main,
                .bitstream-single-bit .main-content {
                    padding-top: 2rem;
                }
                
                /* BitStream specific styles */
                .bitstream-single-wrapper { 
                    max-width: 800px; 
                    margin: 2rem auto; 
                    padding: 0 1rem; 
                    display: block !important;
                    visibility: visible !important;
                }
                
                .bitstream-back-link { 
                    display: inline-block; 
                    margin-bottom: 2rem; 
                    padding: 0.5rem 1rem; 
                    background: var(--wp--preset--color--accent-1, #2c6e49); 
                    color: white; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    transition: background-color 0.2s ease;
                    font-size: 0.9rem;
                }
                
                .bitstream-back-link:hover { 
                    background: var(--wp--preset--color--accent-2, #044389); 
                    color: white; 
                    text-decoration: none;
                }
                
                /* Ensure BitStream card displays properly */
                .bitstream-single-wrapper .bit-card {
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    border: 1px solid #e1e1e1;
                    display: block !important;
                    visibility: visible !important;
                }
                
                /* Ensure all bit card elements are visible */
                .bitstream-single-wrapper .bit-card * {
                    display: revert !important;
                    visibility: visible !important;
                }
                
                /* Handle theme-specific containers */
                .bitstream-single-bit .content-area {
                    max-width: none;
                }
                
                /* Clean up article styling but don't hide everything */
                .bitstream-single-bit article:not(.bit-card) {
                    margin: 0;
                    padding: 0;
                }
                
                /* Ensure content is visible */
                .bitstream-single-bit .entry-content .bitstream-single-wrapper {
                    display: block !important;
                    opacity: 1 !important;
                    visibility: visible !important;
                }
            </style>
        <?php }
    }    /**
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
     * Save ReBit OpenGraph data when post is saved
     */
    public function save_rebit_og_data($post_id) {
        // Check if this is a ReBit (has a rebit_url)
        $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
        
        if (empty($rebit_url)) {
            // Not a ReBit, clean up any existing OG data
            delete_post_meta($post_id, '_bitstream_og_title');
            delete_post_meta($post_id, '_bitstream_og_desc');
            delete_post_meta($post_id, '_bitstream_og_image');
            return;
        }
        
        // Check if we already have OG data for this URL
        $existing_title = get_post_meta($post_id, '_bitstream_og_title', true);
        if (!empty($existing_title)) {
            return; // Already has OG data, probably from AJAX fetch
        }
        
        // If we reach here, it means we have a ReBit URL but no OG data
        // This could happen if the post was saved without using the block editor
        // In this case, we'll fetch the data synchronously
        $ajax_handler = new BitStream_Ajax_Handlers();
        
        // Simulate the AJAX request data
        $_POST_backup = $_POST;
        $_POST = [
            'url' => $rebit_url,
            'post_id' => $post_id,
            'nonce' => wp_create_nonce('bitstream_og_fetch_nonce')
        ];
        
        // Temporarily allow this to run without AJAX context
        add_filter('wp_doing_ajax', '__return_true');
        
        // Capture the output and handle it
        ob_start();
        $ajax_handler->handle_fetch_og_data();
        $output = ob_get_clean();
        
        // Restore original POST data
        $_POST = $_POST_backup;
        remove_filter('wp_doing_ajax', '__return_true');
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
                $content = $content . $quoted_box; // Put quoted content after new content (social media style)
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
        // Load QuickPost PWA on quickbit pages or pages with quickpost shortcode
        $should_load_quickpost = false;
        
        global $post;
        
        // Check if current page has quickpost shortcode
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'bitstream_quick_post')) {
            $should_load_quickpost = true;
        }
        
        // Check if URL suggests quickbit page
        if (isset($_SERVER['REQUEST_URI']) && 
            (strpos($_SERVER['REQUEST_URI'], '/quickbit/') !== false || 
             strpos($_SERVER['REQUEST_URI'], '/bitstream/quickbit/') !== false ||
             is_page('quickbit'))) {
            $should_load_quickpost = true;
        }
        
        if ($should_load_quickpost) {
            $base = BITSTREAM_PLUGIN_URL;
            $manifest_url = $base . 'manifest.json';
            $sw_url = $base . 'sw.js';
            
            echo '<link rel="manifest" href="'.esc_url($manifest_url).'">';
            echo '<meta name="theme-color" content="#2c6e49">';
            echo '<meta name="apple-mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
            echo '<meta name="apple-mobile-web-app-title" content="BitStream QuickPost">';
            
            echo '<script>
            if("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                        scope: "/bitstream/",
                        updateViaCache: "none"
                    }).then(function(registration) {
                        console.log("QuickPost SW registered with scope:", registration.scope);
                        
                        // Check for installation prompt
                        window.addEventListener("beforeinstallprompt", function(event) {
                            console.log("BitStream QuickPost PWA installation available");
                            // Store the event for later use
                            window.deferredPrompt = event;
                        });
                        
                    }).catch(function(error) {
                        console.warn("QuickPost SW registration failed:", error);
                    });
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
        
        // Don't load feed PWA if QuickPost PWA should be loaded
        $should_load_quickpost = false;
        
        // Check if current page has quickpost shortcode
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'bitstream_quick_post')) {
            $should_load_quickpost = true;
        }
        
        // Check if URL suggests quickbit page
        if (isset($_SERVER['REQUEST_URI']) && 
            (strpos($_SERVER['REQUEST_URI'], '/quickbit/') !== false || 
             strpos($_SERVER['REQUEST_URI'], '/bitstream/quickbit/') !== false ||
             is_page('quickbit'))) {
            $should_load_quickpost = true;
        }
        
        // Only load feed PWA if QuickPost shouldn't be loaded
        if (!$should_load_quickpost) {
            // Load on archive pages or pages with [bitstream] shortcode
            $is_bit_archive = is_post_type_archive('bit');
            $has_feed_shortcode = is_a($post, 'WP_Post') && 
                                 (has_shortcode($post->post_content, 'bitstream') || 
                                  has_shortcode($post->post_content, 'bitstream_latest'));
            $is_bitstream_page = isset($_SERVER['REQUEST_URI']) && 
                                strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false;
            
            if ($is_bit_archive || $has_feed_shortcode || $is_bitstream_page) {
                $base = BITSTREAM_PLUGIN_URL;
                $manifest_url = $base . 'manifest-feed.json';
                $sw_url = $base . 'sw-feed.js';
                
                echo '<link rel="manifest" href="'.esc_url($manifest_url).'">';
                echo '<meta name="theme-color" content="#2c6e49">';
                echo '<meta name="apple-mobile-web-app-capable" content="yes">';
                echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
                echo '<meta name="apple-mobile-web-app-title" content="BitStream Feed">';
                
                echo '<script>
                if("serviceWorker" in navigator) {
                    window.addEventListener("load", function() {
                        navigator.serviceWorker.register("'.esc_url($sw_url).'", {
                            scope: "/bitstream/",
                            updateViaCache: "none"
                        }).then(function(registration) {
                            console.log("BitStream Feed PWA registered with scope:", registration.scope);
                            
                            // Check for installation prompt
                            window.addEventListener("beforeinstallprompt", function(event) {
                                console.log("BitStream Feed PWA installation available");
                                // Store the event for later use
                                window.deferredPrompt = event;
                            });
                            
                        }).catch(function(error) {
                            console.warn("Feed SW registration failed:", error);
                        });
                    });
                }
                </script>';
            }
        }
    }

    /**
     * Render floating QuickBit button for admins
     */
    public function render_floating_quickbit_button() {
        // Only show to users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        $quickbit_url = admin_url('post-new.php?post_type=bit');
        $rebit_url = admin_url('post-new.php?post_type=bit&rebit=1');
        ?>
        <div id="bitstream-floating-quickbit" style="position: fixed; bottom: 30px; right: 30px; z-index: 9999;">
            <div class="quickbit-menu">
                <button class="quickbit-toggle" 
                        style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #2c6e49; color: white; border-radius: 50%; border: none; box-shadow: 0 4px 12px rgba(44,110,73,0.25); transition: all 0.3s ease; font-size: 24px; cursor: pointer;"
                        title="Quick Actions"
                        onmouseover="this.style.transform='scale(1.1)'; this.style.background='#1f4d35';"
                        onmouseout="this.style.transform='scale(1)'; this.style.background='#2c6e49';">
                    <i class="fa-solid fa-plus" style="margin: 0;"></i>
                </button>
                <div class="quickbit-dropdown" style="position: absolute; bottom: 70px; right: 0; background: white; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 160px; opacity: 0; visibility: hidden; transform: translateY(10px); transition: all 0.3s ease;">
                    <a href="<?php echo esc_url($quickbit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;"
                       onmouseover="this.style.background='#f5f5f5';"
                       onmouseout="this.style.background='white';">
                        <i class="fa-solid fa-comment" style="margin-right: 8px; color: #2c6e49;"></i>
                        Add New Bit
                    </a>
                    <a href="<?php echo esc_url($rebit_url); ?>" 
                       style="display: flex; align-items: center; padding: 12px 16px; text-decoration: none; color: #333;"
                       onmouseover="this.style.background='#f5f5f5';"
                       onmouseout="this.style.background='white';">
                        <i class="fa-solid fa-link" style="margin-right: 8px; color: #2c6e49;"></i>
                        Add New ReBit
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}

// Bit card rendering function (kept global for compatibility)
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
