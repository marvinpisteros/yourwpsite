<?php
/**
 * Plugin Name: yourWPsite Agent
 * Plugin URI: https://dev.yoursitehulp.nl/yourwpsite
 * Description: Secure site agent for connecting a WordPress site to the yourWPsite control plane.
 * Version: 0.2.4
 * Author: yoursitehulp.nl
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-http.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-discovery.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-heartbeat.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-command-poller.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ywpsa-admin.php';

final class YourWPsite_Agent
{
    const VERSION = '0.2.4';
    const OPTION_KEY = 'ywpsa_settings';
    const DISCOVERY_HOOK = 'ywpsa_run_discovery';
    const HEARTBEAT_HOOK = 'ywpsa_run_heartbeat';
    const COMMAND_POLL_HOOK = 'ywpsa_run_command_poll';
    const ADMIN_ACTION_HOOK = 'ywpsa_admin_action';

    public static function init()
    {
        add_filter('cron_schedules', array(__CLASS__, 'register_schedules'));
        add_action('init', array('YWPSA_Discovery', 'maybe_run_on_request'));
        add_action('admin_init', array('YWPSA_Settings', 'register'));
        add_action('admin_menu', array('YWPSA_Admin', 'register_page'));
        add_action('admin_notices', array('YWPSA_Admin', 'render_notice'));
        add_action(self::DISCOVERY_HOOK, array('YWPSA_Discovery', 'run'));
        add_action(self::HEARTBEAT_HOOK, array('YWPSA_Heartbeat', 'run'));
        add_action(self::COMMAND_POLL_HOOK, array('YWPSA_Command_Poller', 'run'));
        add_action('admin_post_' . self::ADMIN_ACTION_HOOK, array('YWPSA_Admin', 'handle_action'));

        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    public static function activate()
    {
        YWPSA_Settings::ensure_bootstrap();
        self::schedule_events();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::DISCOVERY_HOOK);
        wp_clear_scheduled_hook(self::HEARTBEAT_HOOK);
        wp_clear_scheduled_hook(self::COMMAND_POLL_HOOK);
    }

    public static function register_schedules($schedules)
    {
        $schedules['ywpsa_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Every Minute', 'yourwpsite-agent'),
        );

        $schedules['ywpsa_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every Five Minutes', 'yourwpsite-agent'),
        );

        return $schedules;
    }

    public static function schedule_events()
    {
        if (! wp_next_scheduled(self::DISCOVERY_HOOK)) {
            wp_schedule_event(time() + 30, 'ywpsa_five_minutes', self::DISCOVERY_HOOK);
        }

        if (! wp_next_scheduled(self::HEARTBEAT_HOOK)) {
            wp_schedule_event(time() + 60, 'ywpsa_minute', self::HEARTBEAT_HOOK);
        }

        if (! wp_next_scheduled(self::COMMAND_POLL_HOOK)) {
            wp_schedule_event(time() + 75, 'ywpsa_minute', self::COMMAND_POLL_HOOK);
        }
    }
}

YourWPsite_Agent::init();
