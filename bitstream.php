<?php
/**
 * Plugin Name: BitStream
 * Description: A lightweight microblogging platform for WordPress with PWA support, masonry layout, and social sharing.
 * Version: 3.3.0
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BITSTREAM_VERSION', '3.3.0');
define('BITSTREAM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BITSTREAM_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('admin_notices', function() {
    $dir = BITSTREAM_PLUGIN_PATH;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    echo '<div class="notice notice-info"><p><strong>BitStream Search:</strong><br>';
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $filepath = $file->getPathname();
        if (strpos($filepath, 'node_modules') !== false || strpos($filepath, '.git') !== false) continue;
        $content = file_get_contents($filepath);
        if (strpos($content, '280') !== false) {
            echo esc_html($filepath) . ':<br>';
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (strpos($line, '280') !== false) {
                    echo 'Line ' . ($num + 1) . ': ' . esc_html(trim($line)) . '<br>';
                }
            }
            echo '<br>';
        }
    }
    echo '</p></div>';
});

/**
 * Main BitStream Plugin Class
 */
class BitStream_Plugin
{

    private $components = [];
    private static $instance = null;

    public static function get_instance()
    {
        return self::$instance;
    }

    public function __construct()
    {
        self::$instance = $this;
        add_action('wp_enqueue_scripts', [$this, 'register_global_assets'], 5);
        add_action('admin_enqueue_scripts', [$this, 'register_global_assets'], 5);
        if (did_action('plugins_loaded')) {
            $this->init();
        } else {
            add_action('plugins_loaded', [$this, 'init']);
        }
    }

    /**
     * Register global CDN assets
     */
    public function register_global_assets()
    {
        wp_register_script('twemoji', 'https://cdn.jsdelivr.net/npm/@twemoji/api@latest/dist/twemoji.min.js', [], null, true);
    }

    /**
     * Initialize the plugin
     */
    public function init()
    {
        // Load includes
        $this->load_includes();

        // Initialize components
        $this->init_components();

        // Strip image metadata on upload
        add_filter('wp_generate_attachment_metadata', [$this, 'strip_image_metadata_on_upload'], 10, 2);
    }

    /**
     * Load required files
     */
    private function load_includes()
    {
        // Core functionality classes
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-post-type.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-shortcodes.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-og-fetcher.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-error-logger.php';

        // New modular classes
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-admin-interface.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-block-editor.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-pwa-manager.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-rss-feeds.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-content-display.php';
        require_once BITSTREAM_PLUGIN_PATH . 'includes/class-rebit-mappings.php';
    }

    /**
     * Initialize all plugin components
     */
    private function init_components()
    {
        // Audio support removed: plugin will not add audio MIME types or bypass audio file checks.

        // Initialize core components
        $this->components['post_type'] = new BitStream_Post_Type();
        $this->components['ajax_handlers'] = new BitStream_Ajax_Handlers();
        $this->components['shortcodes'] = new BitStream_Shortcodes();
        $this->components['og_fetcher'] = new BitStream_OG_Fetcher();
        $this->components['error_logger'] = new BitStream_Error_Logger();

        // Initialize new modular components
        $this->components['admin_interface'] = new BitStream_Admin_Interface();
        $this->components['block_editor'] = new BitStream_Block_Editor();
        $this->components['pwa_manager'] = new BitStream_PWA_Manager();
        $this->components['rss_feeds'] = new BitStream_RSS_Feeds();
        $this->components['content_display'] = new BitStream_Content_Display();

        // Invalidate hashtag count cache when bits are created/updated/deleted
        add_action('save_post_bit', ['BitStream_Content_Display', 'flush_hashtag_cache']);
        add_action('trashed_post', ['BitStream_Content_Display', 'flush_hashtag_cache']);
        add_action('deleted_post', ['BitStream_Content_Display', 'flush_hashtag_cache']);

    // ReBit mappings is a utility class, no need to instantiate
    }

