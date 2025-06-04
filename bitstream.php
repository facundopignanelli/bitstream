<?php
/**
 * Plugin Name: BitStream
 * Description: A microblogging plugin for sharing Bits and ReBits.
 * Version: beta 0.3
 * Author: Facundo Pignanelli
 * Text Domain: bitstream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

define('BITSTREAM_VERSION', 'beta 0.3');

/*
// Activation hook: populate default ReBit mappings
register_activation_hook(__FILE__, 'bitstream_set_default_rebit_mappings');
function bitstream_set_default_rebit_mappings() {
    $defaults = [
        ['domain'=>'youtube.com',     'label'=>'shared a video',     'icon'=>'fab fa-youtube'],
        ['domain'=>'spotify.com',     'label'=>'shared a song',      'icon'=>'fab fa-spotify'],
        ['domain'=>'goodreads.com',   'label'=>'shared a book',      'icon'=>'fab fa-goodreads'],
        ['domain'=>'letterboxd.com',  'label'=>'shared a movie',     'icon'=>'fab fa-letterboxd'],
        ['domain'=>'justwatch.com',   'label'=>'shared a TV show',   'icon'=>'fas fa-tv'],
        ['domain'=>'steamcommunity.com','label'=>'shared a game',     'icon'=>'fab fa-steam'],
        ['domain'=>'pocketcasts.com', 'label'=>'shared a podcast',   'icon'=>'fas fa-podcast'],
        // Additional defaults
        ['domain'=>'twitter.com',     'label'=>'shared a tweet',     'icon'=>'fab fa-twitter'],
        ['domain'=>'facebook.com',    'label'=>'shared a post',      'icon'=>'fab fa-facebook'],
        ['domain'=>'instagram.com',   'label'=>'shared a photo',     'icon'=>'fab fa-instagram'],
        ['domain'=>'medium.com',      'label'=>'shared an article',  'icon'=>'fab fa-medium'],
        ['domain'=>'github.com',      'label'=>'shared a repo',      'icon'=>'fab fa-github'],
        ['domain'=>'soundcloud.com',  'label'=>'shared audio',       'icon'=>'fab fa-soundcloud'],
    ];
    if (get_option('bitstream_rebit_mappings') === false) {
        update_option('bitstream_rebit_mappings', $defaults);
    }
}
*/

// 1) Register CPT & Auto‐Title
add_action( 'init', 'bitstream_register_bit_post_type' );
function bitstream_register_bit_post_type() {
    $labels = [
        'name'               => _x( 'Bits', 'Post Type General Name', 'bitstream' ),
        'singular_name'      => _x( 'Bit', 'Post Type Singular Name', 'bitstream' ),
        'menu_name'          => __( 'BitStream', 'bitstream' ),
        'name_admin_bar'     => __( 'Bit', 'bitstream' ),
        'add_new_item'       => __( 'Add New Bit', 'bitstream' ),
        'edit_item'          => __( 'Edit Bit', 'bitstream' ),
        'new_item'           => __( 'New Bit', 'bitstream' ),
        'view_item'          => __( 'View Bit', 'bitstream' ),
        'all_items'          => __( 'All Bits', 'bitstream' ),
        'search_items'       => __( 'Search Bits', 'bitstream' ),
    ];
    $args = [
        'label'               => __( 'Bit', 'bitstream' ),
        'labels'              => $labels,
        'supports'            => ['editor', 'author', 'custom-fields', 'comments'],
        'public'              => true,
        'show_in_menu'        => true,
        'menu_position'       => 5,
        'menu_icon'           => 'dashicons-format-status',
        'has_archive'         => true,
        'rewrite'             => ['slug' => 'bitstream'],
        'show_in_rest'        => true,
    ];
    register_post_type('bit', $args);
}

