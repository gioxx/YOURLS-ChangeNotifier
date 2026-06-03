<?php
/*
Plugin Name: YOURLS Change Notifier
Plugin URI: https://github.com/gioxx/YOURLS-ChangeNotifier
Description: Send email notifications when a short URL is created, edited, or deleted.
Version: 1.3.0
Author: Gioxx
Author URI: https://gioxx.org
Text Domain: yourls-change-notifier
Domain Path: /languages
*/

if (!defined('YOURLS_ABSPATH')) die();

define('YNM_VERSION',    '1.3.0');
define('YNM_OPT_KEY',    'yn_change_notifier_settings');
define('YNM_AUTH_OPT_KEY','yn_change_notifier_auth_sessions');
define('YNM_DOMAIN',     'yourls-change-notifier');
define('YNM_SNAP_EDIT',  'ynm__last_edit_snapshot');
define('YNM_SNAP_DEL',   'ynm__last_delete_snapshot');

$ynm_inc = dirname(__FILE__) . '/inc/';
require_once $ynm_inc . 'update-check.php';
require_once $ynm_inc . 'class-yn-notify-mail.php';

yourls_add_filter('plugin_page_title_yn-change-notifier', 'ynm_page_title_with_badge');

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

function ynm_render_plugin_page() {
    if ($GLOBALS['__ynm_instance']) {
        $GLOBALS['__ynm_instance']->render_admin_page();
    } else {
        echo '<p>'.yourls__('Plugin not initialized.', YNM_DOMAIN).'</p>';
    }
}

function ynm_render_footer(): string {
    $html  = '<div class="plugin-footer">';
    $html .= '<div class="plugin-footer-top">';
    $html .= '<span>';
    $html .= '<a href="https://yourls.gioxx.org/plugins/change-notifier" target="_blank" rel="noopener noreferrer">🔔 YOURLS Change Notifier</a>';
    $html .= ' &nbsp;·&nbsp; ';
    $html .= '<a href="https://github.com/gioxx/YOURLS-ChangeNotifier" target="_blank" rel="noopener noreferrer">';
    $html .= '<img src="https://github.githubassets.com/favicons/favicon.png" class="github-icon" alt="GitHub Icon" />GitHub</a>';
    $html .= '</span>';
    $html .= '<a href="#" onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;">&#8593; Back to top</a>';
    $html .= '</div>';
    $html .= '❤️ Lovingly developed by the usually-on-vacation brain cell of ';
    $html .= '<a href="https://github.com/gioxx" target="_blank" rel="noopener noreferrer">Gioxx</a> - <a href="https://gioxx.org" target="_blank" rel="noopener noreferrer">Gioxx\'s Wall</a>';
    $html .= '</div>';
    return $html;
}