    /**
     * Get a component instance
     */
    public function get_component($component_name)
    {
        return isset($this->components[$component_name]) ? $this->components[$component_name] : null;
    }

    /**
     * Strip metadata from uploaded images and their generated sub-sizes.
     *
     * @param array $metadata Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public function strip_image_metadata_on_upload($metadata, $attachment_id)
    {
        $mime_type = get_post_mime_type($attachment_id);
        if (empty($mime_type) || strpos($mime_type, 'image/') !== 0) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if ($file && file_exists($file)) {
            $this->strip_metadata_from_file($file);
        }

        // Process all sub-sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dirname = dirname($file);
            foreach ($metadata['sizes'] as $size => $size_info) {
                if (!empty($size_info['file'])) {
                    $subsize_file = path_join($dirname, $size_info['file']);
                    if (file_exists($subsize_file)) {
                        $this->strip_metadata_from_file($subsize_file);
                    }
                }
            }
        }

        return $metadata;
    }

    /**
     * Strip metadata from a single image file.
     *
     * @param string $file_path Absolute path to the file.
     * @return bool True if successful, false otherwise.
     */
    public function strip_metadata_from_file($file_path)
    {
        if (empty($file_path) || !file_exists($file_path)) {
            return false;
        }

        // Try Imagick first if available
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($file_path);
                $imagick->stripImage();
                $result = $imagick->writeImage($file_path);
                $imagick->clear();
                $imagick->destroy();
                if ($result) {
                    return true;
                }
            } catch (Exception $e) {
                // If Imagick fails, we fall back to GD
                if (class_exists('BitStream_Error_Logger')) {
                    BitStream_Error_Logger::log('Imagick metadata stripping failed: ' . $e->getMessage());
                }
            }
        }

        // GD Fallback
        if (function_exists('gd_info')) {
            $image = null;
            $mime_info = wp_check_filetype($file_path);
            $mime_type = $mime_info['type'];

            if ($mime_type === 'image/jpeg' || $mime_type === 'image/jpg') {
                if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
                    $image = @imagecreatefromjpeg($file_path);
                    if ($image) {
                        // GD does not support EXIF, so writing it back strips it
                        @imagejpeg($image, $file_path, 90);
                    }
                }
            } elseif ($mime_type === 'image/png') {
                if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
                    $image = @imagecreatefrompng($file_path);
                    if ($image) {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        @imagepng($image, $file_path);
                    }
                }
            } elseif ($mime_type === 'image/webp') {
                if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
                    $image = @imagecreatefromwebp($file_path);
                    if ($image) {
                        @imagewebp($image, $file_path);
                    }
                }
            }

            if ($image) {
                @imagedestroy($image);
                return true;
            }
        }

        return false;
    }
}

/**
 * Render ReBit media/preview section for a card
 *
 * @param int $post_id
 * @return string
 */
