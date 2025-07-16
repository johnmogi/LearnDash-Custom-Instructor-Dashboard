<?php
/**
 * LearnDash Grade Calculator
 * 
 * Handles all grade calculations for the LearnDash Custom Dashboard
 *
 * --- DOC FIX: SUCCESSFUL SQL QUERIES ---
 *
 * 1. Get instructor groups:
 * SELECT g.ID, g.post_title
 * FROM {prefix}_posts g
 * JOIN {prefix}_postmeta pm ON g.ID = pm.post_id
 * WHERE g.post_type = 'groups'
 * AND pm.meta_key = 'learndash_group_leaders_{user_id}'
 * ORDER BY g.post_title ASC
 *
 * 2. Get group average score:
 * SELECT AVG(CAST(meta.activity_meta_value AS DECIMAL(5,2))) as avg_score
 * FROM {prefix}_learndash_user_activity a
 * JOIN {prefix}_learndash_user_activity_meta meta ON a.activity_id = meta.activity_id
 * WHERE a.post_id IN (quiz_ids)
 * AND a.activity_type = 'quiz'
 * AND a.activity_status = 1
 * AND meta.activity_meta_key = 'percentage'
 * AND a.user_id IN (
 *   SELECT user_id 
 *   FROM {prefix}_usermeta 
 *   WHERE meta_key = 'learndash_group_users_{group_id}'
 * )
 *
 * 3. Get student quiz statistics:
 * SELECT 
 *   u.ID as user_id,
 *   u.display_name,
 *   a.post_id as quiz_id,
 *   p.post_title as quiz_name,
 *   MAX(CAST(pm1.activity_meta_value AS DECIMAL(5,2))) as score,
 *   COUNT(DISTINCT a.activity_id) as attempts,
 *   MAX(a.activity_completed) as last_attempt
 * FROM {prefix}_users u
 * JOIN {prefix}_usermeta um ON u.ID = um.user_id
 * JOIN {prefix}_learndash_user_activity a ON u.ID = a.user_id
 * JOIN {prefix}_posts p ON a.post_id = p.ID
 * JOIN {prefix}_learndash_user_activity_meta pm1 
 *   ON a.activity_id = pm1.activity_id AND pm1.activity_meta_key = 'percentage'
 * WHERE um.meta_key = 'learndash_group_users_{group_id}'
 * AND a.activity_type = 'quiz'
 * AND a.activity_status = 1
 * GROUP BY u.ID, u.display_name, a.post_id, p.post_title
 * ORDER BY u.display_name, p.post_title ASC
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
        $this->table_postmeta = $wpdb->postmeta;
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
    
    /**
     * Get average score for a group
     * 
     * @param int $group_id The group ID
     * @return string The average score or 'N/A' if no data
     */
    public function get_group_average($group_id) {
        $cache_key = 'ld_group_avg_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get group users
        $users = learndash_get_groups_users($group_id);
        if (empty($users)) {
            return 'N/A';
        }
        
        // Get group courses
        $courses = learndash_group_enrolled_courses($group_id);
        if (empty($courses)) {
            return 'N/A';
        }
        
        // Get all quizzes from these courses
        $quizzes = array();
        foreach ($courses as $course_id) {
            $course_quizzes = learndash_get_course_quiz_list($course_id);
            if (!empty($course_quizzes)) {
                foreach ($course_quizzes as $quiz) {
                    $quizzes[] = $quiz['post']->ID;
                }
            }
        }
        
        if (empty($quizzes)) {
            return 'N/A';
        }
        
        // Get user IDs
        $user_ids = wp_list_pluck($users, 'ID');
        
        // Get quiz scores
        $total_score = 0;
        $total_quizzes = 0;
        
        foreach ($quizzes as $quiz_id) {
            foreach ($user_ids as $user_id) {
                $quiz_data = get_user_meta($user_id, '_sfwd-quizzes', true);
                
                if (!empty($quiz_data)) {
                    foreach ($quiz_data as $quiz_attempt) {
                        if ($quiz_attempt['quiz'] == $quiz_id) {
                            if (isset($quiz_attempt['percentage']) && $quiz_attempt['percentage'] > 0) {
                                $total_score += $quiz_attempt['percentage'];
                                $total_quizzes++;
                            }
                        }
                    }
                }
            }
        }
        
        // Calculate average
        $average = ($total_quizzes > 0) ? round($total_score / $total_quizzes) : 'N/A';
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $average, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $average;
    }
    
    /**
     * Get quiz statistics for a group
     * 
     * @param int $group_id The group ID
     * @return array Quiz statistics data
     */
    public function get_group_quiz_stats($group_id) {
        $cache_key = 'ld_group_quiz_stats_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get group users
        $users = learndash_get_groups_users($group_id);
        if (empty($users)) {
            return array(
                'total_quizzes' => 0,
                'total_attempts' => 0,
                'average_score' => 'N/A',
                'quizzes' => array()
            );
        }
        
        // Get group courses
        $courses = learndash_group_enrolled_courses($group_id);
        if (empty($courses)) {
            return array(
                'total_quizzes' => 0,
                'total_attempts' => 0,
                'average_score' => 'N/A',
                'quizzes' => array()
            );
        }
        
        // Get all quizzes from these courses
        $quizzes = array();
        foreach ($courses as $course_id) {
            $course_quizzes = learndash_get_course_quiz_list($course_id);
            if (!empty($course_quizzes)) {
                foreach ($course_quizzes as $quiz) {
                    $quizzes[$quiz['post']->ID] = $quiz['post']->post_title;
                }
            }
        }
        
        if (empty($quizzes)) {
            return array(
                'total_quizzes' => 0,
                'total_attempts' => 0,
                'average_score' => 'N/A',
                'quizzes' => array()
            );
        }
        
        // Get user IDs
        $user_ids = wp_list_pluck($users, 'ID');
        
        // Initialize stats
        $stats = array(
            'total_quizzes' => count($quizzes),
            'total_attempts' => 0,
            'average_score' => 0,
            'quizzes' => array()
        );
        
        // Initialize quiz stats
        foreach ($quizzes as $quiz_id => $quiz_title) {
            $stats['quizzes'][$quiz_id] = array(
                'id' => $quiz_id,
                'title' => $quiz_title,
                'attempts' => 0,
                'completed' => 0,
                'average_score' => 0,
                'total_score' => 0
            );
        }
        
        // Get quiz scores
        $total_score = 0;
        $total_attempts = 0;
        
        foreach ($user_ids as $user_id) {
            $quiz_data = get_user_meta($user_id, '_sfwd-quizzes', true);
            
            if (!empty($quiz_data)) {
                foreach ($quiz_data as $quiz_attempt) {
                    $quiz_id = $quiz_attempt['quiz'];
                    
                    if (isset($quizzes[$quiz_id]) && isset($quiz_attempt['percentage'])) {
                        $stats['quizzes'][$quiz_id]['attempts']++;
                        $stats['quizzes'][$quiz_id]['total_score'] += $quiz_attempt['percentage'];
                        $stats['quizzes'][$quiz_id]['completed']++;
                        
                        $total_score += $quiz_attempt['percentage'];
                        $total_attempts++;
                    }
                }
            }
        }
        
        // Calculate averages
        $stats['total_attempts'] = $total_attempts;
        $stats['average_score'] = ($total_attempts > 0) ? round($total_score / $total_attempts) : 'N/A';
        
        foreach ($stats['quizzes'] as $quiz_id => &$quiz_stats) {
            $quiz_stats['average_score'] = ($quiz_stats['attempts'] > 0) 
                ? round($quiz_stats['total_score'] / $quiz_stats['attempts']) 
                : 'N/A';
        }
        
        // Sort quizzes by attempts (descending)
        uasort($stats['quizzes'], function($a, $b) {
            return $b['attempts'] - $a['attempts'];
        });
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $stats, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get all groups that a teacher/instructor leads
     * 
     * @param int $teacher_id The user ID of the teacher
     * @return array Array of group objects with ID and title
     */
    public function get_teacher_groups($teacher_id) {
        $cache_key = 'ld_teacher_groups_' . $teacher_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $query = $this->wpdb->prepare(
            "SELECT g.ID, g.post_title, g.post_name
             FROM {$this->table_posts} g
             JOIN {$this->table_postmeta} pm ON g.ID = pm.post_id
             WHERE g.post_type = 'groups'
             AND pm.meta_key = %s
             ORDER BY g.post_title ASC",
            'learndash_group_leaders_' . $teacher_id
        );
        
        $groups = $this->wpdb->get_results($query);
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $groups, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $groups;
    }
    
    /**
     * Get summary data for all groups a teacher leads
     * 
     * @param int $teacher_id The user ID of the teacher
     * @return array Groups with summary statistics
     */
    public function get_teacher_groups_summary($teacher_id) {
        $cache_key = 'ld_teacher_groups_summary_' . $teacher_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $groups = $this->get_teacher_groups($teacher_id);
        if (empty($groups)) {
            return array();
        }
        
        $summary = array();
        
        foreach ($groups as $group) {
            // Get number of students in this group
            $student_count = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$this->table_usermeta} 
                WHERE meta_key = %s",
                'learndash_group_users_' . $group->ID
            ));
            
            // Get group average
            $average_score = $this->get_group_average($group->ID);
            
            // Get group courses
            $courses = learndash_group_enrolled_courses($group->ID);
            $course_count = is_array($courses) ? count($courses) : 0;
            
            // Get quizzes
            $quizzes = $this->get_group_quizzes($group->ID);
            $quiz_count = is_array($quizzes) ? count($quizzes) : 0;
            
            // Get latest activity
            $latest_activity = $this->get_group_latest_activity($group->ID);
            
            $summary[] = array(
                'id' => $group->ID,
                'title' => $group->post_title,
                'slug' => $group->post_name,
                'student_count' => (int)$student_count,
                'average_score' => $average_score,
                'course_count' => $course_count,
                'quiz_count' => $quiz_count,
                'latest_activity' => $latest_activity
            );
        }
        
        // Sort by group name
        usort($summary, function($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $summary, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $summary;
    }
    
    /**
     * Get latest activity timestamp for a group
     * 
     * @param int $group_id The group ID
     * @return int|string Unix timestamp or 'N/A'
     */
    public function get_group_latest_activity($group_id) {
        $cache_key = 'ld_group_latest_activity_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $query = $this->wpdb->prepare(
            "SELECT MAX(a.activity_completed) 
             FROM {$this->table_activity} a
             JOIN {$this->table_usermeta} um ON a.user_id = um.user_id
             WHERE um.meta_key = %s
             AND a.activity_type IN ('quiz', 'lesson', 'topic', 'course')
             AND a.activity_status = 1",
            'learndash_group_users_' . $group_id
        );
        
        $timestamp = $this->wpdb->get_var($query);
        $result = $timestamp ? (int)$timestamp : 'N/A';
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $result, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Get detailed student data for a specific group with quiz scores
     * 
     * @param int $group_id The group ID
     * @return array Student data with quiz scores
     */
    public function get_group_student_details($group_id) {
        $cache_key = 'ld_group_student_details_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get all students in the group
        $query = $this->wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email
             FROM {$this->table_users} u
             JOIN {$this->table_usermeta} um ON u.ID = um.user_id
             WHERE um.meta_key = %s
             ORDER BY u.display_name ASC",
            'learndash_group_users_' . $group_id
        );
        
        $students = $this->wpdb->get_results($query);
        
        if (empty($students)) {
            return array();
        }
        
        $student_data = array();
        
        // Get quizzes for this group
        $quizzes = $this->get_group_quizzes($group_id);
        
        if (empty($quizzes)) {
            // If no quizzes, just return basic student info
            foreach ($students as $student) {
                $student_data[] = array(
                    'id' => $student->ID,
                    'name' => $student->display_name,
                    'email' => $student->user_email,
                    'average_score' => 'N/A',
                    'quizzes_taken' => 0,
                    'quizzes_passed' => 0,
                    'last_activity' => 'N/A',
                    'quiz_details' => array()
                );
            }
            
            return $student_data;
        }
        
        $quiz_ids = implode(',', array_map('intval', $quizzes));
        $student_ids = wp_list_pluck($students, 'ID');
        $student_ids_str = implode(',', array_map('intval', $student_ids));
        
        // Get all quiz attempts for these students
        $query = 
            "SELECT 
                a.user_id,
                a.post_id as quiz_id,
                p.post_title as quiz_name,
                COUNT(DISTINCT a.activity_id) as attempts,
                MAX(a.activity_completed) as last_attempt,
                MAX(CAST(pm.activity_meta_value AS DECIMAL(5,2))) as best_score
             FROM {$this->table_activity} a
             JOIN {$this->table_posts} p ON a.post_id = p.ID
             LEFT JOIN {$this->table_activity_meta} pm 
                ON a.activity_id = pm.activity_id AND pm.activity_meta_key = 'percentage'
             WHERE a.user_id IN ($student_ids_str)
             AND a.post_id IN ($quiz_ids)
             AND a.activity_type = 'quiz'
             AND a.activity_status = 1
             GROUP BY a.user_id, a.post_id, p.post_title
             ORDER BY a.user_id, p.post_title ASC";
        
        $attempts = $this->wpdb->get_results($query);
        
        // Organize by student
        $student_quiz_data = array();
        foreach ($attempts as $attempt) {
            if (!isset($student_quiz_data[$attempt->user_id])) {
                $student_quiz_data[$attempt->user_id] = array(
                    'quiz_count' => 0,
                    'total_score' => 0,
                    'quizzes_passed' => 0,
                    'last_activity' => 0,
                    'quizzes' => array()
                );
            }
            
            $student_quiz_data[$attempt->user_id]['quiz_count']++;
            $student_quiz_data[$attempt->user_id]['total_score'] += $attempt->best_score;
            
            // Check if quiz was passed (assuming 80% is passing)
            if ($attempt->best_score >= 80) {
                $student_quiz_data[$attempt->user_id]['quizzes_passed']++;
            }
            
            // Update last activity if newer
            if ($attempt->last_attempt > $student_quiz_data[$attempt->user_id]['last_activity']) {
                $student_quiz_data[$attempt->user_id]['last_activity'] = $attempt->last_attempt;
            }
            
            // Store quiz details
            $student_quiz_data[$attempt->user_id]['quizzes'][$attempt->quiz_id] = array(
                'id' => $attempt->quiz_id,
                'title' => $attempt->quiz_name,
                'attempts' => $attempt->attempts,
                'best_score' => $attempt->best_score,
                'last_attempt' => $attempt->last_attempt,
                'passed' => ($attempt->best_score >= 80)
            );
        }
        
        // Build final student data array
        foreach ($students as $student) {
            $quiz_data = isset($student_quiz_data[$student->ID]) ? $student_quiz_data[$student->ID] : array(
                'quiz_count' => 0,
                'total_score' => 0,
                'quizzes_passed' => 0,
                'last_activity' => 'N/A',
                'quizzes' => array()
            );
            
            $average_score = ($quiz_data['quiz_count'] > 0) 
                ? round($quiz_data['total_score'] / $quiz_data['quiz_count'], 2) 
                : 'N/A';
            
            $student_data[] = array(
                'id' => $student->ID,
                'name' => $student->display_name,
                'email' => $student->user_email,
                'average_score' => $average_score,
                'quizzes_taken' => $quiz_data['quiz_count'],
                'quizzes_passed' => $quiz_data['quizzes_passed'],
                'last_activity' => $quiz_data['last_activity'],
                'quiz_details' => $quiz_data['quizzes']
            );
        }
        
        // Sort by name
        usort($student_data, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $student_data, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $student_data;
    }
    
    /**
     * Get quizzes with statistics for a group, optimized for teacher dashboard
     * 
     * @param int $group_id The group ID
     * @return array Quiz statistics with detailed metrics
     */
    public function get_group_quiz_detailed_stats($group_id) {
        $cache_key = 'ld_group_quiz_detailed_stats_' . $group_id;
        $cached = wp_cache_get($cache_key, 'learndash_grades');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get quizzes for this group
        $quizzes = $this->get_group_quizzes($group_id);
        if (empty($quizzes)) {
            return array();
        }
        
        // Get all students in the group
        $student_count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$this->table_usermeta} 
            WHERE meta_key = %s",
            'learndash_group_users_' . $group_id
        ));
        
        $quiz_ids = implode(',', array_map('intval', $quizzes));
        
        // Get aggregated quiz statistics from user activity
        $query = $this->wpdb->prepare(
            "SELECT 
                a.post_id as quiz_id,
                p.post_title as quiz_name,
                COUNT(DISTINCT a.user_id) as unique_students,
                COUNT(DISTINCT a.activity_id) as total_attempts,
                AVG(CAST(pm.activity_meta_value AS DECIMAL(5,2))) as average_score,
                MIN(CAST(pm.activity_meta_value AS DECIMAL(5,2))) as min_score,
                MAX(CAST(pm.activity_meta_value AS DECIMAL(5,2))) as max_score,
                SUM(CASE WHEN CAST(pm.activity_meta_value AS DECIMAL(5,2)) >= 80 THEN 1 ELSE 0 END) as passed_count,
                MAX(a.activity_completed) as last_attempt_date
             FROM {$this->table_activity} a
             JOIN {$this->table_posts} p ON a.post_id = p.ID
             LEFT JOIN {$this->table_activity_meta} pm 
                ON a.activity_id = pm.activity_id AND pm.activity_meta_key = 'percentage'
             JOIN {$this->table_usermeta} um ON a.user_id = um.user_id AND um.meta_key = %s
             WHERE a.post_id IN ($quiz_ids)
             AND a.activity_type = 'quiz'
             AND a.activity_status = 1
             GROUP BY a.post_id, p.post_title
             ORDER BY p.post_title ASC",
            'learndash_group_users_' . $group_id
        );
        
        $quiz_stats = $this->wpdb->get_results($query);
        
        // Format the results
        $detailed_stats = array();
        foreach ($quiz_stats as $stat) {
            $detailed_stats[] = array(
                'id' => $stat->quiz_id,
                'title' => $stat->quiz_name,
                'unique_students' => (int)$stat->unique_students,
                'total_students' => (int)$student_count,
                'completion_rate' => $student_count > 0 ? round(($stat->unique_students / $student_count) * 100, 2) : 0,
                'total_attempts' => (int)$stat->total_attempts,
                'average_score' => round($stat->average_score, 2),
                'min_score' => round($stat->min_score, 2),
                'max_score' => round($stat->max_score, 2),
                'passed_count' => (int)$stat->passed_count,
                'passing_rate' => $stat->unique_students > 0 ? round(($stat->passed_count / $stat->unique_students) * 100, 2) : 0,
                'last_attempt' => $stat->last_attempt_date
            );
        }
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $detailed_stats, 'learndash_grades', HOUR_IN_SECONDS);
        
        return $detailed_stats;
    }
}
?>
