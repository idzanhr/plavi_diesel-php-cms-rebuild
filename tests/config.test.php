<?php
return [
    'db' => ['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null],
    'app_key' => base64_encode(str_repeat('k', 32)),
    'public_dir' => sys_get_temp_dir() . '/plava-nafta-test-public',
];
