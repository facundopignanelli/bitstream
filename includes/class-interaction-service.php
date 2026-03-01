<?php
/**
 * BitStream Interaction Service
 *
 * Handles user interaction endpoints (likes/unlikes).
 *
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Bitstream_Interaction_Service
{
    public function __construct()
    {
        add_action('wp_ajax_bitstream_like', [$this, 'handle_like']);
        add_action('wp_ajax_nopriv_bitstream_like', [$this, 'handle_like']);
    }

    /**
     * Handle like/unlike AJAX requests with security checks
     */
    public function handle_like()
    {
        try {
            check_ajax_referer('bitstream_like_nonce', 'nonce');

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

            $current = (int)get_post_meta($post_id, '_bitstream_likes', true);
            $type = (isset($_POST['type']) && $_POST['type'] === 'unlike') ? 'unlike' : 'like';

            if ($type === 'unlike') {
                $new_count = max(0, $current - 1);
            }
            else {
                $new_count = $current + 1;
            }

            update_post_meta($post_id, '_bitstream_likes', $new_count);
            wp_send_json_success(['likes' => $new_count]);

        }
        catch (Exception $e) {
            error_log('BitStream like error: ' . $e->getMessage());
            wp_send_json_error('An error occurred while processing your request.');
        }
    }
}
