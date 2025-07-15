# LearnDash Custom Instructor Dashboard

A WordPress plugin that provides a custom dashboard view for LearnDash instructors, featuring a unique grading system based on quiz attempts.

## Features

- Group selection dropdown for instructors
- Student progress table with custom grading
- AJAX loading of group data
- Threshold-based grading system
- Responsive design
- Internationalization support

## Installation

1. Download the plugin files
2. Upload the `learndash-custom-dashboard` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

1. Add the shortcode `[custom_instructor_dashboard]` to any page where you want the dashboard to appear
2. Instructors will see a dropdown of their groups
3. Select a group to view student progress and grades

## Customization

### Grading Threshold
The plugin uses a threshold system for grading. By default, the threshold is set to 10 quizzes. This means:
- Students who complete 10 or more quizzes can achieve up to 100% grade
- The grade is calculated based on both the number of quizzes completed and their average score

To modify the threshold, edit the main plugin file:
```php
private $threshold = 10; // Change this value to adjust the threshold
```

### CSS Customization
The plugin includes a CSS file that can be modified to match your theme. The CSS file is located at:
`/assets/css/dashboard.css`

## Requirements

- WordPress 5.0 or higher
- LearnDash LMS
- PHP 7.4 or higher
- The `school_teacher` role must be mapped to LearnDash instructor capabilities (already implemented in the theme's `teacher-role-mapping.php`)

## Support

For support or questions, please contact the plugin developer.

## License

This plugin is licensed under the GPL v2 or later.
# LearnDash-Custom-Instructor-Dashboard
