<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Command_Poller
{
    public static function run()
    {
        $settings = YWPSA_Settings::get();

        if ($settings['mode'] !== 'managed' || $settings['access_token'] === '') {
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
                case 'site.export_structure':
                    $result = self::export_structure();
                    break;
                case 'content.create_page':
                    $result = self::create_content($command['payload'] ?? array(), 'page');
                    break;
                case 'content.update_page':
                    $result = self::update_content($command['payload'] ?? array(), 'page');
                    break;
                case 'content.create_post':
                    $result = self::create_content($command['payload'] ?? array(), 'post');
                    break;
                case 'content.update_post':
                    $result = self::update_content($command['payload'] ?? array(), 'post');
                    break;
                case 'content.trash_page':
                    $result = self::trash_content($command['payload'] ?? array(), 'page');
                    break;
                case 'content.trash_post':
                    $result = self::trash_content($command['payload'] ?? array(), 'post');
                    break;
                case 'media.upload_from_control_plane':
                    $result = self::upload_media_from_control_plane($command['payload'] ?? array(), $settings);
                    break;
                case 'menu.upsert':
                    $result = self::upsert_menu($command['payload'] ?? array());
                    break;
                case 'media.attach_featured_image':
                    $result = self::attach_featured_image($command['payload'] ?? array());
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

    private static function export_structure()
    {
        $pages = self::fetch_structure_posts('page');
        $posts = self::fetch_structure_posts('post');
        $menus = self::fetch_structure_menus();

        return array(
            'pages' => $pages,
            'posts' => $posts,
            'menus' => $menus,
            'counts' => array(
                'pages' => count($pages),
                'posts' => count($posts),
                'menus' => count($menus),
            ),
        );
    }

    private static function create_content($payload, $post_type)
    {
        self::assert_supported_post_type($post_type);

        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $slug = sanitize_title((string) ($payload['slug'] ?? ''));
        $content = wp_kses_post((string) ($payload['content_html'] ?? ''));
        $status = self::sanitize_post_status((string) ($payload['status'] ?? 'draft'));

        if ($title === '') {
            throw new RuntimeException('Missing content title.');
        }

        $postarr = array(
            'post_type' => $post_type,
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
            'post_type' => $post_type,
            'preview_url' => get_preview_post_link($post_id),
        );
    }

    private static function update_content($payload, $post_type)
    {
        self::assert_supported_post_type($post_type);

        $id_key = $post_type === 'page' ? 'page_ref' : 'post_ref';
        $label = $post_type === 'page' ? 'page' : 'post';
        $post_id = absint($payload[$id_key]['id'] ?? 0);

        if ($post_id < 1) {
            throw new RuntimeException('Missing target ' . $label . ' ID.');
        }

        $post = get_post($post_id);

        if (! $post || $post->post_type !== $post_type) {
            throw new RuntimeException('Target ' . $label . ' not found.');
        }

        $postarr = array(
            'ID' => $post_id,
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
            $postarr['post_status'] = self::sanitize_post_status((string) $payload['status']);
        }

        $updated = wp_update_post($postarr, true);

        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        update_post_meta($post_id, '_ywpsa_managed', '1');

        $revisions = wp_get_post_revisions(
            $post_id,
            array(
                'posts_per_page' => 1,
            )
        );
        $latest_revision = $revisions ? reset($revisions) : null;

        return array(
            'post_id' => (int) $post_id,
            'post_type' => $post_type,
            'revision_id' => $latest_revision ? (int) $latest_revision->ID : 0,
            'preview_url' => get_preview_post_link($post_id),
        );
    }

    private static function trash_content($payload, $post_type)
    {
        self::assert_supported_post_type($post_type);

        $id_key = $post_type === 'page' ? 'page_ref' : 'post_ref';
        $label = $post_type === 'page' ? 'page' : 'post';
        $post_id = absint($payload[$id_key]['id'] ?? 0);

        if ($post_id < 1) {
            throw new RuntimeException('Missing target ' . $label . ' ID.');
        }

        $post = get_post($post_id);

        if (! $post || $post->post_type !== $post_type) {
            throw new RuntimeException('Target ' . $label . ' not found.');
        }

        $previous_status = $post->post_status;
        $trashed = wp_trash_post($post_id);

        if (! $trashed) {
            throw new RuntimeException('Failed to move content to trash.');
        }

        return array(
            'post_id' => (int) $post_id,
            'post_type' => $post_type,
            'previous_status' => $previous_status,
            'current_status' => 'trash',
        );
    }

    private static function upsert_menu($payload)
    {
        $menu_location = sanitize_key((string) ($payload['menu_location'] ?? ''));
        $items = isset($payload['items']) && is_array($payload['items']) ? array_values($payload['items']) : array();

        if ($menu_location === '') {
            throw new RuntimeException('Missing menu_location.');
        }

        if (empty($items)) {
            throw new RuntimeException('Missing menu items.');
        }

        $menu_id = self::ensure_menu_for_location($menu_location);
        $existing_items = wp_get_nav_menu_items($menu_id, array('post_status' => 'any'));

        if (is_array($existing_items)) {
            foreach ($existing_items as $existing_item) {
                wp_delete_post((int) $existing_item->ID, true);
            }
        }

        $created_items = array();
        $position = 1;

        foreach ($items as $item) {
            $item_type = sanitize_key((string) ($item['type'] ?? ''));

            if ($item_type !== 'page') {
                throw new RuntimeException('Only page menu items are supported in phase 3.');
            }

            $object_id = absint($item['object_id'] ?? 0);
            $page = get_post($object_id);

            if (! $page || $page->post_type !== 'page') {
                throw new RuntimeException('Menu item page not found: ' . $object_id);
            }

            $title = sanitize_text_field((string) ($item['title'] ?? get_the_title($page)));
            $menu_item_id = wp_update_nav_menu_item(
                $menu_id,
                0,
                array(
                    'menu-item-title' => $title,
                    'menu-item-object-id' => $object_id,
                    'menu-item-object' => 'page',
                    'menu-item-type' => 'post_type',
                    'menu-item-status' => 'publish',
                    'menu-item-position' => absint($item['position'] ?? $position),
                )
            );

            if (is_wp_error($menu_item_id) || ! $menu_item_id) {
                throw new RuntimeException('Failed to create menu item for page ' . $object_id);
            }

            $created_items[] = array(
                'menu_item_id' => (int) $menu_item_id,
                'object_id' => $object_id,
                'title' => $title,
            );
            $position++;
        }

        return array(
            'menu_id' => (int) $menu_id,
            'menu_location' => $menu_location,
            'items' => $created_items,
        );
    }

    private static function attach_featured_image($payload)
    {
        $post_id = absint($payload['post_ref']['id'] ?? 0);
        $attachment_id = absint($payload['attachment_id'] ?? 0);

        if ($post_id < 1 || $attachment_id < 1) {
            throw new RuntimeException('Missing post_ref.id or attachment_id.');
        }

        $post = get_post($post_id);
        $attachment = get_post($attachment_id);

        if (! $post) {
            throw new RuntimeException('Target post not found.');
        }

        if (! $attachment || $attachment->post_type !== 'attachment') {
            throw new RuntimeException('Attachment not found.');
        }

        $updated = set_post_thumbnail($post_id, $attachment_id);

        if (! $updated && get_post_thumbnail_id($post_id) !== $attachment_id) {
            throw new RuntimeException('Failed to attach featured image.');
        }

        return array(
            'post_id' => (int) $post_id,
            'attachment_id' => (int) $attachment_id,
            'featured_image_url' => wp_get_attachment_url($attachment_id),
        );
    }

    private static function upload_media_from_control_plane($payload, $settings)
    {
        $filename = sanitize_file_name((string) ($payload['filename'] ?? ''));
        $mime_type = sanitize_text_field((string) ($payload['mime_type'] ?? ''));
        $upload_token = sanitize_text_field((string) ($payload['upload_token'] ?? ''));
        $purpose = sanitize_key((string) ($payload['purpose'] ?? 'content_image'));
        $allowed_mime_types = array(
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        );

        if ($filename === '' || $mime_type === '' || $upload_token === '') {
            throw new RuntimeException('Missing filename, mime_type or upload_token.');
        }

        if (! in_array($mime_type, $allowed_mime_types, true)) {
            throw new RuntimeException('Unsupported upload mime type.');
        }

        $download = YWPSA_Http::download_file(
            '/v1/agents/media-download',
            array(
                'upload_token' => $upload_token,
            ),
            $settings['access_token']
        );

        if (is_wp_error($download)) {
            throw new RuntimeException($download->get_error_message());
        }

        if (! self::is_success_status($download['status_code'])) {
            if (! empty($download['file_path']) && file_exists($download['file_path'])) {
                wp_delete_file($download['file_path']);
            }

            throw new RuntimeException('Media download failed.');
        }

        $file_path = $download['file_path'];
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;

        if (! $file_size || $file_size < 1) {
            if (file_exists($file_path)) {
                wp_delete_file($file_path);
            }

            throw new RuntimeException('Downloaded media file is empty.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file_check = wp_check_filetype_and_ext($file_path, $filename, get_allowed_mime_types());
        $detected_mime_type = isset($file_check['type']) ? (string) $file_check['type'] : '';

        if ($detected_mime_type === '' || ! in_array($detected_mime_type, $allowed_mime_types, true)) {
            wp_delete_file($file_path);
            throw new RuntimeException('Downloaded media file failed type validation.');
        }

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $file_path,
            'type' => $detected_mime_type,
            'size' => $file_size,
            'error' => 0,
        );

        $attachment_id = media_handle_sideload(
            $file_array,
            0,
            null,
            array(
                'post_title' => pathinfo($filename, PATHINFO_FILENAME),
                'post_mime_type' => $detected_mime_type,
            )
        );

        if (is_wp_error($attachment_id)) {
            if (file_exists($file_path)) {
                wp_delete_file($file_path);
            }

            throw new RuntimeException($attachment_id->get_error_message());
        }

        update_post_meta((int) $attachment_id, '_ywpsa_managed', '1');
        update_post_meta((int) $attachment_id, '_ywpsa_upload_purpose', $purpose);

        return array(
            'attachment_id' => (int) $attachment_id,
            'filename' => $filename,
            'mime_type' => $detected_mime_type,
            'purpose' => $purpose,
            'url' => wp_get_attachment_url((int) $attachment_id),
        );
    }

    private static function sanitize_post_status($status)
    {
        $allowed = array('draft', 'publish', 'pending', 'private');
        $status = sanitize_key($status);

        if (! in_array($status, $allowed, true)) {
            return 'draft';
        }

        return $status;
    }

    private static function assert_supported_post_type($post_type)
    {
        if (! in_array($post_type, array('page', 'post'), true)) {
            throw new RuntimeException('Unsupported post type.');
        }
    }

    private static function fetch_structure_posts($post_type)
    {
        $posts = get_posts(
            array(
                'post_type' => $post_type,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'trash'),
                'posts_per_page' => 100,
                'orderby' => 'modified',
                'order' => 'DESC',
            )
        );

        $items = array();

        foreach ($posts as $post) {
            $items[] = array(
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'status' => $post->post_status,
                'modified_gmt' => get_post_field('post_modified_gmt', $post),
                'url' => get_permalink($post),
                'managed' => get_post_meta($post->ID, '_ywpsa_managed', true) === '1',
            );
        }

        return $items;
    }

    private static function fetch_structure_menus()
    {
        $locations = get_nav_menu_locations();
        $menus = array();

        foreach ($locations as $location => $menu_id) {
            $menu = wp_get_nav_menu_object($menu_id);
            if (! $menu) {
                continue;
            }

            $items = wp_get_nav_menu_items($menu_id) ?: array();
            $menu_items = array();

            foreach ($items as $item) {
                $menu_items[] = array(
                    'menu_item_id' => (int) $item->ID,
                    'title' => $item->title,
                    'object_id' => (int) $item->object_id,
                    'type' => $item->type,
                    'object' => $item->object,
                    'position' => (int) $item->menu_order,
                    'url' => $item->url,
                );
            }

            $menus[] = array(
                'menu_id' => (int) $menu_id,
                'location' => $location,
                'name' => $menu->name,
                'items' => $menu_items,
            );
        }

        return $menus;
    }

    private static function ensure_menu_for_location($location)
    {
        $locations = get_nav_menu_locations();
        $menu_id = isset($locations[$location]) ? absint($locations[$location]) : 0;

        if ($menu_id > 0) {
            return $menu_id;
        }

        $menu_name = 'yourWPsite ' . ucfirst(str_replace(array('-', '_'), ' ', $location));
        $menu_id = wp_create_nav_menu($menu_name);

        if (is_wp_error($menu_id) || ! $menu_id) {
            throw new RuntimeException('Failed to create menu for location ' . $location);
        }

        $locations[$location] = (int) $menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        return (int) $menu_id;
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

        if (isset($result['attachment_id'])) {
            return 'attachment_id=' . absint($result['attachment_id']);
        }

        if (isset($result['menu_id'])) {
            return 'menu_id=' . absint($result['menu_id']);
        }

        return implode(', ', array_slice(array_keys($result), 0, 3));
    }
}
