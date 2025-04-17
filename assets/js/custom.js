$(document).ready(function () {
    $('.ajax-form').on('submit', function (e) {
        e.preventDefault();
        const form = $(this);
        // ADD: Basic form validation
        if (!form[0].checkValidity()) {
            form[0].reportValidity();
            return;
        }
        // ADD: Show loading spinner
        const submitButton = form.find('button[type="submit"]');
        const originalText = submitButton.text();
        submitButton.text('Loading...').prop('disabled', true);
        const formData = new FormData(this);
        $.ajax({
            url: '/admin-panel/inc/ajax-handler.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (result) {
                // CHANGE: Robust error handling
                if (result.success && result.data && result.data.message) {
                    form[0].reset();
                    $('#medicineModal, #orderModal, #ticketModal, #userModal, #roleModal, #settings-form').modal('hide');
                    showAlert(result.data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    const message = result.data && result.data.message ? result.data.message : 'An error occurred';
                    showAlert(message, 'danger');
                }
            },
            error: function (xhr) {
                showAlert('An error occurred: ' + (xhr.statusText || 'Unknown error'), 'danger');
            },
            complete: function () {
                // ADD: Restore button state
                submitButton.text(originalText).prop('disabled', false);
            }
        });
    });

    function showAlert(message, type) {
        // CHANGE: Append to body
        const alert = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
        $('body').prepend(alert);
        setTimeout(() => $('.alert').remove(), 3000);
    }

    // Handle search forms
    $('#medicine-search, #order-search, #payment-search, #ticket-search, #user-search').on('submit', function (e) {
        e.preventDefault();
        const query = $(this).find('input[name="s"]').val();
        window.location.href = `?s=${encodeURIComponent(query)}`;
    });
});