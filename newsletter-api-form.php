<?php
/*
Plugin Name: NGO Tools newsletter subscription form
Description: Newsletter subscription form sending data as JSON via cURL POST with Bearer Token authentication, AJAX support, localization, and admin settings including API endpoint URL and segment selection.
Version: 1.4
Author: David Recinos
Text Domain: ngo_tools_newsletter
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

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
        '1.4',
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
    <form id="ngo-tools-newsletter-form" class="newsletter-form" method="post" novalidate>

        <?php if(($attributes['form-labels'] == 'true')){ ?>
        <label class="ngo_tools_newsletter_form_label"><?php echo esc_html_e('First Name', 'ngo_tools_newsletter'); ?></label><br>
        <?php } ?>
            <input class="ngo_tools_newsletter_form_input"
                   placeholder="<?php echo esc_html_e('First Name', 'ngo_tools_newsletter'); ?>"
                   type="text" name="ngo_tools_firstName" required>
        <br>
        <?php if(($attributes['form-labels'] == 'true')){ ?>
        <label class="ngo_tools_newsletter_form_label"><?php echo esc_html_e('Last Name', 'ngo_tools_newsletter'); ?></label><br>
        <?php } ?>
            <input class="ngo_tools_newsletter_form_input" placeholder="<?php echo esc_html_e('Last Name', 'ngo_tools_newsletter'); ?>"
                   type="text" name="ngo_tools_lastName" required>
        <br>
        <?php if(($attributes['form-labels'] == 'true')){ ?>
        <label class="ngo_tools_newsletter_form_label"><?php echo esc_html_e('Email', 'ngo_tools_newsletter'); ?></label> <br>
        <?php } ?>
            <input class="ngo_tools_newsletter_form_input" placeholder="<?php echo esc_html_e('Email', 'ngo_tools_newsletter'); ?>"
                   type="email" name="ngo_tools_email" required>
        <br>
        <input class="ngo_tools_newsletter_form_button" type="submit" value="<?php echo esc_attr__('Subscribe', 'ngo_tools_newsletter'); ?>">
    </form>
    <div id="ngo-message" style="margin-top:15px;"></div>
    <?php
    return ob_get_clean();
});

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
            $errors[] = sprintf(__('Please fill the %s field.', 'ngo_tools_newsletter'), ucfirst($field));
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
    $bearer_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
    $organization_name = get_option('ngo_tools_newsletter_organization_name', '');
    $segment_id = get_option('ngo_tools_newsletter_api_segment', '');

    if (empty($organization_name)) {
        wp_send_json_error(['messages' => [__('API endpoint URL is not configured.', 'ngo_tools_newsletter')]]);
    }

    // cURL request
    $ch = curl_init(ngo_tools_subscribe_contact_segments_url($organization_name, $segment_id));
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
        wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . $curl_error]]);
    }

    wp_send_json_success(['messages' => [__('Thank you for subscribing!', 'ngo_tools_newsletter')]]);
}

// Admin menu and settings
add_action('admin_menu', 'ngo_tools_add_settings_page');
add_action('admin_init', 'ngo_tools_register_settings');

function ngo_tools_get_contact_segments_url($organizationName){
    return "https://$organizationName.ngo.tools/api/v2/contact-segments";
}
function ngo_tools_subscribe_contact_segments_url($organizationName, $segmentId){
    return "https://$organizationName.ngo.tools/api/v2/contact-segments/$segmentId/subscribe";
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
        'sanitize_callback' => 'sanitize_text_field',
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

function ngo_tools_render_settings_page()
{
    $bearer_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
    $organizationName = get_option('ngo_tools_newsletter_organization_name', '');
    $selected_segment = get_option('ngo_tools_newsletter_api_segment', '');

    // Prepare segments array
    $segments = [];

    if ($bearer_token && $organizationName) {

        $ch = curl_init(ngo_tools_get_contact_segments_url($organizationName));
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
                        esc_html_e('The token seems to be expired', 'ngo_tools_newsletter');
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
        <h1><?php esc_html_e('Newsletter API Settings', 'ngo_tools_newsletter'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ngo_tools_newsletter_api_options');
            do_settings_sections('ngo_tools_newsletter_api_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('API Bearer Token', 'ngo_tools_newsletter'); ?></th>
                    <td>
                        <input type="password" name="ngo_tools_newsletter_api_bearer_token"
                               value="<?php echo esc_attr($bearer_token); ?>" class="regular-text" autocomplete="off"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Organization name', 'ngo_tools_newsletter'); ?></th>
                    <td>
                        <input type="text" name="ngo_tools_newsletter_organization_name" value="<?php echo esc_attr($organizationName); ?>"
                               class="regular-text"/>
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
