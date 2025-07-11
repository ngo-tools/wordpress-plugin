<?php

final class NGO_Tools_Admin {

    private static $instance = null;
    private $encryption;

    private function __construct($encryption) {
        $this->encryption = $encryption;

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function get_instance($encryption) {
        if (null === self::$instance) {
            self::$instance = new self($encryption);
        }
        return self::$instance;
    }

    public function add_settings_page() {
        add_options_page(
            __('Newsletter API Settings', 'ngo_tools_newsletter'),
            __('Newsletter API', 'ngo_tools_newsletter'),
            'manage_options',
            'ngo-newsletter-api',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('ngo_tools_newsletter_api_options', 'ngo_tools_newsletter_api_bearer_token', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_bearer_token'],
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

    public function sanitize_bearer_token($input) {
        $input = sanitize_text_field($input);
        if (empty($input)) {
            // Return existing encrypted token if input empty
            return get_option('ngo_tools_newsletter_api_bearer_token', '');
        }
        // Encrypt token before saving
        return $this->encryption->encrypt($input);
    }

    private function is_valid_organization_name($organization_name) {
        return (bool) preg_match('/.+\.ngo\.tools$/m', $organization_name);
    }

    private function get_contact_segments_url($organization_name) {
        return "https://$organization_name/api/v2/contact-segments";
    }

    public function render_settings_page() {
        $encrypted_token = get_option('ngo_tools_newsletter_api_bearer_token', '');
        $bearer_token = $encrypted_token ? $this->encryption->decrypt($encrypted_token) : '';
        $organization_name = get_option('ngo_tools_newsletter_organization_name', '');
        $selected_segment = get_option('ngo_tools_newsletter_api_segment', '');

        $segments = [];
        $is_valid_org = $this->is_valid_organization_name($organization_name);

        if (!$is_valid_org) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__("The organisation name can't be empty and must end with .ngo.tools", 'ngo_tools_newsletter');
            echo '</p></div>';
        }

        if (!empty($bearer_token) && $is_valid_org) {
            $endpoint_url = $this->get_contact_segments_url($organization_name);
            $args = NGO_Tools_Frontend::get_default_arguments($bearer_token);

            $response = wp_remote_get($endpoint_url, $args);
            $body = wp_remote_retrieve_body($response);

            preg_match('/login/', strtolower($body), $matches);

            if (!is_wp_error($response) && $response) {
                $json = json_decode($body, true);
                if (isset($json['data']) && is_array($json['data'])) {
                    $segments = $json['data'];
                } else {
                    if ($matches) {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('The token seems to be expired', 'ngo_tools_newsletter');
                        echo '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('Unexpected Error. Please verify the bearer token and the organization name', 'ngo_tools_newsletter');
                        echo '</p></div>';
                    }
                }
            } else {
                $error_message = $response->get_error_message();
                echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
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
                            <p class="description"><?php esc_html_e("You can generate your token in the Profile area under the \"API Token\" section", 'ngo_tools_newsletter'); ?></p>
                        </th>
                        <td>
                            <input type="text" name="ngo_tools_newsletter_api_bearer_token" value=""
                                   class="regular-text" autocomplete="off"
                                   placeholder="<?php esc_attr_e('Enter a value if you want to set a new token', 'ngo_tools_newsletter'); ?>" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e('Organization name', 'ngo_tools_newsletter'); ?>
                            <p class="description"><?php esc_html_e('Your organization name is the first part of the URL. For example, if your URL is examplename.ngo.tools/app/dashboard, the value to enter is examplename.ngo.tools.', 'ngo_tools_newsletter'); ?></p>
                        </th>
                        <td>
                            <input type="text" name="ngo_tools_newsletter_organization_name" value="<?php echo esc_attr($organization_name); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e('i.e.: organization.ngo.tools', 'ngo_tools_newsletter'); ?>" />
                        </td>
                    </tr>
                    <?php if ($bearer_token && $segments): ?>
                        <tr valign="top">
                            <th scope="row"><?php esc_html_e('Select Segment', 'ngo_tools_newsletter'); ?></th>
                            <td>
                                <select name="ngo_tools_newsletter_api_segment">
                                    <option value=""><?php esc_html_e('Please select a segment', 'ngo_tools_newsletter'); ?></option>
                                    <?php foreach ($segments as $segment):
                                        $id = $segment['id'] ?? '';
                                        $name = $segment['name'] ?? '';
                                        if ($id && $name): ?>
                                            <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_segment, $id); ?>>
                                                <?php echo esc_html($name); ?>
                                            </option>
                                        <?php endif;
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
}