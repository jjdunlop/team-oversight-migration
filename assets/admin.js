jQuery(document).ready(function($) {
    
    // Team assignment form functionality
    $('.team-assignment-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('There was an error processing your request.');
            }
        });
    });
    
    // Trial application processing
    $('.process-trial-btn').on('click', function() {
        var applicationId = $(this).data('id');
        var action = $(this).data('action');
        var assignedTeam = '';
        
        if (action === 'accept') {
            assignedTeam = prompt('Enter team assignment (e.g., SLD1B-M):');
            if (!assignedTeam) {
                return;
            }
        }
        
        if (confirm('Are you sure you want to ' + action + ' this application?')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'process_trial_application',
                    application_id: applicationId,
                    trial_action: action,
                    assigned_team: assignedTeam,
                    nonce: $('#trial_nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('There was an error processing the application.');
                }
            });
        }
    });
    
    // Fee matrix editing
    $('.fee-matrix-table input').on('change', function() {
        var row = $(this).closest('tr');
        var feeClass = row.find('.fee-class').text();
        var teamRole = row.find('.team-role').text();
        var feeAmount = $(this).val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_fee_matrix',
                fee_class: feeClass,
                team_role: teamRole,
                fee_amount: feeAmount,
                nonce: $('#fee_matrix_nonce').val()
            },
            success: function(response) {
                if (!response.success) {
                    alert('Error updating fee matrix: ' + response.data);
                }
            }
        });
    });
    
    // File upload validation
    $('input[type="file"]').on('change', function() {
        var file = this.files[0];
        if (file && !file.name.toLowerCase().endsWith('.csv')) {
            alert('Please select a CSV file.');
            $(this).val('');
        }
    });
    
    // Auto-refresh dashboard every 5 minutes
    if ($('.dashboard-grid').length > 0) {
        setInterval(function() {
            location.reload();
        }, 300000); // 5 minutes
    }
    
});