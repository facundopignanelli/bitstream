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
     * Keep live poster previews visually accurate while removing interactive markup
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
     * Build a small attachment from embedded audio artwork and return id/url pair.
     */
    private function create_embedded_artwork_attachment($audio_attachment_id, $binary_data, $mime_type)
    {
        $data_uri = $this->optimize_embedded_artwork_data_uri($binary_data, $mime_type, 192);
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $data_uri, $matches)) {
            return [];
        }

        $optimized_mime = sanitize_mime_type($matches[1]);
        $optimized_binary = base64_decode($matches[2], true);
        if ($optimized_binary === false || $optimized_binary === '') {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return [];
        }

        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $ext_map[$optimized_mime] ?? 'jpg';

        $relative_dir = 'bitstream-artwork';
        $target_dir = trailingslashit($upload_dir['basedir']) . $relative_dir;
        if (!wp_mkdir_p($target_dir)) {
            return [];
        }

        $hash = substr(md5($optimized_binary), 0, 10);
        $filename = 'bitstream-audio-artwork-' . intval($audio_attachment_id) . '-' . $hash . '.' . $extension;
        $filepath = trailingslashit($target_dir) . $filename;

        if (file_put_contents($filepath, $optimized_binary) === false) {
            return [];
        }

        $filetype = wp_check_filetype($filename, null);
        $attachment_post = [
            'post_mime_type' => $filetype['type'] ?: $optimized_mime,
            'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => intval($audio_attachment_id),
        ];

        $artwork_attachment_id = wp_insert_attachment($attachment_post, $filepath, 0);
        if (is_wp_error($artwork_attachment_id) || !$artwork_attachment_id) {
            @unlink($filepath);
            return [];
        }

        $metadata = wp_generate_attachment_metadata($artwork_attachment_id, $filepath);
        if (!is_wp_error($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($artwork_attachment_id, $metadata);
        }

        $thumb_url = wp_get_attachment_image_url($artwork_attachment_id, 'thumbnail');
        $url = $thumb_url ? $thumb_url : wp_get_attachment_url($artwork_attachment_id);

        if (!$url) {
            wp_delete_attachment($artwork_attachment_id, true);
            return [];
        }

        return [
            'artwork_id' => intval($artwork_attachment_id),
            'artwork' => $url,
        ];
    }

    /**
     * Resize embedded artwork binary to a compact square size and return a data URI.
     */
    private function optimize_embedded_artwork_data_uri($binary_data, $mime_type, $max_dimension = 192)
    {
        if (empty($binary_data) || empty($mime_type)) {
            return '';
        }

        if (!function_exists('wp_get_image_editor')) {
            return 'data:' . sanitize_mime_type($mime_type) . ';base64,' . base64_encode($binary_data);
        }

        $mime = sanitize_mime_type($mime_type);
        $extension_map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $extension_map[$mime] ?? 'jpg';

        $tmp_input = wp_tempnam('bitstream-audio-artwork.' . $extension);
        if (!$tmp_input) {
            return 'data:' . $mime . ';base64,' . base64_encode($binary_data);
        }

        file_put_contents($tmp_input, $binary_data);

        $editor = wp_get_image_editor($tmp_input);
        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            if (is_array($size) && isset($size['width'], $size['height'])) {
                $width = intval($size['width']);
                $height = intval($size['height']);
                if ($width > $max_dimension || $height > $max_dimension) {
                    $editor->resize($max_dimension, $max_dimension, false);
                }
            }

            $saved = $editor->save($tmp_input);
            if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                $optimized_binary = file_get_contents($saved['path']);
                $optimized_mime = !empty($saved['mime-type']) ? sanitize_mime_type($saved['mime-type']) : $mime;

                @unlink($saved['path']);
                if ($saved['path'] !== $tmp_input) {
                    @unlink($tmp_input);
                }

                if ($optimized_binary !== false) {
                    return 'data:' . $optimized_mime . ';base64,' . base64_encode($optimized_binary);
                }
            }
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagesx') && function_exists('imagesy')) {
            $source = @imagecreatefromstring($binary_data);
            if ($source !== false) {
                $src_w = imagesx($source);
                $src_h = imagesy($source);
                $scale = ($src_w > 0 && $src_h > 0) ? min(1, $max_dimension / max($src_w, $src_h)) : 1;

                if ($scale < 1) {
                    $dst_w = max(1, (int)round($src_w * $scale));
                    $dst_h = max(1, (int)round($src_h * $scale));
                    $resized = imagecreatetruecolor($dst_w, $dst_h);
                    if ($resized !== false) {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                        imagecopyresampled($resized, $source, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

                        ob_start();
                        if ($mime === 'image/png') {
                            imagepng($resized, null, 6);
                            $out_mime = 'image/png';
                        }
                        else if ($mime === 'image/gif') {
                            imagegif($resized);
                            $out_mime = 'image/gif';
                        }
                        else {
                            imagejpeg($resized, null, 82);
                            $out_mime = 'image/jpeg';
                        }
                        $optimized_binary = ob_get_clean();

                        imagedestroy($resized);
                        imagedestroy($source);
                        @unlink($tmp_input);

                        if (!empty($optimized_binary)) {
                            return 'data:' . $out_mime . ';base64,' . base64_encode($optimized_binary);
                        }
                    }
                }

                imagedestroy($source);
            }
        }

        @unlink($tmp_input);
        return 'data:' . $mime . ';base64,' . base64_encode($binary_data);
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
        add_action('wp_ajax_bitstream_submit_poster', [$this, 'handle_submit_poster']);
        add_action('wp_ajax_bitstream_upload_media', [$this, 'handle_upload_media']);
        add_action('wp_ajax_bitstream_prepare_rebit_image_for_crop', [$this, 'handle_prepare_rebit_image_for_crop']);
        add_action('wp_ajax_bitstream_crop_media', [$this, 'handle_crop_media']);
        add_action('wp_ajax_bitstream_get_audio_meta', [$this, 'handle_get_audio_meta']);
        add_action('wp_ajax_bitstream_update_audio_meta', [$this, 'handle_update_audio_meta']);
        add_action('wp_ajax_bitstream_delete_post', [$this, 'handle_delete_post']);
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
        delete_post_meta($attachment_id, '_bitstream_uploaded_via_poster');
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

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        foreach ($ids as $id) {
            $generated_artwork_id = intval(get_post_meta($id, '_bitstream_generated_artwork_id', true));
            if ($generated_artwork_id > 0) {
                $ids[] = $generated_artwork_id;
            }

            if (wp_attachment_is('audio', $id)) {
                $audio_meta = get_post_meta($id, '_bitstream_audio_meta', true);
                if (is_array($audio_meta)) {
                    $audio_artwork_id = intval($audio_meta['artwork_id'] ?? 0);
                    if ($audio_artwork_id > 0) {
                        $ids[] = $audio_artwork_id;
                    }
                    elseif (!empty($audio_meta['artwork'])) {
                        $audio_artwork_url_id = attachment_url_to_postid((string)$audio_meta['artwork']);
                        if ($audio_artwork_url_id > 0) {
                            $ids[] = intval($audio_artwork_url_id);
                        }
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

        $audio_meta_like = '%"artwork_id";i:' . intval($attachment_id) . ';%';
        $audio_meta_query = "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_bitstream_audio_meta'
                             AND pm.meta_value LIKE %s
                             AND pm.post_id NOT IN ({$excluded_placeholders})
                             AND p.post_status NOT IN ('trash','auto-draft')
                         LIMIT 1";
        $audio_meta_query_args = array_merge([$audio_meta_like], $excluded_post_ids);
        $audio_meta_ref = $wpdb->get_var($wpdb->prepare($audio_meta_query, $audio_meta_query_args));
        if (!empty($audio_meta_ref)) {
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
                $artwork_id = isset($audio_meta['artwork_id']) ? intval($audio_meta['artwork_id']) : 0;

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

                $has_artwork = !empty($artwork) || $artwork_id > 0;
                $artwork_markup = '';
                if ($has_artwork) {
                    if ($artwork_id > 0) {
                        $thumb_url = wp_get_attachment_image_url($artwork_id, 'thumbnail');
                        if (!$thumb_url) {
                            $thumb_url = wp_get_attachment_url($artwork_id);
                        }
                        if ($thumb_url) {
                            $artwork_markup = '<div class="bitstream-audio-artwork-wrap"><img class="bitstream-audio-artwork" src="' . esc_url($thumb_url) . '" loading="lazy" decoding="async" alt=""></div>';
                        }
                    }

                    if (!$artwork_markup && !empty($artwork)) {
                        $artwork_markup = '<div class="bitstream-audio-artwork-wrap"><img class="bitstream-audio-artwork" src="' . esc_attr($artwork) . '" alt=""></div>';
                    }
                }

                return '<div class="bitstream-audio-embed' . ($artwork_markup ? '' : ' no-artwork') . '">'
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
    private function get_audio_meta($attachment_id, $file_path = '')
    {
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
            $thumb_url = wp_get_attachment_image_url($meta['artwork_id'], 'thumbnail');
            $meta['artwork'] = $thumb_url ? $thumb_url : wp_get_attachment_url($meta['artwork_id']);
        }

        if (empty($meta['artwork']) && !empty($raw['image']['data']) && !empty($raw['image']['mime'])) {
            $generated = $this->create_embedded_artwork_attachment(
                $attachment_id,
                $raw['image']['data'],
                $raw['image']['mime']
            );

            if (!empty($generated['artwork'])) {
                $meta['artwork'] = $generated['artwork'];
                $meta['artwork_id'] = intval($generated['artwork_id']);
                update_post_meta($attachment_id, '_bitstream_generated_artwork_id', intval($generated['artwork_id']));
            }
            else {
                $meta['artwork'] = $this->optimize_embedded_artwork_data_uri(
                    $raw['image']['data'],
                    $raw['image']['mime'],
                    192
                );
            }
        }

        $meta = array_merge($meta, array_intersect_key($stored, $meta));

        if (!empty($meta['artwork_id'])) {
            $thumb_url = wp_get_attachment_image_url(intval($meta['artwork_id']), 'thumbnail');
            if ($thumb_url) {
                $meta['artwork'] = $thumb_url;
            }
        }

        $meta = array_filter($meta, static function ($value) {
            return $value !== '' && $value !== null && $value !== false;
        });

        update_post_meta($attachment_id, '_bitstream_audio_meta', $meta);
        return $meta;
    }

    /**
     * Fetch stored audio metadata
     */
    public function handle_get_audio_meta()
    {
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
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Unable to fetch audio metadata.');
        }
    }

    /**
     * Update audio tags and artwork
     */
    public function handle_update_audio_meta()
    {
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
            $artwork_clear = isset($_POST['artwork_clear']) && intval($_POST['artwork_clear']) === 1;
            $raw_artwork_url = trim(wp_unslash($_POST['artwork_url'] ?? ''));
            $existing_meta = get_post_meta($attachment_id, '_bitstream_audio_meta', true);
            $existing_meta = is_array($existing_meta) ? $existing_meta : [];
            $generated_artwork_id = intval(get_post_meta($attachment_id, '_bitstream_generated_artwork_id', true));

            if ($artwork_clear) {
                $artwork_id = 0;
                $artwork_url = '';
                if ($generated_artwork_id > 0) {
                    wp_delete_attachment($generated_artwork_id, true);
                    delete_post_meta($attachment_id, '_bitstream_generated_artwork_id');
                }
            }
            else {
                if ($artwork_id > 0 && wp_attachment_is('image', $artwork_id)) {
                    $thumb_url = wp_get_attachment_image_url($artwork_id, 'thumbnail');
                    $artwork_url = $thumb_url ? $thumb_url : wp_get_attachment_url($artwork_id);
                    if ($generated_artwork_id > 0 && $generated_artwork_id !== $artwork_id) {
                        wp_delete_attachment($generated_artwork_id, true);
                        delete_post_meta($attachment_id, '_bitstream_generated_artwork_id');
                    }
                }
                else {
                    $artwork_id = 0;

                    if ($raw_artwork_url !== '') {
                        if (preg_match('#^data:image\/[a-zA-Z0-9.+-]+;base64,#', $raw_artwork_url)) {
                            $artwork_url = $raw_artwork_url;
                        }
                        else {
                            $artwork_url = esc_url_raw($raw_artwork_url);
                        }

                        if ($generated_artwork_id > 0) {
                            wp_delete_attachment($generated_artwork_id, true);
                            delete_post_meta($attachment_id, '_bitstream_generated_artwork_id');
                        }
                    }
                    else {
                        $artwork_url = isset($existing_meta['artwork']) ? (string)$existing_meta['artwork'] : '';
                        $artwork_id = isset($existing_meta['artwork_id']) ? intval($existing_meta['artwork_id']) : 0;
                    }
                }
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
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Unable to update audio metadata.');
        }
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
     * Handle media upload for poster drag-and-drop
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

            update_post_meta($attachment_id, '_bitstream_uploaded_via_poster', 1);
            update_post_meta($attachment_id, '_bitstream_upload_created_at', time());

            $audio_meta = [];
            if (strpos($file_type['type'], 'audio/') === 0) {
                $audio_meta = $this->get_audio_meta($attachment_id, $file_path);
            }

            $preview_url = $file_url;
            if (strpos($file_type['type'], 'image/') === 0) {
                $preview_url = wp_get_attachment_image_url($attachment_id, 'medium');
                if (!$preview_url) {
                    $preview_url = wp_get_attachment_image_url($attachment_id, 'full');
                }
                if (!$preview_url) {
                    $preview_url = $file_url;
                }
            }

            wp_send_json_success([
                'id' => $attachment_id,
                'url' => $file_url,
                'preview_url' => $preview_url,
                'mime' => $file_type['type'],
                'audio_meta' => $audio_meta,
                'edit_url' => get_edit_post_link($attachment_id, ''),
            ]);
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Upload failed.');
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

            update_post_meta($attachment_id, '_bitstream_uploaded_via_poster', 1);
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
        }
        catch (Exception $e) {
            wp_send_json_error($e->getMessage() ?: 'Crop failed.');
        }
    }

    /**
     * Handle tabbed frontend poster submissions
     */
    public function handle_submit_poster()
    {
        try {
            check_ajax_referer('bitstream_poster_submit_nonce', 'nonce');

            if (!is_user_logged_in() || !current_user_can('edit_posts')) {
                wp_send_json_error('Insufficient permissions.');
            }

            $poster_type = sanitize_key($_POST['poster_type'] ?? '');
            if (!in_array($poster_type, ['bit', 'rebit'], true)) {
                wp_send_json_error('Invalid poster type.');
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

            if ($poster_type === 'bit') {
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
                    'rendered_html' => $this->sanitize_live_preview_markup(bitstream_render_card($post_id)),
                    'is_scheduled' => $schedule['is_scheduled'],
                    'is_draft' => $save_as_draft || $is_auto_draft,
                    'was_updated' => $is_update,
                ]);
            }

            if ($is_update && empty(get_post_meta($edit_post_id, 'bitstream_rebit_url', true))) {
                wp_send_json_error('This post is a Bit. Edit it from the Bit tab.');
            }

            $url = esc_url_raw(wp_unslash($_POST['rebit_url'] ?? ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error('A valid URL is required for Rebit.');
            }

            $commentary = wp_kses_post(wp_unslash($_POST['rebit_commentary'] ?? ''));
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

            if ($attachment_id > 0 && empty($manual_image)) {
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
                'rendered_html' => $this->sanitize_live_preview_markup(bitstream_render_card($post_id)),
                'is_scheduled' => $schedule['is_scheduled'],
                'is_draft' => $save_as_draft || $is_auto_draft,
                'was_updated' => $is_update,
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

            $rendered_html = $this->sanitize_live_preview_markup(bitstream_render_card($preview_post_id));
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
}