// Auto-generate Bit titles
add_action( 'save_post', 'bitstream_auto_generate_title' );
function bitstream_auto_generate_title( $post_id ) {
    if ( get_post_type( $post_id ) !== 'bit' ) return;
    remove_action( 'save_post', 'bitstream_auto_generate_title' );
    $post_date = get_post_field( 'post_date', $post_id );
    $date = new DateTime( $post_date );
    $date_str = $date->format('Y-m-d');
    $bits = get_posts([
        'post_type'      => 'bit',
        'post_status'    => 'publish',
        'date_query'     => [[
            'year'  => $date->format('Y'),
            'month' => $date->format('m'),
            'day'   => $date->format('d'),
        ]],
        'fields'         => 'ids',
        'posts_per_page' => -1,
    ]);
    $count = count( $bits ) + 1;
    $count_str = str_pad( $count, 3, '0', STR_PAD_LEFT );
    $new_title = 'Bit #' . $date_str . ':' . $count_str;
    wp_update_post(['ID' => $post_id, 'post_title' => $new_title]);
    add_action( 'save_post', 'bitstream_auto_generate_title' );
}

// Open comments by default on new Bits
add_filter( 'wp_insert_post_data', function( $data, $postarr ) {
    if ( $data['post_type'] === 'bit' && empty( $postarr['ID'] ) ) {
        $data['comment_status'] = 'open';
    }
    return $data;
}, 10, 2 );

// Register ReBit URL meta and block
add_action('init', 'bitstream_register_meta_and_block');
function bitstream_register_meta_and_block() {
    register_post_meta('bit', 'bitstream_rebit_url', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
        'auth_callback' => function() { return current_user_can('edit_posts'); }
    ]);
    register_block_type('bitstream/rebit-url', ['editor_script' => 'bitstream-block']);
}

// Enqueue block editor script
add_action('enqueue_block_editor_assets', 'bitstream_enqueue_block_editor_assets');
function bitstream_enqueue_block_editor_assets() {
    wp_register_script(
        'bitstream-block',
        plugins_url('bitstream.js', __FILE__),
        ['wp-blocks','wp-element','wp-editor','wp-components','wp-data'],
        '1.0', true
    );
    $inline_js = <<<'JS'
(function(){
    const {registerBlockType,createBlock} = wp.blocks;
    const {dispatch,select} = wp.data;
    const {InspectorControls} = wp.blockEditor||wp.editor;
    const {PanelBody,TextControl} = wp.components;
    registerBlockType('bitstream/rebit-url',{title:'ReBit URL',icon:'admin-links',category:'widgets',attributes:{bitstream_rebit_url:{type:'string',source:'meta',meta:'bitstream_rebit_url'}},edit({attributes,setAttributes}){return[
        wp.element.createElement(InspectorControls,null,
            wp.element.createElement(PanelBody,{title:'ReBit Settings',initialOpen:true},
                wp.element.createElement(TextControl,{label:'ReBit URL',value:attributes.bitstream_rebit_url,onChange:value=>setAttributes({bitstream_rebit_url:value})})
            )
        )
    ];},save(){return null;}});
    if(window.location.search.includes('rebit=1')&&select('core/editor')&&select('core/editor').isEditedPostNew()){
        dispatch('core/block-editor').insertBlock(createBlock('bitstream/rebit-url'));
    }
})();
JS;
    wp_add_inline_script('bitstream-block',$inline_js);
    wp_enqueue_script('bitstream-block');
}

// Default content for new ReBit posts
add_filter('default_content','bitstream_default_rebit_content',10,2);
function bitstream_default_rebit_content($content,$post) {
    if($post->post_type==='bit'&&!empty($_GET['rebit'])&&$_GET['rebit']==='1'){
        return '<!-- wp:bitstream/rebit-url /-->'."\n";
    }
    return $content;
}

// Add "Post ReBit" submenu
add_action('admin_menu','bitstream_add_post_rebit_submenu');
function bitstream_add_post_rebit_submenu(){
    add_submenu_page('edit.php?post_type=bit','Post ReBit','Post ReBit','edit_posts','bitstream-post-rebit','bitstream_handle_post_rebit_submenu');
}
function bitstream_handle_post_rebit_submenu(){
    wp_redirect(admin_url('post-new.php?post_type=bit&rebit=1'));
    exit;
}

