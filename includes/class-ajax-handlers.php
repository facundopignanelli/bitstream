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
        add_action('wp_ajax_bitstream_fetch_og_data', [$this, 'handle_fetch_og_data']);
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

    /**
     * Handle OG data fetch for ReBit block preview
     */
    public function handle_fetch_og_data() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bitstream_og_fetch_nonce')) {
                wp_send_json_error('Invalid nonce.');
            }

            // Check permissions
            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $url = sanitize_url($_POST['url'] ?? '');
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('Invalid URL.');
            }

            // Fetch the page
            $resp = wp_remote_get($url, [
                'timeout' => 10,
                'user-agent' => 'Mozilla/5.0 (compatible; BitStream/1.0; +' . home_url() . ')'
            ]);

            if (is_wp_error($resp)) {
                wp_send_json_error('Failed to fetch URL: ' . $resp->get_error_message());
            }

            if (wp_remote_retrieve_response_code($resp) !== 200) {
                wp_send_json_error('URL returned error code: ' . wp_remote_retrieve_response_code($resp));
            }

            $html = wp_remote_retrieve_body($resp);
            $og_title = $og_desc = $og_img = '';

            // Extract OG data
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $og_title = $m[1];
            }
            if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $og_desc = $m[1];
            }
            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $og_img = $m[1];
            }

            // Fallback to title tag if no OG title
            if (empty($og_title) && preg_match('/<title>(.*?)<\/title>/', $html, $m)) {
                $og_title = $m[1];
            }

            // Fallback to meta description if no OG description
            if (empty($og_desc) && preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $og_desc = $m[1];
            }

            // Clean up the data
            $og_title = html_entity_decode(trim($og_title), ENT_QUOTES, 'UTF-8');
            $og_desc = html_entity_decode(trim($og_desc), ENT_QUOTES, 'UTF-8');
            $og_img = trim($og_img);

            // Make relative URLs absolute
            if ($og_img && !filter_var($og_img, FILTER_VALIDATE_URL)) {
                $parsed_url = parse_url($url);
                if ($og_img[0] === '/') {
                    $og_img = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $og_img;
                } else {
                    $og_img = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/' . $og_img;
                }
            }

            wp_send_json_success([
                'title' => $og_title,
                'description' => $og_desc,
                'image' => $og_img,
                'url' => $url
            ]);

        } catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching preview data.');
        }
    }
}

new BitStream_Ajax_Handlers();
