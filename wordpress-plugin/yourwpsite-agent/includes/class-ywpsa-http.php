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

    public static function download_file($path, $query = array(), $token = '')
    {
        $url = self::build_url($path, $query);

        if (is_wp_error($url)) {
            return $url;
        }

        self::ensure_file_functions_loaded();

        $temp_file = wp_tempnam('ywpsa-download');

        if (! $temp_file) {
            return new WP_Error('ywpsa_temp_file_failed', 'Failed to allocate a temporary download file.');
        }

        $args = array(
            'method' => 'GET',
            'timeout' => 60,
            'redirection' => 0,
            'sslverify' => true,
            'stream' => true,
            'filename' => $temp_file,
            'headers' => array(
                'Accept' => '*/*',
            ),
        );

        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if (file_exists($temp_file)) {
                wp_delete_file($temp_file);
            }

            return $response;
        }

        return array(
            'status_code' => (int) wp_remote_retrieve_response_code($response),
            'headers' => wp_remote_retrieve_headers($response),
            'file_path' => $temp_file,
        );
    }

    private static function request($method, $path, $payload, $token)
    {
        $url = self::build_url($path, $method === 'GET' ? $payload : array());

        if (is_wp_error($url)) {
            return $url;
        }

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

        if ($method !== 'GET') {
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

    private static function build_url($path, $query = array())
    {
        $settings = YWPSA_Settings::get();
        $base_url = untrailingslashit($settings['control_plane_base_url']);

        if ($base_url === '') {
            return new WP_Error('ywpsa_missing_base_url', 'Missing control plane base URL.');
        }

        $url = $base_url . '/' . ltrim($path, '/');

        if (! empty($query)) {
            $url = add_query_arg($query, $url);
        }

        return $url;
    }

    private static function ensure_file_functions_loaded()
    {
        if (! function_exists('wp_tempnam') || ! function_exists('wp_delete_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }
}