// Add ReBit Mappings admin page
add_action('admin_menu', function(){
    add_submenu_page(
        'edit.php?post_type=bit',
        'ReBit Mappings',
        'ReBit Mappings',
        'manage_options',
        'bitstream-rebit-mappings',
        'bitstream_rebit_mappings_page'
    );
});
function bitstream_rebit_mappings_page() {
    if (isset($_POST['bitstream_rebit_mappings']) && check_admin_referer('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce')) {
        $posted = $_POST['bitstream_rebit_mappings'];
        $new = [];
        foreach ($posted as $map) {
            if (isset($map['remove']) && $map['remove']) continue;
            $domain = sanitize_text_field($map['domain'] ?? '');
            $label  = sanitize_text_field($map['label'] ?? '');
            $icon   = sanitize_text_field($map['icon'] ?? '');
            if (!$domain || !$label || !$icon) continue;
            $new[] = compact('domain','label','icon');
        }
        update_option('bitstream_rebit_mappings', $new);
        echo '<div class="updated notice is-dismissible"><p>ReBit mappings saved.</p></div>';
    }
    $mappings = get_option('bitstream_rebit_mappings', []);
    echo '<div class="wrap"><h1>ReBit Mappings</h1><form method="post">';
    wp_nonce_field('bitstream_rebit_mappings_save','bitstream_rebit_mappings_nonce');
    echo '<table class="widefat fixed"><thead><tr><th>Domain</th><th>Label</th><th>Icon Class</th><th>Remove</th></tr></thead><tbody>';
    foreach ($mappings as $i => $map) {
        echo '<tr>';
        echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][domain]" value="'.esc_attr($map['domain']).'" /></td>';
        echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][label]"  value="'.esc_attr($map['label']).'"  /></td>';
        echo '<td><input type="text" name="bitstream_rebit_mappings['.$i.'][icon]"   value="'.esc_attr($map['icon']).'"   /></td>';
        echo '<td><input type="checkbox" name="bitstream_rebit_mappings['.$i.'][remove]" value="1" /></td>';
        echo '</tr>';
    }
    echo '<tr><td><input type="text" name="bitstream_rebit_mappings[new][domain]" /></td>';
    echo '<td><input type="text" name="bitstream_rebit_mappings[new][label]" /></td>';
    echo '<td><input type="text" name="bitstream_rebit_mappings[new][icon]" /></td>';
    echo '<td></td></tr>';
    echo '</tbody></table>';
    submit_button('Save Mappings');
    echo '</form></div>';
}

// 4) OG Data Fetching on Save (one‐time)
add_action( 'save_post_bit', function( $post_id, $post, $update ) {
    if ( $post->post_type !== 'bit' ) return;
    $url = get_post_meta( $post_id, 'bitstream_rebit_url', true );
    if ( empty( $url ) || get_post_meta( $post_id, '_bitstream_og_fetched', true ) ) return;
    $resp = wp_remote_get( $url, [ 'timeout' => 5 ] );
    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return;
    $html = wp_remote_retrieve_body( $resp );
    $og_title = $og_desc = $og_img = '';
    if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) $og_title = $m[1];
    if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) $og_desc = $m[1];
    if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) $og_img  = $m[1];
    if ( empty($og_title) && preg_match('/<title>(.*?)<\/title>/', $html, $m)) $og_title = $m[1];
    update_post_meta($post_id,'_bitstream_og_title', sanitize_text_field($og_title));
    update_post_meta($post_id,'_bitstream_og_desc',  sanitize_text_field($og_desc));
    update_post_meta($post_id,'_bitstream_og_image', esc_url_raw($og_img));
    update_post_meta($post_id,'_bitstream_og_fetched', time());
}, 10, 3 );

