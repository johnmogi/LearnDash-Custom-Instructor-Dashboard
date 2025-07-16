<?php
/**
 * Debug helper for LearnDash Custom Dashboard
 */

// Add this to wp-config.php to enable debugging
// define('WP_DEBUG', true);
// define('WP_DEBUG_LOG', true);
// define('WP_DEBUG_DISPLAY', false);

// Force error logging for this plugin
ini_set('log_errors', 1);
ini_set('error_log', WP_CONTENT_DIR . '/debug.log');

/**
 * Log AJAX requests to debug.log
 */
function ld_custom_dashboard_log_ajax_request() {
    if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], [
        'load_group_content', 
        'get_group_average', 
        'get_group_quiz_stats',
        'export_teacher_summary',
        'export_detailed_grades'
    ])) {
        error_log('======= LearnDash Custom Dashboard AJAX Request =======');
        error_log('Action: ' . $_REQUEST['action']);
        error_log('Request method: ' . $_SERVER['REQUEST_METHOD']);
        error_log('Request data: ' . print_r($_REQUEST, true));
        
        // Check if user is logged in
        error_log('User logged in: ' . (is_user_logged_in() ? 'Yes' : 'No'));
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            error_log('User ID: ' . $user->ID);
            error_log('User roles: ' . implode(', ', $user->roles));
            error_log('User capabilities: ' . print_r($user->allcaps, true));
            
            // Check if user is a group leader
            if (function_exists('learndash_is_group_leader_user')) {
                error_log('Is group leader: ' . (learndash_is_group_leader_user($user->ID) ? 'Yes' : 'No'));
            }
            
            // Log group IDs if this is a group-related request
            if (isset($_REQUEST['group_id'])) {
                $group_id = intval($_REQUEST['group_id']);
                error_log('Requested group ID: ' . $group_id);
                
                // Check if group exists
                $group = get_post($group_id);
                error_log('Group exists: ' . ($group ? 'Yes' : 'No'));
                if ($group) {
                    error_log('Group title: ' . $group->post_title);
                    error_log('Group status: ' . $group->post_status);
                }
                
                // Check if user has access to this group
                if (function_exists('learndash_is_admin_user') && learndash_is_admin_user()) {
                    error_log('User is admin, has access to all groups');
                } else if (function_exists('learndash_get_administrators_group_ids')) {
                    $user_group_ids = learndash_get_administrators_group_ids($user->ID);
                    error_log('User group IDs from LearnDash: ' . implode(', ', $user_group_ids));
                    error_log('Has access to requested group via LearnDash: ' . (in_array($group_id, $user_group_ids) ? 'Yes' : 'No'));
                    
                    // Check direct database access
                    global $wpdb;
                    $meta_value = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->postmeta} 
                        WHERE post_id = %d AND meta_key = '_ld_group_leaders'",
                        $group_id
                    ));
                    
                    if ($meta_value) {
                        $leaders = maybe_unserialize($meta_value);
                        error_log('Group leaders from DB: ' . print_r($leaders, true));
                        error_log('User in leaders array: ' . (in_array($user->ID, $leaders) ? 'Yes' : 'No'));
                    } else {
                        error_log('No group leaders found in database');
                    }
                }
            }
        }
        
        // Check nonce
        if (isset($_REQUEST['nonce'])) {
            $nonce_valid = wp_verify_nonce($_REQUEST['nonce'], 'learndash_custom_dashboard_nonce');
            error_log('Nonce valid: ' . ($nonce_valid ? 'Yes' : 'No'));
        } else {
            error_log('Nonce not provided');
        }
        
        error_log('=================================================');
    }
}
add_action('init', 'ld_custom_dashboard_log_ajax_request');

/**
 * Log group access checks
 */
function ld_custom_dashboard_log_group_access($user_id, $group_id) {
    error_log('======= LearnDash Custom Dashboard Group Access Check =======');
    error_log('User ID: ' . $user_id);
    error_log('Group ID: ' . $group_id);
    
    // Check direct database access
    global $wpdb;
    $meta_value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} 
        WHERE post_id = %d AND meta_key = '_ld_group_leaders'",
        $group_id
    ));
    
    error_log('Group leaders meta value: ' . $meta_value);
    
    // Check if user ID is in the serialized array
    if ($meta_value) {
        $leaders = maybe_unserialize($meta_value);
        error_log('Unserialized leaders: ' . print_r($leaders, true));
        error_log('User in leaders array: ' . (in_array($user_id, $leaders) ? 'Yes' : 'No'));
    }
    
    error_log('=================================================');
}
