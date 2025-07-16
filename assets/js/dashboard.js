jQuery(document).ready(function($) {
    'use strict';

    // Cache DOM elements
    const $groupSelect = $('#group-select');
    const $groupContent = $('#group-content');
    const $groupTitle = $('.group-title');
    const $studentsCount = $('.students-count .count');
    const $coursesCount = $('.courses-count .count');
    const $studentsList = $('.group-students');
    const $coursesList = $('.group-courses');
    const $gradebookStats = $('#gradebook-stats');
    const $groupAverage = $('#group-average');
    const $quizzesCount = $('#quizzes-count');
    const $quizStats = $('#quiz-stats');
    const $loading = $('<div class="ld-loading"><span class="spinner is-active"></span> Loading...</div>');

    // Initialize
    init();
    
    function init() {
        // Set up event handlers
        $groupSelect.on('change', handleGroupChange);
        
        // Initial load if group is preselected
        if ($groupSelect.val()) {
            loadGroupData($groupSelect.val());
        }
    }

    /**
     * Handle group selection change
     */
    function handleGroupChange() {
        const groupId = $groupSelect.val();

        if (!groupId) {
            resetGroupContent();
            $gradebookStats.hide();
            return;
        }

        $gradebookStats.show();
        loadGroupData(groupId);
        loadGradebookStats(groupId);
    }

    /**
     * Load group data via AJAX
     * @param {string} groupId - The ID of the group to load
     */
    function loadGroupData(groupId) {
        // Show loading state
        $groupContent.addClass('loading');
        $groupContent.html($loading);

        console.log('Loading group data for ID:', groupId);
        console.log('AJAX URL:', customDashboard.ajaxurl);
        console.log('Nonce:', customDashboard.nonce);
        
        // Clear any previous errors
        $groupContent.find('.error-message').remove();
        
        // Create the data object
        const data = {
            action: 'load_group_content',
            group_id: groupId,
            nonce: customDashboard.nonce
        };
        
        console.log('Sending AJAX request with data:', data);
        
        // Make the AJAX request
        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                console.log('AJAX Success Response:', response);
                if (response && response.success && response.data) {
                    updateGroupContent(response.data);
                } else {
                    const errorMsg = response && response.data && response.data.message ? 
                        response.data.message : 'Failed to load group data';
                    showError(errorMsg);
                }
                $groupContent.removeClass('loading');
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response Text:', xhr.responseText);
                console.error('Response Status:', xhr.status);
                console.error('Response Headers:', xhr.getAllResponseHeaders());
                
                let errorMessage = 'Error loading group data';
                
                try {
                    // Try to parse the response as JSON
                    if (xhr.responseText && xhr.responseText.trim() !== '') {
                        const jsonResponse = JSON.parse(xhr.responseText);
                        if (jsonResponse && jsonResponse.data && jsonResponse.data.message) {
                            errorMessage = jsonResponse.data.message;
                        }
                    } else if (xhr.status === 400) {
                        errorMessage = 'Bad request: The server could not understand the request. Please check your permissions and try again.';
                    } else if (xhr.status === 403) {
                        errorMessage = 'Access denied: You do not have permission to view this group.';
                    } else if (xhr.status === 404) {
                        errorMessage = 'Not found: The requested group could not be found.';
                    } else {
                        errorMessage = 'Error loading group data: ' + error;
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    errorMessage = 'Error loading group data. Please try again.';
                }
                
                showError(errorMessage);
                $groupContent.removeClass('loading');
            },
            complete: function() {
                $groupContent.removeClass('loading');
            }
        });
    }

    /**
     * Update the UI with group data
     * @param {Object} data - Group data from the server
     */
    function updateGroupContent(data) {
        if (!data) return;

        // Update group info
        $groupTitle.text(data.group_name || 'No group name');
        $studentsCount.text(data.students ? data.students.length : 0);
        $coursesCount.text(data.courses ? data.courses.length : 0);

        // Update students list
        if (data.students && data.students.length > 0) {
            let studentsHtml = '<ul class="students-list">';
            data.students.forEach(function(student) {
                studentsHtml += `<li>${student.name}</li>`;
            });
            studentsHtml += '</ul>';
            $studentsList.html(studentsHtml);
        } else {
            $studentsList.html('<p>No students in this group.</p>');
        }

        // Update courses list
        if (data.courses && data.courses.length > 0) {
            let coursesHtml = '<ul class="courses-list">';
            data.courses.forEach(function(course) {
                coursesHtml += `<li>${course.title}</li>`;
            });
            coursesHtml += '</ul>';
            $coursesList.html(coursesHtml);
        } else {
            $coursesList.html('<p>No courses in this group.</p>');
        }
    }

    /**
     * Reset the group content area
     */
    function resetGroupContent() {
        $groupTitle.text('');
        $studentsCount.text('0');
        $coursesCount.text('0');
        $studentsList.empty();
        $coursesList.empty();
    }

    /**
     * Display an error message
     * @param {string} message - The error message to display
     */
    function showError(message) {
        $groupContent.html(`
            <div class="error-message">
                <span class="dashicons dashicons-warning"></span>
                <p>${message || 'An error occurred'}</p>
            </div>
        `);
    }

    /**
     * Load gradebook statistics for a group
     * @param {string} groupId - The ID of the group to load statistics for
     */
    function loadGradebookStats(groupId) {
        if (!groupId) return;
        
        // Show loading state
        $gradebookStats.addClass('loading');
        
        console.log('Loading gradebook stats for group ID:', groupId);
        
        // Get group average
        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_group_average',
                group_id: groupId,
                nonce: customDashboard.nonce
            },
            dataType: 'json',
            success: function(response) {
                console.log('Group average response:', response);
                if (response && response.success) {
                    $groupAverage.html(response.data.formatted || 'N/A');
                } else {
                    console.error('Error getting group average:', response.data);
                    $groupAverage.html('Error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Group average AJAX Error:', status, error);
                console.error('Response Text:', xhr.responseText);
                $groupAverage.html('Error');
            }
        });
        
        // Get quiz statistics
        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_group_quiz_stats',
                group_id: groupId,
                nonce: customDashboard.nonce
            },
            dataType: 'json',
            success: function(response) {
                console.log('Quiz stats response:', response);
                if (response && response.success) {
                    updateQuizStats(response.data);
                } else {
                    console.error('Error getting quiz stats:', response.data);
                    $quizStats.html('<p>Error loading quiz statistics.</p>');
                }
                $gradebookStats.removeClass('loading');
            },
            error: function(xhr, status, error) {
                console.error('Quiz stats AJAX Error:', status, error);
                console.error('Response Text:', xhr.responseText);
                $quizStats.html('<p>Error loading quiz statistics.</p>');
                $gradebookStats.removeClass('loading');
            },
            complete: function() {
                $gradebookStats.removeClass('loading');
            }
        });
    }

    /**
     * Update the quiz statistics display
     * @param {Object} stats - Quiz statistics data from the server
     */
    function updateQuizStats(stats) {
        console.log('Quiz stats data:', stats);
        
        if (!stats || Object.keys(stats).length === 0) {
            $quizStats.html('<p>No quiz data available for this group.</p>');
            $quizzesCount.text('0');
            return;
        }
        
        // Create the HTML for quiz stats
        let html = '<div class="quiz-stats-container">';
        
        // Add a table for better data presentation
        html += '<table class="quiz-stats-table">';
        html += '<thead><tr><th>Quiz</th><th>Average Score</th><th>Pass Rate</th><th>Attempts</th></tr></thead>';
        html += '<tbody>';
        
        // Loop through quizzes
        let quizCount = 0;
        let totalAverage = 0;
        let validQuizzes = 0;
        
        // Our new data structure has quiz IDs as keys
        for (const quizId in stats) {
            if (isNaN(parseInt(quizId))) continue; // Skip non-numeric keys
            
            const quiz = stats[quizId];
            quizCount++;
            
            // Format average score
            const avgScore = quiz.average !== 'N/A' ? quiz.average + '%' : 'N/A';
            
            // Calculate passing rate
            let passRate = 'N/A';
            if (quiz.passing_count !== 'N/A' && quiz.total_students > 0) {
                passRate = Math.round((quiz.passing_count / quiz.total_students) * 100) + '%';
            }
            
            // Add to total average calculation
            if (quiz.average !== 'N/A') {
                totalAverage += parseFloat(quiz.average);
                validQuizzes++;
            }
            
            html += `<tr>
                <td>${quiz.title || 'Untitled Quiz'}</td>
                <td>${avgScore}</td>
                <td>${passRate}</td>
                <td>${quiz.total_attempts || 0}</td>
            </tr>`;
        }
        
        html += '</tbody></table>';
        
        // Show overall average
        const overallAverage = validQuizzes > 0 ? (totalAverage / validQuizzes).toFixed(2) + '%' : 'N/A';
        html += `<div class="overall-average">
            <strong>Overall Average:</strong> ${overallAverage}
        </div>`;
        
        // Update quizzes count
        $quizzesCount.text(quizCount);
        
        html += '</div>'; // Close container
        
        $quizStats.html(html);
    }
});