// 5) AJAX: Likes
add_action( 'wp_ajax_bitstream_like',    'bitstream_handle_like' );
add_action( 'wp_ajax_nopriv_bitstream_like','bitstream_handle_like' );
function bitstream_handle_like() {
    if ( empty($_POST['post_id']) || ! is_numeric($_POST['post_id']) ) {
        wp_send_json_error('Invalid post ID.');
    }
    $post_id = intval( $_POST['post_id'] );
    $current = (int) get_post_meta( $post_id, '_bitstream_likes', true );
    $type    = ( isset($_POST['type']) && $_POST['type'] === 'unlike' ) ? 'unlike' : 'like';

    if ( $type === 'unlike' ) {
        $new_count = max( 0, $current - 1 );
    } else {
        $new_count = $current + 1;
    }

    update_post_meta( $post_id, '_bitstream_likes', $new_count );
    wp_send_json_success( array( 'likes' => $new_count ) );
}

// 6) AJAX: Load More / Infinite Scroll
add_action('wp_ajax_bitstream_load_more','bitstream_load_more');
add_action('wp_ajax_nopriv_bitstream_load_more','bitstream_load_more');
function bitstream_load_more() {
    $page = isset($_POST['page'])?intval($_POST['page']):1;
    $q = new WP_Query([
        'post_type'      => 'bit',
        'post_status'    => 'publish',
        'posts_per_page' => 10,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC'
    ]);
    if ($q->have_posts()) { while($q->have_posts()){ $q->the_post(); echo bitstream_render_card(get_the_ID()); }}
    wp_reset_postdata(); wp_die();
}

// 7) Shortcode + Loop + Load More
add_action('init', function(){ add_shortcode('bitstream','bitstream_render_shortcode'); });
function bitstream_render_shortcode($atts){
    $atts = shortcode_atts(['posts_per_page'=>10,'paged'=>get_query_var('paged')?:1],$atts);
    $q = new WP_Query(['post_type'=>'bit','posts_per_page'=>intval($atts['posts_per_page']),'paged'=>intval($atts['paged']),'orderby'=>'date','order'=>'DESC']);
    $max = $q->max_num_pages;
    ob_start();
    if ($q->have_posts()){
        $current_page = intval($atts['paged']);
        echo '<div class="bitstream-feed" data-page="'.$current_page.'" data-max-page="'.$max.'">';
        while($q->have_posts()){ $q->the_post(); echo bitstream_render_card(get_the_ID()); }
        echo '</div>';
        if ($max>1) echo '<button id="bitstream-load-more" class="bitstream-load-more">Load More</button>';
    } else { echo '<p>No Bits found.</p>'; }
    wp_reset_postdata(); return ob_get_clean();
}

// Register [bitstream_latest] shortcode
add_action('init', function(){
    add_shortcode('bitstream_latest','bitstream_render_latest_shortcode');
});

/**
 * Shortcode callback for [bitstream_latest]
 * Outputs only the 3 most recent Bits, rendered with bitstream_render_card().
 */
function bitstream_render_latest_shortcode($atts) {
    $q = new WP_Query([
        'post_type'      => 'bit',
        'post_status'    => 'publish',
        'posts_per_page' => 3,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    ob_start();
    echo '<div class="bitstream-feed">';

    while ($q->have_posts()) {
        $q->the_post();
        echo bitstream_render_card(get_the_ID());
    }

    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
}



// Enqueue front-end assets
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('bitstream-css',plugins_url('bitstream.css',__FILE__),[], '1.0');
    wp_enqueue_script('bitstream-js',plugins_url('bitstream.js',__FILE__),[], '1.0', true);
    wp_localize_script('bitstream-js','bitstream_ajax',['ajax_url'=>admin_url('admin-ajax.php')]);
});

