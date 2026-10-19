<?php
// Copy to app/config.php and fill in. app/config.php is git-ignored (and chmod 600 it).
// The repo root is the web root (CloudPanel default). app/, bin/, sql/, tests/ are never
// served: the vhost in deploy/cloudpanel-vhost.conf only whitelists the site's own files.

return [
    // MySQL / MariaDB (production). For local dev you can use:
    //   'dsn' => 'sqlite:' . __DIR__ . '/../var/plava-nafta.sqlite'
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=plava_nafta;charset=utf8mb4', // CloudPanel: database name + user from the Databases tab
        'user' => 'plava_nafta',
        'pass' => 'change-me',
    ],

    // 32 random bytes, base64. Encrypts the TOTP secret at rest.
    // Generate with:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Changing it invalidates the stored 2FA secret (run bin/reset-2fa.php).
    'app_key' => '',

    // Folder nginx serves (the repo root). The CMS regenerates index.html, the legal
    // pages and data/stations.json (+ .gz copies) here on every save.
    'web_root' => dirname(__DIR__),

    // URL path of the hidden admin (must match the plava-nafta/ folder name).
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

    // When the ina.hr hours table says "PRIVREMENO ZATVORENO" for every day, the sync marks the
    // station temporarily closed (and reopens it when real hours return). Closures you set by
    // hand are never changed. false = only flag it in the editor.
    'ina_auto_close' => true,

    // Bunny CDN (optional). With an API key, every save clears Bunny's cache for the site,
    // so visitors never get an old copy. Bunny panel → Account → API → "API Key"
    // (account key); pullzone_id = the number in the pull zone's URL, used by bin/bunny-purge.php --zone.
    'bunny' => [
        'api_key'     => '',
        'pullzone_id' => '',
        'site_url'    => 'https://plava-nafta.crodesign93.eu',
    ],

    // Keep false with Bunny: nginx already puts the real visitor IP in REMOTE_ADDR (see the
    // BUNNY block in deploy/cloudpanel-vhost.conf). true only for a proxy that is NOT set up that way.
    'trust_proxy' => false,
];
