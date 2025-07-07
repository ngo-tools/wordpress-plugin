jQuery(document).ready(function($) {
    $('#newsletter-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $msg = $('#ngo-message');
        $msg.html(''); // clear previous messages

        var fields = [];
        $form.find('input[name^="ngo_"]').each(function() {
            var name = $(this).attr('name').replace('ngo_', '');
            if (fields.indexOf(name) === -1) {
                fields.push(name);
            }
        });

        var formData = $form.serializeArray();

        // Remove existing fields[] entries
        formData = formData.filter(function(item) {
            return item.name !== 'fields[]';
        });

        formData.push({name: 'action', value: 'ngo_submit_form'});
        formData.push({name: 'nonce', value: ngo_ajax_obj.nonce});

        fields.forEach(function(field) {
            formData.push({name: 'fields[]', value: field});
        });

        $.ajax({
            url: ngo_ajax_obj.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $msg.css('color', 'green').html(response.data.messages.join('<br>'));
                    $form[0].reset();
                } else {
                    $msg.css('color', 'red').html(response.data.messages.join('<br>'));
                }
            },
            error: function() {
                $msg.css('color', 'red').html('An unexpected error occurred.');
            }
        });
    });
});
