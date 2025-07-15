<?php
/**
 * Plugin Name: LearnDash Custom Instructor Dashboard
 * Description: Uninstallation script for LearnDash Custom Instructor Dashboard
 * Version: 1.0
 */

// Security check
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove any custom options
$option_keys = array(
    'custom_dashboard_settings',
    'custom_dashboard_threshold'
);

foreach ($option_keys as $option) {
    delete_option($option);
}

// Remove any custom tables
// Note: This plugin doesn't create custom tables, but if it did, we would remove them here
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}custom_dashboard_data");
