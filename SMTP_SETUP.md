# SMTP Configuration Setup

The email system now uses **PHPMailer with SMTP** for reliable email delivery instead of the `mail()` function.

## Configuration Options

The system supports three ways to configure SMTP credentials:

### Option 1: Environment Variables (Recommended for Production)

Set environment variables on your server:

```bash
export SMTP_HOST="smtp.hostinger.com"
export SMTP_PORT="465"
export SMTP_SECURE="ssl"
export SMTP_USERNAME="contact@maboximmo.fr"
export SMTP_PASSWORD="your-password-here"
export MAIL_FROM="contact@maboximmo.fr"
export MAIL_FROM_NAME="MaBoxImmo"
```

### Option 2: Configuration File (Development)

Create one of these files with your SMTP credentials:

1. **`public_html/smtp_config.php`** (local, not version controlled)
2. **`smtp_config.php`** (in parent directory)
3. **`/home/u630423897/smtp_config.php`** (production server home)

File content:
```php
<?php
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USERNAME', 'contact@maboximmo.fr');
define('SMTP_PASSWORD', 'your-actual-password-here');
define('MAIL_FROM', 'contact@maboximmo.fr');
define('MAIL_FROM_NAME', 'MaBoxImmo');
```

### Option 3: Default Values

If no configuration is found, the system uses these defaults:
- Host: `smtp.hostinger.com`
- Port: `465`
- Security: `ssl`
- Username: `contact@maboximmo.fr`
- Password: (empty - email will fail unless overridden)
- From: `contact@maboximmo.fr`
- From Name: `MaBoxImmo`

## SMTP Configuration Details

| Parameter | Value |
|-----------|-------|
| SMTP Host | `smtp.hostinger.com` |
| SMTP Port | `465` |
| Security | `SSL` |
| Username | `contact@maboximmo.fr` |
| Password | Your Hostinger account password |

## Email Features

- **Format**: HTML emails with professional styling
- **Attachments**: Salary PDF documents attached automatically
- **Recipients**: User email addresses from the database
- **Sender**: `contact@maboximmo.fr` (MaBoxImmo)

## Testing Email Sending

The email is sent when an admin clicks the envelope icon (📧) on the salary admin page (`rh_salaires.php`).

**Email includes:**
1. Professional HTML-formatted message
2. Salary notification PDF attachment
3. Instructions for creating/updating salary

## Troubleshooting

If emails don't send:

1. **Check SMTP password** - Ensure the password is correctly set
2. **Verify SMTP credentials** - Test in Hostinger control panel
3. **Check firewall** - Port 465 must be open to `smtp.hostinger.com`
4. **Review logs** - Check application debug mode for SMTP errors
5. **Enable debug mode** - Set `APP_DEBUG = true` in `inc/bootstrap.php` to see SMTP errors

## Code Files Modified

- `public_html/inc/mailer.php` - Updated to use PHPMailer with SMTP
- `public_html/config/smtp.php` - New SMTP configuration function
- `public_html/rh_salaires.php` - Updated email handler with PDF attachment
- `public_html/lib/phpmailer/` - PHPMailer library (new)
- `public_html/tcpdf/` - TCPDF library for PDF generation (new)

## Implementation Details

The `send_mail()` function in `inc/mailer.php` now:
1. Uses SMTP via PHPMailer
2. Supports HTML content
3. Supports file attachments
4. Includes error handling and logging

Example usage:
```php
send_mail(
    'user@example.com',
    'Subject',
    '<p>HTML content</p>',
    ['/path/to/file.pdf'],
    true  // isHtml = true
);
```
