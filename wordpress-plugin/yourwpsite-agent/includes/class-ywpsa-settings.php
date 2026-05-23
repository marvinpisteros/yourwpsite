<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Settings
{
    public static function register()
    {
        register_setting(
            'ywpsa_settings_group',
            YourWPsite_Agent::OPTION_KEY,
            array(
                'sanitize_callback' => array(__CLASS__, 'sanitize'),
                'default' => self::defaults(),
            )
        );
    }

    public static function defaults()
    {
        return array(
            'control_plane_base_url' => 'https://dev.yoursitehulp.nl/yourwpsite',
            'agent_local_id' => '',
            'agent_secret' => '',
            'site_fingerprint' => '',
            'bootstrap_home_url' => '',
            'bootstrap_site_url' => '',
            'mode' => 'discovery',
            'site_id' => '',
            'agent_id' => '',
            'access_token' => '',
            'refresh_token' => '',
            'access_expires_at' => 0,
            'enabled_capabilities' => array(),
            'last_discovery_at' => '',
            'last_heartbeat_at' => '',
            'last_command_poll_at' => '',
            'last_error' => '',
            'last_http_code' => 0,
            'last_status_message' => '',
            'last_response_stage' => '',
            'last_response_excerpt' => '',
            'processed_commands' => array(),
            'recent_commands' => array(),
        );
    }

    public static function sanitize($input)
    {
        $current = self::get();
        $sanitized = $current;

        $sanitized['control_plane_base_url'] = self::sanitize_base_url($input['control_plane_base_url'] ?? $current['control_plane_base_url']);

        return $sanitized;
    }

    public static function ensure_bootstrap()
    {
        $settings = self::get();
        $updated = false;
        $current_home_url = home_url('/');
        $current_site_url = site_url('/');

        if (self::should_reset_for_legacy_snapshot($settings, $current_home_url, $current_site_url)) {
            $settings = self::reset_for_new_site($settings);
            $updated = true;
        } elseif (
            $settings['bootstrap_home_url'] !== ''
            && $settings['bootstrap_site_url'] !== ''
            && (
                untrailingslashit($settings['bootstrap_home_url']) !== untrailingslashit($current_home_url)
                || untrailingslashit($settings['bootstrap_site_url']) !== untrailingslashit($current_site_url)
            )
        ) {
            $settings = self::reset_for_new_site($settings);
            $updated = true;
        }

        if ($settings['agent_local_id'] === '') {
            $settings['agent_local_id'] = wp_generate_uuid4();
            $updated = true;
        }

        if ($settings['agent_secret'] === '') {
            $settings['agent_secret'] = self::random_token(32);
            $updated = true;
        }

        if ($settings['site_fingerprint'] === '') {
            $settings['site_fingerprint'] = self::generate_site_fingerprint($settings['agent_local_id']);
            $updated = true;
        }

        if ($settings['bootstrap_home_url'] === '' || $updated) {
            $settings['bootstrap_home_url'] = $current_home_url;
        }

        if ($settings['bootstrap_site_url'] === '' || $updated) {
            $settings['bootstrap_site_url'] = $current_site_url;
        }

        if ($updated || ! get_option(YourWPsite_Agent::OPTION_KEY)) {
            update_option(YourWPsite_Agent::OPTION_KEY, wp_parse_args($settings, self::defaults()), false);
        }
    }

    public static function get()
    {
        $settings = wp_parse_args(get_option(YourWPsite_Agent::OPTION_KEY, array()), self::defaults());
        $normalized_base_url = self::sanitize_base_url($settings['control_plane_base_url'] ?? '');

        if (($settings['control_plane_base_url'] ?? '') !== $normalized_base_url) {
            $settings['control_plane_base_url'] = $normalized_base_url;
            update_option(YourWPsite_Agent::OPTION_KEY, $settings, false);
        }

        return $settings;
    }

    public static function update($changes)
    {
        $settings = array_merge(self::get(), $changes);
        $settings['control_plane_base_url'] = self::sanitize_base_url($settings['control_plane_base_url'] ?? '');
        update_option(YourWPsite_Agent::OPTION_KEY, $settings, false);
    }

    public static function add_processed_command($command_id, $status)
    {
        $settings = self::get();
        $processed = is_array($settings['processed_commands']) ? $settings['processed_commands'] : array();

        $processed[$command_id] = array(
            'status' => $status,
            'time' => current_time('mysql', true),
        );

        if (count($processed) > 100) {
            $processed = array_slice($processed, -100, null, true);
        }

        $settings['processed_commands'] = $processed;
        update_option(YourWPsite_Agent::OPTION_KEY, $settings, false);
    }

    public static function add_recent_command($command_id, $capability, $status, $summary = '')
    {
        $settings = self::get();
        $recent = is_array($settings['recent_commands']) ? array_values($settings['recent_commands']) : array();

        array_unshift(
            $recent,
            array(
                'command_id' => sanitize_text_field($command_id),
                'capability' => sanitize_text_field($capability),
                'status' => sanitize_text_field($status),
                'summary' => sanitize_text_field($summary),
                'time' => current_time('mysql', true),
            )
        );

        $settings['recent_commands'] = array_slice($recent, 0, 10);
        update_option(YourWPsite_Agent::OPTION_KEY, $settings, false);
    }

    public static function has_processed_command($command_id)
    {
        $settings = self::get();
        return isset($settings['processed_commands'][$command_id]);
    }

    public static function set_error($message, $http_code = 0)
    {
        self::update(
            array(
                'last_error' => sanitize_text_field($message),
                'last_http_code' => $http_code > 0 ? absint($http_code) : self::get()['last_http_code'],
            )
        );
    }

    public static function clear_error()
    {
        self::update(
            array(
                'last_error' => '',
            )
        );
    }

    public static function remember_response($stage, $status_code, $body)
    {
        $excerpt = '';

        if (is_array($body)) {
            $excerpt = wp_json_encode($body);
        } elseif (is_string($body)) {
            $excerpt = $body;
        }

        self::update(
            array(
                'last_response_stage' => sanitize_text_field($stage),
                'last_http_code' => absint($status_code),
                'last_response_excerpt' => sanitize_textarea_field(substr((string) $excerpt, 0, 1000)),
            )
        );
    }

    public static function local_capabilities()
    {
        return array(
            'site.read_public_content_index',
            'site.export_structure',
            'content.create_page',
            'content.update_page',
            'content.create_post',
            'content.update_post',
            'content.trash_page',
            'content.trash_post',
            'media.upload_from_control_plane',
            'menu.upsert',
            'media.attach_featured_image',
        );
    }

    private static function sanitize_base_url($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return self::defaults()['control_plane_base_url'];
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return self::defaults()['control_plane_base_url'];
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            return self::defaults()['control_plane_base_url'];
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $is_local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);

        if ($scheme !== 'https' && ! $is_local) {
            return self::defaults()['control_plane_base_url'];
        }

        return untrailingslashit(esc_url_raw($url));
    }

    private static function generate_site_fingerprint($agent_local_id)
    {
        $material = implode(
            '|',
            array(
                home_url('/'),
                site_url('/'),
                ABSPATH,
                wp_salt('auth'),
                $agent_local_id,
            )
        );

        return 'sha256:' . hash('sha256', $material);
    }

    private static function random_token($bytes)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function reset_for_new_site($settings)
    {
        $settings['agent_local_id'] = '';
        $settings['agent_secret'] = '';
        $settings['site_fingerprint'] = '';
        $settings['mode'] = 'discovery';
        $settings['site_id'] = '';
        $settings['agent_id'] = '';
        $settings['access_token'] = '';
        $settings['refresh_token'] = '';
        $settings['access_expires_at'] = 0;
        $settings['enabled_capabilities'] = array();
        $settings['last_discovery_at'] = '';
        $settings['last_heartbeat_at'] = '';
        $settings['last_command_poll_at'] = '';
        $settings['last_error'] = '';
        $settings['last_http_code'] = 0;
        $settings['last_status_message'] = 'Agent identity regenerated for cloned site.';
        $settings['last_response_stage'] = '';
        $settings['last_response_excerpt'] = '';
        $settings['processed_commands'] = array();
        $settings['recent_commands'] = array();

        return $settings;
    }

    private static function should_reset_for_legacy_snapshot($settings, $current_home_url, $current_site_url)
    {
        if ($settings['bootstrap_home_url'] !== '' || $settings['bootstrap_site_url'] !== '') {
            return false;
        }

        if ($settings['agent_local_id'] === '') {
            return false;
        }

        if ($settings['mode'] === 'managed' && $settings['site_id'] !== '' && $settings['agent_id'] !== '') {
            return false;
        }

        $current_home_host = wp_parse_url($current_home_url, PHP_URL_HOST);
        $current_site_host = wp_parse_url($current_site_url, PHP_URL_HOST);

        if ($current_home_host === '' || $current_site_host === '') {
            return false;
        }

        return true;
    }
}
