<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Http
{
    public static function post_json($path, $payload, $token = '')
    {
        return self::request('POST', $path, $payload, $token);
    }

    public static function get_json($path, $query = array(), $token = '')
    {
        return self::request('GET', $path, $query, $token);
    }

    private static function request($method, $path, $payload, $token)
    {
        $settings = YWPSA_Settings::get();
        $base_url = untrailingslashit($settings['control_plane_base_url']);

        if ($base_url === '') {
            return new WP_Error('ywpsa_missing_base_url', 'Missing control plane base URL.');
        }

        $url = $base_url . '/' . ltrim($path, '/');
        $args = array(
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        );

        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        if ($method === 'GET') {
            if (! empty($payload)) {
                $url = add_query_arg($payload, $url);
            }
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
        }

        return array(
            'status_code' => (int) wp_remote_retrieve_response_code($response),
            'body' => $decoded,
            'raw_body' => $body,
        );
    }
}
