<?php
/*
Plugin Name: NGO Tools newsletter subscription form
Description: Newsletter subscription form sending data as JSON via cURL POST with Bearer Token authentication, AJAX support, localization, and admin settings including API endpoint URL and segment selection.
Version: 1.4
Author: David Recinos
Text Domain: newsletter
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// Load plugin textdomain
function ngo_load_textdomain()
{
    load_plugin_textdomain('newsletter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'ngo_load_textdomain');

// Enqueue JS and localize AJAX URL + nonce
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'newsletter-form-js',
        plugin_dir_url(__FILE__) . 'js/newsletter-form.js',
        ['jquery'],
        '1.4',
        true
    );
    wp_localize_script('newsletter-form-js', 'ngo_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ngo_nonce')
    ]);
});

// Allowed fields
function ngo_get_allowed_fields()
{
    return ['firstName', 'lastName', 'email'];
}

// Shortcode to render form
add_shortcode('newsletter_api_form', function ($atts) {
    $allowed_fields = ngo_get_allowed_fields();

    $atts = shortcode_atts([
        'fields' => 'firstname,lastname,email'
    ], $atts, 'newsletter_api_form');

    $requested_fields = array_map('trim', explode(',', $atts['fields']));
    $fields = array_intersect($requested_fields, $allowed_fields);
    if (empty($fields)) {
        $fields = $allowed_fields;
    }

    ob_start();
    ?>
    <form id="newsletter-form" class="newsletter-form" method="post" novalidate>
        <?php foreach ($fields as $field):
            $name_attr = 'ngo_' . $field;
            $label = ucfirst($field);
            $type = ($field === 'email') ? 'email' : 'text';
            ?>
            <label><?php echo esc_html($label); ?><br>
                <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name_attr); ?>" required>
            </label><br>
        <?php endforeach; ?>
        <input type="submit" value="<?php echo esc_attr__('Subscribe', 'newsletter'); ?>">
    </form>
    <div id="ngo-message" style="margin-top:15px;"></div>
    <?php
    return ob_get_clean();
});

// AJAX handler
add_action('wp_ajax_nopriv_ngo_submit_form', 'ngo_ajax_form_handler');
add_action('wp_ajax_ngo_submit_form', 'ngo_ajax_form_handler');

function ngo_ajax_form_handler()
{
    check_ajax_referer('ngo_nonce', 'nonce');

    $allowed_fields = ngo_get_allowed_fields();
    $requested_fields = isset($_POST['fields']) ? (array)$_POST['fields'] : [];
    $fields = array_intersect($requested_fields, $allowed_fields);

    if (empty($fields)) {
        wp_send_json_error(['messages' => [__('No valid fields specified.', 'newsletter')]]);
    }

    $data = [];
    $errors = [];

    foreach ($fields as $field) {
        $key = 'ngo_' . $field;
        if (empty($_POST[$key])) {
            $errors[] = sprintf(__('Please fill the %s field.', 'newsletter'), ucfirst($field));
        } else {
            $value = sanitize_text_field(wp_unslash($_POST[$key]));
            if ($field === 'email' && !is_email($value)) {
                $errors[] = __('Please enter a valid email address.', 'newsletter');
            }
            $data[$field] = $value;
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(['messages' => $errors]);
    }

    // Get Bearer Token, API URL and selected segment from options
    $bearer_token = get_option('ngo_newsletter_api_bearer_token', '');
    $organization_name = get_option('ngo_newsletter_organization_name', '');
    $segment_id = get_option('ngo_newsletter_api_segment', '');

    if (empty($organization_name)) {
        wp_send_json_error(['messages' => [__('API endpoint URL is not configured.', 'newsletter')]]);
    }

    // cURL request
    $ch = curl_init(ngo_subscribe_contact_segments_url($organization_name, $segment_id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    $headers = ['Content-Type: application/json'];
    if ($bearer_token) {
        $headers[] = 'Authorization: Bearer ' . $bearer_token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'newsletter') . $curl_error]]);
    }

    wp_send_json_success(['messages' => [__('Thank you for subscribing!', 'newsletter')]]);
}

// Admin menu and settings
add_action('admin_menu', 'ngo_add_settings_page');
add_action('admin_init', 'ngo_register_settings');

function ngo_get_contact_segments_url($organizationName){
    return "https://$organizationName.ngo.tools/api/v2/contact-segments";
}
function ngo_subscribe_contact_segments_url($organizationName, $segmentId){
    return "https://$organizationName.ngo.tools/api/v2/contact-segments/$segmentId/subscribe";
}

function ngo_add_settings_page()
{
    add_options_page(
        __('Newsletter API Settings', 'newsletter'),
        __('Newsletter API', 'newsletter'),
        'manage_options',
        'ngo-newsletter-api',
        'ngo_render_settings_page'
    );
}

function ngo_register_settings()
{
    register_setting('ngo_newsletter_api_options', 'ngo_newsletter_api_bearer_token', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('ngo_newsletter_api_options', 'ngo_newsletter_organization_name', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('ngo_newsletter_api_options', 'ngo_newsletter_api_segment', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
}

function ngo_render_settings_page()
{
    $bearer_token = get_option('ngo_newsletter_api_bearer_token', '');
    $organizationName = get_option('ngo_newsletter_organization_name', '');
    $selected_segment = get_option('ngo_newsletter_api_segment', '');

    // Prepare segments array
    $segments = [];

    if ($bearer_token && $organizationName) {

        $ch = curl_init(ngo_get_contact_segments_url($organizationName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $bearer_token,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Manual handling for API redirection to login page when token is invalid or expired
        preg_match('/login/', strtolower($response), $matches);

        if (!$curl_error && $response) {
            $json = json_decode($response, true);
            if (isset($json['data']) && is_array($json['data'])) {
                $segments = $json['data'];
            } else {
                if ($matches) {
                    echo "<div class=\"notice notice-error\"><p>";
                        esc_html_e('The token seems to be expired', 'newsletter');
                    echo "</p></div>";
                } else {
                    echo "<div class=\"notice notice-error\"><p>$response</p></div>";
                }
            }
        } else {
            echo "<div class=\"notice notice-error\"><p>$curl_error</p></div>";
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Newsletter API Settings', 'newsletter'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ngo_newsletter_api_options');
            do_settings_sections('ngo_newsletter_api_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('API Bearer Token', 'newsletter'); ?></th>
                    <td>
                        <input type="password" name="ngo_newsletter_api_bearer_token"
                               value="<?php echo esc_attr($bearer_token); ?>" class="regular-text" autocomplete="off"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Organization name', 'newsletter'); ?></th>
                    <td>
                        <input type="text" name="ngo_newsletter_organization_name" value="<?php echo esc_attr($organizationName); ?>"
                               class="regular-text"/>
                    </td>
                </tr>
                <?php if ($bearer_token && $segments): ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Select Segment', 'newsletter'); ?></th>
                        <td>
                            <select name="ngo_newsletter_api_segment">
                                <option value=""><?php esc_html_e('Please select a segment', 'newsletter'); ?></option>
                                <?php foreach ($segments as $segment):
                                    $id = isset($segment['id']) ? $segment['id'] : '';
                                    $name = isset($segment['name']) ? $segment['name'] : '';
                                    if ($id && $name):
                                        ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_segment, $id); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php
                                    endif;
                                endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
