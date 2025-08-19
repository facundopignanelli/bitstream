<?php
/**
 * BitStream AJAX Handlers
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Ajax_Handlers {
    
    public function __construct() {
        add_action('wp_ajax_bitstream_like', [$this, 'handle_like']);
        add_action('wp_ajax_nopriv_bitstream_like', [$this, 'handle_like']);
        add_action('wp_ajax_bitstream_load_more', [$this, 'handle_load_more']);
        add_action('wp_ajax_nopriv_bitstream_load_more', [$this, 'handle_load_more']);
    }
    
    /**
     * Handle like/unlike AJAX requests with security checks
     */
    public function handle_like() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bitstream_like_nonce')) {
                wp_send_json_error('Invalid nonce.');
            }
            
            // Check permissions
            if (!current_user_can('read')) {
                wp_send_json_error('Insufficient permissions.');
            }
            
            if (empty($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
                wp_send_json_error('Invalid post ID.');
            }
            
            $post_id = intval($_POST['post_id']);
            
            // Verify post exists and is published
            if (get_post_status($post_id) !== 'publish') {
                wp_send_json_error('Post not found or not published.');
            }
            
            $current = (int) get_post_meta($post_id, '_bitstream_likes', true);
            $type = (isset($_POST['type']) && $_POST['type'] === 'unlike') ? 'unlike' : 'like';

            if ($type === 'unlike') {
                $new_count = max(0, $current - 1);
            } else {
                $new_count = $current + 1;
            }

            update_post_meta($post_id, '_bitstream_likes', $new_count);
            wp_send_json_success(['likes' => $new_count]);
            
        } catch (Exception $e) {
            error_log('BitStream like error: ' . $e->getMessage());
            wp_send_json_error('An error occurred while processing your request.');
        }
    }
    
    /**
     * Handle load more posts AJAX requests with security checks
     */
    public function handle_load_more() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bitstream_load_more_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $q = new WP_Query([
            'post_type'      => 'bit',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ]);
        
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                echo bitstream_render_card(get_the_ID());
            }
        }
        
        wp_reset_postdata();
        wp_die();
    }
}

new BitStream_Ajax_Handlers();
