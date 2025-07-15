jQuery(document).ready(function($) {
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
