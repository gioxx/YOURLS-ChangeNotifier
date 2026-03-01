<?php
/*
Plugin Name: YOURLS Change Notifier
Plugin URI: https://github.com/gioxx/YOURLS-ChangeNotifier
Description: Send email notifications when a short URL is created, edited, or deleted.
Version: 1.2.0
Author: Gioxx
Author URI: https://gioxx.org
Text Domain: yourls-change-notifier
Domain Path: /languages
*/

if (!defined('YOURLS_ABSPATH')) die();

define('YNM_VERSION',    '1.2.0');
define('YNM_OPT_KEY',    'yn_change_notifier_settings');
define('YNM_AUTH_OPT_KEY','yn_change_notifier_auth_sessions');
define('YNM_DOMAIN',     'yourls-change-notifier');
define('YNM_SNAP_EDIT',  'ynm__last_edit_snapshot');
define('YNM_SNAP_DEL',   'ynm__last_delete_snapshot');

// -----------------------------------------------------------------------------
// Bootstrap: register admin page and initialize plugin
// -----------------------------------------------------------------------------

$GLOBALS['__ynm_instance'] = null;

yourls_add_action('plugins_loaded', 'ynm_boot');
function ynm_boot() {
    $GLOBALS['__ynm_instance'] = new YN_Notify_Mail();

    yourls_register_plugin_page(
        'yn-change-notifier',
        yourls__('Manage notifications', YNM_DOMAIN),
        'ynm_render_plugin_page'
    );

    ynm_load_textdomain();
}

function ynm_load_textdomain() {
    $locale = yourls_get_locale();
    $path   = dirname(__FILE__) . '/languages/';
    $mo     = $path . YNM_DOMAIN . '-' . $locale . '.mo';
    $po     = $path . YNM_DOMAIN . '-' . $locale . '.po';
    if (file_exists($mo)) {
        yourls_load_textdomain(YNM_DOMAIN, $mo);
    } elseif (file_exists($po)) {
        yourls_load_textdomain(YNM_DOMAIN, $po);
    }
}

// Plugin page renderer wrapper
function ynm_render_plugin_page() {
    if ($GLOBALS['__ynm_instance']) {
        $GLOBALS['__ynm_instance']->render_admin_page();
    } else {
        echo '<p>'.yourls__('Plugin not initialized.', YNM_DOMAIN).'</p>';
    }
}

// Footer HTML for admin page
function ynm_render_footer(): string {
    $html  = '<div class="plugin-footer">';
    $html .= '<a href="https://github.com/gioxx/YOURLS-ChangeNotifier" target="_blank" rel="noopener noreferrer">';
    $html .= '<img src="https://github.githubassets.com/favicons/favicon.png" class="github-icon" alt="GitHub Icon" />';
    $html .= 'YOURLS Change Notifier</a><br>';
    $html .= '❤️ Lovingly developed by the usually-on-vacation brain cell of ';
    $html .= '<a href="https://github.com/gioxx" target="_blank" rel="noopener noreferrer">Gioxx</a> - <a href="https://gioxx.org" target="_blank" rel="noopener noreferrer">Gioxx\'s Wall</a>';
    $html .= '</div>';
    return $html;
}

// -----------------------------------------------------------------------------
// Main plugin class
// -----------------------------------------------------------------------------
require_once dirname(__FILE__) . '/inc/class-yn-notify-mail.php';
