<?php
/**
 * Plugin Name: LearnDash Custom Instructor Dashboard
 * Description: Enhanced instructor dashboard with custom grading metrics
 * Version: 1.0
 * Author: Your Name
 * Text Domain: learndash-custom-dashboard
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Security check
if (!defined('ABSPATH')) {
    exit;
}

class LearnDash_Custom_Dashboard {
    private $version = '1.0.0';
    private $plugin_name = 'learndash-custom-dashboard';
    private $plugin_slug = 'learndash-custom-dashboard';
    private $threshold = 10;
    private $learndash_loaded = false;
    private $initialized = false;
    private $is_instructor = false;
    private $instructor_groups = array();

    public function __construct() {
        try {
            // Initialize grade calculator
            add_action('plugins_loaded', array($this, 'init_grade_calculator'));
            
            // Register shortcode
            add_shortcode('learndash_instructor_dashboard', array($this, 'render_dashboard'));
            
        } catch (Exception $e) {
            error_log('Custom Dashboard - Initialization error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize the grade calculator
     */
    public function init_grade_calculator() {
        if (!class_exists('LearnDash_Grade_Calculator')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-learndash-grade-calculator.php';
        }
        $this->grade_calculator = new LearnDash_Grade_Calculator();
    }
    
    private function init_plugin() {
        try {
            // Initialize textdomain
            load_plugin_textdomain(
                'learndash-custom-dashboard',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages/'
            );
            
            // Initialize hooks
            // Add action hooks
            add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
            
            // Add AJAX hooks
            add_action('wp_ajax_nopriv_ld_custom_dashboard', array($this, 'ajax_unauthorized'));
            add_action('wp_ajax_ld_custom_dashboard', array($this, 'ajax_handler'));
            
            // Add AJAX handlers for grade statistics
            add_action('wp_ajax_get_group_average', array($this, 'ajax_get_group_average'));
            add_action('wp_ajax_get_group_quiz_stats', array($this, 'ajax_get_group_quiz_stats'));
            add_action('wp_ajax_export_teacher_summary', array($this, 'ajax_export_teacher_summary'));
            add_action('wp_ajax_export_detailed_grades', array($this, 'ajax_export_detailed_grades'));
            
            // Set initialized flag
            $this->initialized = true;
            
        } catch (Exception $e) {
            error_log('Custom Dashboard - Plugin initialization error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function render_dashboard_content() {
        // Get instructor groups
        $groups = $this->get_instructor_groups();
        
        if (empty($groups)) {
            return '<p>' . esc_html__('No groups assigned to your account.', 'learndash-custom-dashboard') . '</p>';
        }
        
        // Enqueue assets
        $this->enqueue_assets();
        
        ob_start();
        ?>
        <div class="learndash-custom-dashboard">
            <div class="dashboard-header">
                <h1><?php esc_html_e('Instructor Dashboard', 'learndash-custom-dashboard'); ?></h1>
                <p><?php esc_html_e('Manage your classes and view student progress', 'learndash-custom-dashboard'); ?></p>
            </div>
            
            <div class="group-selector">
                <label for="group-select"><?php esc_html_e('Select Class:', 'learndash-custom-dashboard'); ?></label>
                <select id="group-select">
                    <option value=""><?php esc_html_e('-- Select a Class --', 'learndash-custom-dashboard'); ?></option>
                    <?php foreach ($groups as $group_id => $group_name) : ?>
                        <option value="<?php echo esc_attr($group_id); ?>"><?php echo esc_html($group_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="group-content">
                <div class="group-info">
                    <div class="group-title"></div>
                    <div class="group-stats">
                        <div class="students-count">
                            <span class="count">0</span>
                            <span class="label"><?php esc_html_e('Students', 'learndash-custom-dashboard'); ?></span>
                        </div>
                        <div class="courses-count">
                            <span class="count">0</span>
                            <span class="label"><?php esc_html_e('Courses', 'learndash-custom-dashboard'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="group-students"></div>
                <div class="group-courses"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function render_dashboard() {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to access the instructor dashboard.', 'learndash-custom-dashboard') . '</p>';
        }
        
        // Check user capabilities
        if (!current_user_can('administrator') && !$this->is_instructor()) {
            return '<p>' . __('You do not have permission to access this dashboard.', 'learndash-custom-dashboard') . '</p>';
        }
        
        // Enqueue assets
        $this->enqueue_assets();
        
        // Start output buffering
        ob_start();
        
        // Get the current user's groups
        $user_id = get_current_user_id();
        $groups = $this->get_instructor_groups($user_id);
        
        // Get the first group ID for initial load if available
        $initial_group_id = !empty($groups) ? $groups[0]->ID : 0;
        
        // Include the dashboard template
        include plugin_dir_path(__FILE__) . 'templates/dashboard.php';
        
        // Include the gradebook stats template if we have a group
        if ($initial_group_id) {
            $this->render_gradebook_stats($initial_group_id);
        } else {
            echo '<div id="gradebook-stats" style="display: none;"></div>';
        }
        
        // Return the buffered content
        return ob_get_clean();
    }
    
    /**
     * Render the gradebook statistics section
     */
    private function render_gradebook_stats($group_id) {
        if (!$this->grade_calculator) {
            return;
        }
        
        // Get the group object
        $group = get_post($group_id);
        if (!$group) {
            return;
        }
        
        // Get group stats
        $group_average = $this->grade_calculator->get_group_average($group_id);
        $quiz_stats = $this->grade_calculator->get_group_quiz_stats($group_id);
        $quiz_count = !empty($quiz_stats) ? count($quiz_stats) : 0;
        
        // Get export URLs
        $export_summary_url = add_query_arg(
            array(
                'action' => 'export_teacher_summary',
                'group_id' => $group_id,
                'nonce' => wp_create_nonce('ld_export_nonce')
            ),
            admin_url('admin-ajax.php')
        );
        
        $export_detailed_url = add_query_arg(
            array(
                'action' => 'export_detailed_grades',
                'group_id' => $group_id,
                'nonce' => wp_create_nonce('ld_export_nonce')
            ),
            admin_url('admin-ajax.php')
        );
        
        // Output the gradebook stats HTML
        ?>
        <div id="gradebook-stats" class="gradebook-stats">
            <div class="gradebook-stats-header">
                <h2><?php echo esc_html__('Gradebook Statistics', 'learndash-custom-dashboard'); ?></h2>
                <div class="export-controls">
                    <a href="#" id="export-summary" class="export-btn" data-url="<?php echo esc_url($export_summary_url); ?>">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                        <?php esc_html_e('Export Summary', 'learndash-custom-dashboard'); ?>
                    </a>
                    <a href="#" id="export-detailed" class="export-btn" data-url="<?php echo esc_url($export_detailed_url); ?>">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                        <?php esc_html_e('Export Detailed Grades', 'learndash-custom-dashboard'); ?>
                    </a>
                </div>
                <div id="export-status" class="export-status"></div>
            </div>
            
            <div class="gradebook-stats-grid">
                <div class="gradebook-stat">
                    <span id="group-average" class="gradebook-stat-value">
                        <?php echo $group_average !== 'N/A' ? esc_html($group_average . '%') : 'N/A'; ?>
                    </span>
                    <span class="gradebook-stat-label"><?php esc_html_e('Group Average', 'learndash-custom-dashboard'); ?></span>
                </div>
                
                <div class="gradebook-stat">
                    <span id="quizzes-count" class="gradebook-stat-value">
                        <?php echo esc_html($quiz_count); ?>
                    </span>
                    <span class="gradebook-stat-label"><?php esc_html_e('Quizzes', 'learndash-custom-dashboard'); ?></span>
                </div>
            </div>
            
            <div id="quiz-stats" class="quiz-stats">
                <?php if (!empty($quiz_stats)) : ?>
                    <div class="quiz-stats-container">
                        <table class="quiz-stats-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Quiz', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Average Score', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Attempts', 'learndash-custom-dashboard'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quiz_stats as $quiz_id => $quiz) : ?>
                                    <tr>
                                        <td><?php echo esc_html($quiz['title']); ?></td>
                                        <td><?php echo $quiz['average'] !== 'N/A' ? esc_html($quiz['average'] . '%') : 'N/A'; ?></td>
                                        <td><?php echo esc_html($quiz['attempts']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php 
                        // Calculate overall average
                        $total_average = 0;
                        $valid_quizzes = 0;
                        
                        foreach ($quiz_stats as $quiz) {
                            if ($quiz['average'] !== 'N/A') {
                                $total_average += floatval($quiz['average']);
                                $valid_quizzes++;
                            }
                        }
                        
                        if ($valid_quizzes > 0) : 
                            $overall_average = round($total_average / $valid_quizzes, 2);
                        ?>
                            <div class="overall-average">
                                <strong><?php esc_html_e('Overall Average:', 'learndash-custom-dashboard'); ?></strong> 
                                <?php echo esc_html($overall_average); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <p class="no-data"><?php esc_html_e('No quiz data available for this group.', 'learndash-custom-dashboard'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function enqueue_assets() {
        // Only load on pages with our shortcode
        if (!is_singular()) return;
        
        global $post;
        if (!has_shortcode($post->post_content, 'learndash_instructor_dashboard')) {
            return;
        }
        
        // Enqueue Dashicons for the frontend
        wp_enqueue_style('dashicons');
        
        // Enqueue styles
        wp_enqueue_style(
            'learndash-custom-dashboard',
            plugin_dir_url(__FILE__) . 'assets/css/dashboard.css',
            array('dashicons'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/dashboard.css')
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'learndash-custom-dashboard',
            plugin_dir_url(__FILE__) . 'assets/js/dashboard.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/dashboard.js'),
            true
        );
        
        // Localize script with AJAX URL and nonce
        $ajax_params = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('custom_dashboard_nonce'),
            'exportNonce' => wp_create_nonce('ld_export_nonce'),
            'exportUrl' => admin_url('admin-ajax.php'),
            'i18n' => array(
                'exporting' => __('Exporting...', 'learndash-custom-dashboard'),
                'exportFailed' => __('Export failed. Please try again.', 'learndash-custom-dashboard'),
                'exportComplete' => __('Export completed!', 'learndash-custom-dashboard'),
                'noData' => __('No data available', 'learndash-custom-dashboard'),
                'exportSummary' => __('Export Summary', 'learndash-custom-dashboard'),
                'exportDetailed' => __('Export Detailed Grades', 'learndash-custom-dashboard')
            )
        );
        
        wp_localize_script('learndash-custom-dashboard', 'customDashboard', $ajax_params);
    }
    
    public function is_instructor() {
        // Check if we've already determined this
        if ($this->is_instructor !== false) {
            return $this->is_instructor;
        }
        
        // Get current user
        $user = wp_get_current_user();
        
        // Check if user has instructor role or capabilities
        $this->is_instructor = in_array('school_teacher', (array) $user->roles) || 
                              in_array('group_leader', (array) $user->roles) ||
                              $user->has_cap('manage_learndash_groups') ||
                              $user->has_cap('edit_courses');
        
        return $this->is_instructor;
    }
    
    public function get_instructor_groups() {
        // If we've already loaded the groups, return them
        if (!empty($this->instructor_groups)) {
            return $this->instructor_groups;
        }
        
        // Get current user
        $user = wp_get_current_user();
        
        // Initialize groups array
        $groups = array();
        
        // If user is an admin, get all groups
        if (in_array('administrator', (array) $user->roles)) {
            $group_query = new WP_Query(array(
                'post_type' => 'groups',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids',
            ));
            
            if ($group_query->have_posts()) {
                foreach ($group_query->posts as $group_id) {
                    $groups[$group_id] = get_the_title($group_id);
                }
            }
            
            $this->instructor_groups = $groups;
            return $groups;
        }
        
        // For non-admin users, get groups they're a leader of
        if (function_exists('learndash_get_administrators_group_ids')) {
            $group_ids = learndash_get_administrators_group_ids($user->ID);
            
            if (!empty($group_ids)) {
                foreach ($group_ids as $group_id) {
                    $groups[$group_id] = get_the_title($group_id);
                }
            }
        }
        
        // Fallback: Check user meta for group leader assignments
        if (empty($groups) && function_exists('learndash_get_administrators_group_ids')) {
            $group_leader_groups = get_user_meta($user->ID, 'learndash_group_leaders_');
            
            if (!empty($group_leader_groups)) {
                foreach ($group_leader_groups as $group_id) {
                    if (get_post_status($group_id) === 'publish') {
                        $groups[$group_id] = get_the_title($group_id);
                    }
                }
            }
        }
        
        // Cache the results
        $this->instructor_groups = $groups;
        
        return $groups;
    }
    
    public function ajax_load_group_content() {
        error_log('AJAX load_group_content called');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'custom_dashboard_nonce')) {
                throw new Exception('Invalid nonce');
            }
            
            // Check if user is logged in
            if (!is_user_logged_in()) {
                throw new Exception('User not logged in');
            }
            
            // Get current user info for debugging
            $current_user = wp_get_current_user();
            error_log('Current user ID: ' . $current_user->ID);
            error_log('Current user roles: ' . implode(', ', $current_user->roles));
            
            // Get group ID from request
            $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
            error_log('Requested group ID: ' . $group_id);
            
            if (!$group_id) {
                throw new Exception('No group ID provided');
            }
            
            // Check if user has permission to view this group
            $user_groups = $this->get_instructor_groups();
            error_log('User group IDs: ' . implode(', ', array_keys($user_groups)));
            
            if (!in_array($group_id, array_keys($user_groups)) && !current_user_can('manage_options')) {
                throw new Exception('Access denied. You do not have permission to view this group.');
            }
            
            // Get group data
            error_log('Getting group data for ID: ' . $group_id);
            $group_data = $this->get_group_data($group_id);
            
            if (is_wp_error($group_data)) {
                throw new Exception($group_data->get_error_message());
            }
            
            error_log('Successfully retrieved group data');
            
            // Send success response
            wp_send_json_success($group_data);
            
        } catch (Exception $e) {
            error_log('Error in ajax_load_group_content: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'debug' => array(
                    'user_id' => isset($current_user) ? $current_user->ID : 0,
                    'group_id' => isset($group_id) ? $group_id : 0,
                    'user_groups' => isset($user_groups) ? array_keys($user_groups) : array()
                )
            ));
        }
        
        wp_die();
    }
    
    private function get_group_data($group_id) {
        // Get group courses
        $courses = learndash_group_enrolled_courses($group_id);
        
        // Get group users
        $users = learndash_get_groups_users($group_id);
        
        // Prepare response data
        $data = array(
            'group_id' => $group_id,
            'group_name' => get_the_title($group_id),
            'courses' => array(),
            'students' => array(),
            'stats' => array(
                'total_courses' => 0,
                'total_students' => 0,
                'total_completed' => 0,
                'total_in_progress' => 0,
                'total_not_started' => 0,
            )
        );
        
        // Process courses
        if (!empty($courses)) {
            $data['stats']['total_courses'] = count($courses);
            
            foreach ($courses as $course_id) {
                $data['courses'][$course_id] = array(
                    'id' => $course_id,
                    'title' => get_the_title($course_id),
                    'url' => get_permalink($course_id),
                    'completed' => 0,
                    'in_progress' => 0,
                    'not_started' => 0
                );
            }
        }
        
        // Process users
        if (!empty($users)) {
            $data['stats']['total_students'] = count($users);
            
            foreach ($users as $user) {
                $user_data = array(
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'courses' => array()
                );
                
                // Get user's course progress
                if (!empty($courses)) {
                    foreach ($courses as $course_id) {
                        $course_completed = learndash_course_completed($user->ID, $course_id);
                        $progress = learndash_user_get_course_progress($user->ID, $course_id, 'summary');
                        
                        $status = 'not_started';
                        if ($course_completed) {
                            $status = 'completed';
                            $data['courses'][$course_id]['completed']++;
                            $data['stats']['total_completed']++;
                        } elseif (!empty($progress['status']) && $progress['status'] === 'in_progress') {
                            $status = 'in_progress';
                            $data['courses'][$course_id]['in_progress']++;
                            $data['stats']['total_in_progress']++;
                        } else {
                            $data['courses'][$course_id]['not_started']++;
                            $data['stats']['total_not_started']++;
                        }
                        
                        $user_data['courses'][$course_id] = array(
                            'status' => $status,
                            'progress' => $progress,
                            'last_activity' => get_user_meta($user->ID, 'learndash_last_activity', true)
                        );
                    }
                }
                
                $data['students'][] = $user_data;
            }
        }
        
        return $data;
    }
    
    public function deactivate() {
        // Clean up any plugin data if needed
        delete_option('learndash_custom_dashboard_version');
    }
    
    /**
     * Handle unauthorized AJAX requests
     */
    public function ajax_unauthorized() {
        wp_send_json_error(array('message' => 'You must be logged in to perform this action.'));
        wp_die();
    }
    
    /**
     * AJAX handler to get group average
     */
    public function ajax_get_group_average() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learndash_custom_dashboard_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'learndash-custom-dashboard')), 403);
        }
        
        // Check permissions
        if (!current_user_can('administrator') && !$this->is_instructor()) {
            wp_send_json_error(array('message' => __('You do not have permission to view this data.', 'learndash-custom-dashboard')), 403);
        }
        
        // Get group ID
        $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
        if (!$group_id) {
            wp_send_json_error(array('message' => __('No group ID provided.', 'learndash-custom-dashboard')), 400);
        }
        
        // Verify user has access to this group
        if (!learndash_is_group_leader_of_user(get_current_user_id()) && !current_user_can('manage_options')) {
            $instructor_groups = $this->get_instructor_groups(get_current_user_id());
            $has_access = false;
            
            foreach ($instructor_groups as $group) {
                if ($group->ID == $group_id) {
                    $has_access = true;
                    break;
                }
            }
            
            if (!$has_access) {
                wp_send_json_error(array('message' => __('You do not have access to this group.', 'learndash-custom-dashboard')), 403);
            }
        }
        
        // Get group average
        $average = $this->grade_calculator->get_group_average($group_id);
        
        // Format the response
        $response = array(
            'raw' => $average,
            'formatted' => $average !== 'N/A' ? $average . '%' : 'N/A'
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX handler to get group quiz statistics
     */
    public function ajax_get_group_quiz_stats() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learndash_custom_dashboard_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'learndash-custom-dashboard')), 403);
        }
        
        // Check permissions
        if (!current_user_can('administrator') && !$this->is_instructor()) {
            wp_send_json_error(array('message' => __('You do not have permission to view this data.', 'learndash-custom-dashboard')), 403);
        }
        
        // Get group ID
        $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
        if (!$group_id) {
            wp_send_json_error(array('message' => __('No group ID provided.', 'learndash-custom-dashboard')), 400);
        }
        
        // Verify user has access to this group
        if (!learndash_is_group_leader_of_user(get_current_user_id()) && !current_user_can('manage_options')) {
            $instructor_groups = $this->get_instructor_groups(get_current_user_id());
            $has_access = false;
            
            foreach ($instructor_groups as $group) {
                if ($group->ID == $group_id) {
                    $has_access = true;
                    break;
                }
            }
            
            if (!$has_access) {
                wp_send_json_error(array('message' => __('You do not have access to this group.', 'learndash-custom-dashboard')), 403);
            }
        }
        
        // Get quiz stats
        $quiz_stats = $this->grade_calculator->get_group_quiz_stats($group_id);
        
        wp_send_json_success($quiz_stats);
    }
    
    /**
     * AJAX handler to export teacher summary
     */
    public function ajax_export_teacher_summary() {
        // Check nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ld_export_nonce')) {
            wp_die(__('Security check failed.', 'learndash-custom-dashboard'));
        }
        
        // Check permissions
        if (!current_user_can('administrator') && !$this->is_instructor()) {
            wp_die(__('You do not have permission to export this data.', 'learndash-custom-dashboard'));
        }
        
        // Get group ID
        $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
        if (!$group_id) {
            wp_die(__('No group ID provided.', 'learndash-custom-dashboard'));
        }
        
        // Verify user has access to this group
        $user_groups = $this->get_instructor_groups();
        if (!isset($user_groups[$group_id]) && !current_user_can('manage_options')) {
            wp_die(__('You do not have access to this group.', 'learndash-custom-dashboard'));
        }
        
        // Verify user has access to this group
        if (!learndash_is_group_leader_of_user(get_current_user_id()) && !current_user_can('manage_options')) {
            $instructor_groups = $this->get_instructor_groups(get_current_user_id());
            $has_access = false;
            
            foreach ($instructor_groups as $group) {
                if ($group->ID == $group_id) {
                    $has_access = true;
                    break;
                }
            }
            
            if (!$has_access) {
                wp_die(__('You do not have access to this group.', 'learndash-custom-dashboard'));
            }
        }
        
        // Get group data
        $group = get_post($group_id);
        if (!$group) {
            wp_die(__('Group not found.', 'learndash-custom-dashboard'));
        }
        
        // Get group stats
        $group_average = $this->grade_calculator->get_group_average($group_id);
        $quiz_stats = $this->grade_calculator->get_group_quiz_stats($group_id);
        $students = learndash_get_groups_users($group_id);
        $courses = learndash_group_enrolled_courses($group_id);
        
        // Calculate overall quiz average
        $total_average = 0;
        $valid_quizzes = 0;
        
        foreach ($quiz_stats as $quiz) {
            if ($quiz['average'] !== 'N/A') {
                $total_average += floatval($quiz['average']);
                $valid_quizzes++;
            }
        }
        
        $overall_average = $valid_quizzes > 0 ? round($total_average / $valid_quizzes, 2) : 0;
        
        // Set headers for CSV download
        $filename = 'teacher-summary-' . $group_id . '-' . date('Y-m-d') . '.csv';
        
        // Force download headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");
        
        // Add headers
        fputcsv($output, array(
            __('Group Name', 'learndash-custom-dashboard'),
            __('Group ID', 'learndash-custom-dashboard'),
            __('Number of Students', 'learndash-custom-dashboard'),
            __('Number of Courses', 'learndash-custom-dashboard'),
            __('Number of Quizzes', 'learndash-custom-dashboard'),
            __('Group Average', 'learndash-custom-dashboard'),
            __('Overall Quiz Average', 'learndash-custom-dashboard'),
            __('Export Date', 'learndash-custom-dashboard')
        ));
        
        // Add data row
        fputcsv($output, array(
            $group->post_title,
            $group_id,
            count($students),
            count($courses),
            count($quiz_stats),
            $group_average !== 'N/A' ? $group_average . '%' : 'N/A',
            $valid_quizzes > 0 ? $overall_average . '%' : 'N/A',
            current_time('Y-m-d H:i:s')
        ));
        
        // Add empty row for spacing
        fputcsv($output, array(''));
        
        // Add quiz statistics
        fputcsv($output, array(__('Quiz Statistics', 'learndash-custom-dashboard')));
        fputcsv($output, array(
            __('Quiz Name', 'learndash-custom-dashboard'),
            __('Average Score', 'learndash-custom-dashboard'),
            __('Number of Attempts', 'learndash-custom-dashboard')
        ));
        
        foreach ($quiz_stats as $quiz_id => $quiz) {
            fputcsv($output, array(
                $quiz['title'],
                $quiz['average'] !== 'N/A' ? $quiz['average'] . '%' : 'N/A',
                $quiz['attempts']
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * AJAX handler to export detailed grades
     */
    public function ajax_export_detailed_grades() {
        // Check nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ld_export_nonce')) {
            wp_die(__('Security check failed.', 'learndash-custom-dashboard'));
        }
        
        // Check permissions
        if (!current_user_can('administrator') && !$this->is_instructor()) {
            wp_die(__('You do not have permission to export this data.', 'learndash-custom-dashboard'));
        }
        
        // Get group ID
        $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
        if (!$group_id) {
            wp_die(__('No group ID provided.', 'learndash-custom-dashboard'));
        }
        
        // Verify user has access to this group
        $user_groups = $this->get_instructor_groups();
        if (!isset($user_groups[$group_id]) && !current_user_can('manage_options')) {
            wp_die(__('You do not have access to this group.', 'learndash-custom-dashboard'));
        }
        
        // Verify user has access to this group
        if (!learndash_is_group_leader_of_user(get_current_user_id()) && !current_user_can('manage_options')) {
            $instructor_groups = $this->get_instructor_groups(get_current_user_id());
            $has_access = false;
            
            foreach ($instructor_groups as $group) {
                if ($group->ID == $group_id) {
                    $has_access = true;
                    break;
                }
            }
            
            if (!$has_access) {
                wp_die(__('You do not have access to this group.', 'learndash-custom-dashboard'));
            }
        }
        
        // Get group data
        $group = get_post($group_id);
        if (!$group) {
            wp_die(__('Group not found.', 'learndash-custom-dashboard'));
        }
        
        // Get student progress
        $student_progress = $this->grade_calculator->get_group_student_progress($group_id);
        
        // Get quiz stats for headers
        $quiz_stats = $this->grade_calculator->get_group_quiz_stats($group_id);
        
        // Set headers for CSV download
        $filename = 'detailed-grades-' . $group_id . '-' . date('Y-m-d') . '.csv';
        
        // Force download headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");
        
        // Prepare headers
        $headers = array(
            __('Student ID', 'learndash-custom-dashboard'),
            __('Student Name', 'learndash-custom-dashboard'),
            __('Email', 'learndash-custom-dashboard'),
            __('Quizzes Completed', 'learndash-custom-dashboard'),
            __('Average Score', 'learndash-custom-dashboard'),
            __('Last Activity', 'learndash-custom-dashboard')
        );
        
        // Add quiz titles as headers
        foreach ($quiz_stats as $quiz_id => $quiz) {
            $headers[] = $quiz['title'] . ' (' . __('Score', 'learndash-custom-dashboard') . ')';
            $headers[] = $quiz['title'] . ' (' . __('Date', 'learndash-custom-dashboard') . ')';
            $headers[] = $quiz['title'] . ' (' . __('Points', 'learndash-custom-dashboard') . ')';
        }
        
        // Output headers
        fputcsv($output, $headers);
        
        // Output student data
        foreach ($student_progress as $student) {
            $row = array(
                $student['user_id'],
                $student['display_name'],
                $student['user_email'],
                $student['quizzes_completed'],
                $student['average_score'] . '%',
                $student['last_activity'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $student['last_activity']) : 'N/A'
            );
            
            // Add quiz scores and dates
            foreach ($quiz_stats as $quiz_id => $quiz) {
                $quiz_data = isset($student['quizzes'][$quiz_id]) ? $student['quizzes'][$quiz_id] : null;
                
                if ($quiz_data && $quiz_data['completed']) {
                    $row[] = $quiz_data['score'] . '%';
                    $row[] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $quiz_data['timestamp']);
                    $row[] = $quiz_data['points_earned'] . '/' . $quiz_data['points_total'];
                } else {
                    $row[] = 'N/A';
                    $row[] = 'N/A';
                    $row[] = 'N/A';
                }
            }
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
}

// Initialize the plugin
function init_custom_dashboard() {
    // Store plugin instance in global
    $GLOBALS['learndash_custom_dashboard'] = new LearnDash_Custom_Dashboard();
}

// Check for LearnDash dependency
add_action('plugins_loaded', function() {
    if (!class_exists('SFWD_LMS')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('LearnDash LMS plugin is required for the Custom Instructor Dashboard to work properly.', 'learndash-custom-dashboard') . 
                 '</p></div>';
        });
        return;
    }
    
    // Initialize plugin after LearnDash is confirmed loaded
    init_custom_dashboard();
});
