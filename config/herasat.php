<?php

return [
    // Frontend origin (used for CORS / Sanctum stateful domains documentation)
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'auth' => [
        // Failed attempts before the account is temporarily locked
        'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
        // Captcha becomes mandatory on login after this many failures from the same IP/username
        'captcha_after_failures' => (int) env('AUTH_CAPTCHA_AFTER_FAILURES', 2),
        // Absolute session lifetime (minutes) regardless of activity
        'absolute_session_minutes' => (int) env('AUTH_ABSOLUTE_SESSION_MINUTES', 480),
        // bcrypt cost used only for imported default passwords (national code). The user is forced to
        // change it on first login, and the new password uses the normal BCRYPT_ROUNDS cost.
        'import_bcrypt_rounds' => (int) env('IMPORT_BCRYPT_ROUNDS', 10),
    ],

    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 10),
    ],

    'import' => [
        'personnel_code_pad' => (int) env('IMPORT_PERSONNEL_CODE_PAD', 6),
        'max_upload_kb' => (int) env('IMPORT_MAX_UPLOAD_KB', 5120),
    ],

    'reports' => [
        'max_attachments' => 3,
        'max_attachment_kb' => 5120,
        // extension => allowed MIME types detected from file content
        'allowed_mimes' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
        ],
        'disk' => 'local', // storage/app/private (outside the public web root)
        'categories' => [
            'personnel' => 'حفاظت پرسنلی و سلامت اقتصادی',
            'physical' => 'حفاظت فیزیکی و پدافند غیرعامل',
            'it_security' => 'امنیت فناوری اطلاعات و اسناد',
            'electronic' => 'حفاظت الکترونیک',
            'explosives' => 'مواد ناریه و انفجاری',
            'corruption' => 'تخلف مالی / فساد اقتصادی',
            'other' => 'سایر',
        ],
    ],

    'captcha' => [
        'length' => 5,
        'ttl_seconds' => 300,
        // ambiguous characters removed
        'alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
    ],

    'security_headers' => [
        // Enable HSTS only when the site is served over HTTPS
        'hsts' => (bool) env('SECURITY_HSTS', false),
    ],
];
