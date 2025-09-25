<?php
/*
Plugin Name: YOURLS Change Notifier
Plugin URI: https://github.com/gioxx/YOURLS-ChangeNotifier
Description: Send email notifications when a short URL is created, edited, or deleted.
Version: 1.1.0
Author: Gioxx
Author URI: https://gioxx.org
Text Domain: yourls-change-notifier
Domain Path: /languages
*/

if (!defined('YOURLS_ABSPATH')) die();

define('YNM_VERSION',    '1.1.0');
define('YNM_OPT_KEY',    'yn_change_notifier_settings');
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
        yourls__('YOURLS Change Notifier', YNM_DOMAIN),
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

class YN_Notify_Mail {
    public function __construct() {
        // Register lifecycle hooks
        yourls_add_filter('add_new_link',  [$this, 'on_created_filter'], 99, 5);
        yourls_add_action('pre_edit_link', [$this, 'on_edit_pre'],       10, 5);
        yourls_add_filter('edit_link',     [$this, 'on_edit_filter'],    10, 8);
        yourls_add_action('admin_init',    [$this, 'capture_delete_on_admin_init']);
        yourls_add_action('delete_link',   [$this, 'on_delete_direct'], 10, 1);
    }

    /* =========================
       Settings and admin page
       ========================= */

    public static function defaults(): array {
        return [
            'recipients'     => '',
            'notify_create'  => 1,
            'notify_edit'    => 1,
            'notify_delete'  => 1,
            'subject_prefix' => '[YOURLS]',
            'debug_enabled'  => 0,
            'admin_password' => '',  // Admin password for settings access
            'use_smtp'       => 0,
            'smtp_host'      => '',
            'smtp_port'      => 587,
            'smtp_security'  => 'tls',  // none, ssl, tls
            'smtp_auth'      => 1,
            'smtp_username'  => '',
            'smtp_password'  => '',
            'smtp_from_email'=> '',
            'smtp_from_name' => 'YOURLS Change Notifier',
        ];
    }

    public static function get_settings(): array {
        $opt = yourls_get_option(YNM_OPT_KEY);
        if (!is_array($opt)) $opt = [];
        return array_merge(self::defaults(), $opt);
    }

    public static function save_settings(array $in): void {
        $s = [
            'recipients'     => trim((string)($in['recipients'] ?? '')),
            'notify_create'  => empty($in['notify_create']) ? 0 : 1,
            'notify_edit'    => empty($in['notify_edit']) ? 0 : 1,
            'notify_delete'  => empty($in['notify_delete']) ? 0 : 1,
            'subject_prefix' => trim((string)($in['subject_prefix'] ?? '[YOURLS]')),
            'debug_enabled'  => empty($in['debug_enabled']) ? 0 : 1,
            'use_smtp'       => empty($in['use_smtp']) ? 0 : 1,
            'smtp_host'      => trim((string)($in['smtp_host'] ?? '')),
            'smtp_port'      => max(1, min(65535, (int)($in['smtp_port'] ?? 587))),
            'smtp_security'  => in_array($in['smtp_security'] ?? '', ['none', 'ssl', 'tls']) ? $in['smtp_security'] : 'tls',
            'smtp_auth'      => empty($in['smtp_auth']) ? 0 : 1,
            'smtp_username'  => trim((string)($in['smtp_username'] ?? '')),
            'smtp_from_email'=> trim((string)($in['smtp_from_email'] ?? '')),
            'smtp_from_name' => trim((string)($in['smtp_from_name'] ?? 'YOURLS Change Notifier')),
        ];
        
        // Handle SMTP password separately (only update if provided)
        if (!empty($in['smtp_password'])) {
            $s['smtp_password'] = base64_encode($in['smtp_password']); // Simple encoding for storage
        } else {
            // Keep existing SMTP password
            $current = self::get_settings();
            $s['smtp_password'] = $current['smtp_password'];
        }
        
        // Only update admin password if a new one is provided
        if (!empty($in['admin_password'])) {
            $s['admin_password'] = password_hash($in['admin_password'], PASSWORD_DEFAULT);
        } else {
            // Keep existing admin password
            $current = self::get_settings();
            $s['admin_password'] = $current['admin_password'];
        }
        
        yourls_update_option(YNM_OPT_KEY, $s);
    }

    // Check if user is authenticated to access settings
    private function is_authenticated(): bool {
        $settings = self::get_settings();
        
        // If no password is set, require initial setup
        if (empty($settings['admin_password'])) {
            return false;
        }
        
        // Check session authentication
        if (isset($_SESSION['ynm_authenticated']) && $_SESSION['ynm_authenticated'] === true) {
            return true;
        }
        
        return false;
    }

    // Verify provided password against stored hash
    private function verify_password(string $password): bool {
        $settings = self::get_settings();
        if (empty($settings['admin_password'])) {
            return false;
        }
        return password_verify($password, $settings['admin_password']);
    }

