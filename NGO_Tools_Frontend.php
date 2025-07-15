<?php

final class NGO_Tools_Frontend {

    private static $instance = null;
    private $encryption;

    private function __construct($encryption) {
        $this->encryption = $encryption;

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('ngo_tools_newsletter_api_form', [$this, 'render_newsletter_form']);

        // AJAX handlers
        add_action('wp_ajax_nopriv_ngo_tools_submit_form', [$this, 'ajax_form_handler']);
        add_action('wp_ajax_ngo_tools_submit_form', [$this, 'ajax_form_handler']);
    }

    public static function get_instance($encryption) {
        if (null === self::$instance) {
            self::$instance = new self($encryption);
        }
        return self::$instance;
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'newsletter-form-js',
            plugin_dir_url(__FILE__) . 'js/newsletter-form.js',
            ['jquery'],
            '1.0',
            true
        );
        wp_localize_script('newsletter-form-js', 'ngo_tools_ajax_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ngo_tools_nonce'),
        ]);
    }

    public function render_newsletter_form($attributes) {
        $attributes = shortcode_atts([
            'form-labels' => 'true',
        ], $attributes, 'ngo_tools_newsletter_api_form');

        ob_start();
        ?>
        <form id="ngo-tools-newsletter-form" class="ngo_tools_newsletter-form" method="post" novalidate>
            <?php $this->render_input('text', 'firstName', __('First Name', 'ngo_tools_newsletter'), $attributes); ?>
            <?php $this->render_input('text', 'lastName', __('Last Name', 'ngo_tools_newsletter'), $attributes); ?>
            <?php $this->render_input('email', 'email', __('Email', 'ngo_tools_newsletter'), $attributes); ?>
            <p class="ngo_tools_newsletter_form_field">
                <input class="ngo_tools_newsletter_form_button" type="submit" value="<?php echo esc_attr__('Subscribe', 'ngo_tools_newsletter'); ?>">
            </p>
        </form>
        <div id="ngo-message" class="ngo_tools_notice" style="margin-top:15px;"></div>
        <?php
        return ob_get_clean();
    }

    private function render_input($type, $name, $label, $attributes) {
        ?>
        <p class="ngo_tools_newsletter_form_field">
            <?php if ($attributes['form-labels'] === 'true'): ?>
                <label class="ngo_tools_newsletter_form_label" for="ngo_tools_<?php echo esc_attr($name); ?>">
                    <?php echo esc_html($label); ?>
                </label><br>
            <?php endif; ?>
            <input
                id="ngo_tools_<?php echo esc_attr($name); ?>"
                class="ngo_tools_newsletter_form_input"
                placeholder="<?php echo esc_attr($label); ?>"
                type="<?php echo esc_attr($type); ?>"
                name="ngo_tools_<?php echo esc_attr($name); ?>"
                required
            >
        </p>
        <?php
    }

    public function ajax_form_handler() {
        check_ajax_referer('ngo_tools_nonce', 'nonce');

        $fields = isset($_POST['fields']) ? (array) $_POST['fields'] : ['firstName', 'lastName', 'email'];

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
        $encrypted_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
        $bearer_token = $encrypted_token ? $this->encryption->decrypt($encrypted_token) : '';
        $organization_name = get_option('ngo_tools_newsletter_organization_name', '');
        $segment_id = get_option('ngo_tools_newsletter_api_segment', '');
        $endpoint_url = self::subscribe_contact_segments_url($organization_name, $segment_id);

        $args = array_merge(
            self::get_default_arguments($bearer_token),
            ['body' => json_encode($data)]
        );

        $response = wp_remote_post($endpoint_url, $args);
        $body = wp_remote_retrieve_body($response);

        // Detect redirection to login page when bearer token is expired or invalid
        preg_match('/login/', strtolower($body), $matchesLoginRedirection);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . $error_message]]);
        } elseif (!empty($matchesLoginRedirection)) {
            wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . __('The token seems to be expired', 'ngo_tools_newsletter')], 'success' => false]);
        } elseif ($response['response']['code'] === 404) {
            if(empty($segment_id)){
                wp_send_json_error(['messages' => [__('Failed to subscribe: the newsletter segment is not selected', 'ngo_tools_newsletter') ], 'success' => false]);
            } else {
                wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . __('Endpoint not found!', 'ngo_tools_newsletter')], 'success' => false]);
            }
        } elseif (in_array($response['response']['code'], [200, 201], true)) {
            wp_send_json_success(['messages' => [__('Thank you for subscribing!', 'ngo_tools_newsletter')]]);
        } else {
            wp_send_json_error(['messages' => [__('Failed to subscribe: ', 'ngo_tools_newsletter') . __('Unknown error', 'ngo_tools_newsletter')]]);
        }
    }

    public static function get_default_arguments($bearer_token) {
        return [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => $bearer_token ? 'Bearer ' . $bearer_token : '',
            ],
            'timeout'   => 15,
            'sslverify' => false,
        ];
    }

    public static function subscribe_contact_segments_url($organization_name, $segment_id) {
        return "https://$organization_name/api/v2/contact-segments/$segment_id/subscribe";
    }
}