// 8) Render a single Bit card with ReBit Label/Icon
function bitstream_render_card( $post_id ) {
    $content   = apply_filters( 'the_content', get_post_field('post_content',$post_id) );
    $timestamp = human_time_diff(get_post_modified_time('U',false,$post_id),current_time('timestamp')).' ago';
    $avatar    = get_avatar(get_post_field('post_author',$post_id),48,'','',['class'=>'bit-avatar-img']);
    $likes     = (int)get_post_meta($post_id,'_bitstream_likes',true);
    $comments  = get_comments_number($post_id);
    $rebit_url = get_post_meta($post_id,'bitstream_rebit_url',true);

    ob_start(); ?>
    <article id="bit-<?php echo esc_attr($post_id); ?>" class="bit-card" style="margin:2rem auto;padding:1.5rem;max-width:720px;border:1px solid #eee;border-radius:16px;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <header class="bit-card-header" style="display:flex;align-items:center;margin-bottom:1rem;">
            <div class="bit-avatar" style="width:48px;height:48px;margin-right:0.75rem;border-radius:9999px;overflow:hidden;">
                <?php echo $avatar; ?>
            </div>
            <div class="bit-meta" style="font-size:0.875rem;color:var(--wp--preset--color--secondary,#666);">
                <span class="bit-timestamp"><?php echo esc_html($timestamp); ?></span>
            </div>
        </header>

        <div class="bit-card-content" style="font-size:1rem;line-height:1.6;margin-bottom:1rem;">
            <?php echo $content; ?>
        </div>

        <?php if ($rebit_url):
            $parsed = parse_url($rebit_url);
            $host   = $parsed['host'] ?? '';
            // Lookup mapping
            $map = null;
            $mappings = get_option('bitstream_rebit_mappings',[]);
            foreach($mappings as $m) {
                if (stripos($host,$m['domain'])!==false) { $map = $m; break; }
            }
            if ($map) {
                echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">'
                   . '<i class="'.esc_attr($map['icon']).'" aria-hidden="true" style="margin-right:0.5rem;"></i>'
                   . esc_html($map['label'])
                   . '</div>';
            } else {
                echo '<div class="bit-rebit-label" style="margin-bottom:0.5rem;font-size:0.95rem;color:#333;">
                <i class="fas fa-link" aria-hidden="true" style="margin-right:0.5rem;"></i> shared a link</div>';
            }

            // YouTube embed if applicable
            $is_yt = stripos($host,'youtube.com')!==false||stripos($host,'youtu.be')!==false;
            if ($is_yt) {
                $video_id='';
                if (stripos($host,'youtu.be')!==false) {
                    $video_id = ltrim($parsed['path'],'/');
                } elseif (isset($parsed['query'])){
                    parse_str($parsed['query'],$args);
                    $video_id = $args['v']??'';
                }
                if ($video_id) {
                    echo '<div class="bit-rebit-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1rem 0;">'
                       . '<iframe src="https://www.youtube.com/embed/'.esc_attr($video_id).'" '
                       . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                       . 'allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe></div>';
                } else {
                    echo '<a href="'.esc_url($rebit_url). '" target="_blank" rel="noopener">'.esc_html($rebit_url).'</a>';
                }
            } else {
                // OG preview
                echo '<div class="bit-rebit-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;margin-bottom:1rem;">';
                $og_img = get_post_meta($post_id,'_bitstream_og_image',true);
                if ($og_img) echo '<img src="'.esc_url($og_img).'" style="width:100%;display:block;" alt="">';
                echo '<div style="padding:0.75rem;">';
                $og_title = get_post_meta($post_id,'_bitstream_og_title',true);
                $og_desc  = get_post_meta($post_id,'_bitstream_og_desc',true);
                if ($og_title) echo '<h4 style="margin:0 0 0.5rem;font-size:1.1rem;">
                   <a href="'.esc_url($rebit_url).'" target="_blank" rel="noopener">'.esc_html($og_title).'</a></h4>';
                if ($og_desc) echo '<p style="margin:0;font-size:0.95rem;color:#555;">'.esc_html($og_desc).'</p>';
                if (!$og_desc) echo '<a href="'.esc_url($rebit_url).'" target="_blank" rel="noopener">'.esc_html($rebit_url).'</a>';
                echo '</div></div>';
            }
        endif; ?>

        <hr style="margin:1rem 0;border:none;border-top:1px solid #eee;">

        <footer class="bit-card-footer" style="display:flex;gap:1rem;font-size:0.875rem;align-items:center;">
    <button class="bit-comment-toggle bit-action" data-target="comments-<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;">
        <i class="fas fa-comment-dots"></i> <?php echo esc_html($comments); ?>
    </button>
    <button class="bit-like bit-action" data-post-id="<?php echo esc_attr($post_id); ?>" style="background:none;border:none;cursor:pointer;">
        <i class="fas fa-heart"></i> <span class="bit-like-count"><?php echo esc_html($likes); ?></span>
    </button>
    <button class="bit-permalink bit-action" data-url="<?php echo esc_url(get_permalink($post_id)); ?>" style="background:none;border:none;cursor:pointer;">
        <i class="fa-solid fa-up-right-from-square"></i>
    </button>
</footer>

        <div id="comments-<?php echo $post_id; ?>" class="bit-comments">
            <div class="bit-comments-list">
                <?php wp_list_comments(['style'=>'div','short_ping'=>true,'avatar_size'=>32],get_comments(['post_id'=>$post_id,'status'=>'approve'])); ?>
            </div>
            <div class="bit-comment-form">
                <?php comment_form([
                    'comment_notes_after'   => '',
                    'title_reply'           => 'Leave a Comment',
                    'logged_in_as'          => '',
                    'comment_field'         => '<p><textarea name="comment" required></textarea></p>',
                    'form_action'           => esc_url( $_SERVER['REQUEST_URI'] ),
                    'comment_post_redirect' => esc_url( $_SERVER['REQUEST_URI'] ),
                ], $post_id ); ?>
            </div>
        </div>
    </article>
    <?php
    return ob_get_clean();
}