function bitstream_render_rebit_section($post_id)
{
    $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
    if (empty($rebit_url)) {
        return '';
    }

    $parsed = parse_url($rebit_url);
    $host = $parsed['host'] ?? '';

    ob_start();

    $map = BitStream_ReBit_Mappings::get_mapping_for_domain($host);
    if ($map) {
        echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
            . '<i class="' . esc_attr($map['icon']) . '" aria-hidden="true" style="margin-right:0.5rem;"></i>'
            . esc_html($map['label'])
            . '</div>';
    }
    elseif (stripos($host, 'youtube.com') !== false || stripos($host, 'youtu.be') !== false || stripos($host, 'youtube-nocookie.com') !== false) {
        echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
            . '<i class="fab fa-youtube" aria-hidden="true" style="margin-right:0.5rem;"></i>'
            . 'shared a video</div>';
    }
    else {
        echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
            . '<i class="fas fa-link" aria-hidden="true" style="margin-right:0.5rem;"></i> shared a link</div>';
    }

    $is_yt = stripos($host, 'youtube.com') !== false || stripos($host, 'youtu.be') !== false || stripos($host, 'youtube-nocookie.com') !== false;
    $is_twitter = stripos($host, 'twitter.com') !== false || stripos($host, 'x.com') !== false;

    if ($is_yt) {
        $video_id = '';
        if (stripos($host, 'youtu.be') !== false) {
            $video_id = ltrim($parsed['path'] ?? '', '/');
        }
        elseif (isset($parsed['query'])) {
            parse_str($parsed['query'], $args);
            $video_id = $args['v'] ?? '';
        }

        if ($video_id) {
            echo '<div class="bit-rebit-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1rem 0;border-radius:15px;">'
                . '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" '
                . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                . 'allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;border-radius:15px;"></iframe></div>';
        }
        else {
            echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" style="white-space:normal;overflow-wrap:anywhere;word-break:break-word;">' . esc_html($rebit_url) . '</a>';
        }
    }
    elseif ($is_twitter) {
        echo '<div class="bit-rebit-preview bit-rebit-twitter" style="border:1px solid #ddd;border-radius:12px;overflow:hidden;margin-bottom:1rem;display:flex;align-items:center;padding:1rem;gap:1.25rem;background:#fafafa;">';
        $og_img = get_post_meta($post_id, '_bitstream_og_image', true);

        if ($og_img) {
            echo '<div style="flex-shrink:0;width:80px;height:80px;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
            echo '<img src="' . esc_url($og_img) . '" style="width:100%;height:100%;object-fit:cover;display:block;" alt="">';
            echo '</div>';
        }

        echo '<div style="flex-grow:1;min-width:0;display:flex;flex-direction:column;justify-content:center;">';

        $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
        $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);

        if ($og_title) {
            echo '<h4 style="margin:0 0 0.35rem;font-size:1.05rem;line-height:1.3;">'
                . '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" style="color:var(--wp--preset--color--foreground,#333);text-decoration:none;font-weight:600;">' . wp_kses_post($og_title) . '</a></h4>';
        }
        if ($og_desc) {
            echo '<p style="margin:0;font-size:0.9rem;color:#555;line-height:1.4;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;">' . wp_kses_post($og_desc) . '</p>';
        }
        if (!$og_desc && !$og_title) {
            echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:0.9rem;">' . esc_html($rebit_url) . '</a>';
        }
        echo '</div></div>';
    }
    else {
        echo '<div class="bit-rebit-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;margin-bottom:1rem;">';
        $og_img = get_post_meta($post_id, '_bitstream_og_image', true);
        if ($og_img) {
            echo '<img src="' . esc_url($og_img) . '" style="width:100%;display:block;" alt="">';
        }

        echo '<div style="padding:0.75rem;">';
        $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
        $og_desc = get_post_meta($post_id, '_bitstream_og_desc', true);
        if ($og_title) {
            echo '<h4 style="margin:0 0 0.5rem;font-size:1.1rem;">'
                . '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" style="white-space:normal;overflow-wrap:anywhere;word-break:break-word;">' . wp_kses_post($og_title) . '</a></h4>';
        }
        if ($og_desc) {
            echo '<p style="margin:0;font-size:0.95rem;color:#555;">' . wp_kses_post($og_desc) . '</p>';
        }
        if (!$og_desc) {
            echo '<a href="' . esc_url($rebit_url) . '" target="_blank" rel="noopener" style="white-space:normal;overflow-wrap:anywhere;word-break:break-word;">' . esc_html($rebit_url) . '</a>';
        }
        echo '</div></div>';
    }

    return ob_get_clean();
}

/**
 * Render a nested quoted Bit card without action buttons/comments
 *
 * @param int $post_id
 * @return string
 */
