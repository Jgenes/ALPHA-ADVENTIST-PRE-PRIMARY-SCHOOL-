<?php
/**
 * COPY THIS FILE TO config.php IN THIS SAME PRIVATE FOLDER, then edit it.
 * Never place config.php, source code or private uploads inside public_html.
 * This file is the ONLY settings file needed for a new cPanel installation.
 * Existing installs: preserve the old DATA_DIR and application keys!
 */
return [
    'APP_ENV' => 'production',
    'BASE_URL' => 'https://your-school-domain.example',
    // Automatic private path: no cPanel username or PHP path editing required.
    'DATA_DIR' => __DIR__ . '/private',
    'TRUST_PROXY_HOPS' => 0,

    // Use the FULL database and username shown in cPanel Database Wizard.
    'MYSQL_HOST' => 'localhost',
    'MYSQL_PORT' => 3306,
    'MYSQL_DB_NAME' => 'account_alpha_school',
    'MYSQL_USER' => 'account_schooluser',
    'MYSQL_PASSWORD' => '',
    // Empty = local loopback allowed; remote production MySQL requires TLS.
    'MYSQL_SSL_MODE' => '',
    'MYSQL_TLS_CA_FILE' => '',

    // Use your provider's actual SMTP settings. Production needs email alerts.
    'SMTP_HOST' => '',
    'SMTP_PORT' => 587,
    'SMTP_SECURITY' => 'starttls', // Or tls with port 465.
    'SMTP_USERNAME' => '',
    'SMTP_PASSWORD' => '',
    'SMTP_FROM_EMAIL' => '',
    'SMTP_FROM_NAME' => 'Alpha School',
    'OFFICE_ALERT_EMAIL' => '',
    'PRIVACY_ALERT_EMAIL' => '',

    // Enter school-approved integer periods (1–3650 days), then approve.
    'FORM_RETENTION_DAYS' => '',
    'ADMISSION_RETENTION_DAYS' => '',
    'RETENTION_POLICY_APPROVED' => false,

    'STORAGE_DRIVER' => 'local',
    // For NEW installations only: blank generates keys once in DATA_DIR.
    // Preserve these keys/private files on every update and back them up.
    'STORAGE_ENCRYPTION_KEY' => '',
    'AUDIT_HMAC_KEY' => '',

    // Optional browser installer. Set a UNIQUE RANDOM token, at least 32
    // characters, using cPanel's password generator. Never put it in a URL.
    // Visit https://yourdomain/setup.php and enter it there. Clear it after
    // installation and delete public_html/setup.php. Blank disables setup.
    'SETUP_TOKEN' => '',

    // Optional owner-only troubleshooting page for a "temporarily unavailable"
    // website. Set a DIFFERENT unique random token of at least 32 characters,
    // then open https://yourdomain/diagnose.php. It is read-only: it never
    // changes a record and never sends email. Delete public_html/diagnose.php
    // and clear this token once the website is healthy. Blank disables it.
    'DIAGNOSTIC_TOKEN' => '',
];
