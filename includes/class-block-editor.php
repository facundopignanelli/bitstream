<?php
/**
 * BitStream Block Editor Handler
 * 
 * Handles block registration, editor scripts, and JavaScript functionality
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Block_Editor {
    
    public function __construct() {
        add_action('init', [$this, 'register_meta_and_block']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_filter('default_content', [$this, 'default_rebit_content'], 10, 2);
        add_action('add_meta_boxes', [$this, 'handle_shared_content_meta']);
        add_action('edit_form_after_title', [$this, 'inject_shared_url_script']);
        add_action('admin_init', [$this, 'handle_shared_key_restoration']);
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

        // Check for media_ids parameter for PWA shared media
        $media_ids_array = [];
        if (isset($_GET['media_ids']) && !empty($_GET['media_ids'])) {
            $media_ids = sanitize_text_field($_GET['media_ids']);
            $media_ids_array = array_map('intval', explode(',', $media_ids));
            error_log('BitStream: enqueue_block_editor_assets - media_ids detected: ' . print_r($media_ids_array, true));
        }

        // Localize script for block editor
        wp_localize_script('bitstream-block', 'bitstream_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url(),
            'like_nonce' => wp_create_nonce('bitstream_like_nonce'),
            'load_more_nonce' => wp_create_nonce('bitstream_load_more_nonce'),
            'og_fetch_nonce' => wp_create_nonce('bitstream_og_fetch_nonce'),
            'post_id' => $current_post_id,
            'media_ids' => $media_ids_array  // Pass media IDs to JavaScript
        ]);
        
        $inline_js = $this->get_block_editor_js();
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
     * Default content for ReBit posts
     */
    public function default_rebit_content($content, $post) {
        if ($post->post_type === 'bit' && !empty($_GET['rebit']) && $_GET['rebit'] === '1') {
            $shared_url = isset($_GET['shared_url']) ? urldecode($_GET['shared_url']) : '';
            $shared_title = isset($_GET['shared_title']) ? urldecode($_GET['shared_title']) : '';
            $shared_text = isset($_GET['shared_text']) ? urldecode($_GET['shared_text']) : '';
            
            // Log for debugging
            error_log('BitStream: default_rebit_content called with shared content: URL=' . $shared_url . ', Title=' . $shared_title . ', Text=' . $shared_text);
            
            $content = '<!-- wp:bitstream/rebit-url /-->'."\n";
            
            // Add shared title and text as content if available
            if ($shared_title || $shared_text) {
                $additional_content = '';
                if ($shared_title && $shared_title !== $shared_url) {
                    $additional_content .= 'Sharing: ' . sanitize_text_field($shared_title) . "\n\n";
                }
                if ($shared_text && $shared_text !== $shared_url && $shared_text !== $shared_title) {
                    $additional_content .= sanitize_text_field($shared_text);
                }
                
                if (trim($additional_content)) {
                    $content .= '<!-- wp:paragraph --><p>' . nl2br(esc_html(trim($additional_content))) . '</p><!-- /wp:paragraph -->' . "\n";
                }
            }
            
            return $content;
        }
        return $content;
    }
    
    /**
     * Handle shared content by setting meta field
     */
    public function handle_shared_content_meta() {
        if (isset($_GET['shared_url']) && !empty($_GET['shared_url'])) {
            global $post;
            
            if ($post && $post->post_type === 'bit') {
                $shared_url = urldecode($_GET['shared_url']);
                error_log('BitStream: Setting shared URL in meta: ' . $shared_url);
                
                // Add JavaScript to set the meta field with better error handling
                echo '<script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    console.log("BitStream: Meta handler - Setting shared URL to: ' . esc_js($shared_url) . '");
                    
                    // Function to set meta and block value
                    function setSharedUrl() {
                        if (wp && wp.data && wp.data.select("core/editor")) {
                            console.log("BitStream: WordPress editor data available");
                            
                            // Method 1: Set post meta directly
                            try {
                                wp.data.dispatch("core/editor").editPost({
                                    meta: { bitstream_rebit_url: "' . esc_js($shared_url) . '" }
                                });
                                console.log("BitStream: Meta set via editPost");
                            } catch (error) {
                                console.error("BitStream: Error setting meta via editPost:", error);
                            }
                            
                            // Method 2: Find and update the block
                            setTimeout(function() {
                                try {
                                    const blocks = wp.data.select("core/block-editor").getBlocks();
                                    console.log("BitStream: Meta handler found blocks:", blocks.length);
                                    
                                    const rebitBlock = blocks.find(block => block.name === "bitstream/rebit-url");
                                    if (rebitBlock) {
                                        console.log("BitStream: Meta handler found ReBit block, updating...");
                                        console.log("BitStream: Current block attributes:", rebitBlock.attributes);
                                        
                                        // Force update the block attributes
                                        wp.data.dispatch("core/block-editor").updateBlockAttributes(rebitBlock.clientId, {
                                            bitstream_rebit_url: "' . esc_js($shared_url) . '"
                                        });
                                        
                                        // Also force update the meta
                                        setTimeout(() => {
                                            wp.data.dispatch("core/editor").editPost({
                                                meta: { bitstream_rebit_url: "' . esc_js($shared_url) . '" }
                                            });
                                            console.log("BitStream: Forced meta update after block update");
                                        }, 200);
                                        
                                        console.log("BitStream: Meta handler updated block attributes");
                                        
                                        // Check if the update worked
                                        setTimeout(() => {
                                            const updatedBlocks = wp.data.select("core/block-editor").getBlocks();
                                            const updatedRebitBlock = updatedBlocks.find(block => block.name === "bitstream/rebit-url");
                                            if (updatedRebitBlock) {
                                                console.log("BitStream: Updated block attributes:", updatedRebitBlock.attributes);
                                            }
                                        }, 500);
                                        
                                    } else {
                                        console.log("BitStream: Meta handler - no ReBit block found yet");
                                    }
                                } catch (error) {
                                    console.error("BitStream: Error in meta handler block update:", error);
                                }
                            }, 1000);
                        } else {
                            console.log("BitStream: WordPress editor not ready, retrying...");
                            setTimeout(setSharedUrl, 500);
                        }
                    }
                    
                    // Start trying to set the URL
                    setSharedUrl();
                });
                </script>';
            }
        }
    }
    
    /**
     * Inject script directly into editor page for immediate execution
     */
    public function inject_shared_url_script() {
        global $post;
        
        // Get post type from multiple sources
        $post_type = '';
        if (isset($_GET['post_type'])) {
            $post_type = $_GET['post_type'];
        } elseif ($post && isset($post->post_type)) {
            $post_type = $post->post_type;
        } elseif (isset($GLOBALS['typenow'])) {
            $post_type = $GLOBALS['typenow'];
        }
        
        // Log for debugging
        if ($post_type === 'bit') {
            error_log('BitStream: inject_shared_url_script running for bit post type');
            error_log('BitStream: GET params: ' . print_r($_GET, true));
        }
        
        // IMPORTANT: Check media_ids FIRST before other shared content
        // This ensures PWA media sharing takes priority
        if ($post_type === 'bit' && isset($_GET['media_ids'])) {
            // Disable all caching for this page
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            
            // Handle media_ids parameter for PWA shared media
            error_log('BitStream: Post type for media check: ' . $post_type);
            error_log('BitStream: isset($_GET[media_ids]): yes');
            error_log('BitStream: INSIDE MEDIA IDS BLOCK - about to inject script - TIMESTAMP: ' . time());
            $media_ids = sanitize_text_field($_GET['media_ids']);
            $ids_array = array_map('intval', explode(',', $media_ids));
            error_log('BitStream: Parsed media IDs: ' . print_r($ids_array, true));
            
            $timestamp = time();
            echo '<!-- MEDIA INSERTION SCRIPT v2.0 - Generated at: ' . $timestamp . ' --><script type="text/javascript">
            console.log("=== BitStream: MEDIA INSERTION SCRIPT START v2.0 - TIMESTAMP: ' . $timestamp . ' ===");
            console.log("BitStream: Media insertion script loaded - VERSION 2.0");
            console.log("BitStream: Current URL:", window.location.href);
            (function() {
                const mediaIds = ' . json_encode($ids_array) . ';
                console.log("BitStream: Media IDs to insert:", mediaIds);
                
                function insertMediaBlocks() {
                    try {
                        if (!window.wp || !window.wp.data || !window.wp.blocks) {
                            console.log("BitStream: WordPress editor not ready for media insertion");
                            return false;
                        }
                        
                        const { select, dispatch } = window.wp.data;
                        const { createBlock } = window.wp.blocks;
                        const blockEditor = select("core/block-editor");
                        const blockDispatcher = dispatch("core/block-editor");
                        
                        if (!blockEditor || !blockDispatcher) {
                            console.log("BitStream: Block editor not available yet");
                            return false;
                        }
                        
                        const blocks = blockEditor.getBlocks();
                        console.log("BitStream: Current blocks:", blocks.length);
                        
                        // Insert media blocks (image or video)
                        const mediaBlocks = [];
                        mediaIds.forEach(function(mediaId) {
                            console.log("BitStream: Creating block for media ID:", mediaId);
                            
                            // We need to determine if its an image or video
                            // For now, well create image blocks and let WordPress handle it
                            const imageBlock = createBlock("core/image", {
                                id: mediaId,
                                sizeSlug: "large"
                            });
                            mediaBlocks.push(imageBlock);
                        });
                        
                        // Insert blocks at the beginning
                        if (mediaBlocks.length > 0) {
                            blockDispatcher.insertBlocks(mediaBlocks, 0);
                            console.log("BitStream: Inserted", mediaBlocks.length, "media blocks");
                            return true;
                        }
                        
                        return false;
                    } catch (error) {
                        console.error("BitStream: Error inserting media blocks:", error);
                        return false;
                    }
                }
                
                // Try to insert media blocks, retry if editor not ready
                function waitForEditor() {
                    if (!insertMediaBlocks()) {
                        setTimeout(waitForEditor, 250);
                    } else {
                        console.log("=== BitStream: MEDIA INSERTION COMPLETE ===");
                    }
                }
                
                setTimeout(waitForEditor, 500);
            })();
            </script>';
        } elseif ($post_type === 'bit' && (isset($_GET['shared_url']) || isset($_GET['shared_text']) || isset($_GET['shared_title']))) {
            $shared_url = isset($_GET['shared_url']) ? urldecode($_GET['shared_url']) : '';
            $shared_text = isset($_GET['shared_text']) ? urldecode($_GET['shared_text']) : '';
            $shared_title = isset($_GET['shared_title']) ? urldecode($_GET['shared_title']) : '';
            
            echo '<script type="text/javascript">
            // Immediate execution when editor loads
            (function() {
                const sharedUrl = "' . addslashes($shared_url) . '";
                const sharedText = "' . addslashes($shared_text) . '";
                const sharedTitle = "' . addslashes($shared_title) . '";
                
                console.log("BitStream: Inject script - Parameters received:");
                console.log("  shared_url:", sharedUrl);
                console.log("  shared_text:", sharedText);
                console.log("  shared_title:", sharedTitle);
                
                // Function to extract URL from any parameter
                function extractUrl() {
                    const urlPattern = /https?:\/\/[^\s]+/g;
                    
                    // Priority order: shared_url, shared_text, shared_title
                    const sources = [sharedUrl, sharedText, sharedTitle];
                    
                    for (const source of sources) {
                        if (source) {
                            const matches = source.match(urlPattern);
                            if (matches && matches.length > 0) {
                                // Return the first (and likely most relevant) URL found
                                const extractedUrl = matches[0];
                                console.log("BitStream: Extracted URL from", source === sharedUrl ? "shared_url" : source === sharedText ? "shared_text" : "shared_title", ":", extractedUrl);
                                return extractedUrl;
                            }
                        }
                    }
                    
                    console.log("BitStream: No URL found in any shared parameter");
                    return "";
                }
                
                const finalUrl = extractUrl();
                
                if (!finalUrl) {
                    console.log("BitStream: No URL to set, skipping ReBit block update");
                    return;
                }
                
                // Function to set meta with better error handling
                function setSharedUrl() {
                    try {
                        // Check if we have a valid post ID and editor is ready
                        if (!window.wp || !window.wp.data) {
                            console.log("BitStream: WordPress editor not ready yet");
                            return false;
                        }
                        
                        const editor = window.wp.data.select("core/editor");
                        const dispatcher = window.wp.data.dispatch("core/editor");
                        
                        if (!editor || !dispatcher) {
                            console.log("BitStream: Editor stores not available yet");
                            return false;
                        }
                        
                        // Check if post is loaded
                        const currentPost = editor.getCurrentPost();
                        if (!currentPost || !currentPost.id) {
                            console.log("BitStream: Post not loaded yet");
                            return false;
                        }
                        
                        console.log("BitStream: WordPress editor data available");
                        
                        // Set meta via the proper method
                        dispatcher.editPost({
                            meta: { bitstream_rebit_url: finalUrl }
                        });
                        
                        console.log("BitStream: Meta set successfully");
                        return true;
                        
                    } catch (error) {
                        console.log("BitStream: Error setting meta via editPost:", error);
                        return false;
                    }
                }
                
                // Function to handle block updates
                function handleBlockUpdates() {
                    try {
                        const blockEditor = window.wp.data.select("core/block-editor");
                        const blockDispatcher = window.wp.data.dispatch("core/block-editor");
                        
                        if (!blockEditor || !blockDispatcher) {
                            return false;
                        }
                        
                        const blocks = blockEditor.getBlocks();
                        console.log("BitStream: Meta handler found blocks:", blocks.length);
                        
                        const rebitBlock = blocks.find(block => block.name === "bitstream/rebit-url");
                        
                        if (rebitBlock) {
                            console.log("BitStream: Meta handler found ReBit block, updating...");
                            blockDispatcher.updateBlockAttributes(rebitBlock.clientId, {
                                bitstream_rebit_url: finalUrl
                            });
                            console.log("BitStream: Meta handler updated block attributes");
                            return true;
                        }
                        
                        return false;
                    } catch (error) {
                        console.log("BitStream: Error updating block:", error);
                        return false;
                    }
                }
                
                // Main waiting function
                function waitForEditor() {
                    let metaSet = false;
                    let blockUpdated = false;
                    
                    // Try to set meta
                    if (!metaSet) {
                        metaSet = setSharedUrl();
                    }
                    
                    // Try to update blocks
                    if (!blockUpdated) {
                        blockUpdated = handleBlockUpdates();
                    }
                    
                    // Continue waiting if either operation failed
                    if (!metaSet || !blockUpdated) {
                        setTimeout(waitForEditor, 250);
                    } else {
                        console.log("BitStream: All operations completed successfully");
                    }
                }
                
                // Start the process
                setTimeout(waitForEditor, 500);
            })();
            </script>';
        }
    }
    
    /**
     * Handle shared key restoration after login
     */
    public function handle_shared_key_restoration() {
        // Check if we have a shared key from the login redirect
        if (isset($_GET['shared_key']) && isset($_GET['post_type']) && $_GET['post_type'] === 'bit') {
            $shared_key = sanitize_text_field($_GET['shared_key']);
            $shared_data = get_transient($shared_key);
            
            if ($shared_data && is_array($shared_data)) {
                // Clean up the transient
                delete_transient($shared_key);
                
                // Redirect with the restored shared data
                $redirect_url = admin_url('post-new.php?post_type=bit&rebit=1');
                
                if (!empty($shared_data['url'])) {
                    $redirect_url = add_query_arg('shared_url', urlencode($shared_data['url']), $redirect_url);
                }
                if (!empty($shared_data['title'])) {
                    $redirect_url = add_query_arg('shared_title', urlencode($shared_data['title']), $redirect_url);
                }
                if (!empty($shared_data['text'])) {
                    $redirect_url = add_query_arg('shared_text', urlencode($shared_data['text']), $redirect_url);
                }
                
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
    
    /**
     * Get the block editor JavaScript code
     */
    private function get_block_editor_js() {
        return <<<'JS'
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
            
            // Use the meta value if block attribute is empty
            const postMeta = useSelect(select => {
                return select('core/editor').getEditedPostAttribute('meta');
            }, []);
            
            const currentUrl = attributes.bitstream_rebit_url || (postMeta && postMeta.bitstream_rebit_url) || '';
            
            // Sync block attributes with meta if they differ
            useEffect(() => {
                if (postMeta && postMeta.bitstream_rebit_url && !attributes.bitstream_rebit_url) {
                    console.log('BitStream: Syncing block attribute with meta value:', postMeta.bitstream_rebit_url);
                    setAttributes({bitstream_rebit_url: postMeta.bitstream_rebit_url});
                }
            }, [postMeta, attributes.bitstream_rebit_url]);
            
            const onURLChange = (value) => {
                setAttributes({bitstream_rebit_url: value});
            };
            
            return [
                wp.element.createElement(InspectorControls,null,
                    wp.element.createElement(PanelBody,{title:'ReBit Settings',initialOpen:true},
                        wp.element.createElement(TextControl,{
                            label:'ReBit URL',
                            value:currentUrl,
                            onChange:onURLChange,
                            help:'Enter the URL you want to share'
                        })
                    )
                ),
                wp.element.createElement('div',blockProps,
                    !currentUrl ? 
                        wp.element.createElement(Placeholder,{
                            icon:'admin-links',
                            label:'ReBit URL',
                            instructions:'Paste or type a URL to share external content'
                        },
                            wp.element.createElement(TextControl,{
                                placeholder:'https://example.com',
                                value:currentUrl,
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
        
        // Handle shared content from Android share sheet - check all possible parameter names
        const urlParams = new URLSearchParams(window.location.search);
        console.log('BitStream: All URL parameters:', Array.from(urlParams.entries()));
        
        // Check both the original share target params and our transformed params
        const sharedUrl = urlParams.get('shared_url') || urlParams.get('url');
        const sharedTitle = urlParams.get('shared_title') || urlParams.get('title');
        const sharedText = urlParams.get('shared_text') || urlParams.get('text');
        
        console.log('BitStream: Extracted parameters:', {
            sharedUrl: sharedUrl,
            sharedTitle: sharedTitle,
            sharedText: sharedText
        });
        
        if (sharedUrl) {
            console.log('BitStream: Shared content detected - URL:', sharedUrl);
            
            // Multiple attempts to populate the block with increasing delays
            const attemptToPopulateBlock = (attempt = 1, maxAttempts = 10) => {
                console.log(`BitStream: Attempt ${attempt} to populate ReBit block`);
                
                const blocks = select('core/block-editor').getBlocks();
                console.log('BitStream: Available blocks:', blocks.map(b => ({name: b.name, clientId: b.clientId, attributes: b.attributes})));
                
                const rebitBlock = blocks.find(block => block.name === 'bitstream/rebit-url');
                
                if (rebitBlock) {
                    console.log('BitStream: Found ReBit block:', rebitBlock);
                    console.log('BitStream: Current block attributes:', rebitBlock.attributes);
                    
                    // Try to update the block attributes
                    try {
                        dispatch('core/block-editor').updateBlockAttributes(rebitBlock.clientId, {
                            bitstream_rebit_url: decodeURIComponent(sharedUrl)
                        });
                        console.log('BitStream: Successfully updated block attributes');
                        
                        // Also try to update the meta directly
                        dispatch('core/editor').editPost({
                            meta: { bitstream_rebit_url: decodeURIComponent(sharedUrl) }
                        });
                        console.log('BitStream: Successfully updated post meta');
                        
                    } catch (error) {
                        console.error('BitStream: Error updating block:', error);
                    }
                    
                } else if (attempt < maxAttempts) {
                    console.log(`BitStream: ReBit block not found, retrying in ${attempt * 100}ms...`);
                    setTimeout(() => attemptToPopulateBlock(attempt + 1, maxAttempts), attempt * 100);
                } else {
                    console.error('BitStream: Could not find ReBit block after', maxAttempts, 'attempts');
                    
                    // Last resort: try to insert a new block with the URL
                    try {
                        const newBlock = createBlock('bitstream/rebit-url', {
                            bitstream_rebit_url: decodeURIComponent(sharedUrl)
                        });
                        dispatch('core/block-editor').insertBlock(newBlock);
                        console.log('BitStream: Inserted new ReBit block as fallback');
                    } catch (error) {
                        console.error('BitStream: Failed to insert fallback block:', error);
                    }
                }
            };
            
            // Start the first attempt immediately
            attemptToPopulateBlock();
        } else {
            console.log('BitStream: No shared URL detected in parameters');
        }
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
        
        // Check for shared parameters immediately
        const currentParams = new URLSearchParams(window.location.search);
        console.log('BitStream: Current page parameters:', Array.from(currentParams.entries()));
        
        const sharedUrl = currentParams.get('shared_url') || currentParams.get('url');
        const sharedTitle = currentParams.get('shared_title') || currentParams.get('title');
        const sharedText = currentParams.get('shared_text') || currentParams.get('text');
        
        console.log('BitStream: Checking for shared parameters:', {
            sharedUrl: sharedUrl,
            sharedTitle: sharedTitle,
            sharedText: sharedText
        });
        
        // Wait for editor to be ready
        const insertRebitBlock = () => {
            const {insertBlocks} = dispatch('core/block-editor');
            const {getBlocks} = select('core/block-editor');
            
            // Check if editor is ready and no blocks exist yet
            if (insertBlocks && getBlocks().length === 0) {
                const rebitBlock = createBlock('bitstream/rebit-url');
                insertBlocks(rebitBlock);
                console.log('BitStream: ReBit block auto-inserted');
                
                // If we have a shared URL, populate it immediately
                if (sharedUrl) {
                    console.log('BitStream: Populating ReBit block with shared URL:', sharedUrl);
                    
                    setTimeout(() => {
                        const blocks = select('core/block-editor').getBlocks();
                        const newRebitBlock = blocks.find(block => block.name === 'bitstream/rebit-url');
                        
                        if (newRebitBlock) {
                            dispatch('core/block-editor').updateBlockAttributes(newRebitBlock.clientId, {
                                bitstream_rebit_url: decodeURIComponent(sharedUrl)
                            });
                            
                            dispatch('core/editor').editPost({
                                meta: { bitstream_rebit_url: decodeURIComponent(sharedUrl) }
                            });
                            
                            console.log('BitStream: ReBit block populated successfully');
                        }
                    }, 100);
                }
            } else {
                // Try again in a moment if editor isn't ready
                setTimeout(insertRebitBlock, 100);
            }
        };
        
        // Start trying to insert the block
        setTimeout(insertRebitBlock, 500);
    }
    
    // ========== MEDIA INSERTION FOR PWA SHARED MEDIA ==========
    // Check if we have media_ids from PWA share target
    if (window.bitstream_ajax && window.bitstream_ajax.media_ids && window.bitstream_ajax.media_ids.length > 0) {
        console.log("=== BitStream: MEDIA INSERTION SCRIPT START ===");
        console.log("BitStream: Media IDs to insert:", window.bitstream_ajax.media_ids);
        
        async function fetchMediaDetails(mediaId) {
            try {
                const response = await fetch(`/wp-json/wp/v2/media/${mediaId}`);
                if (!response.ok) {
                    console.error("BitStream: Failed to fetch media:", response.status);
                    return null;
                }
                const media = await response.json();
                console.log("BitStream: Fetched media details:", media);
                return media;
            } catch (error) {
                console.error("BitStream: Error fetching media:", error);
                return null;
            }
        }
        
        async function insertMediaBlocks() {
            try {
                if (!window.wp || !window.wp.data || !window.wp.blocks) {
                    console.log("BitStream: WordPress editor not ready for media insertion");
                    return false;
                }
                
                const { select, dispatch } = window.wp.data;
                const { createBlock } = window.wp.blocks;
                const blockEditor = select("core/block-editor");
                const blockDispatcher = dispatch("core/block-editor");
                const editor = select("core/editor");
                
                if (!blockEditor || !blockDispatcher || !editor) {
                    console.log("BitStream: Block editor not available yet");
                    return false;
                }
                
                // Make sure editor is fully initialized
                const currentPost = editor.getCurrentPost();
                if (!currentPost || !currentPost.type) {
                    console.log("BitStream: Editor not fully initialized");
                    return false;
                }
                
                const blocks = blockEditor.getBlocks();
                console.log("BitStream: Current blocks:", blocks.length);
                
                // Fetch media details and create blocks
                const mediaBlocks = [];
                for (const mediaId of window.bitstream_ajax.media_ids) {
                    console.log("BitStream: Fetching details for media ID:", mediaId);
                    const media = await fetchMediaDetails(mediaId);
                    
                    if (media) {
                        console.log("BitStream: Creating block with full media data");
                        console.log("BitStream: Media type:", media.media_type);
                        console.log("BitStream: Media mime type:", media.mime_type);
                        console.log("BitStream: Media source URL:", media.source_url);
                        
                        // Determine if it's an image or video
                        const mediaType = media.media_type || 'image';
                        const mimeType = media.mime_type || '';
                        
                        // Check both media_type and mime_type for video detection
                        const isVideo = mediaType === 'video' || mimeType.startsWith('video/');
                        
                        if (isVideo) {
                            console.log("BitStream: Creating VIDEO block");
                            const videoBlock = createBlock("core/video", {
                                id: mediaId,
                                src: media.source_url,
                                caption: media.caption?.rendered || ''
                            });
                            mediaBlocks.push(videoBlock);
                        } else {
                            console.log("BitStream: Creating IMAGE block");
                            const imageBlock = createBlock("core/image", {
                                id: mediaId,
                                url: media.source_url,
                                alt: media.alt_text || '',
                                caption: media.caption?.rendered || '',
                                sizeSlug: "large"
                            });
                            mediaBlocks.push(imageBlock);
                        }
                    } else {
                        console.error("BitStream: Failed to fetch media details for ID:", mediaId);
                    }
                }
                
                // Insert blocks at the beginning
                if (mediaBlocks.length > 0) {
                    blockDispatcher.insertBlocks(mediaBlocks, 0);
                    console.log("BitStream: Inserted", mediaBlocks.length, "media blocks");
                    return true;
                }
                
                return false;
            } catch (error) {
                console.error("BitStream: Error inserting media blocks:", error);
                return false;
            }
        }
        
        // Try to insert media blocks, retry if editor not ready
        async function waitForMediaInsertion(attempt = 1, maxAttempts = 20) {
            console.log("BitStream: Attempt", attempt, "to insert media");
            const success = await insertMediaBlocks();
            
            if (!success && attempt < maxAttempts) {
                setTimeout(() => waitForMediaInsertion(attempt + 1, maxAttempts), 500);
            } else if (success) {
                console.log("=== BitStream: MEDIA INSERTION COMPLETE ===");
            } else {
                console.error("BitStream: Failed to insert media after", maxAttempts, "attempts");
            }
        }
        
        setTimeout(() => waitForMediaInsertion(), 1000);
    }
})();
JS;
    }
}
