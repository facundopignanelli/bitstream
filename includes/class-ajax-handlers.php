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
        add_action('wp_ajax_bitstream_get_quoted_bit', [$this, 'handle_get_quoted_bit']);
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
            // Debug logging
            error_log('BitStream OG Fetch Request: ' . print_r($_POST, true));
            
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bitstream_og_fetch_nonce')) {
                error_log('BitStream OG Fetch: Invalid nonce');
                wp_send_json_error('Invalid nonce.');
            }

            // Check permissions
            if (!current_user_can('edit_posts')) {
                error_log('BitStream OG Fetch: Insufficient permissions');
                wp_send_json_error('Insufficient permissions.');
            }

            $url = sanitize_url($_POST['url'] ?? '');
            $post_id = intval($_POST['post_id'] ?? 0);
            
            error_log('BitStream OG Fetch: URL=' . $url . ', Post ID=' . $post_id);
            
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                error_log('BitStream OG Fetch: Invalid URL: ' . $url);
                wp_send_json_error('Invalid URL.');
            }

            // Check if we already have this data cached
            if ($post_id > 0) {
                $cached_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
                $cached_title = get_post_meta($post_id, '_bitstream_og_title', true);
                $cached_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
                $cached_image = get_post_meta($post_id, '_bitstream_og_image', true);
                
                if ($cached_url === $url && !empty($cached_title)) {
                    wp_send_json_success([
                        'title' => $cached_title,
                        'description' => $cached_desc,
                        'image' => $cached_image,
                        'url' => $url,
                        'cached' => true
                    ]);
                    return;
                }
            }

            // Fetch the page with multiple fallback strategies
            $resp = wp_remote_get($url, [
                'timeout' => 15,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Accept-Encoding' => 'gzip, deflate',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                ]
            ]);

            // If first attempt fails, try with minimal headers
            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
                $resp = wp_remote_get($url, [
                    'timeout' => 10,
                    'sslverify' => false
                ]);
            }

            if (is_wp_error($resp)) {
                error_log('BitStream OG Fetch Error: ' . $resp->get_error_message() . ' for URL: ' . $url);
                wp_send_json_error('Failed to fetch URL: ' . $resp->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($resp);
            if ($response_code !== 200) {
                error_log('BitStream OG Fetch HTTP Error: ' . $response_code . ' for URL: ' . $url);
                wp_send_json_error('URL returned error code: ' . $response_code);
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

            // Store the data immediately if we have a post ID
            if ($post_id > 0) {
                update_post_meta($post_id, '_bitstream_og_title', sanitize_text_field($og_title));
                update_post_meta($post_id, '_bitstream_og_desc', sanitize_text_field($og_desc));
                update_post_meta($post_id, '_bitstream_og_image', esc_url_raw($og_img));
                update_post_meta($post_id, '_bitstream_og_fetched', time());
            }

            wp_send_json_success([
                'title' => $og_title,
                'description' => $og_desc,
                'image' => $og_img,
                'url' => $url,
                'stored' => $post_id > 0
            ]);

        } catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching preview data.');
        }
    }
    
    /**
     * Handle getting quoted bit content for block editor display
     */
    public function handle_get_quoted_bit() {
        error_log('BitStream: handle_get_quoted_bit called');
        error_log('BitStream: POST data: ' . print_r($_POST, true));
        
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bitstream_og_fetch_nonce')) {
                error_log('BitStream: Nonce verification failed');
                wp_send_json_error('Invalid nonce.');
            }

            // Check permissions
            if (!current_user_can('edit_posts')) {
                error_log('BitStream: Permission check failed');
                wp_send_json_error('Insufficient permissions.');
            }

            $quoted_bit_id = intval($_POST['quoted_bit_id'] ?? 0);
            error_log('BitStream: Quoted bit ID: ' . $quoted_bit_id);
            
            if ($quoted_bit_id <= 0) {
                error_log('BitStream: Invalid quoted bit ID');
                wp_send_json_error('Invalid quoted bit ID.');
            }

            $quoted_post = get_post($quoted_bit_id);
            
            if (!$quoted_post || $quoted_post->post_type !== 'bit') {
                wp_send_json_error('Quoted bit not found.');
            }

            // Get the content and apply filters
            $content = apply_filters('the_content', $quoted_post->post_content);
            
            // Get author info
            $author = get_userdata($quoted_post->post_author);
            $author_name = $author ? $author->display_name : 'Unknown';
            
            // Get timestamp
            $timestamp = human_time_diff(get_post_time('U', false, $quoted_post), current_time('timestamp')) . ' ago';

            wp_send_json_success([
                'content' => $content,
                'author' => $author_name,
                'timestamp' => $timestamp,
                'post_id' => $quoted_bit_id
            ]);

        } catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching quoted bit.');
        }
    }
}

new BitStream_Ajax_Handlers();