    public function render_admin_page(): void {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $settings = self::get_settings();
        $needs_setup = empty($settings['admin_password']);
        $is_authenticated = $this->is_authenticated();

        // Handle authentication and setup
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['ynm_action'])) {
                if ($_POST['ynm_action'] === 'setup' && $needs_setup && yourls_verify_nonce('ynm_setup')) {
                    // Initial setup - set password
                    $password = trim($_POST['admin_password'] ?? '');
                    if (strlen($password) >= 6) {
                        $setup_settings = [
                            'admin_password' => password_hash($password, PASSWORD_DEFAULT)
                        ];
                        yourls_update_option(YNM_OPT_KEY, array_merge($settings, $setup_settings));
                        $_SESSION['ynm_authenticated'] = true;
                        $message = yourls__('Setup completed! You can now configure the plugin.', YNM_DOMAIN);
                        $result = ['success' => true];
                        $needs_setup = false;
                        $is_authenticated = true;
                    } else {
                        $message = yourls__('Password must be at least 6 characters long.', YNM_DOMAIN);
                        $result = ['success' => false];
                    }
                } elseif ($_POST['ynm_action'] === 'login' && !$needs_setup && yourls_verify_nonce('ynm_login')) {
                    // Login attempt
                    $password = trim($_POST['admin_password'] ?? '');
                    if ($this->verify_password($password)) {
                        $_SESSION['ynm_authenticated'] = true;
                        $is_authenticated = true;
                        $message = yourls__('Access granted.', YNM_DOMAIN);
                        $result = ['success' => true];
                    } else {
                        $message = yourls__('Invalid password.', YNM_DOMAIN);
                        $result = ['success' => false];
                    }
                } elseif ($_POST['ynm_action'] === 'logout') {
                    // Logout
                    $_SESSION['ynm_authenticated'] = false;
                    unset($_SESSION['ynm_authenticated']);
                    $is_authenticated = false;
                    $message = yourls__('Logged out successfully.', YNM_DOMAIN);
                    $result = ['success' => true];
                }
            }
        }

        // Handle other actions only if authenticated
        if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ynm_action'])) {
            if ($_POST['ynm_action'] === 'save' && yourls_verify_nonce('ynm_save')) {
                self::save_settings($_POST);
                $result = ['success' => true];
                $message = yourls__('Settings saved.', YNM_DOMAIN);
            } elseif ($_POST['ynm_action'] === 'test' && yourls_verify_nonce('ynm_save')) {
                $msg = $this->send_test();
                $result = ['success' => $msg['ok']];
                $message = $msg['text'];
            } elseif ($_POST['ynm_action'] === 'reset' && yourls_verify_nonce('ynm_reset')) {
                // Reset all settings to defaults (but keep admin password)
                $current_settings = self::get_settings();
                $defaults = self::defaults();
                
                // Preserve admin password
                $defaults['admin_password'] = $current_settings['admin_password'];
                
                yourls_update_option(YNM_OPT_KEY, $defaults);
                $result = ['success' => true];
                $message = yourls__('All settings have been reset to defaults. Admin password was preserved.', YNM_DOMAIN);
                
                // Refresh settings for display
                $s = self::get_settings();
            } elseif ($_POST['ynm_action'] === 'reset_password' && yourls_verify_nonce('ynm_reset_password')) {
                // Reset admin password - clear it completely
                $current_settings = self::get_settings();
                $current_settings['admin_password'] = '';
                
                yourls_update_option(YNM_OPT_KEY, $current_settings);
                
                // Clear authentication session
                $_SESSION['ynm_authenticated'] = false;
                unset($_SESSION['ynm_authenticated']);
                
                $result = ['success' => true];
                $message = yourls__('Admin password has been reset. You will need to set it up again on next page load.', YNM_DOMAIN);
                
                // Force a redirect to show the setup page
                echo '<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>';
            }
        }

        // Handle debug log clear (only if authenticated)
        if ($is_authenticated && isset($_GET['clear_debug'])) {
            $debug_file = dirname(__FILE__) . '/debug.log';
            if (file_exists($debug_file)) {
                file_put_contents($debug_file, '');
            }
            $redirect_url = yourls_admin_url('plugins.php?page=yn-change-notifier');
            yourls_redirect($redirect_url);
            return;
        }

        // Admin page styles
        echo '<style>
            .plugin-header { display:flex; flex-direction:column; align-items:flex-start; }
            .plugin-title {
                margin:0; padding:0; font-family:Arial,sans-serif; font-size:2em; font-weight:bold;
                background:-webkit-linear-gradient(#0073aa,#00a8e6);
                -webkit-background-clip:text; -webkit-text-fill-color:transparent;
            }
            .plugin-version { font-size:.85em; color:#666; margin-top:4px; }
            .form-section { margin:30px 0; padding:20px; border:1px solid #ddd; background:#f9f9f9; border-radius:5px; }
            .form-row { margin-bottom:15px; }
            .form-row label { display:block; font-weight:bold; margin-bottom:5px; font-size:1.1em; }
            .form-row input[type="text"], .form-row textarea, .form-row input[type="password"], .form-row input[type="email"], .form-row input[type="number"], .form-row select { width:100%; max-width:600px; padding:5px; }
            .form-row.half { display:inline-block; width:48%; margin-right:2%; vertical-align:top; }
            .form-row.quarter { display:inline-block; width:23%; margin-right:2%; vertical-align:top; }
            #ynm_recipients { font-size:15px; line-height:1.45; }
            input[type="submit"].button { padding:8px 14px; font-size:13px; border-radius:5px; cursor:pointer; }
            .actions-row { display:flex; gap:10px; align-items:center; }
            .checkboxes label { margin-right:14px; font-size:1.1em; }
            .checkboxes .group-title { display:block; margin-bottom:6px; }
            .checkboxes .inline { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
            .checkboxes .inline label { display:inline-flex; align-items:center; margin:0; font-weight:normal; }
            .plugin-footer { margin-top:40px; padding-top:20px; border-top:1px solid #ddd; font-size:.9em; color:#666; text-align:center; opacity:.85; }
            .plugin-footer a { color:#0073aa; text-decoration:none; }
            .plugin-footer a:hover { text-decoration:underline; }
            .plugin-footer .github-icon { vertical-align:middle; width:16px; height:16px; margin-right:4px; display:inline-block; }
            .muted { color:#666; font-size:.95em; }
            .debug-info { background:#fff3cd; border:1px solid #ffeaa7; border-radius:4px; padding:10px; margin-top:10px; }
            .auth-section { max-width:400px; margin:50px auto; text-align:center; }
            .auth-section input[type="password"] { max-width:300px; margin:10px auto; display:block; }
            .logout-link { float:right; font-size:0.9em; }
            .smtp-section { background:#f0f8ff; border:1px solid #87ceeb; }
            .smtp-disabled { opacity:0.6; }
            .danger-zone { background:#fff5f5; border:1px solid #feb2b2; border-radius:4px; padding:15px; margin-top:20px; }
            .reset-button { background:#dc3232 !important; color:white !important; border-color:#dc3232 !important; }
            .reset-button:hover { background:#c53030 !important; }
        </style>';

        // Plugin header
        echo '<div class="plugin-header">';
        echo '<h2 class="plugin-title">🔔 '.yourls__('YOURLS Change Notifier', YNM_DOMAIN).'</h2>';
        echo '<p class="plugin-version">'.yourls__('Version: ', YNM_DOMAIN).YNM_VERSION.'</p>';
        
        // Show logout link if authenticated
        if ($is_authenticated) {
            echo '<div class="logout-link">';
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="ynm_action" value="logout">';
            echo '<input type="submit" value="🚪 '.yourls__('Logout', YNM_DOMAIN).'" class="button" style="font-size:11px; padding:4px 8px;">';
            echo '</form>';
            echo '</div>';
        }
        echo '</div>';

        // Show messages
        $notice = '';
        if (!empty($message)) {
            $notice = '<div style="margin:10px 0; padding:10px; border-left:4px solid '.(!empty($result['success']) ? '#46b450' : '#dc3232').'; background: '.(!empty($result['success']) ? '#e6ffed' : '#fbeaea').';">'.$message.'</div>';
        }
        if ($notice) echo $notice;

        // Show setup form if password not set
        if ($needs_setup) {
            echo '<div class="auth-section">';
            echo '<h3>🔐 '.yourls__('Initial Setup Required', YNM_DOMAIN).'</h3>';
            echo '<p>'.yourls__('Please set an admin password to protect plugin settings:', YNM_DOMAIN).'</p>';
            echo '<form method="post">';
            echo '<input type="hidden" name="ynm_action" value="setup">';
            echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr(yourls_create_nonce('ynm_setup')).'">';
            echo '<input type="password" name="admin_password" placeholder="'.yourls__('Enter admin password (min 6 chars)', YNM_DOMAIN).'" required minlength="6">';
            echo '<br><input type="submit" class="button button-primary" value="🔒 '.yourls__('Set Password & Continue', YNM_DOMAIN).'">';
            echo '</form>';
            echo '</div>';
            echo ynm_render_footer();
            return;
        }

        // Show login form if not authenticated
        if (!$is_authenticated) {
            echo '<div class="auth-section">';
            echo '<h3>🔐 '.yourls__('Authentication Required', YNM_DOMAIN).'</h3>';
            echo '<p>'.yourls__('Please enter the admin password to access plugin settings:', YNM_DOMAIN).'</p>';
            echo '<form method="post">';
            echo '<input type="hidden" name="ynm_action" value="login">';
            echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr(yourls_create_nonce('ynm_login')).'">';
            echo '<input type="password" name="admin_password" placeholder="'.yourls__('Admin password', YNM_DOMAIN).'" required>';
            echo '<br><input type="submit" class="button button-primary" value="🔓 '.yourls__('Access Settings', YNM_DOMAIN).'">';
            echo '</form>';
            echo '</div>';
            echo ynm_render_footer();
            return;
        }

        // Show main settings (only if authenticated)
        $s = self::get_settings();
        $nonce = yourls_create_nonce('ynm_save');

        // Basic Settings form
        echo '<div class="form-section">';
        echo '<h3>📝 '.yourls__('Basic Settings', YNM_DOMAIN).'</h3>';
        echo '<form method="post">';
        echo '<input type="hidden" name="ynm_action" value="save">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr($nonce).'">';

        // Copy all SMTP settings to maintain them
        echo '<input type="hidden" name="use_smtp" value="'.($s['use_smtp'] ? '1' : '0').'">';
        echo '<input type="hidden" name="smtp_host" value="'.yourls_esc_attr($s['smtp_host']).'">';
        echo '<input type="hidden" name="smtp_port" value="'.yourls_esc_attr($s['smtp_port']).'">';
        echo '<input type="hidden" name="smtp_security" value="'.yourls_esc_attr($s['smtp_security']).'">';
        echo '<input type="hidden" name="smtp_auth" value="'.($s['smtp_auth'] ? '1' : '0').'">';
        echo '<input type="hidden" name="smtp_username" value="'.yourls_esc_attr($s['smtp_username']).'">';
        echo '<input type="hidden" name="smtp_from_email" value="'.yourls_esc_attr($s['smtp_from_email']).'">';
        echo '<input type="hidden" name="smtp_from_name" value="'.yourls_esc_attr($s['smtp_from_name']).'">';
        echo '<input type="hidden" name="debug_enabled" value="'.($s['debug_enabled'] ? '1' : '0').'">';

        echo '<div class="form-row">';
        echo '<label for="ynm_recipients">'.yourls__('Recipients (comma-separated)', YNM_DOMAIN).'</label>';
        echo '<textarea id="ynm_recipients" name="recipients" rows="3" placeholder="ops@company.com, admin@company.com">'.yourls_esc_html($s['recipients']).'</textarea>';
        echo '<div class="muted">'.yourls__('Example:', YNM_DOMAIN).' ops@company.com, admin@company.com</div>';
        echo '</div>';

        echo '<div class="form-row checkboxes">';
        echo '<label class="group-title">'.yourls__('Notify on', YNM_DOMAIN).'</label>';
        echo '<div class="inline">';
        echo '<label><input type="checkbox" name="notify_create" '.($s['notify_create']?'checked':'').'> '.yourls__('Create', YNM_DOMAIN).'</label>';
        echo '<label><input type="checkbox" name="notify_edit" '.($s['notify_edit']?'checked':'').'> '.yourls__('Edit', YNM_DOMAIN).'</label>';
        echo '<label><input type="checkbox" name="notify_delete" '.($s['notify_delete']?'checked':'').'> '.yourls__('Delete', YNM_DOMAIN).'</label>';
        echo '</div>';
        echo '</div>';

        echo '<div class="form-row">';
        echo '<label for="ynm_subject_prefix">'.yourls__('Subject prefix', YNM_DOMAIN).'</label>';
        echo '<input type="text" id="ynm_subject_prefix" name="subject_prefix" value="'.yourls_esc_attr($s['subject_prefix']).'">';
        echo '</div>';

        echo '<div class="actions-row">';
        echo '<input type="submit" class="button button-primary" value="💾 '.yourls__('Save Basic Settings', YNM_DOMAIN).'">';
        echo '</div>';

        echo '</form>';
        echo '</div>';

        // SMTP Configuration Section
        echo '<div class="form-section smtp-section">';
        echo '<h3>📧 '.yourls__('Email Configuration', YNM_DOMAIN).'</h3>';
        echo '<form method="post">';
        echo '<input type="hidden" name="ynm_action" value="save">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr($nonce).'">';
        
        // Copy notification settings to maintain them
        echo '<input type="hidden" name="recipients" value="'.yourls_esc_attr($s['recipients']).'">';
        echo '<input type="hidden" name="notify_create" value="'.($s['notify_create'] ? '1' : '0').'">';
        echo '<input type="hidden" name="notify_edit" value="'.($s['notify_edit'] ? '1' : '0').'">';
        echo '<input type="hidden" name="notify_delete" value="'.($s['notify_delete'] ? '1' : '0').'">';
        echo '<input type="hidden" name="subject_prefix" value="'.yourls_esc_attr($s['subject_prefix']).'">';
        echo '<input type="hidden" name="debug_enabled" value="'.($s['debug_enabled'] ? '1' : '0').'">';

        echo '<div class="form-row checkboxes">';
        echo '<label class="group-title">'.yourls__('Email Method', YNM_DOMAIN).'</label>';
        echo '<div class="inline">';
        echo '<label><input type="radio" name="use_smtp" value="0" '.(!$s['use_smtp']?'checked':'').' onchange="toggleSmtp()"> '.yourls__('Use PHP mail() function', YNM_DOMAIN).'</label>';
        echo '<label><input type="radio" name="use_smtp" value="1" '.($s['use_smtp']?'checked':'').' onchange="toggleSmtp()"> '.yourls__('Use SMTP server', YNM_DOMAIN).'</label>';
        echo '</div>';
        echo '</div>';

        echo '<div id="smtp-settings" class="'.(!$s['use_smtp'] ? 'smtp-disabled' : '').'">';
        
        echo '<div class="form-row">';
        echo '<label for="ynm_smtp_from_email">'.yourls__('From Email Address', YNM_DOMAIN).'</label>';
        echo '<input type="email" id="ynm_smtp_from_email" name="smtp_from_email" value="'.yourls_esc_attr($s['smtp_from_email']).'" placeholder="notifications@yourdomain.com">';
        echo '<div class="muted">'.yourls__('Email address that will appear as sender', YNM_DOMAIN).'</div>';
        echo '</div>';

        echo '<div class="form-row">';
        echo '<label for="ynm_smtp_from_name">'.yourls__('From Name', YNM_DOMAIN).'</label>';
        echo '<input type="text" id="ynm_smtp_from_name" name="smtp_from_name" value="'.yourls_esc_attr($s['smtp_from_name']).'" placeholder="YOURLS Change Notifier">';
        echo '</div>';

        echo '<div class="form-row half">';
        echo '<label for="ynm_smtp_host">'.yourls__('SMTP Host', YNM_DOMAIN).'</label>';
        echo '<input type="text" id="ynm_smtp_host" name="smtp_host" value="'.yourls_esc_attr($s['smtp_host']).'" placeholder="smtp.gmail.com">';
        echo '</div>';

        echo '<div class="form-row quarter">';
        echo '<label for="ynm_smtp_port">'.yourls__('Port', YNM_DOMAIN).'</label>';
        echo '<input type="number" id="ynm_smtp_port" name="smtp_port" value="'.yourls_esc_attr($s['smtp_port']).'" min="1" max="65535">';
        echo '</div>';

        echo '<div class="form-row quarter">';
        echo '<label for="ynm_smtp_security">'.yourls__('Security', YNM_DOMAIN).'</label>';
        echo '<select id="ynm_smtp_security" name="smtp_security">';
        echo '<option value="none"'.($s['smtp_security'] === 'none' ? ' selected' : '').'>'.yourls__('None', YNM_DOMAIN).'</option>';
        echo '<option value="ssl"'.($s['smtp_security'] === 'ssl' ? ' selected' : '').'>SSL</option>';
        echo '<option value="tls"'.($s['smtp_security'] === 'tls' ? ' selected' : '').'>TLS</option>';
        echo '</select>';
        echo '</div>';
        
        echo '<div style="clear:both;"></div>';

        echo '<div class="form-row checkboxes">';
        echo '<label class="group-title">'.yourls__('Authentication', YNM_DOMAIN).'</label>';
        echo '<div class="inline">';
        echo '<label><input type="checkbox" name="smtp_auth" '.($s['smtp_auth']?'checked':'').' onchange="toggleSmtpAuth()"> '.yourls__('Require authentication', YNM_DOMAIN).'</label>';
        echo '</div>';
        echo '</div>';

        echo '<div id="smtp-auth" class="'.(!$s['smtp_auth'] ? 'smtp-disabled' : '').'">';
        echo '<div class="form-row half">';
        echo '<label for="ynm_smtp_username">'.yourls__('Username', YNM_DOMAIN).'</label>';
        echo '<input type="text" id="ynm_smtp_username" name="smtp_username" value="'.yourls_esc_attr($s['smtp_username']).'" placeholder="your-email@gmail.com">';
        echo '</div>';

        echo '<div class="form-row half">';
        echo '<label for="ynm_smtp_password">'.yourls__('Password', YNM_DOMAIN).'</label>';
        echo '<input type="password" id="ynm_smtp_password" name="smtp_password" placeholder="'.yourls__('Leave empty to keep current', YNM_DOMAIN).'">';
        echo '<div class="muted">'.yourls__('Use app passwords for Gmail/Outlook', YNM_DOMAIN).'</div>';
        echo '</div>';
        echo '<div style="clear:both;"></div>';
        echo '</div>';

        echo '</div>';

        echo '<div class="actions-row">';
        echo '<input type="submit" class="button button-primary" value="💾 '.yourls__('Save Email Settings', YNM_DOMAIN).'">';
        echo '</div>';

        echo '</form>';
        echo '</div>';

        // JavaScript for SMTP toggles
        echo '<script>
        function toggleSmtp() {
            const useSmtp = document.querySelector(\'input[name="use_smtp"]:checked\').value === "1";
            const smtpSettings = document.getElementById("smtp-settings");
            if (useSmtp) {
                smtpSettings.classList.remove("smtp-disabled");
            } else {
                smtpSettings.classList.add("smtp-disabled");
            }
        }
        
        function toggleSmtpAuth() {
            const requireAuth = document.querySelector(\'input[name="smtp_auth"]\').checked;
            const smtpAuth = document.getElementById("smtp-auth");
            if (requireAuth) {
                smtpAuth.classList.remove("smtp-disabled");
            } else {
                smtpAuth.classList.add("smtp-disabled");
            }
        }
        </script>';

        // Advanced Settings
        echo '<div class="form-section">';
        echo '<h3>⚙️ '.yourls__('Advanced Settings', YNM_DOMAIN).'</h3>';
        echo '<form method="post">';
        echo '<input type="hidden" name="ynm_action" value="save">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr($nonce).'">';
        
        // Copy all other settings to maintain them
        echo '<input type="hidden" name="recipients" value="'.yourls_esc_attr($s['recipients']).'">';
        echo '<input type="hidden" name="notify_create" value="'.($s['notify_create'] ? '1' : '0').'">';
        echo '<input type="hidden" name="notify_edit" value="'.($s['notify_edit'] ? '1' : '0').'">';
        echo '<input type="hidden" name="notify_delete" value="'.($s['notify_delete'] ? '1' : '0').'">';
        echo '<input type="hidden" name="subject_prefix" value="'.yourls_esc_attr($s['subject_prefix']).'">';
        echo '<input type="hidden" name="use_smtp" value="'.($s['use_smtp'] ? '1' : '0').'">';
        echo '<input type="hidden" name="smtp_host" value="'.yourls_esc_attr($s['smtp_host']).'">';
        echo '<input type="hidden" name="smtp_port" value="'.yourls_esc_attr($s['smtp_port']).'">';
        echo '<input type="hidden" name="smtp_security" value="'.yourls_esc_attr($s['smtp_security']).'">';
        echo '<input type="hidden" name="smtp_auth" value="'.($s['smtp_auth'] ? '1' : '0').'">';
        echo '<input type="hidden" name="smtp_username" value="'.yourls_esc_attr($s['smtp_username']).'">';
        echo '<input type="hidden" name="smtp_from_email" value="'.yourls_esc_attr($s['smtp_from_email']).'">';
        echo '<input type="hidden" name="smtp_from_name" value="'.yourls_esc_attr($s['smtp_from_name']).'">';

        // Debug logging option
        echo '<div class="form-row checkboxes">';
        echo '<label class="group-title">'.yourls__('Debug Options', YNM_DOMAIN).'</label>';
        echo '<div class="inline">';
        echo '<label><input type="checkbox" name="debug_enabled" '.($s['debug_enabled']?'checked':'').'> '.yourls__('Enable debug logging', YNM_DOMAIN).'</label>';
        echo '</div>';

        $debug_file = dirname(__FILE__) . '/debug.log';
        $debug_status = $this->check_debug_file_status($debug_file);

        if ($s['debug_enabled']) {
            echo '<div class="debug-info">';
            echo '⚠️ '.yourls__('Debug logging is active. The log file will be automatically rotated when it exceeds 5MB.', YNM_DOMAIN);
            
            if (!$debug_status['writable']) {
                echo '<br><span style="color:#dc3232;">❌ '.yourls__('Warning: Cannot write to debug log file. Check directory permissions or create debug.log file into plugin directory and change permissions (chmod 666).', YNM_DOMAIN).'</span>';
            } elseif ($debug_status['exists']) {
                echo '<br>✅ '.yourls__('Debug log file is writable.', YNM_DOMAIN);
            } else {
                echo '<br>ℹ️ '.yourls__('Debug log file will be created on first use.', YNM_DOMAIN);
            }
            echo '</div>';
        }
        echo '</div>';

        // Password change option
        echo '<div class="form-row">';
        echo '<label for="ynm_admin_password">'.yourls__('Change Admin Password', YNM_DOMAIN).'</label>';
        echo '<input type="password" id="ynm_admin_password" name="admin_password" placeholder="'.yourls__('Leave empty to keep current password', YNM_DOMAIN).'">';
        echo '<div class="muted">'.yourls__('Enter new password only if you want to change it (minimum 6 characters)', YNM_DOMAIN).'</div>';
        echo '</div>';

        echo '<div class="actions-row">';
        echo '<input type="submit" class="button button-primary" value="💾 '.yourls__('Save Advanced Settings', YNM_DOMAIN).'">';
        echo '</div>';

        echo '</form>';

        // Reset to defaults section
        echo '<div class="danger-zone">';
        echo '<h4 style="color:#dc3232; margin-top:0;">⚠️ '.yourls__('Danger Zone', YNM_DOMAIN).'</h4>';

        // Reset all settings
        echo '<div style="margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid #feb2b2;">';
        echo '<h5 style="color:#dc3232; margin-bottom:5px;">🔄 '.yourls__('Reset All Settings', YNM_DOMAIN).'</h5>';
        echo '<p class="muted">'.yourls__('This action will reset ALL plugin settings to their default values. Your admin password will be preserved, but all other configurations (recipients, SMTP settings, debug options) will be lost.', YNM_DOMAIN).'</p>';
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\''.yourls__('Are you sure you want to reset all settings to defaults? This action cannot be undone.', YNM_DOMAIN).'\')">';
        echo '<input type="hidden" name="ynm_action" value="reset">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr(yourls_create_nonce('ynm_reset')).'">';
        echo '<input type="submit" class="button reset-button" value="🔄 '.yourls__('Reset to Defaults', YNM_DOMAIN).'">';
        echo '</form>';
        echo '</div>';

        // Reset admin password
        echo '<div>';
        echo '<h5 style="color:#dc3232; margin-bottom:5px;">🔐 '.yourls__('Reset Admin Password', YNM_DOMAIN).'</h5>';
        echo '<p class="muted">'.yourls__('This will completely remove the admin password. You will be logged out and will need to set up a new password on the next page load.', YNM_DOMAIN).'</p>';
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\''.yourls__('Are you sure you want to reset the admin password? You will be logged out immediately and will need to set up a new password.', YNM_DOMAIN).'\')">';
        echo '<input type="hidden" name="ynm_action" value="reset_password">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr(yourls_create_nonce('ynm_reset_password')).'">';
        echo '<input type="submit" class="button reset-button" value="🔐 '.yourls__('Reset Password', YNM_DOMAIN).'">';
        echo '</form>';
        echo '</div>';

        echo '</div>';

        // Close the form section that was opened earlier
        echo '</div>';

        // Test email form
        echo '<div class="form-section">';
        echo '<h3>✉️ '.yourls__('Test Email', YNM_DOMAIN).'</h3>';
        echo '<form method="post">';
        echo '<input type="hidden" name="ynm_action" value="test">';
        echo '<input type="hidden" name="nonce" value="'.yourls_esc_attr($nonce).'">';
        echo '<div class="actions-row"><input type="submit" class="button" value="✉️ '.yourls__('Send test email', YNM_DOMAIN).'">';
        $method = $s['use_smtp'] ? 'SMTP' : 'PHP mail()';
        echo '<span class="muted">'.yourls__('Sends to all configured recipients using ', YNM_DOMAIN).$method.'</span></div>';
        echo '</form>';
        echo '</div>';

        // Debug log viewer
        if ($s['debug_enabled']) {
            echo '<div class="form-section">';
            echo '<h3>🐛 Debug Log</h3>';
            $debug_file = dirname(__FILE__) . '/debug.log';
            if (file_exists($debug_file)) {
                $file_size = filesize($debug_file);
                $size_mb = round($file_size / 1024 / 1024, 2);
                echo '<div class="muted">'.yourls__('File size: ', YNM_DOMAIN).$size_mb.' MB</div>';
                
                $content = file_get_contents($debug_file);
                echo '<textarea readonly style="width:100%; height:300px; font-family:monospace; font-size:12px;">';
                echo htmlspecialchars($content);
                echo '</textarea>';
                echo '<p><a href="'.yourls_admin_url('plugins.php?page=yn-change-notifier&clear_debug=1').'">Clear log</a> (empties the log file, does not delete it)</p>';
            } else {
                echo '<p>No debug log found yet. It will be created when the first event occurs.</p>';
            }
            echo '</div>';
        }

        echo ynm_render_footer();
    }

    /* =========================
       Event handlers
       ========================= */

    public function on_created_filter($return, $url, $keyword, $title, $row_id = null) {
        $s = self::get_settings();
        if (!$s['notify_create']) return $return;
        
        if (is_array($return) && $return['status'] === 'success') {
            $final_keyword = $return['url']['keyword'] ?? '';
            $final_url = $return['url']['url'] ?? '';
            $final_title = $return['url']['title'] ?? '';
            
            $this->debug_log("CREATE event triggered", [
                'keyword' => $final_keyword,
                'url' => $final_url,
                'title' => $final_title
            ]);
            
            $instance = defined('YOURLS_SITE') ? YOURLS_SITE : '';
            $short    = $final_keyword ? rtrim($instance, '/').'/'.$final_keyword : '';

            $payload = [
                'event'    => 'CREATE',
                'instance' => $instance,
                'short'    => $short,
                'keyword'  => $final_keyword,
                'long'     => $final_url,
                'title'    => $final_title,
                'when'     => date('c'),
                'by'       => $this->who(),
                'ip'       => $this->ip(),
            ];

            $this->debug_log("Sending CREATE notification", $payload);

            $this->send_mail(
                $s['subject_prefix'].' New short URL: '.$final_keyword,
                $this->fmt_body($payload)
            );
        }
        
        return $return;
    }

    public function on_edit_pre($url_new, $keyword_old, $newkeyword = '', $new_url_already_there = null, $keyword_is_ok = true) {
        $info = yourls_get_keyword_infos($keyword_old);
        $snapshot = [
            'keyword'   => $keyword_old,
            'title'     => is_array($info) ? ($info['title'] ?? null) : (is_object($info) ? ($info->title ?? null) : null),
            'long'      => is_array($info) ? ($info['url'] ?? null) : (is_object($info) ? ($info->url ?? null) : null),
            'new_long'  => is_string($url_new) ? $url_new : (is_array($url_new) ? ($url_new['url'] ?? reset($url_new) ?? null) : null),
            'new_key'   => $newkeyword ?: null,
            'new_title' => null,
        ];
        
        $this->debug_log("EDIT pre-capture", $snapshot);
        yourls_update_option(YNM_SNAP_EDIT, $snapshot);
    }

    public function on_edit_filter($return, $url, $keyword_old, $newkeyword = '', $title_new = '', $new_url_already_there = null, $keyword_is_ok = true) {
        $s = self::get_settings();
        if (!$s['notify_edit']) return $return;

        $snap = yourls_get_option(YNM_SNAP_EDIT);
        if (!is_array($snap)) $snap = [];

        $final_keyword = $newkeyword ?: $keyword_old;
        $info = $final_keyword ? yourls_get_keyword_infos($final_keyword) : null;
        
        $final_long = null;
        $final_title = null;
        if (is_array($info)) {
            $final_long = $info['url'] ?? null;
            $final_title = $info['title'] ?? null;
        } elseif (is_object($info)) {
            $final_long = $info->url ?? null;
            $final_title = $info->title ?? null;
        }
        
        if (!$final_long) {
            $final_long = is_string($url) ? $url : (is_array($url) ? ($url['url'] ?? reset($url) ?? null) : null);
        }
        if (!$final_title) {
            $final_title = $title_new ?: null;
        }

        $snap['keyword'] = $keyword_old;

        $instance = defined('YOURLS_SITE') ? YOURLS_SITE : '';
        $short    = $final_keyword ? (rtrim($instance, '/').'/'.$final_keyword) : '';

        $payload = [
            'event'    => 'EDIT',
            'instance' => $instance,
            'short'    => $short,
            'keyword'  => $final_keyword,
            'long'     => $final_long,
            'title'    => $final_title,
            'when'     => date('c'),
            'by'       => $this->who(),
            'ip'       => $this->ip(),
            'before'   => $snap,
        ];

        $this->debug_log("EDIT event triggered", $payload);

        $this->send_mail(
            $s['subject_prefix'].' Short URL edited: '.$final_keyword,
            $this->fmt_body($payload)
        );

        if (function_exists('yourls_delete_option')) {
            yourls_delete_option(YNM_SNAP_EDIT);
        }

        return $return;
    }

    public function capture_delete_on_admin_init() {
        $action = $_REQUEST['action'] ?? null;
        if ($action !== 'delete') return;

        $this->debug_log("DELETE capture initiated", $_REQUEST);

        $keywords = [];
        if (isset($_REQUEST['keyword'])) {
            $v = $_REQUEST['keyword'];
            $keywords = is_array($v) ? $v : [$v];
        }
        
        $keywords = array_unique(array_filter($keywords));
        if (empty($keywords)) return;

        $map = yourls_get_option(YNM_SNAP_DEL);
        $map = is_array($map) ? $map : [];

        foreach ($keywords as $k) {
            $k = trim((string)$k);
            if ($k === '') continue;
            
            $info = yourls_get_keyword_infos($k);
            if ($info) {
                if (is_array($info)) {
                    $map[$k] = [
                        'long'  => $info['url'] ?? null,
                        'title' => $info['title'] ?? null,
                    ];
                } elseif (is_object($info)) {
                    $map[$k] = [
                        'long'  => $info->url ?? null,
                        'title' => $info->title ?? null,
                    ];
                }
                $this->debug_log("Captured DELETE data for keyword '$k'", $map[$k]);
            }
        }

        yourls_update_option(YNM_SNAP_DEL, $map);
    }

    public function on_delete_direct($args) {
        $s = self::get_settings();
        if (!$s['notify_delete']) return;

        $keyword = is_array($args) ? ($args[0] ?? null) : $args;
        if (!$keyword) return;

        $this->debug_log("DELETE event triggered for keyword", $keyword);

        $info = yourls_get_keyword_infos($keyword);
        $long = null;
        $title = null;
        
        if ($info && is_array($info)) {
            $long = $info['url'] ?? null;
            $title = $info['title'] ?? null;
            $this->debug_log("Retrieved DELETE data from database", ['url' => $long, 'title' => $title]);
        } elseif ($info && is_object($info)) {
            $long = $info->url ?? null;
            $title = $info->title ?? null;
            $this->debug_log("Retrieved DELETE data from database (object)", ['url' => $long, 'title' => $title]);
        } else {
            $map = yourls_get_option(YNM_SNAP_DEL);
            $snap = (is_array($map) ? $map : [])[$keyword] ?? null;
            
            if ($snap) {
                $long = $snap['long'] ?? null;
                $title = $snap['title'] ?? null;
                $this->debug_log("Retrieved DELETE data from snapshot", ['url' => $long, 'title' => $title]);
            } else {
                $this->debug_log("No DELETE data available for keyword", $keyword);
            }
        }

        $instance = defined('YOURLS_SITE') ? YOURLS_SITE : '';
        $short = $keyword ? rtrim($instance, '/').'/'.$keyword : '';

        $payload = [
            'event'    => 'DELETE',
            'instance' => $instance,
            'short'    => $short,
            'keyword'  => $keyword,
            'long'     => $long,
            'title'    => $title,
            'when'     => date('c'),
            'by'       => $this->who(),
            'ip'       => $this->ip(),
        ];

        $this->debug_log("Sending DELETE notification", $payload);

        $this->send_mail(
            $s['subject_prefix'].' Short URL deleted: '.$keyword,
            $this->fmt_body($payload)
        );
    }

    /* =========================
       Helper methods
       ========================= */

    private function debug_log($message, $data = null) {
        $settings = self::get_settings();
        if (!$settings['debug_enabled']) {
            return;
        }

        $log_file = dirname(__FILE__) . '/debug.log';
        
        if (file_exists($log_file) && filesize($log_file) > (5 * 1024 * 1024)) {
            $this->rotate_debug_log($log_file);
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message";
        if ($data !== null) {
            $log_entry .= "\n" . print_r($data, true);
        }
        $log_entry .= "\n" . str_repeat('-', 50) . "\n";
        
        $result = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            if (!file_exists($log_file)) {
                $success = @touch($log_file);
                if ($success) {
                    @chmod($log_file, 0644);
                    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
                }
            }
        }
    }

    private function check_debug_file_status($log_file): array {
        $status = [
            'exists' => false,
            'writable' => false,
            'directory_writable' => false
        ];
        
        $directory = dirname($log_file);
        $status['directory_writable'] = is_writable($directory);
        
        if (file_exists($log_file)) {
            $status['exists'] = true;
            $status['writable'] = is_writable($log_file);
        } else {
            $test_result = @file_put_contents($log_file, "test\n", LOCK_EX);
            if ($test_result !== false) {
                $status['writable'] = true;
                @unlink($log_file);
            } else {
                $status['writable'] = $status['directory_writable'];
            }
        }
        
        return $status;
    }

    private function rotate_debug_log($log_file) {
        if (!file_exists($log_file)) return;

        $content = file_get_contents($log_file);
        $content_length = strlen($content);
        
        if ($content_length > (2 * 1024 * 1024)) {
            $keep_size = 2 * 1024 * 1024;
            $new_content = substr($content, -$keep_size);
            
            $first_separator = strpos($new_content, "\n" . str_repeat('-', 50) . "\n");
            if ($first_separator !== false) {
                $new_content = substr($new_content, $first_separator + 52);
            }
            
            $rotation_marker = "[" . date('Y-m-d H:i:s') . "] === LOG ROTATED (size exceeded 5MB) ===\n" . str_repeat('-', 50) . "\n";
            $new_content = $rotation_marker . $new_content;
            
            file_put_contents($log_file, $new_content, LOCK_EX);
        }
    }

    private function who(): string {
        if (function_exists('yourls_get_current_user')) {
            $u = yourls_get_current_user();
            if (is_object($u)) {
                foreach (['user_login','login','username','display_name','user_email'] as $p) {
                    if (isset($u->$p) && $u->$p) {
                        return 'user: ' . $u->$p;
                    }
                }
                if (method_exists($u, 'get')) {
                    foreach (['user_login','display_name','user_email'] as $k) {
                        $val = $u->get($k);
                        if (!empty($val)) {
                            return 'user: ' . $val;
                        }
                    }
                }
            }
        }

        foreach (['PHP_AUTH_USER','REMOTE_USER','HTTP_REMOTE_USER','HTTP_X_FORWARDED_USER','HTTP_X_AUTH_USER'] as $h) {
            if (!empty($_SERVER[$h])) {
                return 'user: ' . trim((string)$_SERVER[$h]);
            }
        }

        if (function_exists('yourls_cookie_name')) {
            $cname = yourls_cookie_name();
            if (!empty($_COOKIE[$cname]) && is_string($_COOKIE[$cname])) {
                if (preg_match('/^([^:\\|]+)[:\\|]/', $_COOKIE[$cname], $m)) {
                    return 'user: ' . $m[1];
                }
            }
        }

        if (!empty($_COOKIE['yourls_username'])) {
            return 'user: ' . $_COOKIE['yourls_username'];
        }

        if (function_exists('yourls_is_valid_user') && yourls_is_valid_user()) {
            return 'user: (authenticated)';
        }
        return 'API/anonymous';
    }

    private function ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $raw = $_SERVER[$h];
                $ip  = is_string($raw) ? trim(explode(',', $raw)[0]) : '';
                if ($ip) return $ip;
            }
        }
        return '(unknown)';
    }

    private function fmt_body(array $p): string {
        $lines = [];
        $lines[] = "Event:   {$p['event']}";
        $lines[] = "When:    {$p['when']}";
        if (!empty($p['by']))        $lines[] = "By:      {$p['by']}";
        if (!empty($p['ip']))        $lines[] = "IP:      {$p['ip']}";
        if (!empty($p['instance']))  $lines[] = "Instance: {$p['instance']}";
        if (!empty($p['short']))     $lines[] = "Short:   {$p['short']}";
        if (!empty($p['keyword']))   $lines[] = "Keyword: {$p['keyword']}";
        if (!empty($p['title']))     $lines[] = "Title:   {$p['title']}";
        if (!empty($p['long']))      $lines[] = "Target:  {$p['long']}";
        if (!empty($p['admin']))     $lines[] = "Admin:   {$p['admin']}";

        if (!empty($p['before']) && is_array($p['before'])) {
            $lines[] = "";
            $lines[] = "Before (snapshot):";
            $labelMap = ['keyword' => 'keyword', 'title' => 'title', 'long' => 'target'];
            foreach ($labelMap as $k => $label) {
                if (!empty($p['before'][$k])) {
                    $lines[] = "  {$label}: {$p['before'][$k]}";
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    // Enhanced email sending with SMTP support
    private function send_mail(string $subject, string $body): void {
        $s = self::get_settings();
        $to = array_filter(array_map('trim', explode(',', $s['recipients'])));
        if (empty($to)) return;

        if ($s['use_smtp'] && !empty($s['smtp_host'])) {
            $this->send_mail_smtp($to, $subject, $body);
        } else {
            $this->send_mail_php($to, $subject, $body);
        }
    }

    // Send email using PHP mail() function
    private function send_mail_php(array $to, string $subject, string $body): void {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'X-YOURLS-Notify-Mail: 1',
            'X-YOURLS-Notifier-Version: ' . YNM_VERSION,
        ];
        
        foreach ($to as $addr) {
            @mail($addr, $subject, $body, implode("\r\n", $headers));
        }
        
        $this->debug_log("Email sent via PHP mail()", ['recipients' => count($to), 'subject' => $subject]);
    }

    // Send email using SMTP
    private function send_mail_smtp(array $to, string $subject, string $body): void {
        $s = self::get_settings();
        
        try {
            // Create socket connection
            $context = stream_context_create();
            
            if ($s['smtp_security'] === 'ssl') {
                $host = 'ssl://' . $s['smtp_host'];
            } else {
                $host = $s['smtp_host'];
            }
            
            $this->debug_log("Connecting to SMTP", ['host' => $host, 'port' => $s['smtp_port']]);
            
            $smtp = stream_socket_client(
                $host . ':' . $s['smtp_port'],
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$smtp) {
                throw new Exception("Failed to connect to SMTP server: $errstr ($errno)");
            }
            
            // Read initial response
            $response = fgets($smtp, 512);
            $this->debug_log("SMTP initial response", $response);
            
            // EHLO command
            $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
            fwrite($smtp, "EHLO $hostname\r\n");
            $response = $this->read_smtp_response($smtp);
            $this->debug_log("EHLO response", $response);
            
            // STARTTLS if required
            if ($s['smtp_security'] === 'tls') {
                fwrite($smtp, "STARTTLS\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("STARTTLS response", $response);
                
                if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Failed to enable TLS encryption");
                }
                
                // Send EHLO again after STARTTLS
                fwrite($smtp, "EHLO $hostname\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("EHLO after TLS response", $response);
            }
            
            // Authentication
            if ($s['smtp_auth'] && !empty($s['smtp_username'])) {
                fwrite($smtp, "AUTH LOGIN\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("AUTH LOGIN response", $response);
                
                fwrite($smtp, base64_encode($s['smtp_username']) . "\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("Username response", $response);
                
                $password = !empty($s['smtp_password']) ? base64_decode($s['smtp_password']) : '';
                fwrite($smtp, base64_encode($password) . "\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("Password response", $response);
            }
            
            // Send email
            $from_email = !empty($s['smtp_from_email']) ? $s['smtp_from_email'] : $s['smtp_username'];
            $from_name = !empty($s['smtp_from_name']) ? $s['smtp_from_name'] : 'YOURLS Change Notifier';
            
            fwrite($smtp, "MAIL FROM: <$from_email>\r\n");
            $response = $this->read_smtp_response($smtp);
            $this->debug_log("MAIL FROM response", $response);
            
            foreach ($to as $recipient) {
                fwrite($smtp, "RCPT TO: <$recipient>\r\n");
                $response = $this->read_smtp_response($smtp);
                $this->debug_log("RCPT TO response for $recipient", $response);
            }
            
            fwrite($smtp, "DATA\r\n");
            $response = $this->read_smtp_response($smtp);
            $this->debug_log("DATA response", $response);
            
            // Email headers and body
            $email_data = "From: $from_name <$from_email>\r\n";
            $email_data .= "To: " . implode(', ', $to) . "\r\n";
            $email_data .= "Subject: $subject\r\n";
            $email_data .= "MIME-Version: 1.0\r\n";
            $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $email_data .= "X-YOURLS-Notify-Mail: 1\r\n";
            $email_data .= "X-YOURLS-Notifier-Version: " . YNM_VERSION . "\r\n";
            $email_data .= "\r\n";
            $email_data .= $body . "\r\n.\r\n";
            
            fwrite($smtp, $email_data);
            $response = $this->read_smtp_response($smtp);
            $this->debug_log("Email sent response", $response);
            
            // Quit
            fwrite($smtp, "QUIT\r\n");
            fclose($smtp);
            
            $this->debug_log("Email sent via SMTP successfully", [
                'recipients' => count($to),
                'subject' => $subject,
                'server' => $s['smtp_host'] . ':' . $s['smtp_port']
            ]);
            
        } catch (Exception $e) {
            $this->debug_log("SMTP Error", $e->getMessage());
            // Fallback to PHP mail
            $this->debug_log("Falling back to PHP mail()");
            $this->send_mail_php($to, $subject, $body);
        }
    }

    // Helper to read SMTP responses
    private function read_smtp_response($smtp): string {
        $response = '';
        while ($line = fgets($smtp, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break; // End of multi-line response
        }
        return trim($response);
    }

    // Enhanced test email
    private function send_test(): array {
        $s = self::get_settings();
        $to = array_filter(array_map('trim', explode(',', $s['recipients'])));
        if (empty($to)) {
            return ['ok'=>false,'text'=>yourls__('Please set at least one recipient, then save settings and try again.', YNM_DOMAIN)];
        }
        
        $method = $s['use_smtp'] ? 'SMTP' : 'PHP mail()';
        $subject = $s['subject_prefix'].' [TEST] Change Notifier mail function is configured';
        $body = "This is a test email from YOURLS Change Notifier (v".YNM_VERSION.").\n";
        $body .= "Email method: $method\n";
        if ($s['use_smtp']) {
            $body .= "SMTP server: {$s['smtp_host']}:{$s['smtp_port']}\n";
        }
        $body .= "Time: ".date('c')."\n";
        
        $this->debug_log("Sending test email via $method", ['recipients' => $to]);
        
        // Send using current method
        $this->send_mail($subject, $body);
        
        $this->debug_log("Test email sent", ['method' => $method, 'recipient_count' => count($to)]);
        
        return [
            'ok' => true, // We assume success as send_mail has fallback
            'text' => yourls__("Test email sent via $method.", YNM_DOMAIN)
        ];
    }
}
