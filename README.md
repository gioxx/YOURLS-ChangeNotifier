# 🔔 YOURLS Change Notifier

**Stay informed about every change to your YOURLS short URLs with instant email notifications.**  
Never miss a creation, edit, or deletion again — whether it happens through admin panel or API.

[![Latest Release](https://img.shields.io/github/v/release/gioxx/YOURLS-ChangeNotifier)](https://github.com/gioxx/YOURLS-ChangeNotifier/releases)
[![License](https://img.shields.io/github/license/gioxx/YOURLS-ChangeNotifier)](LICENSE)

---

## 🚀 Features

- **Real-time email notifications** for URL creation, editing, and deletion
- **Dual email delivery**: PHP mail() or full SMTP configuration
- **SMTP support** with SSL/TLS encryption and authentication  
- **Password-protected admin panel** for secure configuration
- **Smart data capture** with before/after snapshots for edits
- **Advanced debug logging** with automatic rotation (5MB limit)
- **User identification** with IP tracking and authentication details
- **Customizable email templates** with detailed event information
- **Reset options** for settings and admin password
- **Fully internationalized** (i18n-ready)

---

## 🛠️ Installation

1. Download the plugin from [the latest release](https://github.com/gioxx/YOURLS-ChangeNotifier/releases).
2. Unzip the contents into the `user/plugins/yourls-change-notifier/` directory.
3. Activate the plugin in the YOURLS admin panel.
4. Go to the plugin settings page and complete the initial password setup.
5. Configure your notification preferences and email settings.

> **Requires YOURLS 1.9+ and PHP 7.4+**

---

## ⚙️ Usage

### 🔐 First-Time Setup
Set an admin password to protect the plugin configuration from unauthorized access.

### 📧 Basic Configuration
- **Recipients**: Add comma-separated email addresses to receive notifications
- **Events**: Choose which actions trigger notifications (Create/Edit/Delete)
- **Subject prefix**: Customize email subject lines with your own prefix

### 📨 Email Methods

#### PHP mail() Function
Use your server's built-in mail functionality — simple and works out of the box.

#### SMTP Configuration
Configure external SMTP servers with advanced options:
- **Host & Port**: Connect to a SMTP server
- **Security**: Support for SSL/TLS encryption
- **Authentication**: Username and password with app password support
- **Custom sender**: Set your own "From" name and email address

### 🐛 Debug & Troubleshooting
- **Debug logging**: Track email delivery and plugin events
- **Automatic log rotation**: Keeps log files manageable (rotates at 5MB)
- **Test email function**: Verify your configuration works
- **Reset options**: Start fresh when needed

---

## 📧 Email Content

Each notification includes comprehensive details:
- **Event type** (CREATE/EDIT/DELETE)  
- **Timestamp** with precise timing
- **User information** (who made the change + IP address)
- **URL details** (short URL, target URL, title)
- **Before/after snapshots** for edit operations
- **YOURLS instance** identification

Example notification:
```
Event:    CREATE
When:     2025-09-25T12:30:45+00:00
By:       user: admin
IP:       192.168.1.100
Instance: https://yourdomain.com/
Short:    https://yourdomain.com/abc123
Keyword:  abc123
Title:    My Important Link
Target:   https://example.com/very-long-url-here
```

---

## 🔧 Advanced Features

### Password Protection
- **Secure admin panel** with hashed password storage
- **Session management** for convenient access
- **Password reset option** to return to initial setup

### Smart Data Capture
- **Pre-edit snapshots** capture original values before changes
- **Delete data preservation** using multiple fallback methods
- **API compatibility** works with both admin panel and API operations

### SMTP Support
- **Native implementation** — no external libraries required
- **Full protocol support** including EHLO, STARTTLS, AUTH LOGIN
- **Automatic fallback** to PHP mail() if SMTP fails
- **Detailed logging** for troubleshooting connection issues

---

## 🌐 Translation

This plugin is fully localized and ready for internationalization.  
Available languages:
- 🇬🇧 English (default)

You can contribute new translations via `.po`/`.mo` files inside the `languages/` folder.

---

## 🛡️ Security Features

- **Password hashing** using PHP's `password_hash()`
- **CSRF protection** with WordPress-style nonces
- **Session-based authentication** with automatic logout
- **Encoded SMTP passwords** for secure storage
- **Input validation** and sanitization throughout

---

## 💡 Use Cases

- **Team collaboration**: Keep team members informed of URL changes
- **Content management**: Track when marketing URLs are modified  
- **Security monitoring**: Get notified of unauthorized URL modifications
- **API monitoring**: Track programmatic URL operations
- **Audit trails**: Maintain records of all URL lifecycle events

---

## 📄 License

This plugin is licensed under the [MIT License](LICENSE).

---

## 💬 About

Lovingly developed by the usually-on-vacation brain cell of [Gioxx](https://github.com/gioxx), with extensive assistance from Claude AI for advanced features, SMTP implementation, and debugging capabilities.

---

## 🤝 Contributing

Pull requests and feature suggestions are welcome!  
If you find bugs or have feature requests, [open an issue](https://github.com/gioxx/YOURLS-ChangeNotifier/issues).  
If you find it useful, leave a ⭐ on GitHub! ❤️