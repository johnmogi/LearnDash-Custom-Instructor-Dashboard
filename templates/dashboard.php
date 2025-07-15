<?php
/**
 * LearnDash Custom Dashboard - Main Template
 *
 * @package LearnDash_Custom_Dashboard
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

// Get the current user
$user = wp_get_current_user();

// Get instructor groups
$groups = $this->get_instructor_groups($user->ID);

// Get the first group ID for initial load
$initial_group_id = !empty($groups) ? $groups[0]->ID : 0;
?>

<div class="learndash-custom-dashboard">
    <div class="dashboard-header">
        <h1><?php esc_html_e('Instructor Dashboard', 'learndash-custom-dashboard'); ?></h1>
        <p><?php esc_html_e('Manage your groups and view student progress', 'learndash-custom-dashboard'); ?></p>
    </div>

    <div class="group-selector">
        <label for="group-select"><?php esc_html_e('Select a Group:', 'learndash-custom-dashboard'); ?></label>
        <select id="group-select" class="group-select">
            <option value=""><?php esc_html_e('-- Select a Group --', 'learndash-custom-dashboard'); ?></option>
            <?php foreach ($groups as $group) : ?>
                <option value="<?php echo esc_attr($group->ID); ?>" <?php selected($initial_group_id, $group->ID); ?>>
                    <?php echo esc_html($group->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="group-content" class="group-content">
        <?php if (empty($groups)) : ?>
            <div class="no-groups">
                <p><?php esc_html_e('You are not assigned to any groups.', 'learndash-custom-dashboard'); ?></p>
            </div>
        <?php else : ?>
            <div class="group-info">
                <h2 class="group-title"><?php echo esc_html($groups[0]->post_title); ?></h2>
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

            <div class="group-details">
                <div class="group-students">
                    <h3><?php esc_html_e('Students', 'learndash-custom-dashboard'); ?></h3>
                    <p class="loading"><?php esc_html_e('Loading students...', 'learndash-custom-dashboard'); ?></p>
                </div>

                <div class="group-courses">
                    <h3><?php esc_html_e('Courses', 'learndash-custom-dashboard'); ?></h3>
                    <p class="loading"><?php esc_html_e('Loading courses...', 'learndash-custom-dashboard'); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
