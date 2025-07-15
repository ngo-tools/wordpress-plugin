jQuery(document).ready(function($) {
    $('#ngo-tools-newsletter-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $msg = $('#ngo-message');
        $msg.html(''); // clear previous messages

        var fields = [];
        $form.find('input[name^="ngo_tools_"]').each(function() {
            var name = $(this).attr('name').replace('ngo_tools_', '');
            if (fields.indexOf(name) === -1) {
                fields.push(name);
            }
        });

        var formData = $form.serializeArray();

        // Remove existing fields[] entries
        formData = formData.filter(function(item) {
            return item.name !== 'fields[]';
        });

        formData.push({name: 'action', value: 'ngo_tools_submit_form'});
        formData.push({name: 'nonce', value: ngo_tools_ajax_obj.nonce});

        fields.forEach(function(field) {
            formData.push({name: 'fields[]', value: field});
        });

        $.ajax({
            url: ngo_tools_ajax_obj.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $msg.removeClass('ngo_tools_notice-error').addClass('ngo_tools_notice-success')
                        .html(response.data.messages.join('<br>'));
                    $form[0].reset();
                } else {
                    $msg.removeClass('ngo_tools_notice-success').addClass('ngo_tools_notice-error')
                        .html(response.data.messages.join('<br>'));
                }
            },
            error: function() {
                $msg.css('color', 'red').html('An unexpected error occurred.');
            }
        });
    });
});
