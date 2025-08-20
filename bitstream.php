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
        add_action('wp_footer', [$this, 'render_floating_quickbit_button']);
        add_action('init', [$this, 'add_service_worker_rewrite']);
        add_action('template_redirect', [$this, 'serve_service_worker']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // RSS Support
        add_action('init', [$this, 'add_rss_feeds']);
        add_action('wp_head', [$this, 'add_rss_links']);
        
        // Add debug parameter handler
        add_action('template_redirect', [$this, 'handle_debug_requests']);
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
    console.log('BitStream: Script loaded, checking for quoted_bit parameter');
    console.log('BitStream: Current URL:', window.location.href);
    console.log('BitStream: Search params:', window.location.search);
    
    if(window.location.search.includes('quoted_bit=')) {
        console.log('BitStream: Quoted bit detected in URL');
        const urlParams = new URLSearchParams(window.location.search);
        const quotedBitId = urlParams.get('quoted_bit');
        console.log('BitStream: Quoted bit ID:', quotedBitId);
        
        if(quotedBitId) {
            console.log('BitStream: Starting quote display process');
            console.log('BitStream: Checking if editor is new post...');
            
            // Function to check if we're in a new post
            const checkIfNewPost = () => {
                // Fallback 1: check URL for new post indicators
                const urlCheck = window.location.href.includes('post-new.php');
                console.log('BitStream: URL check for post-new.php:', urlCheck);
                
                // Fallback 2: check if post ID is present (new posts don't have IDs yet)
                const postIdCheck = !window.location.href.includes('post=');
                console.log('BitStream: No existing post ID found:', postIdCheck);
                
                // For quote functionality, we want to proceed if it's a new post OR if we have a quoted_bit parameter
                const hasQuotedBit = window.location.search.includes('quoted_bit=');
                console.log('BitStream: Has quoted_bit parameter:', hasQuotedBit);
                
                // If we have a quoted_bit parameter on a post-new.php page, we should proceed
                const shouldProceed = urlCheck && postIdCheck && hasQuotedBit;
                console.log('BitStream: Should proceed with quote display:', shouldProceed);
                
                // Also try WordPress editor API as additional info (but don't block on it)
                if (typeof select !== 'undefined' && select('core/editor') && select('core/editor').isEditedPostNew) {
                    const isNew = select('core/editor').isEditedPostNew();
                    console.log('BitStream: WordPress editor says isNew:', isNew);
                }
                
                return shouldProceed;
            };
            
            if (checkIfNewPost()) {
                console.log('BitStream: Confirmed this is a new post, proceeding with quote display');
                
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
                quotedPreview.style.cssText = 
                    'margin: 16px 0;' +
                    'padding: 16px;' +
                    'border: 1px solid #ddd;' +
                    'border-radius: 8px;' +
                    'background: #f9f9f9;' +
                    'border-left: 4px solid #2c6e49;' +
                    'position: relative;' +
                    'z-index: 1000;' +
                    'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';
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
                notice.style.cssText = 
                    'position: fixed;' +
                    'top: 32px;' +
                    'left: 0;' +
                    'right: 0;' +
                    'background: #2c6e49;' +
                    'color: white;' +
                    'padding: 12px;' +
                    'text-align: center;' +
                    'z-index: 10000;' +
                    'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';
                notice.innerHTML = 'You are quoting Bit #' + quotedBitId + '. Loading quote content...';
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
                        container.innerHTML = 
                            '<div style="display: flex; align-items: center; margin-bottom: 12px; color: #2c6e49; font-weight: 600;">' +
                                '<i class="fa-solid fa-quote-left" style="margin-right: 8px;"></i>' +
                                'Quoting Bit #' + quotedBitId + ' by ' + data.data.author + ' • ' + data.data.timestamp +
                            '</div>' +
                            '<div style="border-left: 3px solid #ccc; padding-left: 12px; color: #555;">' +
                                data.data.content +
                            '</div>';
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
            } else {
                console.log('BitStream: Not a new post, skipping quote display');
            }
        } else {
            console.log('BitStream: No quoted bit ID found');
        }
    } else {
        console.log('BitStream: No quoted_bit parameter found in URL');
    }
    
    // Handle PWA shortcut for ReBit creation
    if (new URLSearchParams(window.location.search).has('rebit')) {
        console.log('BitStream: ReBit shortcut detected, auto-inserting ReBit block');
        
        // Wait for editor to be ready
        const insertRebitBlock = () => {
            const {insertBlocks} = dispatch('core/block-editor');
            const {getBlocks} = select('core/block-editor');
            
            // Check if editor is ready and no blocks exist yet
            if (insertBlocks && getBlocks().length === 0) {
                const rebitBlock = createBlock('bitstream/rebit-url');
                insertBlocks(rebitBlock);
                console.log('BitStream: ReBit block auto-inserted');
            } else {
                // Try again in a moment if editor isn't ready
                setTimeout(insertRebitBlock, 100);
            }
        };
        
        // Start trying to insert the block
        setTimeout(insertRebitBlock, 500);
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
        // 1. Add New Bit (redirect to post-new.php?post_type=bit)
        add_submenu_page('edit.php?post_type=bit', 'Add New Bit', 'Add New Bit', 'edit_posts', 'bitstream-add-bit', [$this, 'handle_add_bit_redirect']);
        
        // 2. Add New ReBit (Post ReBit)
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
        
        // Service Worker debug notice for admins
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>BitStream Debug:</strong> If Service Worker errors occur, ';
            echo '<a href="' . esc_url(add_query_arg('bitstream_debug', 'flush_rewrite')) . '" class="button button-secondary">Flush SW Rewrite Rules</a></p>';
            echo '</div>';
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
            // Ensure assets are loaded with proper priority
            wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION);
            wp_enqueue_script('bitstream-js', BITSTREAM_PLUGIN_URL . 'assets/js/bitstream.js', ['jquery'], BITSTREAM_VERSION, true);
            
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
                /* Hide theme's default post elements that might conflict with our bit card BUT keep title */
                .bitstream-single-bit .entry-footer:not(.bitstream-single-wrapper .entry-footer),
                .bitstream-single-bit .post-navigation,
                .bitstream-single-bit .author-info {
                    display: none !important;
                }
                
                /* Keep and style the entry title for SEO and navigation */
                .bitstream-single-bit .entry-header {
                    margin-bottom: 2rem !important;
                    border-bottom: 1px solid #eee !important;
                    padding-bottom: 1rem !important;
                }
                
                .bitstream-single-bit .entry-title,
                .bitstream-single-bit .entry-header .entry-title {
                    display: block !important;
                    font-size: 1.8rem !important;
                    margin-bottom: 0.5rem !important;
                    color: #333 !important;
                    line-height: 1.3 !important;
                }
                
                /* Show post meta using theme's default styling for better integration */
                .bitstream-single-bit .entry-meta,
                .bitstream-single-bit .entry-header .entry-meta {
                    display: block !important;
                }
                
                /* Hide other meta elements but keep date */
                .bitstream-single-bit .post-meta,
                .bitstream-single-bit .wp-block-post-author,
                .bitstream-single-bit .wp-block-post-terms {
                    display: none !important;
                }
                
                /* Allow wp-block-post-date to show using theme's default styling */
                .bitstream-single-bit .wp-block-post-date {
                    display: block !important;
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
                
                /* Ensure BitStream card displays properly - override masonry layout positioning */
                .bitstream-single-wrapper .bit-card {
                    position: static !important; /* Override masonry absolute positioning */
                    width: 100% !important; /* Full width for single display */
                    max-width: 100% !important;
                    margin: 0 auto !important;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    border: 1px solid #e1e1e1;
                    display: block !important;
                    visibility: visible !important;
                    background: white;
                    border-radius: 16px;
                    padding: 1.5rem;
                    transform: none !important; /* Remove masonry transforms */
                    left: auto !important;
                    top: auto !important;
                }
                    visibility: visible !important;
                }
                
                /* Ensure all bit card elements are visible and properly styled */
                .bitstream-single-wrapper .bit-card * {
                    display: revert !important;
                    visibility: visible !important;
                }
                
                /* Style the bit card header */
                .bitstream-single-wrapper .bit-card-header {
                    display: flex !important;
                    align-items: flex-start !important;
                    margin-bottom: 1rem !important;
                    gap: 0.75rem !important;
                }
                
                .bitstream-single-wrapper .bit-avatar {
                    flex-shrink: 0 !important;
                    width: 48px !important;
                    height: 48px !important;
                    border-radius: 50% !important;
                    overflow: hidden !important;
                }
                
                .bitstream-single-wrapper .bit-avatar img {
                    width: 100% !important;
                    height: 100% !important;
                    object-fit: cover !important;
                }
                
                .bitstream-single-wrapper .bit-author-info h3 {
                    margin: 0 !important;
                    font-size: 1rem !important;
                    font-weight: 600 !important;
                    color: #333 !important;
                }
                
                .bitstream-single-wrapper .bit-timestamp {
                    font-size: 0.875rem !important;
                    color: #666 !important;
                    margin: 0 !important;
                }
                
                /* Style the bit card content */
                .bitstream-single-wrapper .bit-card-content {
                    margin: 1rem 0 !important;
                    line-height: 1.6 !important;
                    color: #333 !important;
                }
                
                /* Style the bit card footer */
                .bitstream-single-wrapper .bit-card-footer {
                    display: flex !important;
                    gap: 1rem !important;
                    font-size: 0.875rem !important;
                    align-items: center !important;
                    margin-top: 1rem !important;
                    padding-top: 1rem !important;
                    border-top: 1px solid #eee !important;
                }
                
                .bitstream-single-wrapper .bit-action {
                    background: none !important;
                    border: none !important;
                    cursor: pointer !important;
                    color: #666 !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.25rem !important;
                    font-size: 0.875rem !important;
                    transition: color 0.2s ease !important;
                }
                
                .bitstream-single-wrapper .bit-action:hover {
                    color: #2c6e49 !important;
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
     * Handle Add New Bit redirect
     */
    public function handle_add_bit_redirect() {
        wp_redirect(admin_url('post-new.php?post_type=bit'));
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
        
        echo '<div class="wrap" style="max-width: 1200px;">';
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
            echo '<div class="updated notice is-dismissible"><p><strong>ReBit mappings saved successfully!</strong></p></div>';
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
        
        $mappings = get_option('bitstream_rebit_mappings', []);
        ?>
        <div class="wrap" style="max-width: 1200px;">
            <h1>ReBit Mappings</h1>
            <p class="description">Configure how different websites appear when shared as ReBits. Each mapping adds a custom icon and label for specific domains.</p>
            
            <!-- Current Mappings -->
            <form method="post" id="mappings-form">
                <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                
                <div class="card">
                    <h2 class="title">Current Mappings</h2>
                    
                    <?php if (empty($mappings)): ?>
                        <p class="description">No mappings configured yet. Add some presets below or create custom mappings.</p>
                    <?php else: ?>
                        <div id="mappings-container">
                            <?php foreach ($mappings as $i => $map): ?>
                                <div class="mapping-row" style="display: flex; align-items: center; margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #fafafa;">
                                    <div style="flex: 1; margin-right: 15px;">
                                        <label><strong>Domain:</strong></label><br>
                                        <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][domain]" 
                                               value="<?php echo esc_attr($map['domain']); ?>" 
                                               placeholder="example.com" style="width: 100%;" />
                                    </div>
                                    <div style="flex: 1; margin-right: 15px;">
                                        <label><strong>Label:</strong></label><br>
                                        <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][label]" 
                                               value="<?php echo esc_attr($map['label']); ?>" 
                                               placeholder="shared from Twitter" style="width: 100%;" />
                                    </div>
                                    <div style="flex: 1; margin-right: 15px;">
                                        <label><strong>Icon Class:</strong></label><br>
                                        <div style="position: relative;">
                                            <input type="text" name="bitstream_rebit_mappings[<?php echo $i; ?>][icon]" 
                                                   value="<?php echo esc_attr($map['icon']); ?>" 
                                                   placeholder="fab fa-twitter" style="width: 100%; padding-right: 40px;" 
                                                   id="icon-input-<?php echo $i; ?>" />
                                            <button type="button" class="button" onclick="openIconPicker('icon-input-<?php echo $i; ?>')" 
                                                    style="position: absolute; right: 5px; top: 2px; height: 26px; padding: 2px 8px;">
                                                <i class="fas fa-palette"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div style="flex: 0 0 150px; margin-right: 15px;">
                                        <label><strong>Preview:</strong></label><br>
                                        <div class="mapping-preview" style="padding: 8px; border: 1px solid #ccc; border-radius: 3px; background: white; min-height: 30px;">
                                            <i class="<?php echo esc_attr($map['icon']); ?>" style="margin-right: 8px; color: #2c6e49;"></i>
                                            <span><?php echo esc_html($map['label']); ?></span>
                                        </div>
                                    </div>
                                    <div style="flex: 0 0 auto;">
                                        <button type="button" class="button button-link-delete" onclick="removeMapping(this)" style="color: #a00;">Remove</button>
                                        <input type="hidden" name="bitstream_rebit_mappings[<?php echo $i; ?>][remove]" value="0" class="remove-flag" />
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save All Mappings" />
                </p>
            </form>
            
            <!-- Bottom Section: Quick Add and Add New Mapping -->
            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <!-- Quick Presets Section -->
                <div class="card" style="flex: 1;">
                    <h2 class="title">Quick Add Popular Sites</h2>
                    <p>Add pre-configured mappings for popular websites:</p>
                    <form method="post" style="margin-bottom: 15px;">
                        <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                        <div style="display: flex; gap: 10px; align-items: end;">
                            <div style="flex: 1;">
                                <label><strong>Website:</strong></label><br>
                                <select name="preset_selection" style="width: 100%;">
                                    <option value="">Select a website...</option>
                                    <?php foreach ($this->get_rebit_presets() as $key => $preset): ?>
                                        <option value="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($preset['label']); ?> (<?php echo esc_html($preset['domain']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex: 0 0 auto;">
                                <button type="submit" name="add_preset" class="button button-secondary">Add Preset</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Add New Mapping Section -->
                <div class="card" style="flex: 1;">
                    <h2 class="title">Add New Mapping</h2>
                    <form method="post">
                        <?php wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce'); ?>
                        <div style="margin-bottom: 15px;">
                            <label><strong>Domain:</strong></label><br>
                            <input type="text" name="bitstream_rebit_mappings[new][domain]" 
                                   placeholder="example.com" style="width: 100%;" />
                            <small class="description">Enter just the domain (e.g., "twitter.com")</small>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label><strong>Label:</strong></label><br>
                            <input type="text" name="bitstream_rebit_mappings[new][label]" 
                                   placeholder="shared from Example" style="width: 100%;" />
                            <small class="description">Text shown when sharing from this site</small>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label><strong>Icon Class:</strong></label><br>
                            <div style="position: relative;">
                                <input type="text" name="bitstream_rebit_mappings[new][icon]" 
                                       placeholder="fas fa-link" style="width: 100%; padding-right: 40px;" 
                                       id="new-icon-input" />
                                <button type="button" class="button" onclick="openIconPicker('new-icon-input')" 
                                        style="position: absolute; right: 5px; top: 2px; height: 26px; padding: 2px 8px;">
                                    <i class="fas fa-palette"></i>
                                </button>
                            </div>
                            <small class="description">Font Awesome class or use the icon picker</small>
                        </div>
                        <p class="submit" style="margin-top: 15px;">
                            <input type="submit" name="submit" class="button-primary" value="Add Mapping" />
                        </p>
                    </form>
                </div>
            </div>
            
            <!-- Icon Picker Modal -->
            <div id="icon-picker-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; max-width: 800px; max-height: 80vh; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                        <h3 style="margin: 0;">Select an Icon</h3>
                        <button type="button" onclick="closeIconPicker()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <input type="text" id="icon-search" placeholder="Search icons..." style="width: 100%; padding: 8px;" onkeyup="filterIcons()" />
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <button type="button" class="icon-category active" onclick="showCategory('all')" data-category="all">All</button>
                        <button type="button" class="icon-category" onclick="showCategory('brands')" data-category="brands">Brands</button>
                        <button type="button" class="icon-category" onclick="showCategory('solid')" data-category="solid">Solid</button>
                        <button type="button" class="icon-category" onclick="showCategory('regular')" data-category="regular">Regular</button>
                    </div>
                    
                    <div id="icon-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; max-height: 400px; overflow-y: auto;">
                        <!-- Icons will be populated by JavaScript -->
                    </div>
                </div>
            </div>
            
            <p class="description" style="margin-top: 20px;">
                <strong>Icon Help:</strong> Use the icon picker button or manually enter <a href="https://fontawesome.com/icons" target="_blank">Font Awesome icons</a>.
            </p>
        </div>
        
        <script>
        let currentIconInput = null;
        let iconLibrary = { brands: [], solid: [], regular: [] };
        let iconsLoaded = false;
        
        // Function to dynamically extract Font Awesome icons from loaded stylesheets
        function loadFontAwesomeIcons() {
            if (iconsLoaded) return Promise.resolve();
            
            return new Promise((resolve) => {
                const styleSheets = document.styleSheets;
                const foundIcons = { brands: [], solid: [], regular: [] };
                let foundAnyIcons = false;
                
                try {
                    for (let sheet of styleSheets) {
                        try {
                            // Check if this is a Font Awesome stylesheet (local or CDN)
                            if (!sheet.href || (!sheet.href.includes('font-awesome') && !sheet.href.includes('fa'))) continue;
                            
                            const rules = sheet.cssRules || sheet.rules;
                            if (!rules) continue;
                            
                            for (let rule of rules) {
                                if (rule.selectorText && rule.selectorText.includes('::before')) {
                                    const selector = rule.selectorText;
                                    
                                    // Extract FA classes - handle multiple selectors
                                    const matches = selector.split(',');
                                    for (let match of matches) {
                                        match = match.trim();
                                        if (match.includes('.fab.fa-')) {
                                            const iconMatch = match.match(/\.fab\.fa-([^:,\s.]+)/);
                                            if (iconMatch) {
                                                foundIcons.brands.push('fab fa-' + iconMatch[1]);
                                                foundAnyIcons = true;
                                            }
                                        } else if (match.includes('.fas.fa-')) {
                                            const iconMatch = match.match(/\.fas\.fa-([^:,\s.]+)/);
                                            if (iconMatch) {
                                                foundIcons.solid.push('fas fa-' + iconMatch[1]);
                                                foundAnyIcons = true;
                                            }
                                        } else if (match.includes('.far.fa-')) {
                                            const iconMatch = match.match(/\.far\.fa-([^:,\s.]+)/);
                                            if (iconMatch) {
                                                foundIcons.regular.push('far fa-' + iconMatch[1]);
                                                foundAnyIcons = true;
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (e) {
                            // Skip inaccessible stylesheets (CORS issues)
                            console.log('Skipping stylesheet due to CORS:', sheet.href);
                            continue;
                        }
                    }
                } catch (e) {
                    console.log('Error accessing stylesheets:', e);
                }
                
                // If we found icons from local FA, use them; otherwise use expanded fallback
                if (foundAnyIcons && (foundIcons.brands.length > 10 || foundIcons.solid.length > 10)) {
                    iconLibrary = foundIcons;
                    console.log('Loaded', foundIcons.brands.length + foundIcons.solid.length + foundIcons.regular.length, 'icons from Font Awesome stylesheets');
                } else {
                    // Use comprehensive fallback library
                    iconLibrary = getFallbackIcons();
                    console.log('Using fallback icon library with', iconLibrary.brands.length + iconLibrary.solid.length + iconLibrary.regular.length, 'icons');
                }
                
                // Remove duplicates and sort
                Object.keys(iconLibrary).forEach(category => {
                    iconLibrary[category] = [...new Set(iconLibrary[category])].sort();
                });
                
                iconsLoaded = true;
                resolve();
            });
        }
            currentIconInput = document.getElementById(inputId);
            document.getElementById('icon-picker-modal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            showCategory('all');
            document.getElementById('icon-search').value = '';
        }
        
        function closeIconPicker() {
            document.getElementById('icon-picker-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentIconInput = null;
        }
        
        function showCategory(category) {
            // Update active category button
            document.querySelectorAll('.icon-category').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`[data-category="${category}"]`).classList.add('active');
            
            const grid = document.getElementById('icon-grid');
            grid.innerHTML = '';
            
            let iconsToShow = [];
            if (category === 'all') {
                iconsToShow = [...iconLibrary.brands, ...iconLibrary.solid, ...iconLibrary.regular];
            } else {
                iconsToShow = iconLibrary[category] || [];
            }
            
            iconsToShow.forEach(iconClass => {
                const iconDiv = document.createElement('div');
                iconDiv.className = 'icon-option';
                iconDiv.style.cssText = 'padding: 15px; text-align: center; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s;';
                iconDiv.innerHTML = `<i class="${iconClass}" style="font-size: 24px; display: block; margin-bottom: 5px;"></i><small style="font-size: 10px; word-break: break-all;">${iconClass}</small>`;
                
                iconDiv.addEventListener('click', () => selectIcon(iconClass));
                iconDiv.addEventListener('mouseenter', () => {
                    iconDiv.style.backgroundColor = '#f0f0f0';
                    iconDiv.style.borderColor = '#2c6e49';
                });
                iconDiv.addEventListener('mouseleave', () => {
                    iconDiv.style.backgroundColor = '';
                    iconDiv.style.borderColor = '#ddd';
                });
                
                grid.appendChild(iconDiv);
            });
        }
        
        function filterIcons() {
            const searchTerm = document.getElementById('icon-search').value.toLowerCase();
            const iconOptions = document.querySelectorAll('.icon-option');
            
            iconOptions.forEach(option => {
                const iconText = option.textContent.toLowerCase();
                if (iconText.includes(searchTerm)) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
        }
        
        function selectIcon(iconClass) {
            if (currentIconInput) {
                currentIconInput.value = iconClass;
                
                // Update preview if it exists nearby
                const mappingRow = currentIconInput.closest('.mapping-row');
                if (mappingRow) {
                    const preview = mappingRow.querySelector('.mapping-preview i');
                    if (preview) {
                        preview.className = iconClass;
                    }
                }
            }
            closeIconPicker();
        }
        
        function removeMapping(button) {
            const row = button.closest('.mapping-row');
            const removeFlag = row.querySelector('.remove-flag');
            row.style.opacity = '0.5';
            row.style.textDecoration = 'line-through';
            removeFlag.value = '1';
            button.textContent = 'Undo';
            button.onclick = function() { undoRemove(this); };
        }
        
        function undoRemove(button) {
            const row = button.closest('.mapping-row');
            const removeFlag = row.querySelector('.remove-flag');
            row.style.opacity = '1';
            row.style.textDecoration = 'none';
            removeFlag.value = '0';
            button.textContent = 'Remove';
            button.onclick = function() { removeMapping(this); };
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('icon-picker-modal');
            if (event.target === modal) {
                closeIconPicker();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeIconPicker();
            }
        });
        </script>
        
        <style>
        .mapping-row:hover {
            background: #f0f0f1 !important;
        }
        .mapping-preview {
            font-size: 14px;
            line-height: 1.4;
        }
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card .title {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.1em;
        }
        .icon-category {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: #f7f7f7;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .icon-category:hover {
            background: #e7e7e7;
            border-color: #2c6e49;
        }
        .icon-category.active {
            background: #2c6e49;
            color: white;
            border-color: #2c6e49;
        }
        .icon-option:hover {
            background: #f0f0f0 !important;
            border-color: #2c6e49 !important;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(44, 110, 73, 0.2);
        }
        #icon-picker-modal {
            backdrop-filter: blur(2px);
        }
        #icon-grid::-webkit-scrollbar {
            width: 8px;
        }
        #icon-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        #icon-grid::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        #icon-grid::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        </style>
        
        <script>
        // Comprehensive fallback icon list in case CSS parsing fails
        function getFallbackIcons() {
            return {
                brands: [
                    'fab fa-500px', 'fab fa-accessible-icon', 'fab fa-accusoft', 'fab fa-adn', 'fab fa-adobe', 'fab fa-adversal',
                    'fab fa-affiliatetheme', 'fab fa-airbnb', 'fab fa-algolia', 'fab fa-amazon', 'fab fa-amazon-pay', 'fab fa-amilia',
                    'fab fa-android', 'fab fa-angellist', 'fab fa-angrycreative', 'fab fa-angular', 'fab fa-app-store', 'fab fa-app-store-ios',
                    'fab fa-apper', 'fab fa-apple', 'fab fa-apple-pay', 'fab fa-artstation', 'fab fa-asymmetrik', 'fab fa-atlassian',
                    'fab fa-audible', 'fab fa-autoprefixer', 'fab fa-avianex', 'fab fa-aviato', 'fab fa-aws', 'fab fa-bandcamp',
                    'fab fa-battle-net', 'fab fa-behance', 'fab fa-behance-square', 'fab fa-bimobject', 'fab fa-bitbucket', 'fab fa-bitcoin',
                    'fab fa-bity', 'fab fa-black-tie', 'fab fa-blackberry', 'fab fa-blogger', 'fab fa-blogger-b', 'fab fa-bluetooth',
                    'fab fa-bluetooth-b', 'fab fa-bootstrap', 'fab fa-btc', 'fab fa-buffer', 'fab fa-buromobelexperte', 'fab fa-buy-n-large',
                    'fab fa-buysellads', 'fab fa-canadian-maple-leaf', 'fab fa-cc-amazon-payments', 'fab fa-cc-amex', 'fab fa-cc-apple-pay',
                    'fab fa-cc-diners-club', 'fab fa-cc-discover', 'fab fa-cc-jcb', 'fab fa-cc-mastercard', 'fab fa-cc-paypal',
                    'fab fa-cc-stripe', 'fab fa-cc-visa', 'fab fa-centercode', 'fab fa-centos', 'fab fa-chrome', 'fab fa-chromecast',
                    'fab fa-cloudflare', 'fab fa-cloudscale', 'fab fa-cloudsmith', 'fab fa-cloudversify', 'fab fa-codepen', 'fab fa-codiepie',
                    'fab fa-confluence', 'fab fa-connectdevelop', 'fab fa-contao', 'fab fa-cotton-bureau', 'fab fa-cpanel', 'fab fa-creative-commons',
                    'fab fa-css3', 'fab fa-css3-alt', 'fab fa-cuttlefish', 'fab fa-dailymotion', 'fab fa-dashcube', 'fab fa-delicious',
                    'fab fa-deploydog', 'fab fa-deskpro', 'fab fa-dev', 'fab fa-deviantart', 'fab fa-dhl', 'fab fa-diaspora',
                    'fab fa-digg', 'fab fa-digital-ocean', 'fab fa-discord', 'fab fa-discourse', 'fab fa-dochub', 'fab fa-docker',
                    'fab fa-draft2digital', 'fab fa-dribbble', 'fab fa-dribbble-square', 'fab fa-dropbox', 'fab fa-drupal', 'fab fa-dyalog',
                    'fab fa-earlybirds', 'fab fa-ebay', 'fab fa-edge', 'fab fa-elementor', 'fab fa-ello', 'fab fa-ember',
                    'fab fa-empire', 'fab fa-envira', 'fab fa-erlang', 'fab fa-ethereum', 'fab fa-etsy', 'fab fa-evernote',
                    'fab fa-expeditedssl', 'fab fa-facebook', 'fab fa-facebook-f', 'fab fa-facebook-messenger', 'fab fa-facebook-square', 'fab fa-fantasy-flight-games',
                    'fab fa-fedex', 'fab fa-fedora', 'fab fa-figma', 'fab fa-firefox', 'fab fa-firefox-browser', 'fab fa-first-order',
                    'fab fa-first-order-alt', 'fab fa-firstdraft', 'fab fa-flickr', 'fab fa-flipboard', 'fab fa-fly', 'fab fa-font-awesome',
                    'fab fa-font-awesome-alt', 'fab fa-font-awesome-flag', 'fab fa-fonticons', 'fab fa-fonticons-fi', 'fab fa-fort-awesome', 'fab fa-fort-awesome-alt',
                    'fab fa-forumbee', 'fab fa-foursquare', 'fab fa-free-code-camp', 'fab fa-freebsd', 'fab fa-fulcrum', 'fab fa-galactic-republic',
                    'fab fa-galactic-senate', 'fab fa-get-pocket', 'fab fa-gg', 'fab fa-gg-circle', 'fab fa-git', 'fab fa-git-alt',
                    'fab fa-git-square', 'fab fa-github', 'fab fa-github-alt', 'fab fa-github-square', 'fab fa-gitkraken', 'fab fa-gitlab',
                    'fab fa-gitter', 'fab fa-glide', 'fab fa-glide-g', 'fab fa-gofore', 'fab fa-goodreads', 'fab fa-goodreads-g',
                    'fab fa-google', 'fab fa-google-drive', 'fab fa-google-pay', 'fab fa-google-play', 'fab fa-google-plus', 'fab fa-google-plus-g',
                    'fab fa-google-plus-square', 'fab fa-google-wallet', 'fab fa-gratipay', 'fab fa-grav', 'fab fa-gripfire', 'fab fa-grunt',
                    'fab fa-guilded', 'fab fa-gulp', 'fab fa-hacker-news', 'fab fa-hacker-news-square', 'fab fa-hackerrank', 'fab fa-hips',
                    'fab fa-hire-a-helper', 'fab fa-hive', 'fab fa-hooli', 'fab fa-hornbill', 'fab fa-hotjar', 'fab fa-houzz',
                    'fab fa-html5', 'fab fa-hubspot', 'fab fa-ideal', 'fab fa-imdb', 'fab fa-innosoft', 'fab fa-instagram',
                    'fab fa-instagram-square', 'fab fa-instalod', 'fab fa-intercom', 'fab fa-internet-explorer', 'fab fa-invision', 'fab fa-ioxhost',
                    'fab fa-itch-io', 'fab fa-itunes', 'fab fa-itunes-note', 'fab fa-java', 'fab fa-jedi-order', 'fab fa-jenkins',
                    'fab fa-jira', 'fab fa-joget', 'fab fa-joomla', 'fab fa-js', 'fab fa-js-square', 'fab fa-jsfiddle',
                    'fab fa-kaggle', 'fab fa-keybase', 'fab fa-keycdn', 'fab fa-kickstarter', 'fab fa-kickstarter-k', 'fab fa-korvue',
                    'fab fa-laravel', 'fab fa-lastfm', 'fab fa-lastfm-square', 'fab fa-leanpub', 'fab fa-less', 'fab fa-line',
                    'fab fa-linkedin', 'fab fa-linkedin-in', 'fab fa-linode', 'fab fa-linux', 'fab fa-lyft', 'fab fa-magento',
                    'fab fa-mailchimp', 'fab fa-mandalorian', 'fab fa-markdown', 'fab fa-mastodon', 'fab fa-maxcdn', 'fab fa-mdb',
                    'fab fa-medapps', 'fab fa-medium', 'fab fa-medium-m', 'fab fa-medrt', 'fab fa-meetup', 'fab fa-megaport',
                    'fab fa-mendeley', 'fab fa-microblog', 'fab fa-microsoft', 'fab fa-mix', 'fab fa-mixcloud', 'fab fa-mixer',
                    'fab fa-mizuni', 'fab fa-modx', 'fab fa-monero', 'fab fa-napster', 'fab fa-neos', 'fab fa-nimblr',
                    'fab fa-node', 'fab fa-node-js', 'fab fa-npm', 'fab fa-ns8', 'fab fa-nutritionix', 'fab fa-octopus-deploy',
                    'fab fa-odnoklassniki', 'fab fa-odnoklassniki-square', 'fab fa-old-republic', 'fab fa-opencart', 'fab fa-openid', 'fab fa-opera',
                    'fab fa-optin-monster', 'fab fa-orcid', 'fab fa-osi', 'fab fa-page4', 'fab fa-pagelines', 'fab fa-palfed',
                    'fab fa-patreon', 'fab fa-paypal', 'fab fa-penny-arcade', 'fab fa-perbyte', 'fab fa-periscope', 'fab fa-phabricator',
                    'fab fa-phoenix-framework', 'fab fa-phoenix-squadron', 'fab fa-php', 'fab fa-pied-piper', 'fab fa-pied-piper-alt', 'fab fa-pied-piper-hat',
                    'fab fa-pied-piper-pp', 'fab fa-pied-piper-square', 'fab fa-pinterest', 'fab fa-pinterest-p', 'fab fa-pinterest-square', 'fab fa-playstation',
                    'fab fa-product-hunt', 'fab fa-pushed', 'fab fa-python', 'fab fa-qq', 'fab fa-quinscape', 'fab fa-quora',
                    'fab fa-r-project', 'fab fa-raspberry-pi', 'fab fa-ravelry', 'fab fa-react', 'fab fa-reacteurope', 'fab fa-readme',
                    'fab fa-rebel', 'fab fa-red-river', 'fab fa-reddit', 'fab fa-reddit-alien', 'fab fa-reddit-square', 'fab fa-redhat',
                    'fab fa-renren', 'fab fa-replyd', 'fab fa-researchgate', 'fab fa-resolving', 'fab fa-rev', 'fab fa-rocketchat',
                    'fab fa-rockrms', 'fab fa-rust', 'fab fa-safari', 'fab fa-salesforce', 'fab fa-sass', 'fab fa-schlix',
                    'fab fa-scribd', 'fab fa-searchengin', 'fab fa-sellcast', 'fab fa-sellsy', 'fab fa-servicestack', 'fab fa-shirtsinbulk',
                    'fab fa-shopify', 'fab fa-shopware', 'fab fa-simplybuilt', 'fab fa-sistrix', 'fab fa-sith', 'fab fa-sketch',
                    'fab fa-skyatlas', 'fab fa-skype', 'fab fa-slack', 'fab fa-slack-hash', 'fab fa-slideshare', 'fab fa-snapchat',
                    'fab fa-snapchat-ghost', 'fab fa-snapchat-square', 'fab fa-soundcloud', 'fab fa-sourcetree', 'fab fa-speakap', 'fab fa-speaker-deck',
                    'fab fa-spotify', 'fab fa-squarespace', 'fab fa-stack-exchange', 'fab fa-stack-overflow', 'fab fa-stackpath', 'fab fa-staylinked',
                    'fab fa-steam', 'fab fa-steam-square', 'fab fa-steam-symbol', 'fab fa-sticker-mule', 'fab fa-strava', 'fab fa-stripe',
                    'fab fa-stripe-s', 'fab fa-studiovinari', 'fab fa-stumbleupon', 'fab fa-stumbleupon-circle', 'fab fa-superpowers', 'fab fa-supple',
                    'fab fa-suse', 'fab fa-swift', 'fab fa-symfony', 'fab fa-teamspeak', 'fab fa-telegram', 'fab fa-telegram-plane',
                    'fab fa-tencent-weibo', 'fab fa-the-red-yeti', 'fab fa-themeco', 'fab fa-themeisle', 'fab fa-think-peaks', 'fab fa-tiktok',
                    'fab fa-trade-federation', 'fab fa-trello', 'fab fa-tripadvisor', 'fab fa-tumblr', 'fab fa-tumblr-square', 'fab fa-twitch',
                    'fab fa-twitter', 'fab fa-twitter-square', 'fab fa-typo3', 'fab fa-uber', 'fab fa-ubuntu', 'fab fa-uikit',
                    'fab fa-umbraco', 'fab fa-uncharted', 'fab fa-uniregistry', 'fab fa-unity', 'fab fa-untappd', 'fab fa-ups',
                    'fab fa-usb', 'fab fa-usps', 'fab fa-ussunnah', 'fab fa-vaadin', 'fab fa-viacoin', 'fab fa-viadeo',
                    'fab fa-viadeo-square', 'fab fa-viber', 'fab fa-vimeo', 'fab fa-vimeo-square', 'fab fa-vimeo-v', 'fab fa-vine',
                    'fab fa-vk', 'fab fa-vnv', 'fab fa-vuejs', 'fab fa-waze', 'fab fa-weebly', 'fab fa-weibo',
                    'fab fa-weixin', 'fab fa-whatsapp', 'fab fa-whatsapp-square', 'fab fa-whmcs', 'fab fa-wikipedia-w', 'fab fa-windows',
                    'fab fa-wix', 'fab fa-wizards-of-the-coast', 'fab fa-wodu', 'fab fa-wolf-pack-battalion', 'fab fa-wordpress', 'fab fa-wordpress-simple',
                    'fab fa-wpbeginner', 'fab fa-wpexplorer', 'fab fa-wpforms', 'fab fa-wpressr', 'fab fa-x-twitter', 'fab fa-xbox',
                    'fab fa-xing', 'fab fa-xing-square', 'fab fa-y-combinator', 'fab fa-yahoo', 'fab fa-yammer', 'fab fa-yandex',
                    'fab fa-yandex-international', 'fab fa-yarn', 'fab fa-yelp', 'fab fa-yoast', 'fab fa-youtube', 'fab fa-youtube-square',
                    'fab fa-zhihu'
                ],
                solid: [
                    'fas fa-ad', 'fas fa-address-book', 'fas fa-address-card', 'fas fa-adjust', 'fas fa-air-freshener', 'fas fa-align-center',
                    'fas fa-align-justify', 'fas fa-align-left', 'fas fa-align-right', 'fas fa-allergies', 'fas fa-ambulance', 'fas fa-american-sign-language-interpreting',
                    'fas fa-anchor', 'fas fa-angle-double-down', 'fas fa-angle-double-left', 'fas fa-angle-double-right', 'fas fa-angle-double-up', 'fas fa-angle-down',
                    'fas fa-angle-left', 'fas fa-angle-right', 'fas fa-angle-up', 'fas fa-angry', 'fas fa-ankh', 'fas fa-apple-alt',
                    'fas fa-archive', 'fas fa-archway', 'fas fa-arrow-alt-circle-down', 'fas fa-arrow-alt-circle-left', 'fas fa-arrow-alt-circle-right', 'fas fa-arrow-alt-circle-up',
                    'fas fa-arrow-circle-down', 'fas fa-arrow-circle-left', 'fas fa-arrow-circle-right', 'fas fa-arrow-circle-up', 'fas fa-arrow-down', 'fas fa-arrow-left',
                    'fas fa-arrow-right', 'fas fa-arrow-up', 'fas fa-arrows-alt', 'fas fa-arrows-alt-h', 'fas fa-arrows-alt-v', 'fas fa-assistive-listening-systems',
                    'fas fa-asterisk', 'fas fa-at', 'fas fa-atlas', 'fas fa-atom', 'fas fa-audio-description', 'fas fa-award',
                    'fas fa-baby', 'fas fa-baby-carriage', 'fas fa-backspace', 'fas fa-backward', 'fas fa-bacon', 'fas fa-bacteria',
                    'fas fa-bacterium', 'fas fa-bahai', 'fas fa-balance-scale', 'fas fa-balance-scale-left', 'fas fa-balance-scale-right', 'fas fa-ban',
                    'fas fa-band-aid', 'fas fa-barcode', 'fas fa-bars', 'fas fa-baseball-ball', 'fas fa-basketball-ball', 'fas fa-bath',
                    'fas fa-battery-empty', 'fas fa-battery-full', 'fas fa-battery-half', 'fas fa-battery-quarter', 'fas fa-battery-three-quarters', 'fas fa-bed',
                    'fas fa-beer', 'fas fa-bell', 'fas fa-bell-slash', 'fas fa-bezier-curve', 'fas fa-bible', 'fas fa-bicycle',
                    'fas fa-biking', 'fas fa-binoculars', 'fas fa-biohazard', 'fas fa-birthday-cake', 'fas fa-blender', 'fas fa-blender-phone',
                    'fas fa-blind', 'fas fa-blog', 'fas fa-bold', 'fas fa-bolt', 'fas fa-bomb', 'fas fa-bone',
                    'fas fa-bong', 'fas fa-book', 'fas fa-book-dead', 'fas fa-book-medical', 'fas fa-book-open', 'fas fa-book-reader',
                    'fas fa-bookmark', 'fas fa-border-all', 'fas fa-border-none', 'fas fa-border-style', 'fas fa-bowling-ball', 'fas fa-box',
                    'fas fa-box-open', 'fas fa-boxes', 'fas fa-braille', 'fas fa-brain', 'fas fa-bread-slice', 'fas fa-briefcase',
                    'fas fa-briefcase-medical', 'fas fa-broadcast-tower', 'fas fa-broom', 'fas fa-brush', 'fas fa-bug', 'fas fa-building',
                    'fas fa-bullhorn', 'fas fa-bullseye', 'fas fa-burn', 'fas fa-bus', 'fas fa-bus-alt', 'fas fa-business-time',
                    'fas fa-calculator', 'fas fa-calendar', 'fas fa-calendar-alt', 'fas fa-calendar-check', 'fas fa-calendar-day', 'fas fa-calendar-minus',
                    'fas fa-calendar-plus', 'fas fa-calendar-times', 'fas fa-calendar-week', 'fas fa-camera', 'fas fa-camera-retro', 'fas fa-campground',
                    'fas fa-candy-cane', 'fas fa-cannabis', 'fas fa-capsules', 'fas fa-car', 'fas fa-car-alt', 'fas fa-car-battery',
                    'fas fa-car-crash', 'fas fa-car-side', 'fas fa-caravan', 'fas fa-caret-down', 'fas fa-caret-left', 'fas fa-caret-right',
                    'fas fa-caret-square-down', 'fas fa-caret-square-left', 'fas fa-caret-square-right', 'fas fa-caret-square-up', 'fas fa-caret-up', 'fas fa-carrot',
                    'fas fa-cart-arrow-down', 'fas fa-cart-plus', 'fas fa-cash-register', 'fas fa-cat', 'fas fa-certificate', 'fas fa-chair',
                    'fas fa-chalkboard', 'fas fa-chalkboard-teacher', 'fas fa-charging-station', 'fas fa-chart-area', 'fas fa-chart-bar', 'fas fa-chart-line',
                    'fas fa-chart-pie', 'fas fa-check', 'fas fa-check-circle', 'fas fa-check-double', 'fas fa-check-square', 'fas fa-cheese',
                    'fas fa-chess', 'fas fa-chess-bishop', 'fas fa-chess-board', 'fas fa-chess-king', 'fas fa-chess-knight', 'fas fa-chess-pawn',
                    'fas fa-chess-queen', 'fas fa-chess-rook', 'fas fa-chevron-circle-down', 'fas fa-chevron-circle-left', 'fas fa-chevron-circle-right', 'fas fa-chevron-circle-up',
                    'fas fa-chevron-down', 'fas fa-chevron-left', 'fas fa-chevron-right', 'fas fa-chevron-up', 'fas fa-child', 'fas fa-church',
                    'fas fa-circle', 'fas fa-circle-notch', 'fas fa-city', 'fas fa-clinic-medical', 'fas fa-clipboard', 'fas fa-clipboard-check',
                    'fas fa-clipboard-list', 'fas fa-clock', 'fas fa-clone', 'fas fa-closed-captioning', 'fas fa-cloud', 'fas fa-cloud-download-alt',
                    'fas fa-cloud-meatball', 'fas fa-cloud-moon', 'fas fa-cloud-moon-rain', 'fas fa-cloud-rain', 'fas fa-cloud-showers-heavy', 'fas fa-cloud-sun',
                    'fas fa-cloud-sun-rain', 'fas fa-cloud-upload-alt', 'fas fa-cocktail', 'fas fa-code', 'fas fa-code-branch', 'fas fa-coffee',
                    'fas fa-cog', 'fas fa-cogs', 'fas fa-coins', 'fas fa-columns', 'fas fa-comment', 'fas fa-comment-alt',
                    'fas fa-comment-dollar', 'fas fa-comment-dots', 'fas fa-comment-medical', 'fas fa-comment-slash', 'fas fa-comments', 'fas fa-comments-dollar',
                    'fas fa-compact-disc', 'fas fa-compass', 'fas fa-compress', 'fas fa-compress-alt', 'fas fa-compress-arrows-alt', 'fas fa-concierge-bell',
                    'fas fa-cookie', 'fas fa-cookie-bite', 'fas fa-copy', 'fas fa-copyright', 'fas fa-couch', 'fas fa-credit-card',
                    'fas fa-crop', 'fas fa-crop-alt', 'fas fa-cross', 'fas fa-crosshairs', 'fas fa-crow', 'fas fa-crown',
                    'fas fa-crutch', 'fas fa-cube', 'fas fa-cubes', 'fas fa-cut', 'fas fa-database', 'fas fa-deaf',
                    'fas fa-democrat', 'fas fa-desktop', 'fas fa-dharmachakra', 'fas fa-diagnoses', 'fas fa-dice', 'fas fa-dice-d20',
                    'fas fa-dice-d6', 'fas fa-dice-five', 'fas fa-dice-four', 'fas fa-dice-one', 'fas fa-dice-six', 'fas fa-dice-three',
                    'fas fa-dice-two', 'fas fa-digital-tachograph', 'fas fa-directions', 'fas fa-disease', 'fas fa-divide', 'fas fa-dizzy',
                    'fas fa-dna', 'fas fa-dog', 'fas fa-dollar-sign', 'fas fa-dolly', 'fas fa-dolly-flatbed', 'fas fa-donate',
                    'fas fa-door-closed', 'fas fa-door-open', 'fas fa-dot-circle', 'fas fa-dove', 'fas fa-download', 'fas fa-drafting-compass',
                    'fas fa-dragon', 'fas fa-draw-polygon', 'fas fa-drum', 'fas fa-drum-steelpan', 'fas fa-drumstick-bite', 'fas fa-dumbbell',
                    'fas fa-dumpster', 'fas fa-dumpster-fire', 'fas fa-dungeon', 'fas fa-edit', 'fas fa-egg', 'fas fa-eject',
                    'fas fa-ellipsis-h', 'fas fa-ellipsis-v', 'fas fa-envelope', 'fas fa-envelope-open', 'fas fa-envelope-open-text', 'fas fa-envelope-square',
                    'fas fa-equals', 'fas fa-eraser', 'fas fa-ethernet', 'fas fa-euro-sign', 'fas fa-exchange-alt', 'fas fa-exclamation',
                    'fas fa-exclamation-circle', 'fas fa-exclamation-triangle', 'fas fa-expand', 'fas fa-expand-alt', 'fas fa-expand-arrows-alt', 'fas fa-external-link-alt',
                    'fas fa-external-link-square-alt', 'fas fa-eye', 'fas fa-eye-dropper', 'fas fa-eye-slash', 'fas fa-fan', 'fas fa-fast-backward',
                    'fas fa-fast-forward', 'fas fa-faucet', 'fas fa-fax', 'fas fa-feather', 'fas fa-feather-alt', 'fas fa-female',
                    'fas fa-fighter-jet', 'fas fa-file', 'fas fa-file-alt', 'fas fa-file-archive', 'fas fa-file-audio', 'fas fa-file-code',
                    'fas fa-file-contract', 'fas fa-file-csv', 'fas fa-file-download', 'fas fa-file-excel', 'fas fa-file-export', 'fas fa-file-image',
                    'fas fa-file-import', 'fas fa-file-invoice', 'fas fa-file-invoice-dollar', 'fas fa-file-medical', 'fas fa-file-medical-alt', 'fas fa-file-pdf',
                    'fas fa-file-powerpoint', 'fas fa-file-prescription', 'fas fa-file-signature', 'fas fa-file-upload', 'fas fa-file-video', 'fas fa-file-word',
                    'fas fa-fill', 'fas fa-fill-drip', 'fas fa-film', 'fas fa-filter', 'fas fa-fingerprint', 'fas fa-fire',
                    'fas fa-fire-alt', 'fas fa-fire-extinguisher', 'fas fa-first-aid', 'fas fa-fish', 'fas fa-fist-raised', 'fas fa-flag',
                    'fas fa-flag-checkered', 'fas fa-flag-usa', 'fas fa-flask', 'fas fa-flushed', 'fas fa-folder', 'fas fa-folder-minus',
                    'fas fa-folder-open', 'fas fa-folder-plus', 'fas fa-font', 'fas fa-football-ball', 'fas fa-forward', 'fas fa-frog',
                    'fas fa-frown', 'fas fa-frown-open', 'fas fa-funnel-dollar', 'fas fa-futbol', 'fas fa-gamepad', 'fas fa-gas-pump',
                    'fas fa-gavel', 'fas fa-gem', 'fas fa-genderless', 'fas fa-ghost', 'fas fa-gift', 'fas fa-gifts',
                    'fas fa-glass-cheers', 'fas fa-glass-martini', 'fas fa-glass-martini-alt', 'fas fa-glass-whiskey', 'fas fa-glasses', 'fas fa-globe',
                    'fas fa-globe-africa', 'fas fa-globe-americas', 'fas fa-globe-asia', 'fas fa-globe-europe', 'fas fa-golf-ball', 'fas fa-gopuram',
                    'fas fa-graduation-cap', 'fas fa-greater-than', 'fas fa-greater-than-equal', 'fas fa-grimace', 'fas fa-grin', 'fas fa-grin-alt',
                    'fas fa-grin-beam', 'fas fa-grin-beam-sweat', 'fas fa-grin-hearts', 'fas fa-grin-squint', 'fas fa-grin-squint-tears', 'fas fa-grin-stars',
                    'fas fa-grin-tears', 'fas fa-grin-tongue', 'fas fa-grin-tongue-squint', 'fas fa-grin-tongue-wink', 'fas fa-grin-wink', 'fas fa-grip-horizontal',
                    'fas fa-grip-lines', 'fas fa-grip-lines-vertical', 'fas fa-grip-vertical', 'fas fa-guitar', 'fas fa-h-square', 'fas fa-hamburger',
                    'fas fa-hammer', 'fas fa-hamsa', 'fas fa-hand-holding', 'fas fa-hand-holding-heart', 'fas fa-hand-holding-medical', 'fas fa-hand-holding-usd',
                    'fas fa-hand-holding-water', 'fas fa-hand-lizard', 'fas fa-hand-middle-finger', 'fas fa-hand-paper', 'fas fa-hand-peace', 'fas fa-hand-point-down',
                    'fas fa-hand-point-left', 'fas fa-hand-point-right', 'fas fa-hand-point-up', 'fas fa-hand-pointer', 'fas fa-hand-rock', 'fas fa-hand-scissors',
                    'fas fa-hand-sparkles', 'fas fa-hand-spock', 'fas fa-hands', 'fas fa-hands-helping', 'fas fa-hands-wash', 'fas fa-handshake',
                    'fas fa-handshake-alt-slash', 'fas fa-handshake-slash', 'fas fa-hanukiah', 'fas fa-hard-hat', 'fas fa-hashtag', 'fas fa-hat-cowboy',
                    'fas fa-hat-cowboy-side', 'fas fa-hat-wizard', 'fas fa-hdd', 'fas fa-head-side-cough', 'fas fa-head-side-cough-slash', 'fas fa-head-side-mask',
                    'fas fa-head-side-virus', 'fas fa-heading', 'fas fa-headphones', 'fas fa-headphones-alt', 'fas fa-headset', 'fas fa-heart',
                    'fas fa-heart-broken', 'fas fa-heartbeat', 'fas fa-helicopter', 'fas fa-highlighter', 'fas fa-hiking', 'fas fa-hippo',
                    'fas fa-history', 'fas fa-hockey-puck', 'fas fa-holly-berry', 'fas fa-home', 'fas fa-horse', 'fas fa-horse-head',
                    'fas fa-hospital', 'fas fa-hospital-alt', 'fas fa-hospital-symbol', 'fas fa-hospital-user', 'fas fa-hot-tub', 'fas fa-hotdog',
                    'fas fa-hotel', 'fas fa-hourglass', 'fas fa-hourglass-end', 'fas fa-hourglass-half', 'fas fa-hourglass-start', 'fas fa-house-damage',
                    'fas fa-house-user', 'fas fa-hryvnia', 'fas fa-i-cursor', 'fas fa-ice-cream', 'fas fa-icicles', 'fas fa-icons',
                    'fas fa-id-badge', 'fas fa-id-card', 'fas fa-id-card-alt', 'fas fa-igloo', 'fas fa-image', 'fas fa-images',
                    'fas fa-inbox', 'fas fa-indent', 'fas fa-industry', 'fas fa-infinity', 'fas fa-info', 'fas fa-info-circle',
                    'fas fa-italic', 'fas fa-jedi', 'fas fa-joint', 'fas fa-journal-whills', 'fas fa-kaaba', 'fas fa-key',
                    'fas fa-keyboard', 'fas fa-khanda', 'fas fa-kiss', 'fas fa-kiss-beam', 'fas fa-kiss-wink-heart', 'fas fa-kiwi-bird',
                    'fas fa-landmark', 'fas fa-language', 'fas fa-laptop', 'fas fa-laptop-code', 'fas fa-laptop-house', 'fas fa-laptop-medical',
                    'fas fa-laugh', 'fas fa-laugh-beam', 'fas fa-laugh-squint', 'fas fa-laugh-wink', 'fas fa-layer-group', 'fas fa-leaf',
                    'fas fa-lemon', 'fas fa-less-than', 'fas fa-less-than-equal', 'fas fa-level-down-alt', 'fas fa-level-up-alt', 'fas fa-life-ring',
                    'fas fa-lightbulb', 'fas fa-link', 'fas fa-lira-sign', 'fas fa-list', 'fas fa-list-alt', 'fas fa-list-ol',
                    'fas fa-list-ul', 'fas fa-location-arrow', 'fas fa-lock', 'fas fa-lock-open', 'fas fa-long-arrow-alt-down', 'fas fa-long-arrow-alt-left',
                    'fas fa-long-arrow-alt-right', 'fas fa-long-arrow-alt-up', 'fas fa-low-vision', 'fas fa-luggage-cart', 'fas fa-lungs', 'fas fa-lungs-virus',
                    'fas fa-magic', 'fas fa-magnet', 'fas fa-mail-bulk', 'fas fa-male', 'fas fa-map', 'fas fa-map-marked',
                    'fas fa-map-marked-alt', 'fas fa-map-marker', 'fas fa-map-marker-alt', 'fas fa-map-pin', 'fas fa-map-signs', 'fas fa-marker',
                    'fas fa-mars', 'fas fa-mars-double', 'fas fa-mars-stroke', 'fas fa-mars-stroke-h', 'fas fa-mars-stroke-v', 'fas fa-mask',
                    'fas fa-medal', 'fas fa-medkit', 'fas fa-meh', 'fas fa-meh-blank', 'fas fa-meh-rolling-eyes', 'fas fa-memory',
                    'fas fa-menorah', 'fas fa-mercury', 'fas fa-meteor', 'fas fa-microchip', 'fas fa-microphone', 'fas fa-microphone-alt',
                    'fas fa-microphone-alt-slash', 'fas fa-microphone-slash', 'fas fa-microscope', 'fas fa-minus', 'fas fa-minus-circle', 'fas fa-minus-square',
                    'fas fa-mitten', 'fas fa-mobile', 'fas fa-mobile-alt', 'fas fa-money-bill', 'fas fa-money-bill-alt', 'fas fa-money-bill-wave',
                    'fas fa-money-bill-wave-alt', 'fas fa-money-check', 'fas fa-money-check-alt', 'fas fa-monument', 'fas fa-moon', 'fas fa-mortar-pestle',
                    'fas fa-mosque', 'fas fa-motorcycle', 'fas fa-mountain', 'fas fa-mouse', 'fas fa-mouse-pointer', 'fas fa-mug-hot',
                    'fas fa-music', 'fas fa-network-wired', 'fas fa-neuter', 'fas fa-newspaper', 'fas fa-not-equal', 'fas fa-notes-medical',
                    'fas fa-object-group', 'fas fa-object-ungroup', 'fas fa-oil-can', 'fas fa-om', 'fas fa-otter', 'fas fa-outdent',
                    'fas fa-pager', 'fas fa-paint-brush', 'fas fa-paint-roller', 'fas fa-palette', 'fas fa-pallet', 'fas fa-paper-plane',
                    'fas fa-paperclip', 'fas fa-parachute-box', 'fas fa-paragraph', 'fas fa-parking', 'fas fa-passport', 'fas fa-pastafarianism',
                    'fas fa-paste', 'fas fa-pause', 'fas fa-pause-circle', 'fas fa-paw', 'fas fa-peace', 'fas fa-pen',
                    'fas fa-pen-alt', 'fas fa-pen-fancy', 'fas fa-pen-nib', 'fas fa-pen-square', 'fas fa-pencil-alt', 'fas fa-pencil-ruler',
                    'fas fa-people-arrows', 'fas fa-people-carry', 'fas fa-pepper-hot', 'fas fa-percent', 'fas fa-percentage', 'fas fa-person-booth',
                    'fas fa-phone', 'fas fa-phone-alt', 'fas fa-phone-slash', 'fas fa-phone-square', 'fas fa-phone-square-alt', 'fas fa-phone-volume',
                    'fas fa-photo-video', 'fas fa-piggy-bank', 'fas fa-pills', 'fas fa-pizza-slice', 'fas fa-place-of-worship', 'fas fa-plane',
                    'fas fa-plane-arrival', 'fas fa-plane-departure', 'fas fa-plane-slash', 'fas fa-play', 'fas fa-play-circle', 'fas fa-plug',
                    'fas fa-plus', 'fas fa-plus-circle', 'fas fa-plus-square', 'fas fa-podcast', 'fas fa-poll', 'fas fa-poll-h',
                    'fas fa-poo', 'fas fa-poo-storm', 'fas fa-poop', 'fas fa-portrait', 'fas fa-pound-sign', 'fas fa-power-off',
                    'fas fa-pray', 'fas fa-praying-hands', 'fas fa-prescription', 'fas fa-prescription-bottle', 'fas fa-prescription-bottle-alt', 'fas fa-print',
                    'fas fa-procedures', 'fas fa-project-diagram', 'fas fa-pump-medical', 'fas fa-pump-soap', 'fas fa-puzzle-piece', 'fas fa-qrcode',
                    'fas fa-question', 'fas fa-question-circle', 'fas fa-quidditch', 'fas fa-quote-left', 'fas fa-quote-right', 'fas fa-quran',
                    'fas fa-radiation', 'fas fa-radiation-alt', 'fas fa-rainbow', 'fas fa-random', 'fas fa-receipt', 'fas fa-record-vinyl',
                    'fas fa-recycle', 'fas fa-redo', 'fas fa-redo-alt', 'fas fa-registered', 'fas fa-remove-format', 'fas fa-reply',
                    'fas fa-reply-all', 'fas fa-republican', 'fas fa-restroom', 'fas fa-retweet', 'fas fa-ribbon', 'fas fa-ring',
                    'fas fa-road', 'fas fa-robot', 'fas fa-rocket', 'fas fa-route', 'fas fa-rss', 'fas fa-rss-square',
                    'fas fa-ruble-sign', 'fas fa-ruler', 'fas fa-ruler-combined', 'fas fa-ruler-horizontal', 'fas fa-ruler-vertical', 'fas fa-running',
                    'fas fa-rupee-sign', 'fas fa-sad-cry', 'fas fa-sad-tear', 'fas fa-satellite', 'fas fa-satellite-dish', 'fas fa-save',
                    'fas fa-school', 'fas fa-screwdriver', 'fas fa-scroll', 'fas fa-sd-card', 'fas fa-search', 'fas fa-search-dollar',
                    'fas fa-search-location', 'fas fa-search-minus', 'fas fa-search-plus', 'fas fa-seedling', 'fas fa-server', 'fas fa-shapes',
                    'fas fa-share', 'fas fa-share-alt', 'fas fa-share-alt-square', 'fas fa-share-square', 'fas fa-shekel-sign', 'fas fa-shield-alt',
                    'fas fa-shield-virus', 'fas fa-ship', 'fas fa-shipping-fast', 'fas fa-shoe-prints', 'fas fa-shopping-bag', 'fas fa-shopping-basket',
                    'fas fa-shopping-cart', 'fas fa-shower', 'fas fa-shuttle-van', 'fas fa-sign', 'fas fa-sign-in-alt', 'fas fa-sign-language',
                    'fas fa-sign-out-alt', 'fas fa-signal', 'fas fa-signature', 'fas fa-sim-card', 'fas fa-sitemap', 'fas fa-skating',
                    'fas fa-skiing', 'fas fa-skiing-nordic', 'fas fa-skull', 'fas fa-skull-crossbones', 'fas fa-slash', 'fas fa-sleigh',
                    'fas fa-sliders-h', 'fas fa-smile', 'fas fa-smile-beam', 'fas fa-smile-wink', 'fas fa-smog', 'fas fa-smoking',
                    'fas fa-smoking-ban', 'fas fa-sms', 'fas fa-snowboarding', 'fas fa-snowflake', 'fas fa-snowman', 'fas fa-snowplow',
                    'fas fa-soap', 'fas fa-socks', 'fas fa-solar-panel', 'fas fa-sort', 'fas fa-sort-alpha-down', 'fas fa-sort-alpha-down-alt',
                    'fas fa-sort-alpha-up', 'fas fa-sort-alpha-up-alt', 'fas fa-sort-amount-down', 'fas fa-sort-amount-down-alt', 'fas fa-sort-amount-up', 'fas fa-sort-amount-up-alt',
                    'fas fa-sort-down', 'fas fa-sort-numeric-down', 'fas fa-sort-numeric-down-alt', 'fas fa-sort-numeric-up', 'fas fa-sort-numeric-up-alt', 'fas fa-sort-up',
                    'fas fa-spa', 'fas fa-space-shuttle', 'fas fa-spell-check', 'fas fa-spider', 'fas fa-spinner', 'fas fa-splotch',
                    'fas fa-spray-can', 'fas fa-square', 'fas fa-square-full', 'fas fa-square-root-alt', 'fas fa-stamp', 'fas fa-star',
                    'fas fa-star-and-crescent', 'fas fa-star-half', 'fas fa-star-half-alt', 'fas fa-star-of-david', 'fas fa-star-of-life', 'fas fa-step-backward',
                    'fas fa-step-forward', 'fas fa-stethoscope', 'fas fa-sticky-note', 'fas fa-stop', 'fas fa-stop-circle', 'fas fa-stopwatch',
                    'fas fa-stopwatch-20', 'fas fa-store', 'fas fa-store-alt', 'fas fa-store-alt-slash', 'fas fa-store-slash', 'fas fa-stream',
                    'fas fa-street-view', 'fas fa-strikethrough', 'fas fa-stroopwafel', 'fas fa-subscript', 'fas fa-subway', 'fas fa-suitcase',
                    'fas fa-suitcase-rolling', 'fas fa-sun', 'fas fa-superscript', 'fas fa-surprise', 'fas fa-swatchbook', 'fas fa-swimmer',
                    'fas fa-swimming-pool', 'fas fa-synagogue', 'fas fa-sync', 'fas fa-sync-alt', 'fas fa-syringe', 'fas fa-table',
                    'fas fa-table-tennis', 'fas fa-tablet', 'fas fa-tablet-alt', 'fas fa-tablets', 'fas fa-tachometer-alt', 'fas fa-tag',
                    'fas fa-tags', 'fas fa-tape', 'fas fa-tasks', 'fas fa-taxi', 'fas fa-teeth', 'fas fa-teeth-open',
                    'fas fa-temperature-high', 'fas fa-temperature-low', 'fas fa-tenge', 'fas fa-terminal', 'fas fa-text-height', 'fas fa-text-width',
                    'fas fa-th', 'fas fa-th-large', 'fas fa-th-list', 'fas fa-theater-masks', 'fas fa-thermometer', 'fas fa-thermometer-empty',
                    'fas fa-thermometer-full', 'fas fa-thermometer-half', 'fas fa-thermometer-quarter', 'fas fa-thermometer-three-quarters', 'fas fa-thumbs-down', 'fas fa-thumbs-up',
                    'fas fa-thumbtack', 'fas fa-ticket-alt', 'fas fa-times', 'fas fa-times-circle', 'fas fa-tint', 'fas fa-tint-slash',
                    'fas fa-tired', 'fas fa-toggle-off', 'fas fa-toggle-on', 'fas fa-toilet', 'fas fa-toilet-paper', 'fas fa-toilet-paper-slash',
                    'fas fa-toolbox', 'fas fa-tools', 'fas fa-tooth', 'fas fa-torah', 'fas fa-torii-gate', 'fas fa-tractor',
                    'fas fa-trademark', 'fas fa-traffic-light', 'fas fa-trailer', 'fas fa-train', 'fas fa-tram', 'fas fa-transgender',
                    'fas fa-transgender-alt', 'fas fa-trash', 'fas fa-trash-alt', 'fas fa-trash-restore', 'fas fa-trash-restore-alt', 'fas fa-tree',
                    'fas fa-trophy', 'fas fa-truck', 'fas fa-truck-loading', 'fas fa-truck-monster', 'fas fa-truck-moving', 'fas fa-truck-pickup',
                    'fas fa-tshirt', 'fas fa-tty', 'fas fa-tv', 'fas fa-umbrella', 'fas fa-umbrella-beach', 'fas fa-underline',
                    'fas fa-undo', 'fas fa-undo-alt', 'fas fa-universal-access', 'fas fa-university', 'fas fa-unlink', 'fas fa-unlock',
                    'fas fa-unlock-alt', 'fas fa-upload', 'fas fa-user', 'fas fa-user-alt', 'fas fa-user-alt-slash', 'fas fa-user-astronaut',
                    'fas fa-user-check', 'fas fa-user-circle', 'fas fa-user-clock', 'fas fa-user-cog', 'fas fa-user-edit', 'fas fa-user-friends',
                    'fas fa-user-graduate', 'fas fa-user-injured', 'fas fa-user-lock', 'fas fa-user-md', 'fas fa-user-minus', 'fas fa-user-ninja',
                    'fas fa-user-nurse', 'fas fa-user-plus', 'fas fa-user-secret', 'fas fa-user-shield', 'fas fa-user-slash', 'fas fa-user-tag',
                    'fas fa-user-tie', 'fas fa-user-times', 'fas fa-users', 'fas fa-users-cog', 'fas fa-users-slash', 'fas fa-utensil-spoon',
                    'fas fa-utensils', 'fas fa-vector-square', 'fas fa-venus', 'fas fa-venus-double', 'fas fa-venus-mars', 'fas fa-vest',
                    'fas fa-vest-patches', 'fas fa-vial', 'fas fa-vials', 'fas fa-video', 'fas fa-video-slash', 'fas fa-vihara',
                    'fas fa-virus', 'fas fa-virus-slash', 'fas fa-viruses', 'fas fa-voicemail', 'fas fa-volleyball-ball', 'fas fa-volume-down',
                    'fas fa-volume-mute', 'fas fa-volume-off', 'fas fa-volume-up', 'fas fa-vote-yea', 'fas fa-vr-cardboard', 'fas fa-walking',
                    'fas fa-wallet', 'fas fa-warehouse', 'fas fa-water', 'fas fa-wave-square', 'fas fa-weight', 'fas fa-weight-hanging',
                    'fas fa-wheelchair', 'fas fa-wifi', 'fas fa-wind', 'fas fa-window-close', 'fas fa-window-maximize', 'fas fa-window-minimize',
                    'fas fa-window-restore', 'fas fa-wine-bottle', 'fas fa-wine-glass', 'fas fa-wine-glass-alt', 'fas fa-won-sign', 'fas fa-wrench',
                    'fas fa-x-ray', 'fas fa-yen-sign', 'fas fa-yin-yang'
                ],
                regular: [
                    'far fa-address-book', 'far fa-address-card', 'far fa-angry', 'far fa-arrow-alt-circle-down', 'far fa-arrow-alt-circle-left', 'far fa-arrow-alt-circle-right',
                    'far fa-arrow-alt-circle-up', 'far fa-bell', 'far fa-bell-slash', 'far fa-bookmark', 'far fa-building', 'far fa-calendar',
                    'far fa-calendar-alt', 'far fa-calendar-check', 'far fa-calendar-minus', 'far fa-calendar-plus', 'far fa-calendar-times', 'far fa-caret-square-down',
                    'far fa-caret-square-left', 'far fa-caret-square-right', 'far fa-caret-square-up', 'far fa-chart-bar', 'far fa-check-circle', 'far fa-check-square',
                    'far fa-circle', 'far fa-clipboard', 'far fa-clock', 'far fa-clone', 'far fa-closed-captioning', 'far fa-comment',
                    'far fa-comment-alt', 'far fa-comment-dots', 'far fa-comments', 'far fa-compass', 'far fa-copy', 'far fa-copyright',
                    'far fa-credit-card', 'far fa-dizzy', 'far fa-dot-circle', 'far fa-edit', 'far fa-envelope', 'far fa-envelope-open',
                    'far fa-eye', 'far fa-eye-slash', 'far fa-file', 'far fa-file-alt', 'far fa-file-archive', 'far fa-file-audio',
                    'far fa-file-code', 'far fa-file-excel', 'far fa-file-image', 'far fa-file-pdf', 'far fa-file-powerpoint', 'far fa-file-video',
                    'far fa-file-word', 'far fa-flag', 'far fa-flushed', 'far fa-folder', 'far fa-folder-open', 'far fa-frown',
                    'far fa-frown-open', 'far fa-futbol', 'far fa-gem', 'far fa-grimace', 'far fa-grin', 'far fa-grin-alt',
                    'far fa-grin-beam', 'far fa-grin-beam-sweat', 'far fa-grin-hearts', 'far fa-grin-squint', 'far fa-grin-squint-tears', 'far fa-grin-stars',
                    'far fa-grin-tears', 'far fa-grin-tongue', 'far fa-grin-tongue-squint', 'far fa-grin-tongue-wink', 'far fa-grin-wink', 'far fa-hand-lizard',
                    'far fa-hand-paper', 'far fa-hand-peace', 'far fa-hand-point-down', 'far fa-hand-point-left', 'far fa-hand-point-right', 'far fa-hand-point-up',
                    'far fa-hand-pointer', 'far fa-hand-rock', 'far fa-hand-scissors', 'far fa-hand-spock', 'far fa-handshake', 'far fa-hdd',
                    'far fa-heart', 'far fa-hospital', 'far fa-hourglass', 'far fa-id-badge', 'far fa-id-card', 'far fa-image',
                    'far fa-images', 'far fa-keyboard', 'far fa-kiss', 'far fa-kiss-beam', 'far fa-kiss-wink-heart', 'far fa-laugh',
                    'far fa-laugh-beam', 'far fa-laugh-squint', 'far fa-laugh-wink', 'far fa-lemon', 'far fa-life-ring', 'far fa-lightbulb',
                    'far fa-list-alt', 'far fa-map', 'far fa-meh', 'far fa-meh-blank', 'far fa-meh-rolling-eyes', 'far fa-minus-square',
                    'far fa-money-bill-alt', 'far fa-moon', 'far fa-newspaper', 'far fa-object-group', 'far fa-object-ungroup', 'far fa-paper-plane',
                    'far fa-pause-circle', 'far fa-play-circle', 'far fa-plus-square', 'far fa-question-circle', 'far fa-registered', 'far fa-sad-cry',
                    'far fa-sad-tear', 'far fa-save', 'far fa-share-square', 'far fa-smile', 'far fa-smile-beam', 'far fa-smile-wink',
                    'far fa-snowflake', 'far fa-square', 'far fa-star', 'far fa-star-half', 'far fa-sticky-note', 'far fa-stop-circle',
                    'far fa-sun', 'far fa-surprise', 'far fa-thumbs-down', 'far fa-thumbs-up', 'far fa-times-circle', 'far fa-tired',
                    'far fa-trash-alt', 'far fa-user', 'far fa-user-circle', 'far fa-window-close', 'far fa-window-maximize', 'far fa-window-minimize',
                    'far fa-window-restore'
                ]
            };
        }
        
        function openIconPicker(inputId) {
            currentIconInput = document.getElementById(inputId);
            document.getElementById('icon-picker-modal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Try to load FA icons, fallback if needed
            loadFontAwesomeIcons().then(() => {
                showCategory('all');
                document.getElementById('icon-search').value = '';
            });
        }
        
        function closeIconPicker() {
            document.getElementById('icon-picker-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentIconInput = null;
        }
        
        function showCategory(category) {
            // Update active category button
            document.querySelectorAll('.icon-category').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`[data-category="${category}"]`).classList.add('active');
            
            const grid = document.getElementById('icon-grid');
            grid.innerHTML = '';
            
            let iconsToShow = [];
            if (category === 'all') {
                iconsToShow = [...iconLibrary.brands, ...iconLibrary.solid, ...iconLibrary.regular];
            } else {
                iconsToShow = iconLibrary[category] || [];
            }
            
            iconsToShow.forEach(iconClass => {
                const iconDiv = document.createElement('div');
                iconDiv.className = 'icon-option';
                iconDiv.style.cssText = 'padding: 15px; text-align: center; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s;';
                iconDiv.innerHTML = `<i class="${iconClass}" style="font-size: 24px; display: block; margin-bottom: 5px;"></i><small style="font-size: 10px; word-break: break-all;">${iconClass}</small>`;
                
                iconDiv.addEventListener('click', () => selectIcon(iconClass));
                iconDiv.addEventListener('mouseenter', () => {
                    iconDiv.style.backgroundColor = '#f0f0f0';
                    iconDiv.style.borderColor = '#2c6e49';
                });
                iconDiv.addEventListener('mouseleave', () => {
                    iconDiv.style.backgroundColor = '';
                    iconDiv.style.borderColor = '#ddd';
                });
                
                grid.appendChild(iconDiv);
            });
        }
        
        function filterIcons() {
            const searchTerm = document.getElementById('icon-search').value.toLowerCase();
            const iconOptions = document.querySelectorAll('.icon-option');
            
            iconOptions.forEach(option => {
                const iconText = option.textContent.toLowerCase();
                if (iconText.includes(searchTerm)) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
        }
        
        function selectIcon(iconClass) {
            if (currentIconInput) {
                currentIconInput.value = iconClass;
                
                // Update preview if it exists nearby
                const mappingRow = currentIconInput.closest('.mapping-row');
                if (mappingRow) {
                    const preview = mappingRow.querySelector('.mapping-preview i');
                    if (preview) {
                        preview.className = iconClass;
                    }
                }
            }
            closeIconPicker();
        }
        
        function removeMapping(button) {
            const row = button.closest('.mapping-row');
            const removeFlag = row.querySelector('.remove-flag');
            row.style.opacity = '0.5';
            row.style.textDecoration = 'line-through';
            removeFlag.value = '1';
            button.textContent = 'Undo';
            button.onclick = function() { undoRemove(this); };
        }
        
        function undoRemove(button) {
            const row = button.closest('.mapping-row');
            const removeFlag = row.querySelector('.remove-flag');
            row.style.opacity = '1';
            row.style.textDecoration = 'none';
            removeFlag.value = '0';
            button.textContent = 'Remove';
            button.onclick = function() { removeMapping(this); };
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('icon-picker-modal');
            if (event.target === modal) {
                closeIconPicker();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeIconPicker();
            }
        });
        </script>
        <?php
    }
    
    /**
     * Get preset ReBit mappings for popular sites
     */
    private function get_rebit_presets() {
        return [
            'twitter' => ['domain' => 'twitter.com', 'label' => 'shared from Twitter', 'icon' => 'fab fa-twitter'],
            'x' => ['domain' => 'x.com', 'label' => 'shared from X', 'icon' => 'fab fa-x-twitter'],
            'youtube' => ['domain' => 'youtube.com', 'label' => 'shared from YouTube', 'icon' => 'fab fa-youtube'],
            'github' => ['domain' => 'github.com', 'label' => 'shared from GitHub', 'icon' => 'fab fa-github'],
            'linkedin' => ['domain' => 'linkedin.com', 'label' => 'shared from LinkedIn', 'icon' => 'fab fa-linkedin'],
            'facebook' => ['domain' => 'facebook.com', 'label' => 'shared from Facebook', 'icon' => 'fab fa-facebook'],
            'instagram' => ['domain' => 'instagram.com', 'label' => 'shared from Instagram', 'icon' => 'fab fa-instagram'],
            'tiktok' => ['domain' => 'tiktok.com', 'label' => 'shared from TikTok', 'icon' => 'fab fa-tiktok'],
            'reddit' => ['domain' => 'reddit.com', 'label' => 'shared from Reddit', 'icon' => 'fab fa-reddit'],
            'medium' => ['domain' => 'medium.com', 'label' => 'shared from Medium', 'icon' => 'fab fa-medium'],
            'dev' => ['domain' => 'dev.to', 'label' => 'shared from DEV', 'icon' => 'fab fa-dev'],
            'hackernews' => ['domain' => 'news.ycombinator.com', 'label' => 'shared from Hacker News', 'icon' => 'fab fa-hacker-news'],
            'stackoverflow' => ['domain' => 'stackoverflow.com', 'label' => 'shared from Stack Overflow', 'icon' => 'fab fa-stack-overflow'],
            'wikipedia' => ['domain' => 'wikipedia.org', 'label' => 'shared from Wikipedia', 'icon' => 'fab fa-wikipedia-w'],
            'bbc' => ['domain' => 'bbc.com', 'label' => 'shared from BBC', 'icon' => 'fas fa-newspaper'],
            'cnn' => ['domain' => 'cnn.com', 'label' => 'shared from CNN', 'icon' => 'fas fa-newspaper'],
            'nytimes' => ['domain' => 'nytimes.com', 'label' => 'shared from NY Times', 'icon' => 'fas fa-newspaper'],
            'spotify' => ['domain' => 'spotify.com', 'label' => 'shared from Spotify', 'icon' => 'fab fa-spotify'],
            'twitch' => ['domain' => 'twitch.tv', 'label' => 'shared from Twitch', 'icon' => 'fab fa-twitch'],
            'discord' => ['domain' => 'discord.com', 'label' => 'shared from Discord', 'icon' => 'fab fa-discord'],
        ];
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
     * Add custom query vars for Service Worker routing
     */
    public function add_query_vars($vars) {
        $vars[] = 'bitstream_sw';
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
    
    /**
     * Register RSS feeds for BitStream content
     */
    public function add_rss_feeds() {
        add_feed('bitstream', [$this, 'bitstream_rss_feed']);
        add_feed('bitstream-bits', [$this, 'bitstream_bits_rss_feed']);
        add_feed('bitstream-rebits', [$this, 'bitstream_rebits_rss_feed']);
    }
    
    /**
     * Add RSS feed links to HTML head
     */
    public function add_rss_links() {
        // Only add RSS links on BitStream-related pages
        global $post;
        $show_rss = false;
        
        // Show on BitStream archive pages
        if (is_post_type_archive('bit')) {
            $show_rss = true;
        }
        
        // Show on pages with BitStream shortcodes
        if (is_a($post, 'WP_Post') && 
            (has_shortcode($post->post_content, 'bitstream') || 
             has_shortcode($post->post_content, 'bitstream_latest'))) {
            $show_rss = true;
        }
        
        // Show on BitStream URL paths
        if (isset($_SERVER['REQUEST_URI']) && 
            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false) {
            $show_rss = true;
        }
        
        if ($show_rss) {
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream Feed" href="' . esc_url(home_url('/feed/bitstream/')) . '">' . "\n";
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream Bits Only" href="' . esc_url(home_url('/feed/bitstream-bits/')) . '">' . "\n";
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream ReBits Only" href="' . esc_url(home_url('/feed/bitstream-rebits/')) . '">' . "\n";
        }
    }
    
    /**
     * Generate RSS feed for all BitStream content
     */
    public function bitstream_rss_feed() {
        $this->generate_rss_feed('all');
    }
    
    /**
     * Generate RSS feed for bits only (no rebits)
     */
    public function bitstream_bits_rss_feed() {
        $this->generate_rss_feed('bits');
    }
    
    /**
     * Generate RSS feed for rebits only
     */
    public function bitstream_rebits_rss_feed() {
        $this->generate_rss_feed('rebits');
    }
    
    /**
     * Generate RSS feed content
     */
    private function generate_rss_feed($type = 'all') {
        header('Content-Type: application/rss+xml; charset=' . get_option('blog_charset'), true);
        
        $meta_query = [];
        $title_suffix = '';
        
        if ($type === 'bits') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => 'bitstream_rebit_url',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '='
                ]
            ];
            $title_suffix = ' - Bits Only';
        } elseif ($type === 'rebits') {
            $meta_query[] = [
                'key' => 'bitstream_rebit_url',
                'value' => '',
                'compare' => '!='
            ];
            $title_suffix = ' - ReBits Only';
        }
        
        $query = new WP_Query([
            'post_type' => 'bit',
            'posts_per_page' => 50,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query
        ]);
        
        $site_title = get_bloginfo('name');
        $site_url = home_url();
        $feed_title = 'BitStream' . $title_suffix . ' - ' . $site_title;
        $feed_description = 'Latest BitStream posts from ' . $site_title;
        
        echo '<?xml version="1.0" encoding="' . get_option('blog_charset') . '"?>' . "\n";
        ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title><?php echo esc_html($feed_title); ?></title>
        <link><?php echo esc_url($site_url); ?></link>
        <description><?php echo esc_html($feed_description); ?></description>
        <language><?php echo esc_html(get_option('rss_language', 'en-US')); ?></language>
        <lastBuildDate><?php echo esc_html(mysql2date('D, d M Y H:i:s +0000', get_lastpostmodified('GMT'), false)); ?></lastBuildDate>
        <atom:link href="<?php echo esc_url(home_url('/feed/bitstream' . ($type !== 'all' ? '-' . $type : '') . '/')); ?>" rel="self" type="application/rss+xml" />
        
        <?php while ($query->have_posts()) : $query->the_post(); 
            $post_id = get_the_ID();
            $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
            $content = get_the_content();
            $permalink = get_permalink($post_id);
            $author = get_the_author();
            $date = get_the_date('D, d M Y H:i:s +0000');
            
            // Generate title
            $title = 'Bit #' . date('Y-m-d', strtotime(get_the_date())) . ':' . str_pad($post_id % 1000, 3, '0', STR_PAD_LEFT);
            if ($rebit_url) {
                $title = 'ReBit: ' . $title;
            }
            if ($author) {
                $title .= ' by ' . $author;
            }
            
            // Prepare description - include ReBit info if applicable
            $description = '';
            if ($rebit_url) {
                $og_title = get_post_meta($post_id, 'bitstream_og_title', true);
                $og_description = get_post_meta($post_id, 'bitstream_og_description', true);
                
                $description .= '<p><strong>Sharing:</strong> <a href="' . esc_url($rebit_url) . '">' . esc_html($rebit_url) . '</a></p>';
                if ($og_title) {
                    $description .= '<p><strong>Title:</strong> ' . esc_html($og_title) . '</p>';
                }
                if ($og_description) {
                    $description .= '<p><strong>Description:</strong> ' . esc_html($og_description) . '</p>';
                }
                if ($content) {
                    $description .= '<p><strong>Comment:</strong> ' . wpautop($content) . '</p>';
                }
            } else {
                $description = wpautop($content);
            }
        ?>
        <item>
            <title><?php echo esc_html($title); ?></title>
            <link><?php echo esc_url($permalink); ?></link>
            <guid isPermaLink="true"><?php echo esc_url($permalink); ?></guid>
            <pubDate><?php echo esc_html($date); ?></pubDate>
            <author><?php echo esc_html($author); ?></author>
            <description><![CDATA[<?php echo $description; ?>]]></description>
            <content:encoded><![CDATA[<?php echo $description; ?>]]></content:encoded>
            <?php if ($rebit_url) : ?>
            <category>ReBit</category>
            <?php else : ?>
            <category>Bit</category>
            <?php endif; ?>
        </item>
        <?php endwhile; ?>
    </channel>
</rss>
        <?php
        wp_reset_postdata();
        exit;
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
