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
        add_action('wp_ajax_bitstream_submit_poster', [$this, 'handle_submit_poster']);
        add_action('wp_ajax_bitstream_upload_media', [$this, 'handle_upload_media']);
        add_action('wp_ajax_bitstream_crop_media', [$this, 'handle_crop_media']);
        add_action('wp_ajax_bitstream_get_audio_meta', [$this, 'handle_get_audio_meta']);
        add_action('wp_ajax_bitstream_update_audio_meta', [$this, 'handle_update_audio_meta']);
    }

    /**
     * Normalize attachment ID and ensure it's valid
     */
    private function get_valid_attachment_id($attachment_id) {
        $attachment_id = intval($attachment_id);

        if ($attachment_id <= 0) {
            return 0;
        }

        $attachment_post = get_post($attachment_id);
        if (!$attachment_post || $attachment_post->post_type !== 'attachment') {
            return 0;
        }

        return $attachment_id;
    }

    /**
     * Build media markup for Bit post content
     */
    private function build_media_markup($attachment_id) {
        if ($attachment_id <= 0) {
            return '';
        }

        if (wp_attachment_is('image', $attachment_id)) {
            return wp_get_attachment_image($attachment_id, 'large');
        }

        if (wp_attachment_is('video', $attachment_id)) {
            $video_url = wp_get_attachment_url($attachment_id);
            if ($video_url) {
                return wp_video_shortcode(['src' => $video_url]);
            }
        }

        if (wp_attachment_is('audio', $attachment_id)) {
            $audio_url = wp_get_attachment_url($attachment_id);
            if ($audio_url) {
                $audio_markup = wp_audio_shortcode(['src' => $audio_url]);
                $audio_meta = $this->get_audio_meta($attachment_id);
                $title = !empty($audio_meta['title']) ? $audio_meta['title'] : get_the_title($attachment_id);
                $artist = $audio_meta['artist'] ?? '';
                $album = $audio_meta['album'] ?? '';
                $artwork = $audio_meta['artwork'] ?? '';

                $meta_markup = '';
                if ($title || $artist || $album) {
                    $meta_markup .= '<div class="bitstream-audio-meta">';
                    if ($title) {
                        $meta_markup .= '<div class="bitstream-audio-title">' . esc_html($title) . '</div>';
                    }
                    if ($artist) {
                        $meta_markup .= '<div class="bitstream-audio-artist">' . esc_html($artist) . '</div>';
                    }
                    if ($album) {
                        $meta_markup .= '<div class="bitstream-audio-album">' . esc_html($album) . '</div>';
                    }
                    $meta_markup .= '</div>';
                }

                $has_artwork = !empty($artwork);
                $artwork_markup = $has_artwork
                    ? '<div class="bitstream-audio-artwork-wrap"><img class="bitstream-audio-artwork" src="' . esc_attr($artwork) . '" alt=""></div>'
                    : '';

                return '<div class="bitstream-audio-embed' . ($has_artwork ? '' : ' no-artwork') . '">'
                    . $artwork_markup
                    . $meta_markup
                    . '<div class="bitstream-audio-player">' . $audio_markup . '</div>'
                    . '</div>';
            }
        }

        $file_url = wp_get_attachment_url($attachment_id);
        if ($file_url) {
            return '<p><a href="' . esc_url($file_url) . '" target="_blank" rel="noopener">Attached media</a></p>';
        }

        return '';
    }

    /**
     * Extract audio tags and cache them on the attachment
     */
    private function get_audio_meta($attachment_id, $file_path = '') {
        $stored = get_post_meta($attachment_id, '_bitstream_audio_meta', true);
        $stored = is_array($stored) ? $stored : [];

        if (!$file_path) {
            $file_path = get_attached_file($attachment_id);
        }

        if (!$file_path || !file_exists($file_path)) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        $raw = wp_read_audio_metadata($file_path);
        if (empty($raw) || !is_array($raw)) {
            return [];
        }

        $meta = [
            'title' => isset($raw['title']) ? sanitize_text_field($raw['title']) : '',
            'artist' => isset($raw['artist']) ? sanitize_text_field($raw['artist']) : '',
            'album' => isset($raw['album']) ? sanitize_text_field($raw['album']) : '',
            'artwork' => $stored['artwork'] ?? '',
            'artwork_id' => isset($stored['artwork_id']) ? intval($stored['artwork_id']) : 0,
        ];

        if (!empty($meta['artwork_id']) && empty($meta['artwork'])) {
            $meta['artwork'] = wp_get_attachment_url($meta['artwork_id']);
        }

        if (empty($meta['artwork']) && !empty($raw['image']['data']) && !empty($raw['image']['mime'])) {
            $mime = sanitize_mime_type($raw['image']['mime']);
            $meta['artwork'] = 'data:' . $mime . ';base64,' . base64_encode($raw['image']['data']);
        }

        $meta = array_merge($meta, array_intersect_key($stored, $meta));
        $meta = array_filter($meta, static function($value) {
            return $value !== '' && $value !== null && $value !== false;
        });

        update_post_meta($attachment_id, '_bitstream_audio_meta', $meta);
        return $meta;
    }

    /**
     * Fetch stored audio metadata
     */
    public function handle_get_audio_meta() {
        try {
            check_ajax_referer('bitstream_audio_meta_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $attachment_id = $this->get_valid_attachment_id($_POST['attachment_id'] ?? 0);
            if ($attachment_id <= 0 || !wp_attachment_is('audio', $attachment_id)) {
                wp_send_json_error('Invalid audio attachment.');
            }

            $meta = $this->get_audio_meta($attachment_id);

            wp_send_json_success([
                'meta' => $meta,
                'title' => get_the_title($attachment_id),
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Unable to fetch audio metadata.');
        }
    }

    /**
     * Update audio tags and artwork
     */
    public function handle_update_audio_meta() {
        try {
            check_ajax_referer('bitstream_audio_meta_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $attachment_id = $this->get_valid_attachment_id($_POST['attachment_id'] ?? 0);
            if ($attachment_id <= 0 || !wp_attachment_is('audio', $attachment_id)) {
                wp_send_json_error('Invalid audio attachment.');
            }

            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $artist = sanitize_text_field(wp_unslash($_POST['artist'] ?? ''));
            $album = sanitize_text_field(wp_unslash($_POST['album'] ?? ''));
            $artwork_id = intval($_POST['artwork_id'] ?? 0);
            $artwork_url = esc_url_raw(wp_unslash($_POST['artwork_url'] ?? ''));

            if ($artwork_id > 0 && !wp_attachment_is('image', $artwork_id)) {
                $artwork_id = 0;
            }

            if ($artwork_id > 0) {
                $artwork_url = wp_get_attachment_url($artwork_id);
            }

            $meta = [
                'title' => $title,
                'artist' => $artist,
                'album' => $album,
                'artwork' => $artwork_url,
                'artwork_id' => $artwork_id,
            ];

            update_post_meta($attachment_id, '_bitstream_audio_meta', $meta);

            if ($title) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_title' => $title,
                ]);
            }

            wp_send_json_success([
                'meta' => $meta,
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Unable to update audio metadata.');
        }
    }

    /**
     * Build post status and date args from schedule form inputs
     */
    private function build_schedule_args($schedule_enabled_key, $schedule_datetime_key) {
        $schedule_enabled = !empty($_POST[$schedule_enabled_key]) && $_POST[$schedule_enabled_key] === '1';

        if (!$schedule_enabled) {
            return [
                'post_status' => 'publish',
                'post_date' => current_time('mysql'),
                'post_date_gmt' => current_time('mysql', true),
                'is_scheduled' => false,
            ];
        }

        $datetime_raw = sanitize_text_field(wp_unslash($_POST[$schedule_datetime_key] ?? ''));
        if (empty($datetime_raw)) {
            throw new Exception('Please choose a date and time to schedule this post.');
        }

        $timezone = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $datetime_raw, $timezone);
        if (!$dt) {
            throw new Exception('Invalid schedule date/time format.');
        }

        if ($dt->getTimestamp() <= current_time('timestamp')) {
            throw new Exception('Scheduled time must be in the future.');
        }

        $dt_gmt = clone $dt;
        $dt_gmt->setTimezone(new DateTimeZone('UTC'));

        return [
            'post_status' => 'future',
            'post_date' => $dt->format('Y-m-d H:i:s'),
            'post_date_gmt' => $dt_gmt->format('Y-m-d H:i:s'),
            'is_scheduled' => true,
        ];
    }

    /**
     * Handle media upload for poster drag-and-drop
     */
    public function handle_upload_media() {
        try {
            check_ajax_referer('bitstream_media_upload_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            if (empty($_FILES['media'])) {
                wp_send_json_error('No file uploaded.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $upload_overrides = [
                'test_form' => false,
            ];

            $uploaded_file = wp_handle_upload($_FILES['media'], $upload_overrides);
            if (isset($uploaded_file['error'])) {
                wp_send_json_error($uploaded_file['error']);
            }

            $file_path = $uploaded_file['file'];
            $file_url = $uploaded_file['url'];
            $file_type = wp_check_filetype(basename($file_path), null);

            $attachment = [
                'post_mime_type' => $file_type['type'],
                'post_title' => sanitize_text_field(pathinfo($file_path, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            $attachment_id = wp_insert_attachment($attachment, $file_path);
            if (is_wp_error($attachment_id)) {
                wp_send_json_error('Could not create attachment.');
            }

            $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $attachment_data);

            $audio_meta = [];
            if (strpos($file_type['type'], 'audio/') === 0) {
                $audio_meta = $this->get_audio_meta($attachment_id, $file_path);
            }

            wp_send_json_success([
                'id' => $attachment_id,
                'url' => $file_url,
                'mime' => $file_type['type'],
                'audio_meta' => $audio_meta,
                'edit_url' => get_edit_post_link($attachment_id, ''),
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Upload failed.');
        }
    }

    /**
     * Handle cropping an existing attachment image and replacing the file
     */
    public function handle_crop_media() {
        try {
            check_ajax_referer('bitstream_media_crop_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $attachment_id = $this->get_valid_attachment_id($_POST['attachment_id'] ?? 0);
            if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
                wp_send_json_error('Invalid image attachment.');
            }

            $crop_x = isset($_POST['crop_x']) ? intval($_POST['crop_x']) : 0;
            $crop_y = isset($_POST['crop_y']) ? intval($_POST['crop_y']) : 0;
            $crop_w = isset($_POST['crop_w']) ? intval($_POST['crop_w']) : 0;
            $crop_h = isset($_POST['crop_h']) ? intval($_POST['crop_h']) : 0;

            if ($crop_w <= 1 || $crop_h <= 1) {
                wp_send_json_error('Crop area is too small.');
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error('Image file not found.');
            }

            $editor = wp_get_image_editor($file_path);
            if (is_wp_error($editor)) {
                wp_send_json_error('Unable to load image editor.');
            }

            $editor->crop($crop_x, $crop_y, $crop_w, $crop_h);
            $saved = $editor->save($file_path);
            if (is_wp_error($saved)) {
                wp_send_json_error('Could not save cropped image.');
            }

            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $metadata);

            wp_send_json_success([
                'id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
                'mime' => get_post_mime_type($attachment_id),
                'cache_buster' => time(),
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Crop failed.');
        }
    }

    /**
     * Handle tabbed frontend poster submissions
     */
    public function handle_submit_poster() {
        try {
            check_ajax_referer('bitstream_poster_submit_nonce', 'nonce');

            if (!is_user_logged_in() || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $poster_type = sanitize_key($_POST['poster_type'] ?? '');
            if (!in_array($poster_type, ['bit', 'rebit'], true)) {
                wp_send_json_error('Invalid poster type.');
            }

            $author_id = get_current_user_id();

            if ($poster_type === 'bit') {
                $raw_content = wp_unslash($_POST['bit_content'] ?? '');
                $content = wp_kses_post($raw_content);
                $attachment_id = $this->get_valid_attachment_id($_POST['bit_attachment_id'] ?? 0);
                $quote_post_id = intval($_POST['quote_post_id'] ?? 0);
                $schedule = $this->build_schedule_args('bit_schedule_enabled', 'bit_schedule_datetime');

                if (trim(wp_strip_all_tags($content)) === '' && $attachment_id <= 0) {
                    wp_send_json_error('Bit content or media is required.');
                }

                $media_markup = $this->build_media_markup($attachment_id);
                $post_content = $content;
                if (!empty($media_markup)) {
                    $post_content .= (empty($post_content) ? '' : "\n\n") . $media_markup;
                }

                $post_id = wp_insert_post([
                    'post_type' => 'bit',
                    'post_status' => $schedule['post_status'],
                    'post_author' => $author_id,
                    'post_content' => $post_content,
                    'comment_status' => 'open',
                    'post_date' => $schedule['post_date'],
                    'post_date_gmt' => $schedule['post_date_gmt'],
                ], true);

                if (is_wp_error($post_id)) {
                    wp_send_json_error('Failed to create Bit.');
                }

                if ($quote_post_id > 0) {
                    $quoted_post = get_post($quote_post_id);
                    if ($quoted_post && $quoted_post->post_type === 'bit' && $quoted_post->post_status === 'publish') {
                        update_post_meta($post_id, '_bitstream_quoted_bit', $quote_post_id);
                    }
                }

                wp_send_json_success([
                    'message' => $schedule['is_scheduled'] ? 'Bit scheduled successfully.' : 'Bit published successfully.',
                    'post_id' => $post_id,
                    'permalink' => get_permalink($post_id),
                    'view_url' => $schedule['is_scheduled'] ? get_preview_post_link($post_id) : get_permalink($post_id),
                    'edit_url' => get_edit_post_link($post_id, ''),
                    'rendered_html' => bitstream_render_card($post_id),
                    'is_scheduled' => $schedule['is_scheduled'],
                ]);
            }

            $url = esc_url_raw(wp_unslash($_POST['rebit_url'] ?? ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('A valid URL is required for Rebit.');
            }

            $commentary = wp_kses_post(wp_unslash($_POST['rebit_commentary'] ?? ''));
            $manual_title = sanitize_text_field(wp_unslash($_POST['rebit_og_title'] ?? ''));
            $manual_desc = sanitize_textarea_field(wp_unslash($_POST['rebit_og_desc'] ?? ''));
            $manual_image = esc_url_raw(wp_unslash($_POST['rebit_og_image'] ?? ''));
            $attachment_id = $this->get_valid_attachment_id($_POST['rebit_attachment_id'] ?? 0);
            $schedule = $this->build_schedule_args('rebit_schedule_enabled', 'rebit_schedule_datetime');

            $og_data = [];
            if (class_exists('BitStream_OG_Fetcher')) {
                $fetcher = new BitStream_OG_Fetcher();
                $fetched = $fetcher->fetch_og_data($url);
                if (is_array($fetched)) {
                    $og_data = $fetched;
                }
            }

            $og_title = !empty($manual_title) ? $manual_title : ($og_data['title'] ?? '');
            $og_desc = !empty($manual_desc) ? $manual_desc : ($og_data['description'] ?? '');
            $og_image = !empty($manual_image) ? $manual_image : ($og_data['image'] ?? '');

            if ($attachment_id > 0) {
                $attachment_image = wp_get_attachment_image_url($attachment_id, 'large');
                if ($attachment_image) {
                    $og_image = $attachment_image;
                }
            }

            $post_id = wp_insert_post([
                'post_type' => 'bit',
                'post_status' => $schedule['post_status'],
                'post_author' => $author_id,
                'post_content' => $commentary,
                'comment_status' => 'open',
                'post_date' => $schedule['post_date'],
                'post_date_gmt' => $schedule['post_date_gmt'],
            ], true);

            if (is_wp_error($post_id)) {
                wp_send_json_error('Failed to create Rebit.');
            }

            update_post_meta($post_id, 'bitstream_rebit_url', esc_url_raw($url));
            update_post_meta($post_id, '_bitstream_og_title', sanitize_text_field($og_title));
            update_post_meta($post_id, '_bitstream_og_desc', sanitize_text_field($og_desc));
            update_post_meta($post_id, '_bitstream_og_image', esc_url_raw($og_image));
            update_post_meta($post_id, '_bitstream_og_fetched', time());

            wp_send_json_success([
                'message' => $schedule['is_scheduled'] ? 'Rebit scheduled successfully.' : 'Rebit published successfully.',
                'post_id' => $post_id,
                'permalink' => get_permalink($post_id),
                'view_url' => $schedule['is_scheduled'] ? get_preview_post_link($post_id) : get_permalink($post_id),
                'edit_url' => get_edit_post_link($post_id, ''),
                'rendered_html' => bitstream_render_card($post_id),
                'is_scheduled' => $schedule['is_scheduled'],
                'og' => [
                    'title' => $og_title,
                    'description' => $og_desc,
                    'image' => $og_image,
                ]
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'An error occurred while creating the post.');
        }
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
