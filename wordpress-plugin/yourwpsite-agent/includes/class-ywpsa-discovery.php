<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Discovery
{
    const REQUEST_LOCK_KEY = 'ywpsa_discovery_request_lock';

    public static function maybe_run_on_request()
    {
        if (wp_doing_cron() || wp_doing_ajax()) {
            return;
        }

        YWPSA_Settings::ensure_bootstrap();

        $settings = YWPSA_Settings::get();

        if ($settings['mode'] === 'managed' && $settings['site_id'] !== '' && $settings['agent_id'] !== '') {
            return;
        }

        $last_discovery = $settings['last_discovery_at'] ? strtotime((string) $settings['last_discovery_at'] . ' UTC') : 0;

        if ($last_discovery > 0 && (time() - $last_discovery) < 300) {
            return;
        }

        if (get_transient(self::REQUEST_LOCK_KEY)) {
            return;
        }

        set_transient(self::REQUEST_LOCK_KEY, 1, MINUTE_IN_SECONDS);
        self::run();
        delete_transient(self::REQUEST_LOCK_KEY);
    }

    public static function run()
    {
        YWPSA_Settings::ensure_bootstrap();

        $settings = YWPSA_Settings::get();

        if ($settings['mode'] === 'managed' && $settings['site_id'] !== '' && $settings['agent_id'] !== '') {
            return;
        }

        $payload = array(
            'agent_local_id' => $settings['agent_local_id'],
            'home_url' => home_url('/'),
            'site_url' => site_url('/'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => YourWPsite_Agent::VERSION,
            'theme_slug' => wp_get_theme()->get_stylesheet(),
            'capabilities' => YWPSA_Settings::local_capabilities(),
            'site_fingerprint' => $settings['site_fingerprint'],
        );

        $response = YWPSA_Http::post_json('/v1/agents/discover', $payload);

        if (is_wp_error($response)) {
            YWPSA_Settings::set_error($response->get_error_message());
            return;
        }

        $body = is_array($response['body']) ? $response['body'] : array();

        YWPSA_Settings::update(
            array(
                'last_discovery_at' => current_time('mysql', true),
            )
        );
        YWPSA_Settings::remember_response('discover', $response['status_code'], $body);

        if (! self::is_success_status($response['status_code'])) {
            YWPSA_Settings::set_error(
                isset($body['message']) ? (string) $body['message'] : 'Discovery request failed.',
                $response['status_code']
            );
            return;
        }

        $status = isset($body['status']) ? (string) $body['status'] : '';

        if ($status === 'claimed') {
            $policy = isset($body['policy']['enabled_capabilities']) && is_array($body['policy']['enabled_capabilities'])
                ? array_values(array_map('sanitize_text_field', $body['policy']['enabled_capabilities']))
                : array();

            YWPSA_Settings::update(
                array(
                    'mode' => 'managed',
                    'site_id' => sanitize_text_field((string) ($body['site_id'] ?? '')),
                    'agent_id' => sanitize_text_field((string) ($body['agent_id'] ?? '')),
                    'access_token' => sanitize_text_field((string) ($body['access_token'] ?? '')),
                    'refresh_token' => sanitize_text_field((string) ($body['refresh_token'] ?? '')),
                    'access_expires_at' => time() + absint($body['expires_in'] ?? 0),
                    'enabled_capabilities' => $policy,
                    'last_status_message' => 'Agent claimed by control plane.',
                )
            );
            YWPSA_Settings::clear_error();
            return;
        }

        if ($status === 'discovered_unclaimed') {
            YWPSA_Settings::update(
                array(
                    'mode' => 'discovery',
                    'last_status_message' => 'Agent discovered and waiting to be claimed.',
                )
            );
            YWPSA_Settings::clear_error();
            return;
        }

        YWPSA_Settings::set_error('Unexpected discovery response.', $response['status_code']);
    }

    private static function is_success_status($status_code)
    {
        return $status_code >= 200 && $status_code < 300;
    }
}