// Add "Quote" row action to Bits in admin
add_filter('post_row_actions', function($actions, $post){
    if ($post->post_type === 'bit') {
        $url = admin_url('post-new.php?post_type=bit&quoted_bit=' . $post->ID);
        $actions['quote'] = '<a href="' . esc_url($url) . '">Quote</a>';
    }
    return $actions;
}, 10, 2);

// Show quoted Bit preview in "Add New Bit" if quoted_bit param is present
add_action('edit_form_after_title', function($post){
    if ($post->post_type === 'bit' && isset($_GET['quoted_bit'])) {
        $quoted_id = intval($_GET['quoted_bit']);
        $quoted_post = get_post($quoted_id);
        if ($quoted_post && in_array($quoted_post->post_type, ['bit'])) {
            $content = apply_filters('the_content', $quoted_post->post_content);
            echo '<div class="bitstream-quoted-preview" style="border-radius:13px; box-shadow:0 2px 12px rgba(0,0,0,0.10); padding:16px; background:#fafafa; margin-bottom:20px;">';
            echo '<strong>Quoting Bit #'.$quoted_id.'</strong><br>' . $content;
            echo '</div>';
            // Add hidden input so we remember on save
            echo '<input type="hidden" name="bitstream_quoted_bit" value="'.$quoted_id.'">';
        }
    }
});

// Save quoted Bit meta on save
add_action('save_post_bit', function($post_id){
    if (isset($_POST['bitstream_quoted_bit'])) {
        update_post_meta($post_id, '_bitstream_quoted_bit', intval($_POST['bitstream_quoted_bit']));
    } else {
        delete_post_meta($post_id, '_bitstream_quoted_bit');
    }
});




// Helper: Get human-friendly date and time
function bitstream_format_quoted_date($post_id) {
    $date = get_the_date('', $post_id);
    $time = get_the_time('', $post_id);
    return sprintf(esc_html__('Posted on %s at %s', 'bitstream'), $date, $time);
}

// Display quoted Bit in output (safe, no recursion, with date, accent color, and rich preview!)

