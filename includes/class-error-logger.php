<?php
/**
 * BitStream Error Logger
 * 
 * @package BitStream
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class BitStream_Error_Logger {
    
    public function __construct() {
        add_action('wp_ajax_bitstream_clear_logs', [$this, 'clear_logs']);
        add_action('admin_menu', [$this, 'add_debug_menu']);
    }
    
    /**
     * Log BitStream specific errors
     */
    public static function log($message, $level = 'error') {
        if (!WP_DEBUG_LOG) return;
        
        $log_entry = sprintf(
            '[%s] BitStream %s: %s',
            current_time('mysql'),
            strtoupper($level),
            $message
        );
        
        error_log($log_entry);
        
        // Store in option for admin viewing (limit to 50 entries)
        $logs = get_option('bitstream_debug_logs', []);
        array_unshift($logs, [
            'time' => current_time('mysql'),
            'level' => $level,
            'message' => $message
        ]);
        
        // Keep only last 50 logs
        $logs = array_slice($logs, 0, 50);
        update_option('bitstream_debug_logs', $logs);
    }
    
    /**
     * Add debug menu for admins
     */
    public function add_debug_menu() {
        if (WP_DEBUG && current_user_can('manage_options')) {
            add_submenu_page(
                'edit.php?post_type=bit',
                'Debug Logs',
                'Debug Logs',
                'manage_options',
                'bitstream-debug',
                [$this, 'debug_page']
            );
        }
    }
    
    /**
     * Debug logs page
     */
    public function debug_page() {
        if (isset($_POST['clear_logs']) && check_admin_referer('bitstream_clear_logs')) {
            delete_option('bitstream_debug_logs');
            echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
        }
        
        $logs = get_option('bitstream_debug_logs', []);
        
        echo '<div class="wrap">';
        echo '<h1>BitStream Debug Logs</h1>';
        
        if (empty($logs)) {
            echo '<p>No logs found.</p>';
        } else {
            echo '<form method="post">';
            wp_nonce_field('bitstream_clear_logs');
            echo '<input type="submit" name="clear_logs" value="Clear Logs" class="button button-secondary" />';
            echo '</form><br>';
            
            echo '<table class="widefat fixed">';
            echo '<thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($logs as $log) {
                $class = $log['level'] === 'error' ? 'error' : 'info';
                echo '<tr class="' . esc_attr($class) . '">';
                echo '<td>' . esc_html($log['time']) . '</td>';
                echo '<td>' . esc_html(strtoupper($log['level'])) . '</td>';
                echo '<td>' . esc_html($log['message']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    /**
     * Clear logs via AJAX
     */
    public function clear_logs() {
        if (!current_user_can('manage_options') || !check_admin_referer('bitstream_clear_logs')) {
            wp_die('Unauthorized');
        }
        
        delete_option('bitstream_debug_logs');
        wp_send_json_success('Logs cleared');
    }
}
