<?php
/*
Plugin Name: NGO Tools newsletter subscription form
Description: Newsletter subscription form sending data as JSON via cURL POST with Bearer Token authentication, AJAX support, localization, and admin settings including API endpoint URL and segment selection.
Version: 1.0
Author: David Recinos
Text Domain: ngo_tools_newsletter
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}
require_once('NGO_DataEncryption.php');

// Load plugin textdomain
function ngo_tools_load_textdomain()
{
    load_plugin_textdomain('ngo_tools_newsletter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'ngo_tools_load_textdomain');

// Enqueue JS and localize AJAX URL + nonce
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'newsletter-form-js',
        plugin_dir_url(__FILE__) . 'js/newsletter-form.js',
        ['jquery'],
        '1.0',
        true
    );
    wp_localize_script('newsletter-form-js', 'ngo_tools_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ngo_tools_nonce')
    ]);
});

// Shortcode to render form
add_shortcode('ngo_tools_newsletter_api_form', function ($attributes) {
    $attributes = shortcode_atts([
        'form-labels' => 'true'
    ], $attributes, 'newsletter_api_form');

    ob_start();
    ?>

    <form id="ngo-tools-newsletter-form" class="ngo_tools_newsletter-form" method="post" novalidate>

        <?php ngo_tools_render_input('text', 'ngo_tools_firstName', 'First Name', $attributes) ?>
        <?php ngo_tools_render_input('text', 'ngo_tools_lastName', 'Last Name', $attributes) ?>
        <?php ngo_tools_render_input('email', 'ngo_tools_email', 'Email', $attributes) ?>
        <p class="ngo_tools_newsletter_form_field">
        <input class="ngo_tools_newsletter_form_button" type="submit" value="<?php echo esc_attr__('Subscribe', 'ngo_tools_newsletter'); ?>">
        </p>
    </form>
    <div id="ngo-message" class="ngo_tools_notice" style="margin-top:15px;"></div>
    <?php
    return ob_get_clean();
});

function ngo_tools_render_input($type, $name, $placeholder, $attributes){
    ?>
        <p class="ngo_tools_newsletter_form_field">
        <?php if(($attributes['form-labels'] == 'true')){ ?>
        <label class="ngo_tools_newsletter_form_label"><?php echo esc_html_e($placeholder, 'ngo_tools_newsletter'); ?></label> <br>
        <?php } ?>
            <input class="ngo_tools_newsletter_form_input" placeholder="<?php echo esc_html_e($placeholder, 'ngo_tools_newsletter'); ?>"
                   type="<?php echo esc_html_e($type) ?>" name="<?php echo esc_html_e($name) ?>" required>
        </p>
    <?php
}

// AJAX handler
add_action('wp_ajax_nopriv_ngo_tools_submit_form', 'ngo_tools_ajax_form_handler');
add_action('wp_ajax_ngo_tools_submit_form', 'ngo_tools_ajax_form_handler');

function ngo_tools_ajax_form_handler()
{
    check_ajax_referer('ngo_tools_nonce', 'nonce');

    $fields = isset($_POST['fields']) ? (array)$_POST['fields'] : [];

    if (empty($fields)) {
        wp_send_json_error(['messages' => [__('No valid fields specified.', 'ngo_tools_newsletter')]]);
    }

    $data = [];
    $errors = [];

    foreach ($fields as $field) {
        $key = 'ngo_tools_' . $field;
        if (empty($_POST[$key])) {
            $errors[] = sprintf(__('Please fill the %s field.', 'ngo_tools_newsletter'), __($field, 'ngo_tools_newsletter'));
        } else {
            $value = sanitize_text_field(wp_unslash($_POST[$key]));
            if ($field === 'email' && !is_email($value)) {
                $errors[] = __('Please enter a valid email address.', 'ngo_tools_newsletter');
            }
            $data[$field] = $value;
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(['messages' => $errors]);
    }

    // Get Bearer Token, API URL and selected segment from options
    $encryption = new NGO_DataEncryption();
    $encrypted_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
    $bearer_token = $encrypted_token ? $encryption->decrypt($encrypted_token) : '';
    $organization_name = get_option('ngo_tools_newsletter_organization_name', '');
    $segment_id = get_option('ngo_tools_newsletter_api_segment', '');
    $endpoint_url = ngo_tools_subscribe_contact_segments_url($organization_name, $segment_id);

    $args = array_merge(
        ngo_tools_get_default_arguments($bearer_token),
        ['body'    => json_encode($data)]
    );

    $response = wp_remote_post($endpoint_url, $args);
    $body = wp_remote_retrieve_body($response);

    // Detect redirection to login page when bearer token is expired or invalid
    preg_match('/login/', strtolower($body), $matchesLoginRedirection);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . $error_message]]);
    } elseif (!empty($matchesLoginRedirection)) {
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter')
            . __('The token seems to be expired', 'ngo_tools_newsletter')], 'success' => false]);
    } elseif ($response['response']['code'] == 404) {
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter')
            . __('Endpoint not found!', 'ngo_tools_newsletter')], 'success' => false]);
    } elseif (in_array($response['response']['code'], [200, 201])) {
        wp_send_json_success(['messages' => [__('Thank you for subscribing!', 'ngo_tools_newsletter')]]);
    } else {
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . 'Unkown error']]);
    }
}

// Admin menu and settings
add_action('admin_menu', 'ngo_tools_add_settings_page');
add_action('admin_init', 'ngo_tools_register_settings');

function ngo_tools_get_contact_segments_url($organizationName){
    return "https://$organizationName/api/v2/contact-segments";
}
function ngo_tools_subscribe_contact_segments_url($organizationName, $segmentId){
    return "https://$organizationName/api/v2/contact-segments/$segmentId/subscribe";
}

function ngo_tools_add_settings_page()
{
    add_options_page(
        __('Newsletter API Settings', 'ngo_tools_newsletter'),
        __('Newsletter API', 'ngo_tools_newsletter'),
        'manage_options',
        'ngo-newsletter-api',
        'ngo_tools_render_settings_page'
    );
}

function ngo_tools_register_settings()
{
    register_setting('ngo_tools_newsletter_api_options', 'ngo_tools_newsletter_api_bearer_token', [
        'type' => 'string',
        'sanitize_callback' => 'ngo_sanitize_bearer_token',
    ]);
    register_setting('ngo_tools_newsletter_api_options', 'ngo_tools_newsletter_organization_name', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('ngo_tools_newsletter_api_options', 'ngo_tools_newsletter_api_segment', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
}
function ngo_sanitize_bearer_token($input) {
    $input = sanitize_text_field($input);
    if(empty($input)) {
        return esc_attr(get_option('ngo_tools_newsletter_api_bearer_token', ''));
    }
    $encryption = new NGO_DataEncryption();

    // Encrypt token before saving
    return $encryption->encrypt($input);
}

function ngo_tools_get_default_arguments($bearer_token){
    return $args = [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => $bearer_token ? 'Bearer ' . $bearer_token : '',
        ],
        'timeout' => 15,
        'sslverify' => false,
    ];
}

function ngo_tools_is_valid_organization_name($organization_name){
    preg_match('/.+\.ngo\.tools$/m', $organization_name, $matches);
    return !empty($matches);
}

function ngo_tools_render_settings_page()
{
    $encryption = new NGO_DataEncryption();
    $encrypted_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
    $bearer_token = $encrypted_token ? $encryption->decrypt($encrypted_token) : '';
    $organizationName = get_option('ngo_tools_newsletter_organization_name', '');
    $selected_segment = get_option('ngo_tools_newsletter_api_segment', '');

    // Prepare segments array
    $segments = [];

    // Validate the organization name
    if(!$is_valid_organization_name = ngo_tools_is_valid_organization_name($organizationName)) {
        echo "<div class=\"notice notice-error\"><p>";
        echo __("The organisation name can't be empty and must end with <b>.ngo.tools</b>", 'ngo_tools_newsletter');
        echo "</p></div>";
    }

    // Retrieve the segments if the bearer token is not empty and the organization name is set
    if (!empty($bearer_token) && $is_valid_organization_name) {
        $endpoint_url = ngo_tools_get_contact_segments_url($organizationName);
        $args = ngo_tools_get_default_arguments($bearer_token);

        $response = wp_remote_get($endpoint_url, $args);
        $body = wp_remote_retrieve_body($response);

        // Manual handling for API redirection to login page when token is invalid or expired
        preg_match('/login/', strtolower($body), $matches);

        if (!is_wp_error($response) && $response) {
            $json = json_decode($body, true);
            if (isset($json['data']) && is_array($json['data'])) {
                $segments = $json['data'];
            } else {
                if ($matches) {
                    echo "<div class=\"notice notice-error\"><p>";
                        esc_html_e('The token seems to be expired', 'ngo_tools_newsletter');
                    echo "</p></div>";
                } else {
                    echo "<div class=\"notice notice-error\"><p>";
                        esc_html_e('Unexpected Error.  Please verify the bearer token and the organization name', 'ngo_tools_newsletter');
                    echo "</p></div>";
                }
            }
        } else {
            $error_message = $response->get_error_message();
            echo "<div class=\"notice notice-error\"><p>$error_message</p></div>";
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Newsletter API Settings', 'ngo_tools_newsletter'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ngo_tools_newsletter_api_options');
            do_settings_sections('ngo_tools_newsletter_api_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <?php esc_html_e('API Bearer Token', 'ngo_tools_newsletter'); ?>
                        <p class="description"><?php esc_html_e("You can generate your token in the Profile area under ".
                                "the \"API Token\" section", 'ngo_tools_newsletter' ) ?></p>
                    </th>
                    <td>
                        <input type="text" name="ngo_tools_newsletter_api_bearer_token" value=""
                               class="regular-text" autocomplete="off"
                               placeholder ="<?php esc_html_e('Enter a value if you want to set a new token', 'ngo_tools_newsletter'); ?>"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <?php esc_html_e('Organization name', 'ngo_tools_newsletter'); ?>
                        <p class="description"><?php esc_html_e('Your organization name is the first part of the URL. ' .
                        'For example, if your URL is examplename.ngo.tools/app/dashboard, ' .
                        'the value to enter is examplename.ngo.tools.', 'ngo_tools_newsletter') ?></p>
                    </th>
                    <td>
                        <input type="text" name="ngo_tools_newsletter_organization_name" value="<?php echo esc_attr($organizationName); ?>"
                               class="regular-text"
                               placeholder ="<?php esc_html_e('i.e.: organization.ngo.tools', 'ngo_tools_newsletter'); ?>"/>
                    </td>
                </tr>
                <?php if ($bearer_token && $segments): ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Select Segment', 'ngo_tools_newsletter'); ?></th>
                        <td>
                            <select name="ngo_tools_newsletter_api_segment">
                                <option value=""><?php esc_html_e('Please select a segment', 'ngo_tools_newsletter'); ?></option>
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
