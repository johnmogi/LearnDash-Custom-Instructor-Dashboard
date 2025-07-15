<?php
/**
 * LearnDash Grade Calculator
 * 
 * Handles all grade calculations for the LearnDash Custom Dashboard
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LearnDash_Grade_Calculator {
    private $wpdb;
    private $table_activity;
    private $table_activity_meta;
    private $table_users;
    private $table_posts;
    private $table_usermeta;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_activity = $wpdb->prefix . 'learndash_user_activity';
        $this->table_activity_meta = $wpdb->prefix . 'learndash_user_activity_meta';
        $this->table_users = $wpdb->users;
        $this->table_posts = $wpdb->posts;
        $this->table_usermeta = $wpdb->usermeta;
    }

    /**
     * Get average score for a specific quiz
     * 
     * @param int $quiz_id The quiz ID
     * @return float|string The average score or 'N/A' if no data
     */
    public function get_quiz_average($quiz_id) {
        $cache_key = 'ld_quiz_avg_' . $quiz_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }

        $query = $this->wpdb->prepare(
            "SELECT AVG(CAST(meta.activity_meta_value AS DECIMAL(5,2))) as avg_score
             FROM {$this->table_activity} a
             JOIN {$this->table_activity_meta} meta ON a.activity_id = meta.activity_id
             WHERE a.post_id = %d 
             AND a.activity_type = 'quiz'
             AND a.activity_status = 1
             AND meta.activity_meta_key = 'percentage'",
            $quiz_id
        );

        $result = $this->wpdb->get_var($query);
        $average = $result ? round((float) $result, 2) : 'N/A';
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $average, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $average;
    }

    /**
     * Get average score for a specific group
     * 
     * @param int $group_id The group ID
     * @return float|string The average score or 'N/A' if no data
     */
    public function get_group_average($group_id) {
        $cache_key = 'ld_group_avg_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // First get all quizzes in the group's courses
        $quizzes = $this->get_group_quizzes($group_id);
        
        if (empty($quizzes)) {
            return 'N/A';
        }

        $quiz_ids = implode(',', array_map('intval', $quizzes));
        
        $query = $this->wpdb->prepare(
            "SELECT AVG(CAST(meta.activity_meta_value AS DECIMAL(5,2))) as avg_score
             FROM {$this->table_activity} a
             JOIN {$this->table_activity_meta} meta ON a.activity_id = meta.activity_id
             WHERE a.post_id IN ($quiz_ids)
             AND a.activity_type = 'quiz'
             AND a.activity_status = 1
             AND meta.activity_meta_key = 'percentage'
             AND a.user_id IN (
                 SELECT user_id 
                 FROM {$this->table_usermeta} 
                 WHERE meta_key = %s
             )",
            'learndash_group_users_' . $group_id
        );

        $result = $this->wpdb->get_var($query);
        $average = $result ? round((float) $result, 2) : 'N/A';
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $average, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $average;
    }

    /**
     * Get all quizzes in a group's courses
     * 
     * @param int $group_id The group ID
     * @return array Array of quiz IDs
     */
    public function get_group_quizzes($group_id) {
        $cache_key = 'ld_group_quizzes_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_groups');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get courses in the group
        $courses = learndash_group_enrolled_courses($group_id);
        
        if (empty($courses)) {
            return array();
        }

        // Get all quizzes in these courses
        $quizzes = array();
        foreach ($courses as $course_id) {
            $course_quizzes = learndash_course_get_children_of_step($course_id, $course_id, 'sfwd-quiz', 'ids', true);
            if (!empty($course_quizzes)) {
                $quizzes = array_merge($quizzes, $course_quizzes);
            }
        }

        $quizzes = array_unique($quizzes);
        
        // Cache for 6 hours
        wp_cache_set($cache_key, $quizzes, 'learndash_groups', 6 * HOUR_IN_SECONDS);
        
        return $quizzes;
    }

    /**
     * Get quiz statistics for a group
     * 
     * @param int $group_id The group ID
     * @return array Quiz statistics
     */
    public function get_group_quiz_stats($group_id) {
        $cache_key = 'ld_group_quiz_stats_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $quizzes = $this->get_group_quizzes($group_id);
        $stats = array();
        
        foreach ($quizzes as $quiz_id) {
            $stats[$quiz_id] = array(
                'title' => get_the_title($quiz_id),
                'average' => $this->get_quiz_average($quiz_id),
                'attempts' => $this->get_quiz_attempt_count($quiz_id, $group_id)
            );
        }
        
        // Sort by quiz title
        uasort($stats, function($a, $b) {
            return strcmp($a['title'], $b['title']);
        });
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $stats, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $stats;
    }

    /**
     * Get number of attempts for a quiz in a group
     * 
     * @param int $quiz_id The quiz ID
     * @param int $group_id The group ID
     * @return int Number of attempts
     */
    private function get_quiz_attempt_count($quiz_id, $group_id) {
        $cache_key = 'ld_quiz_attempts_' . $quiz_id . '_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(DISTINCT a.activity_id)
             FROM {$this->table_activity} a
             WHERE a.post_id = %d
             AND a.activity_type = 'quiz'
             AND a.activity_status = 1
             AND a.user_id IN (
                 SELECT user_id 
                 FROM {$this->table_usermeta} 
                 WHERE meta_key = %s
             )",
            $quiz_id,
            'learndash_group_users_' . $group_id
        ));
        
        // Cache for 1 hour
        wp_cache_set($cache_key, (int) $count, 'learndash_grades', HOUR_IN_SECONDS);
        
        return (int) $count;
    }
    
    /**
     * Get student progress for a group
     * 
     * @param int $group_id The group ID
     * @return array Student progress data
     */
    public function get_group_student_progress($group_id) {
        $cache_key = 'ld_group_student_progress_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $students = learndash_get_groups_users($group_id);
        $quizzes = $this->get_group_quizzes($group_id);
        $progress = array();
        
        if (empty($students) || empty($quizzes)) {
            return array();
        }
        
        $quiz_ids_placeholder = implode(',', array_fill(0, count($quizzes), '%d'));
        $user_ids = array_keys($students);
        $user_ids_placeholder = implode(',', array_fill(0, count($user_ids), '%d'));
        
        // Get all quiz attempts for this group's users and quizzes
        $query = $this->wpdb->prepare(
            "SELECT 
                a.user_id,
                a.post_id as quiz_id,
                MAX(a.activity_completed) as last_attempt,
                MAX(CAST(pm1.meta_value AS DECIMAL(5,2))) as highest_score,
                MAX(CAST(pm2.meta_value AS UNSIGNED)) as points_earned,
                MAX(CAST(pm3.meta_value AS UNSIGNED)) as points_total
            FROM {$this->table_activity} a
            LEFT JOIN {$this->table_activity_meta} pm1 
                ON a.activity_id = pm1.activity_id AND pm1.activity_meta_key = 'percentage'
            LEFT JOIN {$this->table_activity_meta} pm2 
                ON a.activity_id = pm2.activity_id AND pm2.activity_meta_key = 'points_earned'
            LEFT JOIN {$this->table_activity_meta} pm3 
                ON a.activity_id = pm3.activity_id AND pm3.activity_meta_key = 'points_total'
            WHERE a.user_id IN ($user_ids_placeholder)
            AND a.post_id IN ($quiz_ids_placeholder)
            AND a.activity_type = 'quiz'
            AND a.activity_status = 1
            GROUP BY a.user_id, a.post_id",
            array_merge($user_ids, $quizzes)
        );
        
        $attempts = $this->wpdb->get_results($query);
        
        // Initialize progress array
        foreach ($students as $student_id => $student) {
            $progress[$student_id] = array(
                'user_id' => $student_id,
                'display_name' => $student->display_name,
                'user_email' => $student->user_email,
                'quizzes_completed' => 0,
                'average_score' => 0,
                'last_activity' => 0,
                'quizzes' => array()
            );
            
            foreach ($quizzes as $quiz_id) {
                $progress[$student_id]['quizzes'][$quiz_id] = array(
                    'completed' => false,
                    'score' => 0,
                    'timestamp' => 0
                );
            }
        }
        
        // Process attempts
        $total_scores = array();
        $quiz_counts = array();
        
        foreach ($attempts as $attempt) {
            if (!isset($progress[$attempt->user_id])) continue;
            
            $quiz_id = $attempt->quiz_id;
            $score = (float) $attempt->highest_score;
            
            // Update quiz completion status
            $progress[$attempt->user_id]['quizzes'][$quiz_id] = array(
                'completed' => true,
                'score' => $score,
                'timestamp' => (int) $attempt->last_attempt,
                'points_earned' => (int) $attempt->points_earned,
                'points_total' => (int) $attempt->points_total
            );
            
            // Update student stats
            if (!isset($total_scores[$attempt->user_id])) {
                $total_scores[$attempt->user_id] = 0;
                $quiz_counts[$attempt->user_id] = 0;
            }
            
            $total_scores[$attempt->user_id] += $score;
            $quiz_counts[$attempt->user_id]++;
            
            // Update last activity
            if ($attempt->last_attempt > $progress[$attempt->user_id]['last_activity']) {
                $progress[$attempt->user_id]['last_activity'] = (int) $attempt->last_attempt;
            }
        }
        
        // Calculate averages
        foreach ($progress as $user_id => &$data) {
            $data['quizzes_completed'] = $quiz_counts[$user_id] ?? 0;
            $data['average_score'] = ($quiz_counts[$user_id] > 0) 
                ? round($total_scores[$user_id] / $quiz_counts[$user_id], 2)
                : 0;
                
            // Sort quizzes by completion status and timestamp
            uasort($data['quizzes'], function($a, $b) {
                if ($a['completed'] === $b['completed']) {
                    return $b['timestamp'] - $a['timestamp'];
                }
                return $b['completed'] ? 1 : -1;
            });
        }
        
        // Sort students by name
        uasort($progress, function($a, $b) {
            return strcasecmp($a['display_name'], $b['display_name']);
        });
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $progress, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $progress;
    }
}
?>
