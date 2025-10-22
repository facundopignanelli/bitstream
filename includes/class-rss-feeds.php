<?php
/**
 * BitStream RSS Feeds Manager
 * 
 * Handles RSS feed generation and management
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_RSS_Feeds {
    
    public function __construct() {
        add_action('init', [$this, 'add_rss_feeds']);
        add_action('wp_head', [$this, 'add_rss_links']);
        add_filter('query_vars', [$this, 'add_query_vars']);
    }
    
    /**
     * Flush rewrite rules (call after updating feed rules)
     */
    public function flush_feeds() {
        flush_rewrite_rules();
    }
    
    /**
     * Add custom query variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'bitstream_feed';
        return $vars;
    }
    
    /**
     * Register RSS feeds for BitStream content
     */
    public function add_rss_feeds() {
        // Register feeds with the correct URL structure to match admin interface expectations
        add_rewrite_rule('^bitstream/feed/?$', 'index.php?bitstream_feed=all', 'top');
        add_rewrite_rule('^bitstream/feed/bits/?$', 'index.php?bitstream_feed=bits', 'top');
        add_rewrite_rule('^bitstream/feed/rebits/?$', 'index.php?bitstream_feed=rebits', 'top');
        
        // Handle the custom query var
        add_action('template_redirect', [$this, 'handle_feed_request']);
    }
    
    /**
     * Handle feed requests
     */
    public function handle_feed_request() {
        $feed_type = get_query_var('bitstream_feed');
        if ($feed_type) {
            $this->generate_rss_feed($feed_type);
        }
    }
    
    /**
     * Add RSS feed links to HTML head
     */
    public function add_rss_links() {
        // Only add RSS links on BitStream-related pages
        global $post;
        $show_rss = false;
        
        // Show on BitStream archive pages
        if (is_post_type_archive('bit')) {
            $show_rss = true;
        }
        
        // Show on pages with BitStream shortcodes
        if (is_a($post, 'WP_Post') && 
            (has_shortcode($post->post_content, 'bitstream') || 
             has_shortcode($post->post_content, 'bitstream_latest'))) {
            $show_rss = true;
        }
        
        // Show on BitStream URL paths
        if (isset($_SERVER['REQUEST_URI']) && 
            strpos($_SERVER['REQUEST_URI'], '/bitstream/') !== false) {
            $show_rss = true;
        }
        
        if ($show_rss) {
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream Feed" href="' . esc_url(home_url('/bitstream/feed/')) . '">' . "\n";
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream Bits Only" href="' . esc_url(home_url('/bitstream/feed/bits/')) . '">' . "\n";
            echo '<link rel="alternate" type="application/rss+xml" title="BitStream ReBits Only" href="' . esc_url(home_url('/bitstream/feed/rebits/')) . '">' . "\n";
        }
    }
    
    /**
     * Generate RSS feed content
     */
    private function generate_rss_feed($type = 'all') {
        header('Content-Type: application/rss+xml; charset=' . get_option('blog_charset'), true);
        
        $meta_query = [];
        $title_suffix = '';
        
        if ($type === 'bits') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => 'bitstream_rebit_url',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => 'bitstream_rebit_url',
                    'value' => '',
                    'compare' => '='
                ]
            ];
            $title_suffix = ' - Bits Only';
        } elseif ($type === 'rebits') {
            $meta_query[] = [
                'key' => 'bitstream_rebit_url',
                'value' => '',
                'compare' => '!='
            ];
            $title_suffix = ' - ReBits Only';
        }
        
        $query = new WP_Query([
            'post_type' => 'bit',
            'posts_per_page' => 50,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query
        ]);
        
        $site_title = get_bloginfo('name');
        $site_url = home_url();
        $feed_title = 'BitStream' . $title_suffix . ' - ' . $site_title;
        $feed_description = 'Latest BitStream posts from ' . $site_title;
        
        echo '<?xml version="1.0" encoding="' . get_option('blog_charset') . '"?>' . "\n";
        ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title><?php echo esc_html($feed_title); ?></title>
        <link><?php echo esc_url($site_url); ?></link>
        <description><?php echo esc_html($feed_description); ?></description>
        <language><?php echo esc_html(get_option('rss_language', 'en-US')); ?></language>
        <lastBuildDate><?php echo esc_html(mysql2date('D, d M Y H:i:s +0000', get_lastpostmodified('GMT'), false)); ?></lastBuildDate>
        <atom:link href="<?php echo esc_url(home_url('/bitstream/feed' . ($type !== 'all' ? '/' . $type : '') . '/')); ?>" rel="self" type="application/rss+xml" />
        
        <?php while ($query->have_posts()) : $query->the_post(); 
            $post_id = get_the_ID();
            $rebit_url = get_post_meta($post_id, 'bitstream_rebit_url', true);
            $content = get_the_content();
            $permalink = get_permalink($post_id);
            $author = get_the_author();
            $date = get_the_date('D, d M Y H:i:s +0000');
            
            // Generate title
            $title = 'Bit #' . date('Y-m-d', strtotime(get_the_date())) . ':' . str_pad($post_id % 1000, 3, '0', STR_PAD_LEFT);
            if ($rebit_url) {
                $title = 'ReBit: ' . $title;
            }
            if ($author) {
                $title .= ' by ' . $author;
            }
            
            // Prepare description - include ReBit info if applicable
            $description = '';
            if ($rebit_url) {
                $og_title = get_post_meta($post_id, '_bitstream_og_title', true);
                $og_description = get_post_meta($post_id, '_bitstream_og_desc', true);
                
                $description .= '<p><strong>Sharing:</strong> <a href="' . esc_url($rebit_url) . '">' . esc_html($rebit_url) . '</a></p>';
                if ($og_title) {
                    $description .= '<p><strong>Title:</strong> ' . esc_html($og_title) . '</p>';
                }
                if ($og_description) {
                    $description .= '<p><strong>Description:</strong> ' . esc_html($og_description) . '</p>';
                }
                if ($content) {
                    $description .= '<p><strong>Comment:</strong> ' . wpautop($content) . '</p>';
                }
            } else {
                $description = wpautop($content);
            }
        ?>
        <item>
            <title><?php echo esc_html($title); ?></title>
            <link><?php echo esc_url($permalink); ?></link>
            <guid isPermaLink="true"><?php echo esc_url($permalink); ?></guid>
            <pubDate><?php echo esc_html($date); ?></pubDate>
            <author><?php echo esc_html($author); ?></author>
            <description><![CDATA[<?php echo $description; ?>]]></description>
            <content:encoded><![CDATA[<?php echo $description; ?>]]></content:encoded>
            <?php if ($rebit_url) : ?>
            <category>ReBit</category>
            <?php else : ?>
            <category>Bit</category>
            <?php endif; ?>
        </item>
        <?php endwhile; ?>
    </channel>
</rss>
        <?php
        wp_reset_postdata();
        exit;
    }
}
