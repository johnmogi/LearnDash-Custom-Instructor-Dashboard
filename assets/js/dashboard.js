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
    const $exportSummary = $('#export-summary');
    const $exportDetailed = $('#export-detailed');
    const $exportStatus = $('#export-status');
    const $loading = $('<div class="ld-loading"><span class="spinner is-active"></span> Loading...</div>');

    // Handle group selection change
    $groupSelect.on('change', function() {
        const groupId = $(this).val();
        
        if (!groupId) {
            resetGroupContent();
            $gradebookStats.hide();
            return;
        }

        $gradebookStats.show();
        loadGroupData(groupId);
        loadGradebookStats(groupId);
    });

    /**
     * Load group data via AJAX
     * @param {number} groupId - The ID of the group to load
     */
    function loadGroupData(groupId) {
        // Show loading state
        $groupContent.addClass('loading');
        $groupContent.html($loading);

        console.log('Loading group data for ID:', groupId);
        
        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_group_content',
                group_id: groupId,
                nonce: customDashboard.nonce
            },
            dataType: 'json',
            success: function(response) {
                console.log('AJAX Response:', response);
                if (response.success && response.data) {
                    updateGroupContent(response.data);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to load group data';
                    showError(errorMsg);
                }
                $groupContent.removeClass('loading');
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showError('Error loading group data. Please try again.');
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
            const studentsHtml = `
                <h3>Students</h3>
                <ul class="student-list">
                    ${data.students.map(student => `
                        <li class="student-item">
                            <span class="student-name">${student.name || 'Unknown'}</span>
                            <a href="mailto:${student.email}" class="student-email">${student.email || ''}</a>
                        </li>
                    `).join('')}
                </ul>
            `;
            $studentsList.html(studentsHtml);
        } else {
            $studentsList.html(`<p class="no-data">No students in this group.</p>`);
        }

        // Update courses list
        if (data.courses && data.courses.length > 0) {
            const coursesHtml = `
                <h3>Courses</h3>
                <ul class="course-list">
                    ${data.courses.map(course => `
                        <li class="course-item">
                            <a href="${customDashboard.siteUrl || ''}/courses/${course.id}" class="course-title">
                                ${course.title || 'Untitled Course'}
                            </a>
                        </li>
                    `).join('')}
                </ul>
            `;
            $coursesList.html(coursesHtml);
        } else {
            $coursesList.html(`<p class="no-data">No courses assigned to this group.</p>`);
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
     * Show an error message
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

    // Handle export buttons
    $(document).on('click', '#export-summary', function(e) {
        e.preventDefault();
        const groupId = $groupSelect.val();
        if (!groupId) {
            showError('Please select a group first.');
            return;
        }
        
        const exportUrl = `${customDashboard.exportUrl}?action=export_teacher_summary&group_id=${groupId}&nonce=${customDashboard.exportNonce}`;
        console.log('Export URL:', exportUrl); // Debug log
        triggerExport(exportUrl, $exportStatus);
    });
    
    $(document).on('click', '#export-detailed', function(e) {
        e.preventDefault();
        const groupId = $groupSelect.val();
        if (!groupId) {
            showError('Please select a group first.');
            return;
        }
        
        const exportUrl = `${customDashboard.exportUrl}?action=export_detailed_grades&group_id=${groupId}&nonce=${customDashboard.exportNonce}`;
        console.log('Export URL:', exportUrl); // Debug log
        triggerExport(exportUrl, $exportStatus);
    });

    // Initialize
    if ($groupSelect.val()) {
        loadGroupData($groupSelect.val());
        loadGradebookStats($groupSelect.val());
    }

    // Handle export buttons
    $exportSummary.on('click', function(e) {
        e.preventDefault();
        const groupId = $groupSelect.val();
        const exportUrl = $(this).data('url') + '&group_id=' + groupId;
        triggerExport(exportUrl, $exportStatus);
    });

    $exportDetailed.on('click', function(e) {
        e.preventDefault();
        const groupId = $groupSelect.val();
        const exportUrl = $(this).data('url') + '&group_id=' + groupId;
        triggerExport(exportUrl, $exportStatus);
    });

    /**
     * Trigger file download for export
     */
    function triggerExport(url, $statusElement) {
        $statusElement.text(customDashboard.i18n.exporting).fadeIn();
        
        // Create a temporary link element for the download
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.download = ''; // This will make the browser handle the download
        
        // Append to body, trigger click, then remove
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Update status
        $statusElement.text(customDashboard.i18n.exportComplete).delay(2000).fadeOut(1000, function() {
            $(this).text('');
        });
    }

    /**
     * Load gradebook statistics for a group
     */
    function loadGradebookStats(groupId) {
        if (!groupId) return;
        
        // Show loading state
        $gradebookStats.addClass('loading');
        
        // Get group average
        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_group_average',
                group_id: groupId,
                nonce: customDashboard.nonce
            },
            success: function(response) {
                if (response.success) {
                    $groupAverage.html(response.data.formatted || 'N/A');
                } else {
                    console.error('Error getting group average:', response.data);
                    $groupAverage.html('Error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
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
            success: function(response) {
                if (response.success) {
                    updateQuizStats(response.data);
                } else {
                    console.error('Error getting quiz stats:', response.data);
                    $quizStats.html('<p>Error loading quiz statistics.</p>');
                }
                $gradebookStats.removeClass('loading');
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                $quizStats.html('<p>Error loading quiz statistics.</p>');
                $gradebookStats.removeClass('loading');
            }
        });
    }
    
    /**
     * Update the quiz statistics display
     */
    function updateQuizStats(stats) {
        if (!stats || Object.keys(stats).length === 0) {
            $quizStats.html('<p>No quiz data available for this group.</p>');
            $quizzesCount.text('0');
            return;
        }
        
        let quizCount = 0;
        let totalAverage = 0;
        
        // Create the HTML for quiz stats
        let html = '<div class="quiz-stats-container">';
        
        // Add a table for better data presentation
        html += '<table class="quiz-stats-table">';
        html += '<thead><tr><th>Quiz</th><th>Average Score</th><th>Attempts</th></tr></thead>';
        html += '<tbody>';
        
        for (const quizId in stats) {
            const quiz = stats[quizId];
            if (quiz.average && quiz.average !== 'N/A') {
                quizCount++;
                totalAverage += parseFloat(quiz.average);
                
                html += `<tr>
                    <td>${quiz.title || 'Untitled Quiz'}</td>
                    <td>${quiz.average}%</td>
                    <td>${quiz.attempts || 0}</td>
                </tr>`;
            }
        }
        
        html += '</tbody></table>';
        
        // Calculate overall average if we have quizzes
        if (quizCount > 0) {
            const overallAverage = (totalAverage / quizCount).toFixed(2);
            html += `<div class="overall-average">
                <strong>Overall Average:</strong> ${overallAverage}%
            </div>`;
            
            // Update quizzes count
            $quizzesCount.text(quizCount);
        } else {
            html += '<p>No completed quizzes found for this group.</p>';
            $quizzesCount.text('0');
        }
        
        html += '</div>'; // Close container
        
        $quizStats.html(html);
    }

    // Expose functions to global scope for inline event handlers
    window.loadGroupData = function(groupId) {
        if (!groupId) return;
        
        // Update the group select if needed
        if ($groupSelect.val() !== groupId) {
            $groupSelect.val(groupId);
        }
        
        // Load both group data and gradebook stats
        loadGroupData(groupId);
        loadGradebookStats(groupId);
    };

    // Initialize with the first group if available
    const initialGroupId = $groupSelect.val();
    if (initialGroupId) {
        loadGroupData(initialGroupId);
        loadGradebookStats(initialGroupId);
    }
});(function($) {
    // Handle group selection change
    $('#group-select').on('change', function() {
        var groupId = $(this).val();
        loadGroupContent(groupId);
    });

    // Load initial group content
    var initialGroupId = $('#group-select').val();
    if (initialGroupId) {
        loadGroupContent(initialGroupId);
    }

    function loadGroupContent(groupId) {
        $('.loading').show();
        $('#group-content').html('<div class="loading">Loading...</div>');

        $.ajax({
            url: customDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_group_content',
                group_id: groupId,
                nonce: customDashboard.nonce
            },
            success: function(response) {
                $('.loading').hide();
                if (response.success) {
                    $('#group-content').html(response.data.html);
                } else {
                    $('#group-content').html('<p>Error loading group data.</p>');
                }
            },
            error: function() {
                $('.loading').hide();
                $('#group-content').html('<p>Error loading group data. Please try again.</p>');
            }
        });
    }
});
