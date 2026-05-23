<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Command_Poller
{
    public static function run()
    {
        $settings = YWPSA_Settings::get();

        if ($settings['mode'] !== 'managed' || $settings['access_token'] === '' || $settings['site_id'] === '' || $settings['agent_id'] === '') {
            return;
        }

        $response = YWPSA_Http::get_json(
            '/v1/agents/commands',
            array(
                'limit' => 10,
            ),
            $settings['access_token']
        );

        if (is_wp_error($response)) {
            YWPSA_Settings::set_error($response->get_error_message());
            return;
        }

        $body = is_array($response['body']) ? $response['body'] : array();
        YWPSA_Settings::remember_response('command_poll', $response['status_code'], $body);

        if (! self::is_success_status($response['status_code'])) {
            YWPSA_Settings::set_error(
                isset($body['message']) ? (string) $body['message'] : 'Command poll failed.',
                $response['status_code']
            );
            return;
        }

        $commands = isset($body['commands']) && is_array($body['commands']) ? $body['commands'] : array();

        YWPSA_Settings::update(
            array(
                'last_command_poll_at' => current_time('mysql', true),
                'last_status_message' => sprintf('Commands checked successfully. %d command(s) received.', count($commands)),
            )
        );

        YWPSA_Settings::clear_error();

        foreach ($commands as $command) {
            self::process_command($command, $settings);
        }
    }

    private static function process_command($command, $settings)
    {
        $command_id = sanitize_text_field((string) ($command['command_id'] ?? $command['id'] ?? ''));
        $capability = sanitize_text_field((string) ($command['capability'] ?? ''));

        if ($command_id === '' || $capability === '') {
            return;
        }

        if (YWPSA_Settings::has_processed_command($command_id)) {
            return;
        }

        $started_at = gmdate('c');
        $result = array();
        $status = 'failed';

        try {
            self::assert_not_expired($command);
            self::assert_capability_allowed($capability, $settings);

            switch ($capability) {
                case 'site.read_public_content_index':
                    $result = self::read_public_content_index();
                    break;
                case 'content.create_page':
                    $result = self::create_page($command['payload'] ?? array());
                    break;
                case 'content.update_page':
                    $result = self::update_page($command['payload'] ?? array());
                    break;
                default:
                    throw new RuntimeException('Unsupported capability: ' . $capability);
            }

            $status = 'succeeded';
        } catch (Throwable $throwable) {
            $result = array(
                'message' => $throwable->getMessage(),
            );
        }

        self::send_result($command_id, $status, $started_at, gmdate('c'), $result, $settings);
        YWPSA_Settings::add_processed_command($command_id, $status);
        YWPSA_Settings::add_recent_command(
            $command_id,
            $capability,
            $status,
            isset($result['message']) ? (string) $result['message'] : self::summarize_result($result)
        );
    }

    private static function send_result($command_id, $status, $started_at, $finished_at, $result, $settings)
    {
        $payload = array(
            'command_id' => $command_id,
            'status' => $status,
            'started_at' => $started_at,
            'finished_at' => $finished_at,
        );

        if ($status === 'succeeded') {
            $payload['result'] = $result;
        } else {
            $payload['error'] = $result;
        }

        $response = YWPSA_Http::post_json('/v1/agents/command-results', $payload, $settings['access_token']);

        if (is_wp_error($response)) {
            YWPSA_Settings::set_error($response->get_error_message());
            return;
        }

        $body = is_array($response['body']) ? $response['body'] : array();
        YWPSA_Settings::remember_response('command_result', $response['status_code'], $body);

        if (! self::is_success_status($response['status_code'])) {
            YWPSA_Settings::set_error(
                isset($body['message']) ? (string) $body['message'] : 'Command result submit failed.',
                $response['status_code']
            );
        }
    }

    private static function assert_not_expired($command)
    {
        $expires_at = isset($command['expires_at']) ? strtotime((string) $command['expires_at']) : 0;

        if ($expires_at > 0 && $expires_at < time()) {
            throw new RuntimeException('Command expired before execution.');
        }
    }

    private static function assert_capability_allowed($capability, $settings)
    {
        $local_capabilities = YWPSA_Settings::local_capabilities();
        $enabled_capabilities = is_array($settings['enabled_capabilities']) ? $settings['enabled_capabilities'] : array();

        if (! in_array($capability, $local_capabilities, true)) {
            throw new RuntimeException('Capability is not implemented locally.');
        }

        if (! in_array($capability, $enabled_capabilities, true)) {
            throw new RuntimeException('Capability is not enabled by policy.');
        }
    }

    private static function read_public_content_index()
    {
        $posts = get_posts(
            array(
                'post_type' => array('page', 'post'),
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'modified',
                'order' => 'DESC',
            )
        );

        $items = array();

        foreach ($posts as $post) {
            $items[] = array(
                'id' => (int) $post->ID,
                'post_type' => $post->post_type,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'modified_gmt' => get_post_field('post_modified_gmt', $post),
                'url' => get_permalink($post),
            );
        }

        return array(
            'items' => $items,
            'count' => count($items),
        );
    }

    private static function create_page($payload)
    {
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $slug = sanitize_title((string) ($payload['slug'] ?? ''));
        $content = wp_kses_post((string) ($payload['content_html'] ?? ''));
        $status = self::sanitize_page_status((string) ($payload['status'] ?? 'draft'));

        if ($title === '') {
            throw new RuntimeException('Missing page title.');
        }

        $postarr = array(
            'post_type' => 'page',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_status' => $status,
            'meta_input' => array(
                '_ywpsa_managed' => '1',
            ),
        );

        $post_id = wp_insert_post($postarr, true);

        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }

        return array(
            'post_id' => (int) $post_id,
            'preview_url' => get_preview_post_link($post_id),
        );
    }

    private static function update_page($payload)
    {
        $page_id = absint($payload['page_ref']['id'] ?? 0);

        if ($page_id < 1) {
            throw new RuntimeException('Missing target page ID.');
        }

        $post = get_post($page_id);

        if (! $post || $post->post_type !== 'page') {
            throw new RuntimeException('Target page not found.');
        }

        $postarr = array(
            'ID' => $page_id,
        );

        if (isset($payload['title'])) {
            $postarr['post_title'] = sanitize_text_field((string) $payload['title']);
        }

        if (isset($payload['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $payload['slug']);
        }

        if (isset($payload['content_html'])) {
            $postarr['post_content'] = wp_kses_post((string) $payload['content_html']);
        }

        if (isset($payload['status'])) {
            $postarr['post_status'] = self::sanitize_page_status((string) $payload['status']);
        }

        $updated = wp_update_post($postarr, true);

        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        update_post_meta($page_id, '_ywpsa_managed', '1');

        $revisions = wp_get_post_revisions(
            $page_id,
            array(
                'posts_per_page' => 1,
            )
        );
        $latest_revision = $revisions ? reset($revisions) : null;

        return array(
            'post_id' => (int) $page_id,
            'revision_id' => $latest_revision ? (int) $latest_revision->ID : 0,
            'preview_url' => get_preview_post_link($page_id),
        );
    }

    private static function sanitize_page_status($status)
    {
        $allowed = array('draft', 'publish', 'pending', 'private');
        $status = sanitize_key($status);

        if (! in_array($status, $allowed, true)) {
            return 'draft';
        }

        return $status;
    }

    private static function is_success_status($status_code)
    {
        return $status_code >= 200 && $status_code < 300;
    }

    private static function summarize_result($result)
    {
        if (! is_array($result)) {
            return '';
        }

        if (isset($result['post_id'])) {
            return 'post_id=' . absint($result['post_id']);
        }

        if (isset($result['count'])) {
            return 'count=' . absint($result['count']);
        }

        return implode(', ', array_slice(array_keys($result), 0, 3));
    }
}
