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
            return '<!-- wp:bitstream/rebit-url /-->'."\n";
        }
        return $content;
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
    }
}