add_filter('the_content', function($content) {
    global $post;
    static $already_rendered = [];
    if (!isset($post) || !is_object($post) || $post->post_type !== 'bit') return $content;

    // Prevent double render for this post in one request
    if (!empty($already_rendered[$post->ID])) return $content;

    if (!empty($GLOBALS['bitstream_is_rendering_quote'])) return $content;
    $quoted_id = get_post_meta($post->ID, '_bitstream_quoted_bit', true);
    if ($quoted_id) {
        $quoted_post = get_post($quoted_id);
        if ($quoted_post) {
            $header = '<div style="color:var(--wp--preset--color--accent-1,#2c6e49);font-weight:600;margin-bottom:8px;">'
                    . bitstream_format_quoted_date($quoted_id) . '</div>';
            $quoted_content = wpautop($quoted_post->post_content);
            $quoted_content = preg_replace('/<!--\s*wp:.*?\/-->/s', '', $quoted_content);
            $rich_preview = bitstream_render_og_card($quoted_id);
            $quoted_box = '<div class="bitstream-quoted-preview">'
                . $header . $quoted_content . $rich_preview . '</div>';
            $GLOBALS['bitstream_is_rendering_quote'] = true;
            $content = $quoted_box . $content;
            unset($GLOBALS['bitstream_is_rendering_quote']);
        }
    }
    $already_rendered[$post->ID] = true;
    return $content;
}); // End quoted box






// Helper: Get human-friendly date and time for quoted bits
if (!function_exists('bitstream_format_quoted_date')) {
    function bitstream_format_quoted_date($post_id) {
        $date = get_the_date('', $post_id);
        $time = get_the_time('', $post_id);
        $author = get_the_author_meta('display_name', get_post_field('post_author', $post_id));
        return sprintf(esc_html__('%s · Posted on %s at %s', 'bitstream'), esc_html($author), $date, $time);
    }
}

function bitstream_render_og_card($post_id) {
    $url   = get_post_meta($post_id, 'bitstream_rebit_url', true);
    $title = get_post_meta($post_id, '_bitstream_og_title', true);
    $desc  = get_post_meta($post_id, '_bitstream_og_desc', true);
    $img   = get_post_meta($post_id, '_bitstream_og_image', true);
    if (!$url && !$title && !$desc && !$img) return '';
    $card = '<div class="bitstream-og-card" style="display:flex;gap:16px;align-items:flex-start;margin-top:14px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.09);padding:14px;">';
    if ($img) {
        $card .= '<div class="bitstream-og-thumb" style="min-width:72px;width:72px;height:72px;background:#eee;border-radius:8px;overflow:hidden;display:flex;align-items:center;"><img src="'.esc_url($img).'" alt="" style="width:100%;height:auto;max-height:72px;object-fit:cover;display:block;"></div>';
    }
    $card .= '<div class="bitstream-og-meta" style="flex:1 1 0%;">';
    if ($title) {
        $card .= '<div class="bitstream-og-title" style="font-weight:600;font-size:1em;line-height:1.25;margin-bottom:4px;"><a href="'.esc_url($url).'" target="_blank" style="color:inherit;text-decoration:none;">'.esc_html($title).'</a></div>';
    } elseif ($url) {
        $card .= '<div class="bitstream-og-title"><a href="'.esc_url($url).'" target="_blank" style="color:inherit;text-decoration:none;">'.esc_html($url).'</a></div>';
    }
    if ($desc) {
        $card .= '<div class="bitstream-og-desc" style="font-size:0.97em;line-height:1.5;color:#444;margin-top:2px;">'.esc_html($desc).'</div>';
    }
    // HERE: Make the link green and clickable
    $card .= '<div class="bitstream-og-url" style="font-size:0.92em;color:var(--wp--preset--color--accent-1,#2c6e49);overflow-wrap:anywhere;word-break:break-all;margin-top:6px;"><a href="'.esc_url($url).'" target="_blank" style="color:var(--wp--preset--color--accent-1,#2c6e49);text-decoration:underline;word-break:break-all;">'.esc_html($url).'</a></div>';
    $card .= '</div></div>';
    return $card;
}

// Display quoted Bit in output (final: use global context to prevent double quote rendering!)
// End quoted box

