# LearnDash Custom Instructor Dashboard

A WordPress plugin that provides a custom dashboard view for LearnDash instructors, featuring a comprehensive grading system and export functionality.

## Features

- Interactive group selection dropdown
- Real-time grade statistics and analytics
- Detailed quiz performance tracking
- Export grade data to CSV (both summary and detailed reports)
- Responsive design with modern UI
- Internationalization support
- Secure access control for instructors
- Performance-optimized database queries
- Caching for improved speed

## Installation

1. Download the latest plugin files
2. Upload the `learndash-custom-dashboard` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Ensure LearnDash LMS is installed and activated

## Usage

1. Add the shortcode `[learndash_instructor_dashboard]` to any page where you want the dashboard to appear
2. Instructors will see a dropdown of their assigned groups
3. Select a group to view detailed student progress and grade statistics
4. Use the export buttons to download grade reports:
   - **Export Summary**: A high-level overview of group performance
   - **Export Detailed Grades**: Comprehensive report of all student quiz attempts

## Customization

### Grade Calculation
Grades are calculated based on quiz attempts and scores. The system provides:
- Individual quiz statistics
- Group-wide averages
- Detailed student progress tracking
- Points-based scoring system

### Export Options
Two types of exports are available:
1. **Teacher Summary**: Overview of group performance including:
   - Group statistics
   - Quiz averages
   - Completion rates

2. **Detailed Grades**: Comprehensive report including:
   - Student-level quiz attempts
   - Scores and percentages
   - Completion timestamps
   - Points earned vs. total points

### Styling
Customize the appearance by modifying the CSS file:
`/assets/css/dashboard.css`

### Hooks and Filters
The plugin provides several WordPress hooks for customization:
- `learndash_custom_dashboard_before_content`: Action before dashboard content
- `learndash_custom_dashboard_after_content`: Action after dashboard content
- `learndash_custom_dashboard_export_data`: Filter export data before processing

## Requirements

- WordPress 5.6 or higher
- LearnDash LMS 3.4.0 or higher
- PHP 7.4 or higher (PHP 8.0+ recommended)
- MySQL 5.6 or higher
- The `school_teacher` role must be mapped to LearnDash instructor capabilities

## Security

- All AJAX requests are protected with nonces
- User capabilities are verified on all actions
- Sensitive data is sanitized and escaped
- Export files are generated securely with proper access control

## Performance

The plugin includes several performance optimizations:
- Database query optimization
- Transient caching for frequently accessed data
- Lazy loading of resources
- Efficient data processing for exports

## Support

For support, please open an issue in the [GitHub repository](https://github.com/your-repo/learndash-custom-dashboard).

## Changelog

### 1.2.0
- Added comprehensive grade export functionality
- Improved UI with real-time statistics
- Enhanced performance with caching
- Added support for points-based grading
- Improved RTL and accessibility support

### 1.1.0
- Initial release with basic dashboard functionality
- Group and student management
- Basic grade tracking

For support or questions, please contact the plugin developer.

## License

This plugin is licensed under the GPL v2 or later.
# LearnDash-Custom-Instructor-Dashboard