function bitstream_render_nested_quoted_card($post_id, $depth = 0)
{
    $quoted_post = get_post($post_id);
    if (!($quoted_post instanceof WP_Post) || $quoted_post->post_type !== 'bit' || $quoted_post->post_status !== 'publish') {
        ob_start();
?>
        <div id="bit-quoted-missing-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested bit-card-quoted-unavailable" style="margin:0;padding:1rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #ddd;border-radius:15px;background:#fff;">
            <div class="bit-card-content" style="font-size:0.95rem;line-height:1.5;margin:0;">
                <p style="margin:0;color:var(--wp--preset--color--secondary,#666);">Original Bit unavailable.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $content = wpautop(get_post_field('post_content', $post_id));

    if (class_exists('BitStream_Content_Display')) {
        global $post;
        $original_post = $post;
        $post = $quoted_post;
        $display = new BitStream_Content_Display();
        $content = $display->linkify_hashtags($content);
        $post = $original_post;
    }

    $timestamp = human_time_diff(get_post_time('U', false, $post_id), current_time('timestamp')) . ' ago';
    $posted_datetime = get_post_time('d/m/Y H:i', false, $post_id);
    $is_edited = get_post_modified_time('U', false, $post_id) > get_post_time('U', false, $post_id);
    $timestamp_tooltip = 'Posted: ' . $posted_datetime . ($is_edited ? ' • Edited' : '');
    $rebit_markup = bitstream_render_rebit_section($post_id);

    // Retrieve quoted post author and mood details
    $author_id = get_post_field('post_author', $post_id);
    $author_name = get_the_author_meta('display_name', $author_id);
    $mood_emoji = get_post_meta($post_id, '_bitstream_mood_emoji', true);
    $mood_emotion = get_post_meta($post_id, '_bitstream_mood_emotion', true);
    
    // Check if the quoted post is a pure mood post
    $raw_content = trim(get_post_field('post_content', $post_id));
    $has_attachments = !empty(get_post_meta($post_id, '_bitstream_attachment_id', true)) || !empty(get_post_meta($post_id, '_bitstream_attachment_ids', true));
    $quoted_id = (int)get_post_meta($post_id, '_bitstream_quoted_bit', true);
    $is_rebit_card = !empty(get_post_meta($post_id, 'bitstream_rebit_url', true));
    $is_pure_mood = empty($raw_content) && !$has_attachments && ($quoted_id <= 0) && !$is_rebit_card && !empty($mood_emotion);

    $quoted_markup = '';
    if ($quoted_id > 0 && $depth < 1) {
        $quoted_markup = bitstream_render_nested_quoted_card($quoted_id, $depth + 1);
    }

    ob_start();
?>
    <div id="bit-quoted-<?php echo esc_attr($post_id); ?>" class="bit-card bit-card-quoted-nested" style="margin:0;padding:1.2rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #ddd;border-radius:15px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="margin-bottom:0.75rem;">
            <div class="bit-meta" style="font-size:0.82rem;color:var(--wp--preset--color--secondary,#666);display:flex;flex-direction:column;gap:1px;">
                <span class="bit-author-line" style="font-weight:600;color:var(--wp--preset--color--accent-1, #2c6e49);">
                    <?php echo esc_html($author_name); ?>
                    <?php if (!empty($mood_emotion) && !$is_pure_mood): ?>
                        <span class="bit-mood-status" style="font-weight:normal;color:#666;margin-left:0.25rem;">
                            is feeling <?php echo esc_html($mood_emoji); ?> <strong style="color:var(--wp--preset--color--accent-1, #2c6e49);"><?php echo esc_html($mood_emotion); ?></strong>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="bit-timestamp" title="<?php echo esc_attr($timestamp_tooltip); ?>" tabindex="0" style="cursor: pointer;"><span class="bit-timestamp-relative"><?php echo esc_html($timestamp); ?></span><span class="bit-timestamp-full" style="display:none;"><span class="bit-timestamp-separator"> | </span><?php echo esc_html(get_post_time(get_option('date_format') . ' ' . get_option('time_format'), false, $post_id)); ?></span></span>
            </div>
        </header>

        <?php if ($is_pure_mood): ?>
            <div class="bit-card-content bit-card-pure-mood" style="font-size:1.1rem;line-height:1.4;margin:1rem 0;padding:1rem;background:#f8fafc;border:1.5px dashed #e2e8f0;border-radius:12px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;">
                <span class="bit-pure-mood-emoji" style="font-size:2.5rem;line-height:1;margin-bottom:0.25rem;"><?php echo esc_html($mood_emoji); ?></span>
                <span class="bit-pure-mood-text" style="font-weight:500;color:#475569;">
                    is feeling <strong style="color:var(--wp--preset--color--accent-1,#2c6e49);"><?php echo esc_html($mood_emotion); ?></strong>
                </span>
            </div>
        <?php else: ?>
            <?php if (!empty($raw_content)): ?>
                <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
                    <?php echo $content; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php echo $rebit_markup; ?>

        <?php if (!empty($quoted_markup)): ?>
            <div class="bitstream-quoted-preview" data-permalink="<?php echo esc_url(add_query_arg('highlight_bit', $quoted_id, home_url('/bitstream/'))); ?>">
                <?php echo $quoted_markup; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
}

/**
 * Render a bit card
 * 
 * @param int $post_id The post ID to render
 * @param bool $skip_content_filter Whether to skip the content filter to avoid infinite loops
 * @return string The rendered HTML
 */
if (!function_exists('bitstream_comment_callback')) {
    function bitstream_comment_callback($comment, $args, $depth)
    {
        $GLOBALS['comment'] = $comment;
        $tag = ('div' === $args['style']) ? 'div' : 'li';
?>
        <<?php echo $tag; ?> id="comment-<?php comment_ID(); ?>" <?php comment_class(empty($args['has_children']) ? '' : 'parent'); ?>>
            <article id="div-comment-<?php comment_ID(); ?>" class="bit-modern-comment">
                <div class="bit-modern-comment-avatar">
                    <?php if (0 != $args['avatar_size'])
            echo get_avatar($comment, $args['avatar_size']); ?>
                </div>
                <div class="bit-modern-comment-content">
                    <div class="bit-modern-comment-header">
                        <cite class="fn bit-modern-comment-author"><?php echo get_comment_author_link(); ?></cite>
                        <span class="bit-modern-comment-date">
                            <a href="<?php echo esc_url(get_comment_link($comment, $args)); ?>">
                                <?php printf('%s', get_comment_date()); ?>
                            </a>
                        </span>
                    </div>
                    
                    <?php if ('0' == $comment->comment_approved): ?>
                        <p class="comment-awaiting-moderation"><?php esc_html_e('Your comment is awaiting moderation.', 'bitstream'); ?></p>
                    <?php
        endif; ?>

                    <div class="bit-modern-comment-text">
                        <?php comment_text(); ?>
                    </div>

                    <div class="bit-modern-comment-actions">
                        <?php edit_comment_link(esc_html__('Edit', 'bitstream'), '<span class="edit-link">', '</span>'); ?>
                        <?php
        comment_reply_link(array_merge($args, [
            'add_below' => 'div-comment',
            'depth' => $depth,
            'max_depth' => $args['max_depth'],
            'before' => '<span class="reply-link">',
            'after' => '</span>'
        ]));
?>
                    </div>
                </div>
            </article>
        <?php
    }
}

if (!function_exists('bitstream_render_card')) {
    function bitstream_render_card($post_id, $skip_content_filter = false, $options = [])
    {
        $options = wp_parse_args($options, [
            'comment_action' => 'toggle',
            'is_preview'     => false,
        ]);

        // Avoid infinite loop by skipping content filter when rendering in single bit context
        if ($skip_content_filter) {
            $content = get_post_field('post_content', $post_id);
            $content = wpautop($content); // Basic paragraph formatting
        }
        else {
            $GLOBALS['bitstream_is_rendering_card'] = true;
            $content = apply_filters('the_content', get_post_field('post_content', $post_id));
            unset($GLOBALS['bitstream_is_rendering_card']);

            // Ensure paragraph formatting is applied even if the theme disables wpautop filter
            if (strpos($content, '<p>') === false && strpos($content, '<p ') === false) {
                $content = wpautop($content);
            }
        }

        // Normalize WordPress video shortcode output so feed cards do not depend on
        // MediaElement wrapper sizing, which can collapse in the timeline.
        if (strpos($content, 'wp-video') !== false || strpos($content, 'mejs-container') !== false) {
            $previous_dom_state = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $wrapped_content = '<div id="bitstream-card-content-root">' . $content . '</div>';
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $xpath = new DOMXPath($document);
            $wrapper_nodes = $xpath->query('//*[@id="bitstream-card-content-root"]//*[contains(concat(" ", normalize-space(@class), " "), " wp-video ")]');
            if ($wrapper_nodes) {
                for ($index = $wrapper_nodes->length - 1; $index >= 0; $index--) {
                    $wrapper = $wrapper_nodes->item($index);
                    if (!$wrapper || !$wrapper->parentNode) {
                        continue;
                    }

                    $video_node = null;
                    foreach ($wrapper->getElementsByTagName('video') as $candidate_video) {
                        $video_node = $candidate_video;
                        break;
                    }

                    if ($video_node) {
                        $clean_video_node = $video_node->cloneNode(true);
                        $clean_video_node->setAttribute('class', 'bitstream-video-attachment');
                        $clean_video_node->removeAttribute('width');
                        $clean_video_node->removeAttribute('height');
                        $clean_video_node->removeAttribute('style');
                        $wrapper->parentNode->replaceChild($document->importNode($clean_video_node, true), $wrapper);
                    }
                }
            }

            $root = $document->getElementById('bitstream-card-content-root');
            if ($root) {
                $normalized_content = '';
                foreach ($root->childNodes as $child_node) {
                    $normalized_content .= $document->saveHTML($child_node);
                }
                if ($normalized_content !== '') {
                    $content = $normalized_content;
                }
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous_dom_state);
        }

        $timestamp = human_time_diff(get_post_time('U', false, $post_id), current_time('timestamp')) . ' ago';
        $posted_datetime = get_post_time('d/m/Y H:i', false, $post_id);
        $is_edited = get_post_modified_time('U', false, $post_id) > get_post_time('U', false, $post_id);
        $timestamp_tooltip = 'Posted: ' . $posted_datetime . ($is_edited ? ' • Edited' : '');
        $avatar = get_avatar(get_post_field('post_author', $post_id), 96, '', '', ['class' => 'bit-avatar-img', 'extra_attr' => 'style="width:100%;height:100%;object-fit:cover;"']);
        $author_id = get_post_field('post_author', $post_id);
        $author_name = get_the_author_meta('display_name', $author_id);
        $mood_emoji = get_post_meta($post_id, '_bitstream_mood_emoji', true);
        $mood_emotion = get_post_meta($post_id, '_bitstream_mood_emotion', true);
        $likes = (int)get_post_meta($post_id, '_bitstream_likes', true);
        $comments = get_comments_number($post_id);
        $quoted_id = (int)get_post_meta($post_id, '_bitstream_quoted_bit', true);
        $is_rebit_card = !empty(get_post_meta($post_id, 'bitstream_rebit_url', true));
        $rebit_markup = bitstream_render_rebit_section($post_id);
        $quoted_markup = '';
        if ($quoted_id > 0) {
            $quoted_markup = bitstream_render_nested_quoted_card($quoted_id);
        }
        $raw_content = trim(get_post_field('post_content', $post_id));
        $has_attachments = !empty(get_post_meta($post_id, '_bitstream_attachment_id', true)) || !empty(get_post_meta($post_id, '_bitstream_attachment_ids', true));
        $is_pure_mood = empty($raw_content) && !$has_attachments && ($quoted_id <= 0) && !$is_rebit_card && !empty($mood_emotion);

        ob_start(); ?>
    <article id="bit-<?php echo esc_attr($post_id); ?>" class="bit-card" style="margin:0;padding:1.5rem;width:100%;max-width:none;box-sizing:border-box;border:1px solid #eee;border-radius:15px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="display:flex;align-items:center;margin-bottom:1rem;">
            <div class="bit-avatar" style="width:48px;height:48px;margin-right:0.75rem;border-radius:15px;overflow:hidden;flex-shrink:0;">
                <?php echo $avatar; ?>
            </div>
            <div class="bit-meta" style="font-size:0.875rem;color:var(--wp--preset--color--secondary,#666);display:flex;flex-direction:column;gap:2px;">
                <span class="bit-author-line" style="font-weight:600;color:var(--wp--preset--color--accent-1, #2c6e49);">
                    <?php echo esc_html($author_name); ?>
                    <?php if (!empty($mood_emotion) && !$is_pure_mood): ?>
                        <span class="bit-mood-status" style="font-weight:normal;color:#666;margin-left:0.25rem;">
                            is feeling <?php echo esc_html($mood_emoji); ?> <strong style="color:var(--wp--preset--color--accent-1, #2c6e49);"><?php echo esc_html($mood_emotion); ?></strong>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="bit-timestamp" tabindex="0" style="cursor: pointer;"><span class="bit-timestamp-relative"><?php echo esc_html($timestamp); ?></span><span class="bit-timestamp-full" style="display:none;"><span class="bit-timestamp-separator"> | </span><?php echo esc_html(get_post_time(get_option('date_format') . ' ' . get_option('time_format'), false, $post_id)); ?></span></span>
            </div>
        </header>

        <?php if ($is_pure_mood): ?>
            <div class="bit-card-content bit-card-pure-mood" style="font-size:1.4rem;line-height:1.4;margin:1.5rem 0;padding:1.5rem;background:#f8fafc;border:1.5px dashed #e2e8f0;border-radius:15px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;">
                <span class="bit-pure-mood-emoji" style="font-size:3.5rem;line-height:1;margin-bottom:0.5rem;"><?php echo esc_html($mood_emoji); ?></span>
                <span class="bit-pure-mood-text" style="font-weight:500;color:#475569;">
                    is feeling <strong style="color:var(--wp--preset--color--accent-1,#2c6e49);"><?php echo esc_html($mood_emotion); ?></strong>
                </span>
            </div>
        <?php else: ?>
            <?php if (!empty($raw_content)): ?>
                <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
                    <?php echo $content; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php echo $rebit_markup; ?>

        <?php if (!empty($quoted_markup)): ?>
            <div class="bitstream-quoted-preview" data-permalink="<?php echo esc_url(add_query_arg('highlight_bit', $quoted_id, home_url('/bitstream/'))); ?>">
                <?php echo $quoted_markup; ?>
            </div>
        <?php
        endif; ?>

        <div class="bit-card-watermark" style="display:none;align-items:center;gap:0.35rem;font-size:0.8rem;color:#aaa;margin-top:0.75rem;font-weight:500;font-family:inherit;">
            <img src="<?php echo esc_url(BITSTREAM_PLUGIN_URL . 'assets/images/logo_192.png'); ?>" style="width:16px;height:16px;filter:grayscale(100%);opacity:0.5;display:block;" alt="" aria-hidden="true">
            <span>BitStream</span>
        </div>

        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee;">

        <?php
        $can_quote = current_user_can('edit_posts');
        $can_edit = current_user_can('edit_post', $post_id);
        $can_delete = is_user_logged_in() && current_user_can('delete_post', $post_id);
        $show_admin_actions = !$options['is_preview'] && ($can_quote || $can_edit || $can_delete);
?>
        <footer class="bit-card-footer" style="display:flex;gap:0.75rem;font-size:0.875rem;align-items:center;">
            <div class="bit-card-footer-main-actions">
                <?php if ($options['comment_action'] === 'link'): ?>
                    <a class="bit-comment-preview-link bit-action" href="<?php echo esc_url(add_query_arg([
                        'highlight_bit' => $post_id,
                        'open_comments' => $post_id,
                    ], home_url('/bitstream/'))); ?>" style="background:none;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:0.25rem;" title="View comments">
                        <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
                    </a>
                <?php else: ?>
                <button class="bit-comment-toggle bit-action" data-target="comments-<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="View and add comments">
                    <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
                </button>
                <?php endif; ?>
                <button class="bit-like bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="Like this bit">
                    <i class="fas fa-heart"></i> <span class="bit-like-count"><?php echo esc_html($likes); ?></span>
                </button>

                <?php
                $share_base_url = class_exists('BitStream_Shortcodes') ? BitStream_Shortcodes::get_feed_page_url() : home_url('/bitstream/');
                $share_url      = add_query_arg('highlight_bit', $post_id, $share_base_url);
                ?>
                <button class="bit-share bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" data-url="<?php echo esc_url($share_url); ?>" data-title="<?php echo esc_attr(get_the_title($post_id)); ?>" data-share-image="<?php echo esc_url(get_post_meta($post_id, '_bitstream_share_image_url', true)); ?>" style="background:none;border:none;cursor:pointer;" title="Share this bit">
                    <i class="fa-solid fa-share-nodes"></i>
                </button>
            </div>
            <?php if ($show_admin_actions): ?>
                <span class="bit-card-footer-spacer" aria-hidden="true"></span>
                <div class="bit-card-footer-admin-actions">
                    <?php if ($can_quote): ?>
                    <button class="bit-quote bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="Quote this bit">
                        <i class="fa-solid fa-retweet"></i>
                    </button>
                    <?php
            endif; ?>
                    <?php if ($can_edit): ?>
                    <button class="bit-edit bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" data-post-type="<?php echo esc_attr(($is_rebit_card && $quoted_id <= 0) ? 'rebit' : 'bit'); ?>" style="background:none;border:none;cursor:pointer;" title="Edit this bit">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <?php
            endif; ?>
                    <?php if ($can_delete): ?>
                    <button class="bit-delete bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;" title="Delete this bit">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <?php
            endif; ?>
                </div>
            <?php
        endif; ?>
        </footer>

        <div id="comments-<?php echo $post_id; ?>" class="bit-comments">
            <ol class="comment-list">
                <?php wp_list_comments(['style' => 'ol', 'callback' => 'bitstream_comment_callback', 'avatar_size' => 48, 'short_ping' => true, 'max_depth' => 3], get_comments(['post_id' => $post_id, 'status' => 'approve'])); ?>
            </ol>
            <div class="bit-comment-form">
                <?php comment_form([
            'comment_notes_after' => '',
            'title_reply' => 'Leave a Comment',
            'logged_in_as' => '',
            'comment_field' => '<p><textarea name="comment" required></textarea></p>',
        ], $post_id); ?>
            </div>
        </div>
    </article>
    <?php

        return ob_get_clean();
    }
}

// Initialize the plugin
new BitStream_Plugin();

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'bitstream_plugin_activate');
register_deactivation_hook(__FILE__, 'bitstream_plugin_deactivate');

/**
 * Plugin activation callback
 */
function bitstream_plugin_activate()
{
    // Ensure post type is registered before flushing
    $plugin = new BitStream_Plugin();
    $plugin->init();

    // Import default ReBit mappings if none exist (via centralized class)
    BitStream_ReBit_Mappings::import_default_mappings();

    // Ensure weekly BitStream media cleanup is scheduled
    bitstream_schedule_weekly_media_cleanup();

    // Flush rewrite rules to ensure permalinks work (including PWA shortcuts)
    flush_rewrite_rules();
}

/**
 * Schedule weekly media cleanup cron event.
 */
function bitstream_schedule_weekly_media_cleanup()
{
    if (!wp_next_scheduled('bitstream_weekly_media_cleanup_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'bitstream_weekly', 'bitstream_weekly_media_cleanup_event');
    }
}

/**
 * Plugin deactivation callback  
 */
function bitstream_plugin_deactivate()
{
    wp_clear_scheduled_hook('bitstream_weekly_media_cleanup_event');

    // Flush rewrite rules on deactivation
    flush_rewrite_rules();
}