// ===== Front-end Quick Post Page =====
function bitstream_register_quick_post_rule() {
    add_rewrite_rule('^bitstream/new/?$', 'index.php?bitstream_new=1', 'top');
    add_rewrite_tag('%bitstream_new%', '1');
}
add_action('init', 'bitstream_register_quick_post_rule');

function bitstream_quick_post_query_var($vars){
    $vars[] = 'bitstream_new';
    return $vars;
}
add_filter('query_vars', 'bitstream_quick_post_query_var');

function bitstream_quick_post_version_check() {
    if (get_option('bitstream_version') !== BITSTREAM_VERSION) {
        flush_rewrite_rules();
        update_option('bitstream_version', BITSTREAM_VERSION);
    }
}
add_action('init', 'bitstream_quick_post_version_check', 20);

function bitstream_quick_post_activate() {
    flush_rewrite_rules();
    update_option('bitstream_version', BITSTREAM_VERSION);
}
register_activation_hook(__FILE__, 'bitstream_quick_post_activate');

// Output manifest link and service worker registration
add_action('wp_head', function(){
    if (get_query_var('bitstream_new')) {
        $base = plugin_dir_url(__FILE__);
        echo '<link rel="manifest" href="'.esc_url($base.'manifest.json').'">';
        echo '<meta name="theme-color" content="#2c6e49">';
        echo '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("'.esc_url($base.'sw.js').'");}</script>';
    }
});

// Handle quick post page
add_action('template_redirect', function(){
    if (!get_query_var('bitstream_new')) return;
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url(site_url('/bitstream/new')));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('bitstream_quick_new','bitstream_nonce')) {
        $content   = wp_kses_post($_POST['bit_content'] ?? '');
        $rebit_url = isset($_POST['bit_rebit_url']) ? esc_url_raw($_POST['bit_rebit_url']) : '';
        $post_id   = wp_insert_post([
            'post_type'   => 'bit',
            'post_status' => 'publish',
            'post_title'  => '',
            'post_content'=> $content,
        ]);
        if ($post_id && !is_wp_error($post_id)) {
            if ($rebit_url) update_post_meta($post_id,'bitstream_rebit_url',$rebit_url);
            if (!empty($_FILES['bit_image']['tmp_name'])) {
                require_once ABSPATH.'wp-admin/includes/file.php';
                require_once ABSPATH.'wp-admin/includes/media.php';
                $attachment_id = media_handle_upload('bit_image',$post_id);
                if (!is_wp_error($attachment_id)) {
                    $img_url = wp_get_attachment_url($attachment_id);
                    $content .= "\n<img src='".esc_url($img_url)."' alt='' />";
                    wp_update_post(['ID'=>$post_id,'post_content'=>$content]);
                }
            }
            wp_redirect(get_permalink($post_id));
            exit;
        }
    }

    get_header();
    echo '<main id="primary" class="site-main">';
    echo '<article class="page type-page">';
    echo '<div class="entry-content">';
    echo '<h1 class="entry-title">New Bit</h1>';
    echo '<form method="post" enctype="multipart/form-data" class="bitstream-form">';
    wp_nonce_field('bitstream_quick_new','bitstream_nonce');
    echo '<p><label>Content<br><textarea name="bit_content" rows="5" required style="width:100%;"></textarea></label></p>';
    echo '<p><label>ReBit URL<br><input type="url" name="bit_rebit_url" style="width:100%;"></label></p>';
    echo '<p><label>Image<br><input type="file" name="bit_image" accept="image/*"></label></p>';
    echo '<div class="wp-block-button"><button type="submit" class="wp-block-button__link">Post Bit</button></div>';
    echo '</form>';
    echo '<div class="wp-block-button is-style-outline" style="margin-top:1rem;"><a class="wp-block-button__link" href="'.esc_url(admin_url('post-new.php?post_type=bit')).'">Launch Full Editor</a></div>';
    echo '</div>';
    echo '</article>';
    echo '</main>';
    get_footer();
    exit;
});
