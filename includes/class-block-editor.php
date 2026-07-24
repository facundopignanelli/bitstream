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

    /**
     * Log only in debug environments.
     */
    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }
    
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
        register_block_type('bitstream/rebit-url', ['editor_script' => 'bitstream-block', 'api_version' => 3]);
    }
    
    /**
     * Enqueue block editor assets
     */
    public function enqueue_block_editor_assets() {
        wp_register_script(
            'bitstream-block',
            BITSTREAM_PLUGIN_URL . 'assets/js/bitstream-block.js',
            ['wp-blocks','wp-element','wp-editor','wp-components','wp-data'],
            BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/js/bitstream-block.js'),
            true
        );
        
        // Get post ID for editor context
        $current_post_id = 0;
        if (is_admin() && isset($_GET['post'])) {
            $current_post_id = abs((int) wp_unslash($_GET['post']));
        } elseif (is_admin() && isset($_POST['post_ID'])) {
            $current_post_id = abs((int) wp_unslash($_POST['post_ID']));
        }

        // Determine post type to ensure we only load on 'bit' editor screens
        $post_type = '';
        if ($current_post_id) {
            $post_type = get_post_type($current_post_id);
        } elseif (isset($_GET['post_type'])) {
            $post_type = sanitize_text_field(wp_unslash($_GET['post_type']));
        }

        if ($post_type !== 'bit') {
            return;
        }

        // Check for media_ids parameter for PWA shared media
        $media_ids_array = [];
        if (isset($_GET['media_ids']) && !empty($_GET['media_ids'])) {
            $media_ids = sanitize_text_field(wp_unslash($_GET['media_ids']));
            $media_ids_array = array_filter(array_map('absint', explode(',', $media_ids)));
            $this->debug_log('BitStream: enqueue_block_editor_assets - media_ids count: ' . count($media_ids_array));
        }

        // Localize script for block editor
        wp_localize_script('bitstream-block', 'bitstream_ajax', array_merge(BitStream_Ajax_Handlers::get_localized_data(), [
            'post_id'   => $current_post_id,
            'media_ids' => $media_ids_array
        ]));
        
        wp_enqueue_script('bitstream-block');
    }
    
    /**
     * Enqueue frontend assets with optimizations
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style('bitstream-css', BITSTREAM_PLUGIN_URL . 'assets/css/bitstream.css', [], BITSTREAM_VERSION . '.' . filemtime(BITSTREAM_PLUGIN_PATH . 'assets/css/bitstream.css'));
        
        // Cache-busting version for JS
            wp_enqueue_script('bitstream-js');
        // Get post ID for editor context
        $current_post_id = 0;
        if (is_admin() && isset($_GET['post'])) {
            $current_post_id = abs((int) wp_unslash($_GET['post']));
        } elseif (is_admin() && isset($_POST['post_ID'])) {
            $current_post_id = abs((int) wp_unslash($_POST['post_ID']));
        } elseif (!is_admin()) {
            $current_post_id = get_the_ID();
        }

        wp_localize_script('bitstream-js', 'bitstream_ajax', array_merge(BitStream_Ajax_Handlers::get_localized_data(), [
            'post_id' => $current_post_id
        ]));
        
        // Ensure $ is available globally
        add_action('wp_print_footer_scripts', function() {
            echo '<script type="text/javascript">window.$ = window.jQuery;</script>';
        });
    }
    
    /**
     * Default content for ReBit posts
     */
    public function default_rebit_content($content, $post) {
        $is_rebit = isset($_GET['rebit']) && sanitize_text_field(wp_unslash($_GET['rebit'])) === '1';
        if ($post->post_type === 'bit' && $is_rebit) {
            $shared_url = isset($_GET['shared_url']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_url']))) : '';
            $shared_title = isset($_GET['shared_title']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_title']))) : '';
            $shared_text = isset($_GET['shared_text']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_text']))) : '';
            
            $this->debug_log('BitStream: default_rebit_content called for rebit flow');
            
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
                $shared_url = isset($_GET['shared_url']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_url']))) : '';
                $this->debug_log('BitStream: Setting shared URL in meta flow');

                $shared_url_json = wp_json_encode($shared_url);
                $inline_script = <<<JS
document.addEventListener("DOMContentLoaded", function() {
    const sharedUrl = {$shared_url_json};
    function setSharedUrl() {
        if (window.wp && window.wp.data && window.wp.data.select("core/editor")) {
            try {
                window.wp.data.dispatch("core/editor").editPost({
                    meta: { bitstream_rebit_url: sharedUrl }
                });
            } catch (error) {
                console.error("BitStream: Error setting meta via editPost:", error);
            }

            setTimeout(function() {
                try {
                    const blocks = window.wp.data.select("core/block-editor").getBlocks();
                    const rebitBlock = blocks.find(block => block.name === "bitstream/rebit-url");
                    if (rebitBlock) {
                        window.wp.data.dispatch("core/block-editor").updateBlockAttributes(rebitBlock.clientId, {
                            bitstream_rebit_url: sharedUrl
                        });

                        setTimeout(() => {
                            window.wp.data.dispatch("core/editor").editPost({
                                meta: { bitstream_rebit_url: sharedUrl }
                            });
                        }, 200);
                    }
                } catch (error) {
                    console.error("BitStream: Error in meta handler block update:", error);
                }
            }, 1000);
        } else {
            setTimeout(setSharedUrl, 500);
        }
    }

    setSharedUrl();
});
JS;
                wp_add_inline_script('bitstream-block', $inline_script, 'after');
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
            $post_type = sanitize_text_field(wp_unslash($_GET['post_type']));
        } elseif ($post && isset($post->post_type)) {
            $post_type = $post->post_type;
        } elseif (isset($GLOBALS['typenow'])) {
            $post_type = $GLOBALS['typenow'];
        }
        
        if ($post_type === 'bit') {
            $this->debug_log('BitStream: inject_shared_url_script running for bit post type');
        }
        
        // IMPORTANT: Check media_ids FIRST before other shared content
        // This ensures PWA media sharing takes priority
        if ($post_type === 'bit' && isset($_GET['media_ids'])) {
            // Disable all caching for this page
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            
            // Handle media_ids parameter for PWA shared media
            $media_ids = sanitize_text_field(wp_unslash($_GET['media_ids']));
            $ids_array = array_filter(array_map('absint', explode(',', $media_ids)));
            $this->debug_log('BitStream: Parsed media IDs count: ' . count($ids_array));
            
            $ids_array_json = wp_json_encode(array_values($ids_array));
            $timestamp = (int) time();
            $media_inline_script = <<<JS
console.log("=== BitStream: MEDIA INSERTION SCRIPT START v2.0 - TIMESTAMP: {$timestamp} ===");
console.log("BitStream: Media insertion script loaded - VERSION 3.2.0");
console.log("BitStream: Current URL:", window.location.href);
(function() {
    const mediaIds = {$ids_array_json};
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

            const mediaBlocks = [];
            mediaIds.forEach(function(mediaId) {
                const imageBlock = createBlock("core/image", {
                    id: mediaId,
                    sizeSlug: "large"
                });
                mediaBlocks.push(imageBlock);
            });

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

    function waitForEditor() {
        if (!insertMediaBlocks()) {
            setTimeout(waitForEditor, 250);
        } else {
            console.log("=== BitStream: MEDIA INSERTION COMPLETE ===");
        }
    }

    setTimeout(waitForEditor, 500);
})();
JS;
            wp_add_inline_script('bitstream-block', $media_inline_script, 'after');
        } elseif ($post_type === 'bit' && (isset($_GET['shared_url']) || isset($_GET['shared_text']) || isset($_GET['shared_title']))) {
            $shared_url = isset($_GET['shared_url']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_url']))) : '';
            $shared_text = isset($_GET['shared_text']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_text']))) : '';
            $shared_title = isset($_GET['shared_title']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['shared_title']))) : '';

            $shared_payload_json = wp_json_encode([
                'sharedUrl' => $shared_url,
                'sharedText' => $shared_text,
                'sharedTitle' => $shared_title,
            ]);

            $shared_inline_script = <<<JS
// Immediate execution when editor loads
(function() {
    const payload = {$shared_payload_json} || {};
    const sharedUrl = payload.sharedUrl || "";
    const sharedText = payload.sharedText || "";
    const sharedTitle = payload.sharedTitle || "";

    console.log("BitStream: Inject script - Parameters received:");
    console.log("  shared_url:", sharedUrl);
    console.log("  shared_text:", sharedText);
    console.log("  shared_title:", sharedTitle);

    function extractUrl() {
        const urlPattern = /https?:\/\/[^\s]+/g;
        const sources = [sharedUrl, sharedText, sharedTitle];

        for (const source of sources) {
            if (source) {
                const matches = source.match(urlPattern);
                if (matches && matches.length > 0) {
                    return matches[0];
                }
            }
        }

        return "";
    }

    const finalUrl = extractUrl();

    if (!finalUrl) {
        return;
    }

    function setSharedUrl() {
        try {
            if (!window.wp || !window.wp.data) {
                return false;
            }

            const editor = window.wp.data.select("core/editor");
            const dispatcher = window.wp.data.dispatch("core/editor");

            if (!editor || !dispatcher) {
                return false;
            }

            const currentPost = editor.getCurrentPost();
            if (!currentPost || !currentPost.id) {
                return false;
            }

            dispatcher.editPost({
                meta: { bitstream_rebit_url: finalUrl }
            });

            return true;

        } catch (error) {
            console.log("BitStream: Error setting meta via editPost:", error);
            return false;
        }
    }

    function handleBlockUpdates() {
        try {
            const blockEditor = window.wp.data.select("core/block-editor");
            const blockDispatcher = window.wp.data.dispatch("core/block-editor");

            if (!blockEditor || !blockDispatcher) {
                return false;
            }

            const blocks = blockEditor.getBlocks();
            const rebitBlock = blocks.find(block => block.name === "bitstream/rebit-url");

            if (rebitBlock) {
                blockDispatcher.updateBlockAttributes(rebitBlock.clientId, {
                    bitstream_rebit_url: finalUrl
                });
                return true;
            }

            return false;
        } catch (error) {
            console.log("BitStream: Error updating block:", error);
            return false;
        }
    }

    function waitForEditor() {
        const metaSet = setSharedUrl();
        const blockUpdated = handleBlockUpdates();

        if (!metaSet || !blockUpdated) {
            setTimeout(waitForEditor, 250);
        }
    }

    setTimeout(waitForEditor, 500);
})();
JS;
            wp_add_inline_script('bitstream-block', $shared_inline_script, 'after');
        }
    }
    
    /**
     * Handle shared key restoration after login
     */
    public function handle_shared_key_restoration() {
        // Check if we have a shared key from the login redirect
        $is_bit_post_type = isset($_GET['post_type']) && sanitize_text_field(wp_unslash($_GET['post_type'])) === 'bit';
        if (isset($_GET['shared_key']) && $is_bit_post_type) {
            $shared_key = sanitize_text_field(wp_unslash($_GET['shared_key']));
            $shared_data = get_transient($shared_key);
            
            if ($shared_data && is_array($shared_data)) {
                // Clean up the transient
                delete_transient($shared_key);
                
                // Redirect with the restored shared data
                $redirect_url = class_exists('BitStream_Shortcodes')
                    ? BitStream_Shortcodes::get_composer_page_url(['composer_tab' => 'rebit'])
                    : home_url('/bitstream/?composer_tab=rebit');
                
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
    
}
