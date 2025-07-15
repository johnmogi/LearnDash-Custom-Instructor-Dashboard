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
    
    private $threshold = 10; // Minimum quizzes for 100% grade
    private $learndash_loaded = false;
    
    public function __construct() {
        // Check if LearnDash is active
        if (!defined('LEARNDASH_VERSION')) {
            error_log('Custom Dashboard - LearnDash is not active');
            return;
        }
        
        $this->learndash_loaded = true;
        error_log('Custom Dashboard - LearnDash is active');
        
        // Initialize hooks
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'init_textdomain'));
        
        // Register shortcode
        add_shortcode('custom_instructor_dashboard', array($this, 'render_dashboard'));
        
        // Enqueue styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    public function init() {
        if (!$this->learndash_loaded) {
            return;
        }
        
        // Register AJAX handlers
        add_action('wp_ajax_load_group_content', array($this, 'ajax_load_group_content'));
        add_action('wp_ajax_nopriv_load_group_content', array($this, 'ajax_load_group_content'));
    }
    
    public function init_textdomain() {
        load_plugin_textdomain('learndash-custom-dashboard', false, basename(dirname(__FILE__)) . '/languages/');
    }
    
    public function enqueue_assets() {
        // Enqueue styles
        wp_enqueue_style(
            'custom-dashboard',
            plugins_url('assets/css/dashboard.css', __FILE__)
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'custom-dashboard',
            plugins_url('assets/js/dashboard.js', __FILE__),
            array('jquery'),
            '1.0',
            true
        );
        
        // Localize script
        wp_localize_script('custom-dashboard', 'customDashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('custom_dashboard_nonce')
        ));
    }
    
    public function render_dashboard() {
        // Debug logging
        error_log('Custom Dashboard - Render Dashboard Called');
        
        if (!is_user_logged_in()) {
            error_log('Custom Dashboard - User not logged in');
            return '<p>' . __('Access denied. Please log in.', 'learndash-custom-dashboard') . '</p>';
        }
        
        // Get current user
        $user = wp_get_current_user();
        error_log('Custom Dashboard - Current User ID: ' . $user->ID);
        error_log('Custom Dashboard - Current User Login: ' . $user->user_login);
        
        // Check if user has LearnDash capabilities
        $has_learndash_caps = $user->has_cap('manage_learndash') || $user->has_cap('manage_learndash_groups');
        error_log('Custom Dashboard - Has LearnDash Caps: ' . ($has_learndash_caps ? 'Yes' : 'No'));
        
        if (!$this->is_instructor()) {
            error_log('Custom Dashboard - User does not have required role');
            return '<p>' . __('Access denied. Instructor access required.', 'learndash-custom-dashboard') . '</p>';
        }
        
        // Debug log instructor roles
        $roles = (array) $user->roles;
        error_log('Custom Dashboard - User Roles: ' . implode(', ', $roles));
        
        ob_start();
        ?>
        <div class="custom-instructor-dashboard">
            <h2><?php esc_html_e('My Classes', 'learndash-custom-dashboard'); ?></h2>
            
            <div class="group-selector">
                <label for="group-select"><?php esc_html_e('Select Class:', 'learndash-custom-dashboard'); ?></label>
                <select id="group-select">
                    <?php foreach ($this->get_instructor_groups() as $group_id => $group_name): ?>
                        <option value="<?php echo esc_attr($group_id); ?>">
                            <?php echo esc_html($group_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="group-content">
                <p><?php esc_html_e('Loading group data...', 'learndash-custom-dashboard'); ?></p>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    private function is_instructor() {
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        
        // Debug logging
        error_log('Custom Dashboard - User ID: ' . $user->ID);
        error_log('Custom Dashboard - User Roles: ' . implode(', ', $roles));
        
        // Allow access for:
        // 1. Administrators
        // 2. School Teachers (with mapped LearnDash capabilities)
        // 3. Group Leaders
        $is_admin = in_array('administrator', $roles);
        $is_school_teacher = in_array('school_teacher', $roles);
        $is_group_leader = in_array('group_leader', $roles);
        
        // Log access check results
        error_log('Custom Dashboard - Is Admin: ' . ($is_admin ? 'Yes' : 'No'));
        error_log('Custom Dashboard - Is School Teacher: ' . ($is_school_teacher ? 'Yes' : 'No'));
        error_log('Custom Dashboard - Is Group Leader: ' . ($is_group_leader ? 'Yes' : 'No'));
        
        // Return true if any of the allowed roles are present
        return $is_admin || $is_school_teacher || $is_group_leader;
    }
    
    private function get_instructor_groups() {
        $groups = get_posts(array(
            'post_type' => 'groups',
            'meta_query' => array(
                array(
                    'key' => 'group_leader',
                    'value' => get_current_user_id(),
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        ));
        
        $group_list = array();
        foreach ($groups as $group) {
            $group_list[$group->ID] = $group->post_title;
        }
        
        return $group_list;
    }
    
    public function ajax_load_group_content() {
        check_ajax_referer('custom_dashboard_nonce', 'nonce');
        
        if (!isset($_POST['group_id'])) {
            wp_send_json_error(__('Group ID not provided', 'learndash-custom-dashboard'));
        }
        
        $group_id = intval($_POST['group_id']);
        $group = get_post($group_id);
        
        if (!$group || $group->post_type !== 'groups') {
            wp_send_json_error(__('Invalid group', 'learndash-custom-dashboard'));
        }
        
        $courses = learndash_group_enrolled_courses($group_id);
        $students = $this->get_group_students($group_id);
        
        ob_start();
        ?>
        <div class="group-details">
            <h3><?php echo esc_html($group->post_title); ?></h3>
            
            <?php if (!empty($courses)): ?>
                <?php foreach ($courses as $course_id): 
                    $course = get_post($course_id);
                    if (!$course) continue;
                ?>
                    <div class="course-section">
                        <h4><?php echo esc_html($course->post_title); ?></h4>
                        
                        <table class="student-progress-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Student', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Quizzes Taken', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Average Score', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Custom Grade', 'learndash-custom-dashboard'); ?></th>
                                    <th><?php esc_html_e('Status', 'learndash-custom-dashboard'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student_id): 
                                    $student = get_user_by('ID', $student_id);
                                    if (!$student) continue;
                                    
                                    $quiz_data = $this->get_student_quiz_data($student_id, $course_id);
                                    $custom_grade = $this->calculate_custom_grade($quiz_data);
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($student->display_name); ?></td>
                                        <td><?php echo count($quiz_data['scores']); ?></td>
                                        <td><?php echo number_format($quiz_data['average_score'], 2); ?>%</td>
                                        <td><?php echo $custom_grade; ?>%</td>
                                        <td>
                                            <span class="status-badge <?php echo $custom_grade >= 70 ? 'status-complete' : 'status-incomplete'; ?>">
                                                <?php echo $custom_grade >= 70 ? __('Pass', 'learndash-custom-dashboard') : __('In Progress', 'learndash-custom-dashboard'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php esc_html_e('No courses assigned to this group.', 'learndash-custom-dashboard'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }
    
    private function get_group_students($group_id) {
        return learndash_get_groups_user_ids($group_id);
    }
    
    private function get_student_quiz_data($student_id, $course_id) {
        global $wpdb;
        
        $quizzes = array();
        $scores = array();
        $total_score = 0;
        
        // Get all quizzes in the course
        $course_quizzes = learndash_course_get_children_of_step($course_id, $course_id, 'sfwd-quiz', 'ids', true);
        
        if (!empty($course_quizzes)) {
            foreach ($course_quizzes as $quiz_id) {
                $attempts = $this->get_quiz_attempts($student_id, $quiz_id);
                
                if (!empty($attempts)) {
                    // Get the highest score for this quiz
                    $highest_score = 0;
                    foreach ($attempts as $attempt) {
                        if (isset($attempt['percentage'])) {
                            $score = floatval($attempt['percentage']);
                            if ($score > $highest_score) {
                                $highest_score = $score;
                            }
                        }
                    }
                    
                    if ($highest_score > 0) {
                        $scores[] = $highest_score;
                        $total_score += $highest_score;
                    }
                }
            }
        }
        
        $average = !empty($scores) ? ($total_score / count($scores)) : 0;
        
        return array(
            'scores' => $scores,
            'average_score' => $average,
            'quiz_count' => count($scores)
        );
    }
    
    private function calculate_custom_grade($quiz_data) {
        $quiz_count = $quiz_data['quiz_count'];
        $average_score = $quiz_data['average_score'];
        
        if ($quiz_count == 0) {
            return 0;
        }
        
        // If student has taken more quizzes than threshold, cap at threshold
        $effective_quizzes = min($quiz_count, $this->threshold);
        
        // Calculate percentage of threshold reached
        $threshold_percentage = ($effective_quizzes / $this->threshold) * 100;
        
        // Calculate grade (weighted by threshold percentage)
        $grade = ($average_score * $threshold_percentage) / 100;
        
        return min(100, round($grade, 2));
    }
    
    private function get_quiz_attempts($user_id, $quiz_id) {
        $attempts = array();
        
        $quiz_attempts = learndash_get_user_quiz_attempt($user_id, $quiz_id);
        
        if (!empty($quiz_attempts)) {
            foreach ($quiz_attempts as $attempt) {
                if (isset($attempt['percentage'])) {
                    $attempts[] = $attempt;
                }
            }
        }
        
        return $attempts;
    }
}

// Initialize the plugin
function init_custom_dashboard() {
    if (class_exists('LearnDash_Custom_Dashboard')) {
        new LearnDash_Custom_Dashboard();
    }
}
add_action('plugins_loaded', 'init_custom_dashboard');
