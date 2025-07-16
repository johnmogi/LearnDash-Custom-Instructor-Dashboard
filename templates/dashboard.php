<?php
/**
 * LearnDash Custom Dashboard - Main Template
 *
 * @package LearnDash_Custom_Dashboard
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

// Initialize our grade calculator if needed
if (!isset($this->grade_calculator)) {
    $this->init_grade_calculator();
}

// Get the current user ID
$teacher_id = get_current_user_id();

// Use our new teacher dashboard methods to get all teacher groups with summary data
$teacher_groups = $this->grade_calculator->get_teacher_groups_summary($teacher_id);
?>

<div class="learndash-custom-dashboard">
    <div class="dashboard-header">
        <h1><?php esc_html_e('Teacher Dashboard', 'learndash-custom-dashboard'); ?></h1>
        <p><?php esc_html_e('View your classes and student progress', 'learndash-custom-dashboard'); ?></p>
    </div>
    
    <?php if (empty($teacher_groups)) : ?>
        <div class="no-groups-message">
            <p><?php esc_html_e('You are not currently assigned to any classes.', 'learndash-custom-dashboard'); ?></p>
        </div>
    <?php else : ?>
        <div class="teacher-groups">
            <h2><?php esc_html_e('Your Classes', 'learndash-custom-dashboard'); ?></h2>
            
            <div class="groups-list">
                <?php foreach ($teacher_groups as $group) : ?>
                    <div class="group-card" id="group-<?php echo esc_attr($group['id']); ?>">
                        <div class="group-header">
                            <h3><?php echo esc_html($group['title']); ?></h3>
                            <div class="group-meta">
                                <span class="students-count">
                                    <strong><?php echo intval($group['student_count']); ?></strong> 
                                    <?php echo _n('Student', 'Students', intval($group['student_count']), 'learndash-custom-dashboard'); ?>
                                </span>
                                <span class="average-score">
                                    <strong><?php echo $group['average_score'] !== 'N/A' ? esc_html($group['average_score'] . '%') : esc_html__('No Data', 'learndash-custom-dashboard'); ?></strong>
                                    <?php esc_html_e('Average Score', 'learndash-custom-dashboard'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php 
                        // Get detailed student data for this group
                        $students = $this->grade_calculator->get_group_student_details($group['id']); 
                        ?>
                        
                        <div class="group-details">
                            <h4><?php esc_html_e('Students', 'learndash-custom-dashboard'); ?></h4>
                            
                            <?php if (empty($students)) : ?>
                                <p class="no-data"><?php esc_html_e('No students found in this class.', 'learndash-custom-dashboard'); ?></p>
                            <?php else : ?>
                                <table class="students-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Student', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Average Score', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Quizzes Taken', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Quizzes Passed', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Last Activity', 'learndash-custom-dashboard'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student_id => $student_data) : 
                                            $user_info = get_userdata($student_id);
                                            if (!$user_info) continue;
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html($user_info->display_name); ?></td>
                                                <td><?php echo $student_data['average_score'] !== 'N/A' ? esc_html($student_data['average_score'] . '%') : esc_html__('N/A', 'learndash-custom-dashboard'); ?></td>
                                                <td><?php echo esc_html($student_data['quizzes_taken']); ?></td>
                                                <td><?php echo esc_html($student_data['quizzes_passed']); ?></td>
                                                <td><?php echo !empty($student_data['last_activity']) ? esc_html(date_i18n(get_option('date_format'), strtotime($student_data['last_activity']))) : esc_html__('Never', 'learndash-custom-dashboard'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <?php 
                        // Get detailed quiz statistics for this group
                        $quiz_stats = $this->grade_calculator->get_group_quiz_detailed_stats($group['id']); 
                        ?>
                        
                        <div class="quiz-statistics">
                            <h4><?php esc_html_e('Quiz Statistics', 'learndash-custom-dashboard'); ?></h4>
                            
                            <?php if (empty($quiz_stats)) : ?>
                                <p class="no-data"><?php esc_html_e('No quiz data available for this class.', 'learndash-custom-dashboard'); ?></p>
                            <?php else : ?>
                                <table class="quiz-stats-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Quiz', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Average Score', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Pass Rate', 'learndash-custom-dashboard'); ?></th>
                                            <th><?php esc_html_e('Attempts', 'learndash-custom-dashboard'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($quiz_stats as $quiz_id => $quiz) : 
                                            if (!is_numeric($quiz_id)) continue; // Skip non-numeric keys
                                            
                                            // Calculate passing rate
                                            $pass_rate = 'N/A';
                                            if ($quiz['passing_count'] !== 'N/A' && $quiz['total_students'] > 0) {
                                                $pass_rate = round(($quiz['passing_count'] / $quiz['total_students']) * 100) . '%';
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html($quiz['title']); ?></td>
                                                <td><?php echo $quiz['average'] !== 'N/A' ? esc_html($quiz['average'] . '%') : esc_html__('N/A', 'learndash-custom-dashboard'); ?></td>
                                                <td><?php echo esc_html($pass_rate); ?></td>
                                                <td><?php echo esc_html($quiz['total_attempts']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
