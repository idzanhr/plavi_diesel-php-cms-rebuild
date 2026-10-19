<?php
// Copy to app/config.php and fill in. app/config.php is git-ignored.
// Keep the whole app/ directory OUTSIDE the web root (only public/ is served).

return [
    // MySQL / MariaDB (production). For local dev you can use:
    //   'dsn' => 'sqlite:' . __DIR__ . '/../var/plava-nafta.sqlite'
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=plava_nafta;charset=utf8mb4',
        'user' => 'plava_nafta',
        'pass' => 'change-me',
    ],

    // 32 random bytes, base64. Encrypts the TOTP secret at rest.
    // Generate with:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Changing it invalidates the stored 2FA secret (run bin/reset-2fa.php).
    'app_key' => '',

    // Public web root; the CMS regenerates index.html and data/stations.json here.
    'public_dir' => __DIR__ . '/../public',

    // URL path of the hidden admin (must match the folder inside public/).
    'admin_path' => '/plava-nafta/',

    // Name shown in the authenticator app.
    'totp_issuer' => 'Plava nafta CMS',

    // Allow the admin to scan the first TOTP QR code in the browser right after
    // the first password login. Set to false once 2FA is enrolled if you want
    // re-enrolment to be possible only from the CLI (bin/reset-2fa.php).
    'allow_web_enrollment' => true,

    // Session lifetime.
    'session_idle_seconds'     => 1800,   // 30 min without activity
    'session_absolute_seconds' => 28800,  // 8 h max

    // Brute-force protection (counts password, 2FA and recovery-code failures).
    'max_failed_per_ip'       => 3,       // then the IP is locked out ...
    'max_failed_global'       => 20,      // ... and the whole login, across all IPs
    'lockout_minutes'         => 15,
    'lockout_redirect'        => 'https://crodesign93.eu',

    // Set to true only behind a reverse proxy/CDN you control that sets X-Forwarded-For.
    'trust_proxy' => false,
];
