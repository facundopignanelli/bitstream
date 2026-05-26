<?php
/**
 * BitStream AJAX Handlers
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class BitStream_Ajax_Handlers
{

    /**
     * Keep live composer previews visually accurate while removing interactive markup
     * that can break nested form structures (comments/actions).
     */
    private function sanitize_live_preview_markup($markup)
    {
        $markup = (string)$markup;
        if ($markup === '') {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            $markup = preg_replace('#<footer class="bit-card-footer"[\s\S]*?</footer>#', '', $markup);
            $markup = preg_replace('#<div[^>]*class="[^"]*bit-comments[^"]*"[^>]*>[\s\S]*</div>#', '', $markup);
            $markup = preg_replace('#<form\b[\s\S]*?</form>#i', '', $markup);
            return $markup;
        }

        $previous_state = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $wrapped_markup = '<div id="bitstream-live-preview-root">' . $markup . '</div>';
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_markup, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($document);

        $selectors = [
            '//*[@id="bitstream-live-preview-root"]//*[contains(concat(" ", normalize-space(@class), " "), " bit-card-footer ")]'
            . ' | //*[@id="bitstream-live-preview-root"]//*[contains(concat(" ", normalize-space(@class), " "), " bit-comments ")]'
            . ' | //*[@id="bitstream-live-preview-root"]//form'
            . ' | //*[@id="bitstream-live-preview-root"]//hr',
        ];

        foreach ($selectors as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }

            for ($index = $nodes->length - 1; $index >= 0; $index--) {
                $node = $nodes->item($index);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $root = $document->getElementById('bitstream-live-preview-root');
        $clean_markup = '';
        if ($root) {
            foreach ($root->childNodes as $child_node) {
                $clean_markup .= $document->saveHTML($child_node);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        return $clean_markup !== '' ? $clean_markup : $markup;
    }

    /**
     * Return a friendlier message for PHP upload errors.
     *
     * @param int $error_code PHP UPLOAD_ERR_* code.
     * @return string
     */
    private function get_upload_error_message($error_code)
    {
        switch ((int) $error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'This file is larger than the site upload limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload interrupted before the file finished sending. Try again on a steadier connection or choose a smaller image.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server upload folder is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server could not save the uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload stopped by a server extension.';
            default:
                return 'Upload failed.';
        }
    }



    /**
     * Get common AJAX data for localization
     */
    public static function get_localized_data()
    {
        return [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'admin_url'          => admin_url(),
            'like_nonce'         => wp_create_nonce('bitstream_like_nonce'),
            'load_more_nonce'    => wp_create_nonce('bitstream_load_more_nonce'),
            'og_fetch_nonce'     => wp_create_nonce('bitstream_og_fetch_nonce'),
            'media_upload_nonce' => wp_create_nonce('bitstream_media_upload_nonce'),
            'media_crop_nonce'   => wp_create_nonce('bitstream_media_crop_nonce'),
            'delete_post_nonce'  => wp_create_nonce('bitstream_delete_post_nonce'),
            'composer_submit_nonce'=> wp_create_nonce('bitstream_composer_submit_nonce'),
            'feed_url'           => home_url('/bitstream/'),
            'composer_url'         => class_exists('BitStream_Shortcodes') ? BitStream_Shortcodes::get_feed_page_url() : home_url('/bitstream/')
        ];
    }

    public function __construct()
    {
        add_action('wp_ajax_bitstream_like', [$this, 'handle_like']);
        add_action('wp_ajax_nopriv_bitstream_like', [$this, 'handle_like']);
        add_action('wp_ajax_bitstream_load_more', [$this, 'handle_load_more']);
        add_action('wp_ajax_nopriv_bitstream_load_more', [$this, 'handle_load_more']);
        add_action('wp_ajax_bitstream_fetch_og_data', [$this, 'handle_fetch_og_data']);
        add_action('wp_ajax_bitstream_render_rebit_preview', [$this, 'handle_render_rebit_preview']);
        add_action('wp_ajax_bitstream_get_quoted_bit', [$this, 'handle_get_quoted_bit']);
        add_action('wp_ajax_bitstream_submit_composer', [$this, 'handle_submit_composer']);
        add_action('wp_ajax_bitstream_upload_media', [$this, 'handle_upload_media']);
        add_action('wp_ajax_bitstream_upload_media_chunk', [$this, 'handle_upload_media_chunk']);
        add_action('wp_ajax_bitstream_prepare_rebit_image_for_crop', [$this, 'handle_prepare_rebit_image_for_crop']);
        add_action('wp_ajax_bitstream_crop_media', [$this, 'handle_crop_media']);
        add_action('wp_ajax_bitstream_delete_post', [$this, 'handle_delete_post']);
        add_action('wp_ajax_bitstream_get_draft_data', [$this, 'handle_get_draft_data']);
        add_action('wp_ajax_bitstream_get_post_data', [$this, 'handle_get_post_data']);
        add_action('wp_ajax_bitstream_get_post_edit_data', [$this, 'handle_get_post_edit_data']);
        add_action('wp_ajax_bitstream_get_quote_preview', [$this, 'handle_get_quote_preview']);
        add_action('wp_ajax_bitstream_get_attachment_data', [$this, 'handle_get_attachment_data']);
        add_action('before_delete_post', [$this, 'handle_before_delete_post']);
    }

    /**
     * Attach media to a Bit post for ownership tracking and cleanup.
     */
    private function assign_attachment_to_bit($post_id, $attachment_id)
    {
        $attachment_id = $this->get_valid_attachment_id($attachment_id);
        if ($post_id <= 0 || $attachment_id <= 0) {
            return;
        }

        wp_update_post([
            'ID' => $attachment_id,
            'post_parent' => $post_id,
        ]);

        update_post_meta($post_id, '_bitstream_attachment_id', $attachment_id);
        delete_post_meta($attachment_id, '_bitstream_uploaded_via_composer');
        delete_post_meta($attachment_id, '_bitstream_upload_created_at');
    }

    /**
     * Collect possible attachment IDs referenced by a Bit (supports old and new formats).
     */
    private function collect_bit_attachment_ids($post_id)
    {
        $ids = [];

        $tracked_id = intval(get_post_meta($post_id, '_bitstream_attachment_id', true));
        if ($tracked_id > 0) {
            $ids[] = $tracked_id;
        }

        $thumb_id = intval(get_post_thumbnail_id($post_id));
        if ($thumb_id > 0) {
            $ids[] = $thumb_id;
        }

        $children = get_children([
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);
        if (!empty($children)) {
            $ids = array_merge($ids, array_map('intval', $children));
        }

        $content = (string)get_post_field('post_content', $post_id);
        if (!empty($content)) {
            if (preg_match_all('/wp-image-([0-9]+)/', $content, $m)) {
                foreach ($m[1] as $id) {
                    $ids[] = intval($id);
                }
            }

            if (preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', $content, $matches)) {
                foreach ($matches[1] as $url) {
                    $attachment_id = attachment_url_to_postid($url);
                    if ($attachment_id > 0) {
                        $ids[] = intval($attachment_id);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Check if an attachment is still referenced by any other post.
     */
    private function is_attachment_used_elsewhere($attachment_id, $exclude_post_id, $excluded_post_ids = [])
    {
        global $wpdb;

        $excluded_post_ids = is_array($excluded_post_ids) ? $excluded_post_ids : [];
        $excluded_post_ids[] = intval($exclude_post_id);
        $excluded_post_ids = array_values(array_unique(array_filter(array_map('intval', $excluded_post_ids))));
        if (empty($excluded_post_ids)) {
            $excluded_post_ids = [0];
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return true;
        }

        $parent_id = intval($attachment->post_parent);
        if ($parent_id > 0 && !in_array($parent_id, $excluded_post_ids, true)) {
            return true;
        }

        $excluded_placeholders = implode(',', array_fill(0, count($excluded_post_ids), '%d'));
        $meta_query = "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_value = %d
                             AND pm.post_id NOT IN ({$excluded_placeholders})
                             AND p.post_status NOT IN ('trash','auto-draft')
                         LIMIT 1";
        $meta_query_args = array_merge([$attachment_id], $excluded_post_ids);
        $meta_ref = $wpdb->get_var($wpdb->prepare($meta_query, $meta_query_args));
        if (!empty($meta_ref)) {
            return true;
        }

        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $url_like = '%' . $wpdb->esc_like($attachment_url) . '%';
            $url_ref = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID <> %d
                   AND post_status NOT IN ('trash','auto-draft','inherit')
                   AND post_content LIKE %s
                 LIMIT 1",
                $exclude_post_id,
                $url_like
            ));
            if (!empty($url_ref)) {
                return true;
            }
        }

        $class_like = '%wp-image-' . intval($attachment_id) . '%';
        $class_ref = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE ID <> %d
               AND post_status NOT IN ('trash','auto-draft','inherit')
               AND post_content LIKE %s
             LIMIT 1",
            $exclude_post_id,
            $class_like
        ));

        return !empty($class_ref);
    }

    /**
     * Delete orphaned attachments associated with a Bit post.
     */
    private function cleanup_bit_attachments($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'bit') {
            return;
        }

        $attachment_ids = $this->collect_bit_attachment_ids($post_id);
        if (empty($attachment_ids)) {
            return;
        }

        $excluded_reference_posts = array_values(array_unique(array_filter(array_map('intval', array_merge([$post_id], $attachment_ids)))));

        foreach ($attachment_ids as $attachment_id) {
            if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
                continue;
            }

            if ($this->is_attachment_used_elsewhere($attachment_id, $post_id, $excluded_reference_posts)) {
                continue;
            }

            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * Create a WordPress attachment from a file already moved into uploads.
     *
     * @param string $file_path Absolute uploaded file path.
     * @param string $file_url Public uploaded file URL.
     * @return array Attachment response data.
     * @throws Exception When validation or attachment creation fails.
     */
    private function create_media_attachment_from_upload($file_path, $file_url)
    {
        $file_type = wp_check_filetype(basename($file_path), null);
        $mime_type = $file_type['type'];

        if (empty($mime_type) || (strpos($mime_type, 'image/') !== 0 && strpos($mime_type, 'video/') !== 0)) {
            @unlink($file_path);
            throw new Exception('Unsupported file format. Only images and videos are allowed.');
        }

        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title' => sanitize_text_field(pathinfo($file_path, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attachment_id)) {
            throw new Exception('Could not create attachment.');
        }

        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        update_post_meta($attachment_id, '_bitstream_uploaded_via_composer', 1);
        update_post_meta($attachment_id, '_bitstream_upload_created_at', time());

        $preview_url = $file_url;
        if (strpos($file_type['type'], 'image/') === 0) {
            // Find the largest browser-safe generated size first
            $metadata = wp_get_attachment_metadata($attachment_id);
            $best_size = '';
            if (!empty($metadata['sizes'])) {
                // Priority list of sizes from largest to smallest, excluding thumbnail
                $sizes_to_check = ['large', 'medium_large', 'medium'];
                foreach ($sizes_to_check as $size) {
                    if (isset($metadata['sizes'][$size])) {
                        $best_size = $size;
                        break;
                    }
                }
            }

            if ($best_size) {
                $preview_url = wp_get_attachment_image_url($attachment_id, $best_size);
            } else {
                $preview_url = wp_get_attachment_image_url($attachment_id, 'medium');
            }

            if (!$preview_url) {
                $preview_url = wp_get_attachment_image_url($attachment_id, 'full');
            }
            if (!$preview_url) {
                $preview_url = $file_url;
            }
        }

        return [
            'id' => $attachment_id,
            'url' => $file_url,
            'preview_url' => $preview_url,
            'mime' => $file_type['type'],
            'edit_url' => get_edit_post_link($attachment_id, ''),
        ];
    }

    /**
     * Cleanup media when deleting bits from wp-admin flows.
     */
    public function handle_before_delete_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'bit') {
            return;
        }

        $this->cleanup_bit_attachments($post_id);
    }

    /**
     * Normalize attachment ID and ensure it's valid
     */
    private function get_valid_attachment_id($attachment_id)
    {
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
     * Extract a YouTube video ID from a URL.
     */
    private function get_youtube_video_id_from_url($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }

        $host = strtolower((string)$parts['host']);
        $path = isset($parts['path']) ? trim((string)$parts['path'], '/') : '';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }

        $video_id = '';

        if (strpos($host, 'youtu.be') !== false) {
            $video_id = $path;
        }
        elseif (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) {
            if (!empty($query['v'])) {
                $video_id = (string)$query['v'];
            }
            elseif (preg_match('#^(?:embed|shorts|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                $video_id = $m[1];
            }
        }

        $video_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$video_id);
        if (strlen($video_id) > 11) {
            $video_id = substr($video_id, 0, 11);
        }

        return (strlen($video_id) === 11) ? $video_id : '';
    }

    /**
     * Resolve embeddable preview metadata for a ReBit URL.
     */
    private function get_rebit_embed_preview_data($url)
    {
        $video_id = $this->get_youtube_video_id_from_url($url);
        if ($video_id !== '') {
            return [
                'is_embeddable' => true,
                'embed_type' => 'youtube',
                'embed_url' => 'https://www.youtube.com/embed/' . rawurlencode($video_id),
            ];
        }

        return [
            'is_embeddable' => false,
            'embed_type' => '',
            'embed_url' => '',
        ];
    }

    /**
     * Build media markup for Bit post content
     */
    private function build_media_markup($attachment_id)
    {
        if ($attachment_id <= 0) {
            return '';
        }

        if (wp_attachment_is('image', $attachment_id)) {
            $url = wp_get_attachment_image_url($attachment_id, 'full');
            $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $buster = get_post_modified_time('U', false, $attachment_id);
            return sprintf(
                '<img src="%s?t=%s" alt="%s" class="wp-image-%d" />',
                esc_url($url),
                $buster,
                esc_attr($alt),
                $attachment_id
            );
        }

        if (wp_attachment_is('video', $attachment_id)) {
            $video_url = wp_get_attachment_url($attachment_id);
            if ($video_url) {
                return sprintf(
                    '<video class="bitstream-video-attachment" controls preload="metadata" playsinline controlsList="nodownload noplaybackrate" disablepictureinpicture src="%s"></video>',
                    esc_url($video_url)
                );
            }
        }



        $file_url = wp_get_attachment_url($attachment_id);
        if ($file_url) {
            return '<p><a href="' . esc_url($file_url) . '" target="_blank" rel="noopener">Attached media</a></p>';
        }

        return '';
    }



    /**
     * Build post status and date args from schedule form inputs
     */
    private function build_schedule_args($schedule_enabled_key, $schedule_datetime_key)
    {
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
     * Handle media upload for composer drag-and-drop
     */
    public function handle_upload_media()
    {
        try {
            check_ajax_referer('bitstream_media_upload_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            if (empty($_FILES['media'])) {
                wp_send_json_error('No file uploaded.');
            }

            if (!empty($_FILES['media']['error'])) {
                wp_send_json_error($this->get_upload_error_message($_FILES['media']['error']));
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

            wp_send_json_success($this->create_media_attachment_from_upload($uploaded_file['file'], $uploaded_file['url']));
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Upload failed.');
        }
    }

    /**
     * Handle chunked media uploads for mobile browsers and flaky multipart requests.
     */
    public function handle_upload_media_chunk()
    {
        try {
            check_ajax_referer('bitstream_media_upload_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            if (empty($_FILES['chunk'])) {
                wp_send_json_error('No upload chunk received.');
            }

            if (!empty($_FILES['chunk']['error'])) {
                wp_send_json_error($this->get_upload_error_message($_FILES['chunk']['error']));
            }

            $upload_id = sanitize_key(wp_unslash($_POST['upload_id'] ?? ''));
            $chunk_index = isset($_POST['chunk_index']) ? intval($_POST['chunk_index']) : -1;
            $total_chunks = isset($_POST['total_chunks']) ? intval($_POST['total_chunks']) : 0;
            $filename = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));

            if (empty($upload_id) || empty($filename) || $chunk_index < 0 || $total_chunks <= 0 || $total_chunks > 1000 || $chunk_index >= $total_chunks) {
                wp_send_json_error('Invalid upload chunk.');
            }

            $uploads = wp_upload_dir();
            if (!empty($uploads['error'])) {
                wp_send_json_error($uploads['error']);
            }

            $chunk_dir = trailingslashit($uploads['basedir']) . 'bitstream-chunks/' . get_current_user_id() . '/' . $upload_id;
            if (!wp_mkdir_p($chunk_dir)) {
                wp_send_json_error('Could not prepare upload folder.');
            }

            $chunk_path = trailingslashit($chunk_dir) . sprintf('%06d.part', $chunk_index);
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
                wp_send_json_error('Could not save upload chunk.');
            }

            for ($index = 0; $index < $total_chunks; $index++) {
                if (!file_exists(trailingslashit($chunk_dir) . sprintf('%06d.part', $index))) {
                    wp_send_json_success([
                        'partial' => true,
                        'chunk_index' => $chunk_index,
                    ]);
                }
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $assembled_path = wp_tempnam($filename);
            if (!$assembled_path) {
                wp_send_json_error('Could not prepare uploaded file.');
            }

            $out = fopen($assembled_path, 'wb');
            if (!$out) {
                @unlink($assembled_path);
                wp_send_json_error('Could not assemble uploaded file.');
            }

            for ($index = 0; $index < $total_chunks; $index++) {
                $part_path = trailingslashit($chunk_dir) . sprintf('%06d.part', $index);
                $in = fopen($part_path, 'rb');
                if (!$in) {
                    fclose($out);
                    @unlink($assembled_path);
                    wp_send_json_error('Could not read upload chunk.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            for ($index = 0; $index < $total_chunks; $index++) {
                @unlink(trailingslashit($chunk_dir) . sprintf('%06d.part', $index));
            }
            @rmdir($chunk_dir);

            $file_array = [
                'name' => $filename,
                'type' => sanitize_mime_type(wp_unslash($_POST['mime'] ?? '')),
                'tmp_name' => $assembled_path,
                'error' => 0,
                'size' => filesize($assembled_path),
            ];

            $uploaded_file = wp_handle_sideload($file_array, [
                'test_form' => false,
            ]);

            if (isset($uploaded_file['error'])) {
                @unlink($assembled_path);
                wp_send_json_error($uploaded_file['error']);
            }

            wp_send_json_success($this->create_media_attachment_from_upload($uploaded_file['file'], $uploaded_file['url']));
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Upload failed.');
        }
    }

    /**
     * Fetch metadata for a given attachment ID.
     */
    public function handle_get_attachment_data()
    {
        try {
            check_ajax_referer('bitstream_media_upload_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
            if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
                wp_send_json_error('Invalid attachment ID.');
            }

            $file_url = wp_get_attachment_url($attachment_id);
            $mime_type = get_post_mime_type($attachment_id);

            $preview_url = $file_url;
            if (strpos($mime_type, 'image/') === 0) {
                // Find the largest browser-safe generated size first
                $metadata = wp_get_attachment_metadata($attachment_id);
                $best_size = '';
                if (!empty($metadata['sizes'])) {
                    $sizes_to_check = ['large', 'medium_large', 'medium'];
                    foreach ($sizes_to_check as $size) {
                        if (isset($metadata['sizes'][$size])) {
                            $best_size = $size;
                            break;
                        }
                    }
                }

                if ($best_size) {
                    $preview_url = wp_get_attachment_image_url($attachment_id, $best_size);
                } else {
                    $preview_url = wp_get_attachment_image_url($attachment_id, 'medium');
                }

                if (!$preview_url) {
                    $preview_url = wp_get_attachment_image_url($attachment_id, 'full');
                }
            }

            wp_send_json_success([
                'id' => $attachment_id,
                'url' => $file_url,
                'preview_url' => $preview_url ?: $file_url,
                'mime' => $mime_type,
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not get attachment data.');
        }
    }

    /**
     * Import a remote ReBit image URL as an attachment so it can be cropped.
     */
    public function handle_prepare_rebit_image_for_crop()
    {
        try {
            check_ajax_referer('bitstream_media_upload_nonce', 'nonce');

            if (!current_user_can('upload_files') || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('A valid image URL is required.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp_file = download_url($image_url, 20);
            if (is_wp_error($tmp_file)) {
                wp_send_json_error('Could not download image for cropping.');
            }

            $parsed_path = wp_parse_url($image_url, PHP_URL_PATH);
            $filename = $parsed_path ? wp_basename($parsed_path) : '';
            if (empty($filename) || strpos($filename, '.') === false) {
                $filename = 'bitstream-rebit-image.jpg';
            }

            $file_array = [
                'name' => sanitize_file_name($filename),
                'tmp_name' => $tmp_file,
            ];

            $attachment_id = media_handle_sideload($file_array, 0, 'BitStream ReBit image');
            if (is_wp_error($attachment_id) || !$attachment_id) {
                @unlink($tmp_file);
                wp_send_json_error('Could not prepare image for cropping.');
            }

            if (!wp_attachment_is_image($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
                wp_send_json_error('The fetched URL is not a valid image.');
            }

            update_post_meta($attachment_id, '_bitstream_uploaded_via_composer', 1);
            update_post_meta($attachment_id, '_bitstream_upload_created_at', time());

            wp_send_json_success([
                'id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
                'mime' => get_post_mime_type($attachment_id),
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not prepare image for cropping.');
        }
    }

    /**
     * Handle cropping an existing attachment image and replacing the file
     */
    public function handle_crop_media()
    {
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
            
            // Generate a new filename for the crop to avoid affecting other posts
            // Prefix with 'crop-' to avoid Windows path corruption when filenames start with digits
            $info = pathinfo($file_path);
            $new_filename = 'crop-' . $info['filename'] . '-' . time() . '.' . $info['extension'];
            $new_file_path = trailingslashit($info['dirname']) . $new_filename;

            $saved = $editor->save($new_file_path);
            if (is_wp_error($saved)) {
                wp_send_json_error('Could not save cropped image.');
            }

            // Create a new attachment for the cropped image
            $wp_upload_dir = wp_upload_dir();
            $attachment_args = [
                'guid' => $wp_upload_dir['url'] . '/' . $new_filename,
                'post_mime_type' => $saved['mime-type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $new_filename),
                'post_content' => '',
                'post_status' => 'inherit'
            ];

            $new_attachment_id = wp_insert_attachment($attachment_args, $new_file_path);
            if (is_wp_error($new_attachment_id)) {
                @unlink($new_file_path);
                wp_send_json_error('Could not create cropped attachment.');
            }

            $new_metadata = wp_generate_attachment_metadata($new_attachment_id, $new_file_path);
            wp_update_attachment_metadata($new_attachment_id, $new_metadata);

            // Copy alt text from original
            $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if ($alt_text) {
                update_post_meta($new_attachment_id, '_wp_attachment_image_alt', $alt_text);
            }

            // Mark the new attachment as uploaded via composer so it can be managed
            update_post_meta($new_attachment_id, '_bitstream_uploaded_via_composer', '1');

            // Check if we should delete the original to save space
            // Only delete if it was a temporary upload via composer and NOT used elsewhere
            $was_temp = get_post_meta($attachment_id, '_bitstream_uploaded_via_composer', true);
            if ($was_temp && !$this->is_attachment_used_elsewhere($attachment_id, 0)) {
                wp_delete_attachment($attachment_id, true);
            }

            wp_send_json_success([
                'id' => $new_attachment_id,
                'url' => wp_get_attachment_url($new_attachment_id),
                'mime' => get_post_mime_type($new_attachment_id),
                'cache_buster' => time(),
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Crop failed.');
        }
    }

    /**
     * Handle tabbed frontend composer submissions
     */
    public function handle_submit_composer()
    {
        try {
            check_ajax_referer('bitstream_composer_submit_nonce', 'nonce');

            if (!is_user_logged_in() || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $composer_type = sanitize_key($_POST['composer_type'] ?? '');
            if (!in_array($composer_type, ['bit', 'rebit'], true)) {
                wp_send_json_error('Invalid composer type.');
            }

            $save_as_draft = !empty($_POST['save_as_draft']) && $_POST['save_as_draft'] === '1';
            $is_auto_draft = !empty($_POST['is_auto_draft']) && $_POST['is_auto_draft'] === '1';
            $author_id = get_current_user_id();
            $edit_post_id = intval($_POST['edit_post_id'] ?? 0);
            $is_update = false;
            $editing_post = null;

            if ($edit_post_id > 0) {
                $editing_post = get_post($edit_post_id);
                if (!$editing_post || $editing_post->post_type !== 'bit') {
                    wp_send_json_error('Post not found for editing.');
                }

                if (!current_user_can('edit_post', $edit_post_id)) {
                    wp_send_json_error('Insufficient permissions to edit this post.');
                }

                $is_update = true;
            }

            if ($composer_type === 'bit') {
                if ($is_update && !empty(get_post_meta($edit_post_id, 'bitstream_rebit_url', true))) {
                    wp_send_json_error('This post is a Rebit. Edit it from the Rebit tab.');
                }

                $raw_content = wp_unslash($_POST['bit_content'] ?? '');
                $content = wp_kses_post($raw_content);
                $attachment_id = $this->get_valid_attachment_id($_POST['bit_attachment_id'] ?? 0);
                $quote_post_id = intval($_POST['quote_post_id'] ?? 0);
                $schedule = $this->build_schedule_args('bit_schedule_enabled', 'bit_schedule_datetime');

                if ($save_as_draft || $is_auto_draft) {
                    $schedule['post_status'] = 'draft';
                    $schedule['is_scheduled'] = false;
                    if ($is_update && $editing_post) {
                        $schedule['post_date'] = $editing_post->post_date;
                        $schedule['post_date_gmt'] = $editing_post->post_date_gmt;
                    }
                }
                elseif (
                $is_update
                && $editing_post
                && $editing_post->post_status === 'publish'
                && !$schedule['is_scheduled']
                ) {
                    $schedule['post_status'] = 'publish';
                    $schedule['post_date'] = $editing_post->post_date;
                    $schedule['post_date_gmt'] = $editing_post->post_date_gmt;
                }

                // Allow saving empty drafts for auto-save
                if (!$save_as_draft && !$is_auto_draft) {
                    if (trim(wp_strip_all_tags($content)) === '' && $attachment_id <= 0) {
                        wp_send_json_error('Bit content or media is required.');
                    }
                }

                $media_markup = $this->build_media_markup($attachment_id);
                $post_content = $content;
                if (!empty($media_markup)) {
                    $post_content .= (empty($post_content) ? '' : "\n\n") . $media_markup;
                }

                $post_args = [
                    'post_type' => 'bit',
                    'post_status' => $schedule['post_status'],
                    'post_content' => $post_content,
                    'comment_status' => 'open',
                    'post_date' => $schedule['post_date'],
                    'post_date_gmt' => $schedule['post_date_gmt'],
                ];

                if ($is_update) {
                    $post_args['ID'] = $edit_post_id;
                }
                else {
                    $post_args['post_author'] = $author_id;
                }

                $post_id = $is_update ? wp_update_post($post_args, true) : wp_insert_post($post_args, true);

                if (is_wp_error($post_id)) {
                    wp_send_json_error($is_update ? 'Failed to update Bit.' : 'Failed to create Bit.');
                }

                if ($quote_post_id > 0) {
                    $quoted_post = get_post($quote_post_id);
                    if ($quoted_post && $quoted_post->post_type === 'bit' && $quoted_post->post_status === 'publish') {
                        update_post_meta($post_id, '_bitstream_quoted_bit', $quote_post_id);
                    }
                }
                else {
                    delete_post_meta($post_id, '_bitstream_quoted_bit');
                }

                if ($attachment_id > 0) {
                    $this->assign_attachment_to_bit($post_id, $attachment_id);
                }
                else {
                    delete_post_meta($post_id, '_bitstream_attachment_id');
                }

                wp_send_json_success([
                    'message' => $save_as_draft
                    ? ($is_update ? 'Draft updated successfully.' : 'Saved as draft.')
                    : ($is_update
                    ? ($schedule['is_scheduled'] ? 'Bit updated and scheduled successfully.' : 'Bit updated successfully.')
                    : ($schedule['is_scheduled'] ? 'Bit scheduled successfully.' : 'Bit published successfully.')),
                    'post_id' => $post_id,
                    'permalink' => get_permalink($post_id),
                    'view_url' => ($save_as_draft || $schedule['is_scheduled']) ? get_preview_post_link($post_id) : get_permalink($post_id),
                    'edit_url' => get_edit_post_link($post_id, ''),
                    'rendered_html' => $is_update ? bitstream_render_card($post_id) : $this->sanitize_live_preview_markup(bitstream_render_card($post_id)),
                    'is_scheduled' => $schedule['is_scheduled'],
                    'is_draft' => $save_as_draft || $is_auto_draft,
                    'was_updated' => $is_update,
                    'draft_count' => (int) (new WP_Query([
                        'post_type' => 'bit',
                        'post_status' => 'draft',
                        'author' => $author_id,
                        'posts_per_page' => 1,
                        'fields' => 'ids',
                    ]))->found_posts,
                ]);
            }

            if ($is_update && empty(get_post_meta($edit_post_id, 'bitstream_rebit_url', true))) {
                wp_send_json_error('This post is a Bit. Edit it from the Bit tab.');
            }

            $url = esc_url_raw(wp_unslash($_POST['rebit_url'] ?? ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('A valid URL is required for Rebit.');
            }

            $commentary = wp_kses_post(wp_unslash($_POST['rebit_commentary'] ?? $_POST['bit_content'] ?? ''));
            $manual_title = sanitize_text_field(wp_unslash($_POST['rebit_og_title'] ?? ''));
            $manual_desc = sanitize_textarea_field(wp_unslash($_POST['rebit_og_desc'] ?? ''));
            $manual_image = esc_url_raw(wp_unslash($_POST['rebit_og_image'] ?? ''));
            $manual_image_removed = !empty($_POST['rebit_og_image_removed']) && strval($_POST['rebit_og_image_removed']) === '1';
            $attachment_id = $this->get_valid_attachment_id($_POST['rebit_attachment_id'] ?? 0);
            $schedule = $this->build_schedule_args('rebit_schedule_enabled', 'rebit_schedule_datetime');

            if ($save_as_draft || $is_auto_draft) {
                $schedule['post_status'] = 'draft';
                $schedule['is_scheduled'] = false;
                if ($is_update && $editing_post) {
                    $schedule['post_date'] = $editing_post->post_date;
                    $schedule['post_date_gmt'] = $editing_post->post_date_gmt;
                }
            }
            elseif (
            $is_update
            && $editing_post
            && $editing_post->post_status === 'publish'
            && !$schedule['is_scheduled']
            ) {
                $schedule['post_status'] = 'publish';
                $schedule['post_date'] = $editing_post->post_date;
                $schedule['post_date_gmt'] = $editing_post->post_date_gmt;
            }

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
            $og_image = $manual_image_removed ? '' : (!empty($manual_image) ? $manual_image : ($og_data['image'] ?? ''));

            if ($attachment_id > 0) {
                $attachment_image = wp_get_attachment_image_url($attachment_id, 'large');
                if ($attachment_image) {
                    $og_image = $attachment_image;
                }
            }

            $post_args = [
                'post_type' => 'bit',
                'post_status' => $schedule['post_status'],
                'post_content' => $commentary,
                'comment_status' => 'open',
                'post_date' => $schedule['post_date'],
                'post_date_gmt' => $schedule['post_date_gmt'],
            ];

            if ($is_update) {
                $post_args['ID'] = $edit_post_id;
            }
            else {
                $post_args['post_author'] = $author_id;
            }

            $post_id = $is_update ? wp_update_post($post_args, true) : wp_insert_post($post_args, true);

            if (is_wp_error($post_id)) {
                wp_send_json_error($is_update ? 'Failed to update Rebit.' : 'Failed to create Rebit.');
            }

            update_post_meta($post_id, 'bitstream_rebit_url', esc_url_raw($url));
            update_post_meta($post_id, '_bitstream_og_title', sanitize_text_field($og_title));
            update_post_meta($post_id, '_bitstream_og_desc', sanitize_text_field($og_desc));
            update_post_meta($post_id, '_bitstream_og_image', esc_url_raw($og_image));
            update_post_meta($post_id, '_bitstream_og_fetched', time());

            if ($attachment_id > 0) {
                $this->assign_attachment_to_bit($post_id, $attachment_id);
            }
            else {
                delete_post_meta($post_id, '_bitstream_attachment_id');
            }

            wp_send_json_success([
                'message' => $save_as_draft
                ? ($is_update ? 'Draft updated successfully.' : 'Saved as draft.')
                : ($is_update
                ? ($schedule['is_scheduled'] ? 'Rebit updated and scheduled successfully.' : 'Rebit updated successfully.')
                : ($schedule['is_scheduled'] ? 'Rebit scheduled successfully.' : 'Rebit published successfully.')),
                'post_id' => $post_id,
                'permalink' => get_permalink($post_id),
                'view_url' => ($save_as_draft || $schedule['is_scheduled']) ? get_preview_post_link($post_id) : get_permalink($post_id),
                'edit_url' => get_edit_post_link($post_id, ''),
                'rendered_html' => $is_update ? bitstream_render_card($post_id) : $this->sanitize_live_preview_markup(bitstream_render_card($post_id)),
                'is_scheduled' => $schedule['is_scheduled'],
                'is_draft' => $save_as_draft || $is_auto_draft,
                'was_updated' => $is_update,
                'draft_count' => (int) (new WP_Query([
                    'post_type' => 'bit',
                    'post_status' => 'draft',
                    'author' => $author_id,
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                ]))->found_posts,
                'og' => [
                    'title' => $og_title,
                    'description' => $og_desc,
                    'image' => $og_image,
                ]
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'An error occurred while creating the post.');
        }
    }

    /**
     * Handle like/unlike AJAX requests with security checks
     */
    public function handle_like()
    {
        try {
            check_ajax_referer('bitstream_like_nonce', 'nonce');

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

    /**
     * Handle delete Bit post requests
     */
    public function handle_delete_post()
    {
        try {
            if (!is_user_logged_in()) {
                wp_send_json_error('You must be logged in to delete posts.');
            }

            check_ajax_referer('bitstream_delete_post_nonce', 'nonce');

            if (empty($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
                wp_send_json_error('Invalid post ID.');
            }

            $post_id = intval($_POST['post_id']);
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'bit') {
                wp_send_json_error('Post not found.');
            }

            if (!current_user_can('delete_post', $post_id)) {
                wp_send_json_error('Insufficient permissions.');
            }

            $deleted = wp_delete_post($post_id, true);
            if (!$deleted) {
                wp_send_json_error('Could not delete post.');
            }

            wp_send_json_success(['post_id' => $post_id]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not delete post.');
        }
    }

    /**
     * Handle load more posts AJAX requests with security checks
     */
    public function handle_load_more()
    {
        check_ajax_referer('bitstream_load_more_nonce', 'nonce');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $is_preview_mode = isset($_POST['preview_mode']) && $_POST['preview_mode'] === '1';

        $requested_type = isset($_POST['filter_type']) ? sanitize_key(wp_unslash($_POST['filter_type'])) : 'all';
        $selected_type = in_array($requested_type, ['all', 'bits', 'rebits'], true) ? $requested_type : 'all';

        $requested_month = isset($_POST['filter_month']) ? sanitize_text_field(wp_unslash($_POST['filter_month'])) : '';
        $selected_month = preg_match('/^\d{4}-\d{2}$/', $requested_month) ? $requested_month : '';

        $requested_search = isset($_POST['filter_search']) ? sanitize_text_field(wp_unslash($_POST['filter_search'])) : '';
        $selected_search = trim($requested_search);

        $query_args = [
            'post_type' => 'bit',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if ($selected_type === 'bits') {
            $query_args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'bitstream_rebit_url',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '=',
                ],
            ];
        }
        elseif ($selected_type === 'rebits') {
            $query_args['meta_query'] = [
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '!=',
                ],
            ];
        }

        if (!empty($selected_month)) {
            [$year, $month] = explode('-', $selected_month);
            $query_args['date_query'] = [[
                    'year' => intval($year),
                    'monthnum' => intval($month),
                ]];
        }

        if (!empty($selected_search)) {
            $query_args['s'] = $selected_search;
        }

        $requested_hashtag = isset($_POST['filter_hashtag']) ? sanitize_text_field(wp_unslash($_POST['filter_hashtag'])) : '';
        $selected_hashtag = preg_match('/^[A-Za-z][A-Za-z0-9_\x{00C0}-\x{024F}]*$/u', $requested_hashtag) ? $requested_hashtag : '';

        if (!empty($selected_hashtag)) {
            $hashtag_term = $selected_hashtag;
            add_filter('posts_where', $bitstream_hashtag_where = static function ($where) use ($hashtag_term) {
                global $wpdb;
                $like = '%#' . $wpdb->esc_like($hashtag_term) . '%';
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_content LIKE %s", $like);
                return $where;
            });
        }

        $q = new WP_Query($query_args);

        if (!empty($selected_hashtag) && isset($bitstream_hashtag_where)) {
            remove_filter('posts_where', $bitstream_hashtag_where);
        }

        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                echo bitstream_render_card(get_the_ID(), false, ['comment_action' => $is_preview_mode ? 'link' : 'toggle']);
            }
        }

        wp_reset_postdata();
        wp_die();
    }

    /**
     * Handle OG data fetch for ReBit block preview
     */
    public function handle_fetch_og_data()
    {
        try {
            check_ajax_referer('bitstream_og_fetch_nonce', 'nonce');

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream OG Fetch: request received');
            }

            // Check permissions
            if (!current_user_can('edit_posts')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream OG Fetch: insufficient permissions');
                }
                wp_send_json_error('Insufficient permissions.');
            }

            $url = sanitize_url($_POST['url'] ?? '');
            $post_id = intval($_POST['post_id'] ?? 0);

            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream OG Fetch: invalid URL supplied');
                }
                wp_send_json_error('Invalid URL.');
            }

            $embed_data = $this->get_rebit_embed_preview_data($url);

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
                        'cached' => true,
                        'is_embeddable' => $embed_data['is_embeddable'],
                        'embed_type' => $embed_data['embed_type'],
                        'embed_url' => $embed_data['embed_url'],
                    ]);
                    return;
                }
            }

            // Fetch utilizing the robust OG Fetcher class we just upgraded
            $og_title = $og_desc = $og_img = '';
            if (class_exists('BitStream_OG_Fetcher')) {
                $fetcher = new BitStream_OG_Fetcher();
                $fetched = $fetcher->fetch_og_data($url);
                if (is_array($fetched)) {
                    $og_title = $fetched['title'] ?? '';
                    $og_desc = $fetched['description'] ?? '';
                    $og_img = $fetched['image'] ?? '';
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
                'stored' => $post_id > 0,
                'is_embeddable' => $embed_data['is_embeddable'],
                'embed_type' => $embed_data['embed_type'],
                'embed_url' => $embed_data['embed_url'],
            ]);

        }
        catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching preview data.');
        }
    }

    /**
     * Render a live ReBit preview using the same frontend card renderer.
     */
    public function handle_render_rebit_preview()
    {
        try {
            check_ajax_referer('bitstream_og_fetch_nonce', 'nonce');

            if (!is_user_logged_in() || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $url = esc_url_raw(wp_unslash($_POST['rebit_url'] ?? ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('A valid URL is required for preview.');
            }

            $commentary = wp_kses_post(wp_unslash($_POST['rebit_commentary'] ?? ''));
            $manual_title = sanitize_text_field(wp_unslash($_POST['rebit_og_title'] ?? ''));
            $manual_desc = sanitize_textarea_field(wp_unslash($_POST['rebit_og_desc'] ?? ''));
            $manual_image = esc_url_raw(wp_unslash($_POST['rebit_og_image'] ?? ''));
            $manual_image_removed = !empty($_POST['rebit_og_image_removed']) && strval($_POST['rebit_og_image_removed']) === '1';
            $attachment_id = $this->get_valid_attachment_id($_POST['rebit_attachment_id'] ?? 0);

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
            $og_image = $manual_image_removed ? '' : (!empty($manual_image) ? $manual_image : ($og_data['image'] ?? ''));

            if ($attachment_id > 0 && empty($manual_image)) {
                $attachment_image = wp_get_attachment_image_url($attachment_id, 'large');
                if ($attachment_image) {
                    $og_image = $attachment_image;
                }
            }

            $preview_post_id = wp_insert_post([
                'post_type' => 'bit',
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
                'post_content' => $commentary,
                'comment_status' => 'closed',
            ], true);

            if (is_wp_error($preview_post_id) || !$preview_post_id) {
                wp_send_json_error('Could not build live preview.');
            }

            update_post_meta($preview_post_id, 'bitstream_rebit_url', esc_url_raw($url));
            update_post_meta($preview_post_id, '_bitstream_og_title', sanitize_text_field($og_title));
            update_post_meta($preview_post_id, '_bitstream_og_desc', sanitize_text_field($og_desc));
            update_post_meta($preview_post_id, '_bitstream_og_image', esc_url_raw($og_image));

            $rendered_html = $this->sanitize_live_preview_markup(bitstream_render_rebit_section($preview_post_id));
            wp_delete_post($preview_post_id, true);

            wp_send_json_success([
                'rendered_html' => $rendered_html,
                'og' => [
                    'title' => $og_title,
                    'description' => $og_desc,
                    'image' => $og_image,
                ],
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not render preview.');
        }
    }

    /**
     * Handle getting quoted bit content for block editor display
     */
    public function handle_get_quoted_bit()
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BitStream: handle_get_quoted_bit called');
        }

        try {
            check_ajax_referer('bitstream_og_fetch_nonce', 'nonce');

            // Check permissions
            if (!current_user_can('edit_posts')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream: quoted bit permission check failed');
                }
                wp_send_json_error('Insufficient permissions.');
            }

            $quoted_bit_id = intval($_POST['quoted_bit_id'] ?? 0);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BitStream: quoted bit request received');
            }

            if ($quoted_bit_id <= 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BitStream: invalid quoted bit id');
                }
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

        }
        catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching quoted bit.');
        }
    }

    /**
     * Return draft post data for loading into the composer.
     */
    public function handle_get_draft_data()
    {
        try {
            check_ajax_referer('bitstream_composer_submit_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $post_id = intval($_POST['post_id'] ?? 0);
            if ($post_id <= 0) {
                wp_send_json_error('Invalid post ID.');
            }

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'bit' || $post->post_status !== 'draft') {
                wp_send_json_error('Draft not found.');
            }

            if ((int) $post->post_author !== get_current_user_id()) {
                wp_send_json_error('You do not own this draft.');
            }

            $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
            $is_rebit = !empty($rebit_url);

            $attachment_id = intval(get_post_meta($post_id, '_bitstream_attachment_id', true));
            if ($attachment_id <= 0) {
                $attachment_id = intval(get_post_thumbnail_id($post_id));
            }

            $data = [
                'post_id'       => $post_id,
                'content'       => $post->post_content,
                'is_rebit'      => $is_rebit,
                'rebit_url'     => $is_rebit ? $rebit_url : '',
                'og_title'      => $is_rebit ? get_post_meta($post_id, 'bitstream_rebit_og_title', true) : '',
                'og_desc'       => $is_rebit ? get_post_meta($post_id, 'bitstream_rebit_og_desc', true) : '',
                'og_image'      => $is_rebit ? get_post_meta($post_id, 'bitstream_rebit_og_image', true) : '',
                'attachment_url'    => $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : '',
                'attachment_mime'   => $attachment_id > 0 ? get_post_mime_type($attachment_id) : '',
                'attachment_id' => $attachment_id > 0 ? $attachment_id : '',
            ];

            wp_send_json_success($data);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not load draft.');
        }
    }

    /**
     * Return post data for editing in the timeline modal.
     */
    public function handle_get_post_data()
    {
        try {
            check_ajax_referer('bitstream_composer_submit_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $post_id = intval($_POST['post_id'] ?? 0);
            if ($post_id <= 0) {
                wp_send_json_error('Invalid post ID.');
            }

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'bit') {
                wp_send_json_error('Post not found.');
            }

            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error('You do not have permission to edit this post.');
            }

            $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
            $is_rebit = !empty($rebit_url);

            // Resolve primary attachment
            $attachment_id = intval(get_post_meta($post_id, '_bitstream_attachment_id', true));
            if ($attachment_id <= 0) {
                $attachment_id = intval(get_post_thumbnail_id($post_id));
            }
            if ($attachment_id <= 0) {
                $children = get_children([
                    'post_parent' => $post_id,
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'fields' => 'ids',
                    'numberposts' => 1,
                ]);
                if (!empty($children)) {
                    $attachment_id = intval(reset($children));
                }
            }

            $media_preview_html = '';
            if ($attachment_id > 0) {
                if (wp_attachment_is('image', $attachment_id)) {
                    $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
                    $media_preview_html = sprintf('<img src="%s" style="max-height:80px;border-radius:4px;" />', esc_url($url));
                } elseif (wp_attachment_is('video', $attachment_id)) {
                    $media_preview_html = '<div style="font-size:0.9rem;color:#666;"><i class="fa-solid fa-video"></i> Video attached</div>';

                } else {
                    $media_preview_html = '<div style="font-size:0.9rem;color:#666;"><i class="fa-solid fa-file"></i> Media attached</div>';
                }
            }

            $schedule_enabled = '0';
            $schedule_datetime = '';
            if ($post->post_status === 'future') {
                $schedule_enabled = '1';
                $schedule_datetime = mysql2date('Y-m-d\TH:i', $post->post_date, false);
            }

            // Strip media markup from the post body for textarea editing
            $content = (string)$post->post_content;
            $content = strip_shortcodes($content);
            $content = preg_replace('#<figure[^>]*>[\s\S]*?</figure>#i', '', $content);
            $content = preg_replace('#<(audio|video)[^>]*>[\s\S]*?</\1>#i', '', $content);
            $content = preg_replace('#<img[^>]*>#i', '', $content);
            $content = trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES, 'UTF-8'));

            $data = [
                'post_id'           => $post_id,
                'content'           => $content,
                'is_rebit'          => $is_rebit,
                'rebit_url'         => $is_rebit ? $rebit_url : '',
                'og_title'          => $is_rebit ? get_post_meta($post_id, '_bitstream_og_title', true) : '',
                'og_desc'           => $is_rebit ? get_post_meta($post_id, '_bitstream_og_desc', true) : '',
                'og_image'          => $is_rebit ? get_post_meta($post_id, '_bitstream_og_image', true) : '',
                'attachment_id'     => $attachment_id > 0 ? $attachment_id : '',
                'media_preview_html'=> $media_preview_html,
                'schedule_enabled'  => $schedule_enabled,
                'schedule_datetime' => $schedule_datetime,
            ];

            wp_send_json_success($data);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not load post data.');
        }
    }

    /**
     * Return JSON field data for the dedicated timeline edit modal.
     */
    public function handle_get_post_edit_data()
    {
        try {
            check_ajax_referer('bitstream_composer_submit_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $post_id = intval($_POST['post_id'] ?? 0);
            if ($post_id <= 0) {
                wp_send_json_error('Invalid post ID.');
            }

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'bit') {
                wp_send_json_error('Post not found.');
            }

            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error('You do not have permission to edit this post.');
            }

            $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
            $is_rebit  = !empty($rebit_url);

            // Resolve primary attachment
            $attachment_id  = intval(get_post_meta($post_id, '_bitstream_attachment_id', true));
            if ($attachment_id <= 0) {
                $attachment_id = intval(get_post_thumbnail_id($post_id));
            }
            if ($attachment_id <= 0) {
                $children = get_children([
                    'post_parent' => $post_id,
                    'post_type'   => 'attachment',
                    'post_status' => 'inherit',
                    'fields'      => 'ids',
                    'numberposts' => 1,
                ]);
                if (!empty($children)) {
                    $attachment_id = intval(reset($children));
                }
            }

            $attachment_url  = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : '';
            $attachment_mime = $attachment_id > 0 ? get_post_mime_type($attachment_id) : '';

            $schedule_enabled = '0';
            $schedule_datetime = '';
            if ($post->post_status === 'future') {
                $schedule_enabled = '1';
                $schedule_datetime = mysql2date('Y-m-d\TH:i', $post->post_date, false);
            }

            // Strip raw media embeds so only the text is returned for editing
            $editable_content = preg_replace(
                '#<(audio|video)[^>]*>[\s\S]*?</\1>#i',
                '',
                wp_strip_all_tags($post->post_content)
            );
            $editable_content = trim($editable_content);

            $data = [
                'post_id'         => $post_id,
                'post_type'       => $is_rebit ? 'rebit' : 'bit',
                'post_status'     => $post->post_status,
                'content'         => $editable_content,
                'attachment_id'   => $attachment_id,
                'attachment_url'  => $attachment_url ?: '',
                'attachment_mime' => $attachment_mime ?: '',
                'schedule_enabled' => $schedule_enabled,
                'schedule_datetime'=> $schedule_datetime,
            ];

            if ($is_rebit) {
                $data['rebit_url'] = esc_url($rebit_url);
                $data['og_title']  = get_post_meta($post_id, '_bitstream_og_title', true);
                $data['og_desc']   = get_post_meta($post_id, '_bitstream_og_desc', true);
                $data['og_image']  = get_post_meta($post_id, '_bitstream_og_image', true);
                $data['quote_post_id'] = 0;
            }
            else {
                $quote_post_id = intval(get_post_meta($post_id, '_bitstream_quoted_bit', true));
                $data['quote_post_id'] = $quote_post_id > 0 ? $quote_post_id : 0;
                if ($quote_post_id > 0 && function_exists('bitstream_render_nested_quoted_card')) {
                    $data['quote_preview_html'] = bitstream_render_nested_quoted_card($quote_post_id);
                }
            }

            wp_send_json_success($data);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not load post data.');
        }
    }

    /**
     * Return quote preview markup for the timeline modal.
     */
    public function handle_get_quote_preview()
    {
        try {
            check_ajax_referer('bitstream_composer_submit_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $post_id = intval($_POST['post_id'] ?? 0);
            if ($post_id <= 0) {
                wp_send_json_error('Invalid post ID.');
            }

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'bit' || $post->post_status !== 'publish') {
                wp_send_json_error('Post not found.');
            }

            $quote_preview_html = function_exists('bitstream_render_nested_quoted_card')
                ? bitstream_render_nested_quoted_card($post_id)
                : '';

            if ($quote_preview_html === '') {
                wp_send_json_error('Could not build quote preview.');
            }

            wp_send_json_success([
                'post_id' => $post_id,
                'post_title' => get_the_title($post_id),
                'quote_preview_html' => $quote_preview_html,
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Could not load quote preview.');
        }
    }
}
