<?php

if (! defined('ABSPATH')) {
    exit;
}

final class YWPSA_Admin
{
    public static function register_page()
    {
        add_options_page(
            'yourWPsite Agent',
            'yourWPsite Agent',
            'manage_options',
            'ywpsa-agent',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = YWPSA_Settings::get();
        ?>
        <div class="wrap">
            <h1>yourWPsite Agent</h1>
            <p>Deze plugin koppelt deze WordPress-site aan de yourWPsite control plane.</p>

            <form method="post" action="options.php">
                <?php settings_fields('ywpsa_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ywpsa-control-plane-base-url">Control plane base URL</label></th>
                        <td>
                            <input
                                id="ywpsa-control-plane-base-url"
                                type="url"
                                class="regular-text code"
                                name="<?php echo esc_attr(YourWPsite_Agent::OPTION_KEY); ?>[control_plane_base_url]"
                                value="<?php echo esc_attr($settings['control_plane_base_url']); ?>"
                                placeholder="https://dev.yoursitehulp.nl/yourwpsite"
                            />
                            <p class="description">Gebruik de basis-URL van de centrale applicatie.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Instellingen opslaan'); ?>
            </form>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width: 920px;">
                <tbody>
                    <?php self::render_status_row('Mode', $settings['mode']); ?>
                    <?php self::render_status_row('Agent local ID', $settings['agent_local_id']); ?>
                    <?php self::render_status_row('Site ID', $settings['site_id']); ?>
                    <?php self::render_status_row('Agent ID', $settings['agent_id']); ?>
                    <?php self::render_status_row('Site fingerprint', $settings['site_fingerprint']); ?>
                    <?php self::render_status_row('Enabled capabilities', implode(', ', (array) $settings['enabled_capabilities'])); ?>
                    <?php self::render_status_row('Last discovery', $settings['last_discovery_at']); ?>
                    <?php self::render_status_row('Last heartbeat', $settings['last_heartbeat_at']); ?>
                    <?php self::render_status_row('Last command poll', $settings['last_command_poll_at']); ?>
                    <?php self::render_status_row('Last status', $settings['last_status_message']); ?>
                    <?php self::render_status_row('Last error', $settings['last_error']); ?>
                    <?php self::render_status_row('Last response stage', $settings['last_response_stage']); ?>
                    <?php self::render_status_row('Last HTTP code', (string) $settings['last_http_code']); ?>
                </tbody>
            </table>

            <h2>Last Response</h2>
            <textarea readonly rows="8" class="large-text code" style="max-width: 920px;"><?php echo esc_textarea($settings['last_response_excerpt']); ?></textarea>

            <h2>Recent Commands</h2>
            <?php if (! empty($settings['recent_commands']) && is_array($settings['recent_commands'])) : ?>
                <table class="widefat striped" style="max-width: 920px;">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Command</th>
                            <th>Capability</th>
                            <th>Status</th>
                            <th>Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings['recent_commands'] as $item) : ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($item['time'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($item['command_id'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($item['capability'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($item['status'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($item['summary'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Nog geen commands uitgevoerd.</p>
            <?php endif; ?>

            <h2>Acties</h2>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(self::action_url('discover')); ?>">Run discovery now</a>
                <a class="button button-secondary" href="<?php echo esc_url(self::action_url('sync')); ?>">Run heartbeat + command poll</a>
                <a class="button button-secondary" href="<?php echo esc_url(self::action_url('reset')); ?>">Reset to discovery mode</a>
            </p>
        </div>
        <?php
    }

    public static function handle_action()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('ywpsa_admin_action');

        $action = sanitize_key((string) ($_GET['agent_action'] ?? ''));

        switch ($action) {
            case 'discover':
                YWPSA_Discovery::run();
                break;
            case 'sync':
                YWPSA_Heartbeat::run();
                YWPSA_Command_Poller::run();
                break;
            case 'reset':
                YWPSA_Settings::update(
                    array(
                        'mode' => 'discovery',
                        'site_id' => '',
                        'agent_id' => '',
                        'access_token' => '',
                        'refresh_token' => '',
                        'access_expires_at' => 0,
                        'enabled_capabilities' => array(),
                        'processed_commands' => array(),
                        'recent_commands' => array(),
                        'last_response_stage' => '',
                        'last_response_excerpt' => '',
                        'last_status_message' => 'Agent reset to discovery mode.',
                    )
                );
                YWPSA_Settings::clear_error();
                break;
        }

        wp_safe_redirect(admin_url('options-general.php?page=ywpsa-agent'));
        exit;
    }

    public static function render_notice()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = YWPSA_Settings::get();

        if ($settings['last_error'] === '') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>yourWPsite Agent:</strong> %s</p></div>',
            esc_html($settings['last_error'])
        );
    }

    private static function render_status_row($label, $value)
    {
        printf(
            '<tr><th style="width: 220px;">%s</th><td><code>%s</code></td></tr>',
            esc_html($label),
            esc_html((string) $value)
        );
    }

    private static function action_url($action)
    {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => YourWPsite_Agent::ADMIN_ACTION_HOOK,
                    'agent_action' => $action,
                ),
                admin_url('admin-post.php')
            ),
            'ywpsa_admin_action'
        );
    }
}
