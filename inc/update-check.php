<?php
if (!defined('YOURLS_ABSPATH')) die();

define('YNM_GITHUB_OWNER',    'gioxx');
define('YNM_GITHUB_REPO',     'YOURLS-ChangeNotifier');
define('YNM_GITHUB_REPO_URL', 'https://github.com/gioxx/YOURLS-ChangeNotifier');

function ynm_get_update_info(): array {
    static $memo = null;
    if ($memo !== null) return $memo;

    $cache_key = 'ynm_update_cache';
    $cached    = yourls_get_option($cache_key);
    if (is_array($cached) && isset($cached['checked_at']) && (time() - (int)$cached['checked_at']) < 43200) {
        $memo = $cached;
        return $memo;
    }

    $api_url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', YNM_GITHUB_OWNER, YNM_GITHUB_REPO);
    $ctx     = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'User-Agent: YOURLS-ChangeNotifier/' . YNM_VERSION . "\r\n",
        'timeout' => 2,
    ]]);

    $body = @file_get_contents($api_url, false, $ctx);
    $info = ['checked_at' => time(), 'latest_version' => '', 'update_available' => false];

    if (!$body) {
        $memo = $info;
        return $memo;
    }

    $data = json_decode($body, true);
    if (is_array($data) && isset($data['tag_name'])) {
        $latest = ltrim((string)$data['tag_name'], 'v');
        $info['latest_version']   = $latest;
        $info['update_available'] = version_compare($latest, YNM_VERSION, '>');
    }

    yourls_update_option($cache_key, $info);
    $memo = $info;
    return $memo;
}

function ynm_show_update_notice(): void {
    $info = ynm_get_update_info();
    if (empty($info['update_available'])) return;

    $url = YNM_GITHUB_REPO_URL . '/releases/latest';
    echo '<div class="notice notice-info ynm-update-notice">';
    echo '🔔 ' . yourls__('A new version of YOURLS Change Notifier is available: ', YNM_DOMAIN);
    echo '<strong>' . yourls_esc_html($info['latest_version']) . '</strong>. ';
    echo '<a href="' . yourls_esc_attr($url) . '" target="_blank" rel="noopener noreferrer">';
    echo yourls__('View details on GitHub', YNM_DOMAIN);
    echo '</a>.';
    echo '</div>';
}

function ynm_page_title_with_badge(string $title): string {
    $info = ynm_get_update_info();
    if (!empty($info['update_available'])) {
        $title .= ' <span class="ynm-update-badge">' . yourls_esc_html($info['latest_version']) . '</span>';
    }
    return $title;
}
