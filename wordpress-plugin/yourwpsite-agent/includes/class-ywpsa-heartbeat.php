<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Heartbeat
{
    public static function run()
    {
        $settings = YWPSA_Settings::get();

        if ($settings['mode'] !== 'managed' || $settings['access_token'] === '' || $settings['site_id'] === '' || $settings['agent_id'] === '') {
            return;
        }

        $payload = array(
            'plugin_version' => YourWPsite_Agent::VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'health' => array(
                'mode' => 'managed',
                'last_command_at' => $settings['last_command_poll_at'],
                'can_write' => true,
            ),
        );

        $response = YWPSA_Http::post_json('/v1/agents/heartbeat', $payload, $settings['access_token']);

        if (is_wp_error($response)) {
            YWPSA_Settings::set_error($response->get_error_message());
            return;
        }

        $body = is_array($response['body']) ? $response['body'] : array();
        YWPSA_Settings::remember_response('heartbeat', $response['status_code'], $body);

        if (! self::is_success_status($response['status_code'])) {
            YWPSA_Settings::set_error(
                isset($body['message']) ? (string) $body['message'] : 'Heartbeat failed.',
                $response['status_code']
            );
            return;
        }

        YWPSA_Settings::update(
            array(
                'last_heartbeat_at' => current_time('mysql', true),
                'last_status_message' => 'Heartbeat accepted by control plane.',
            )
        );

        YWPSA_Settings::clear_error();
    }

    private static function is_success_status($status_code)
    {
        return $status_code >= 200 && $status_code < 300;
    }
}
