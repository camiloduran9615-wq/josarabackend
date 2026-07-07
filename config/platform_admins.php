<?php

$notificationRecipients = [];

for ($i = 1; $i <= 10; $i++) {
    $email = env("PLATFORM_ADMIN_{$i}_EMAIL");
    $active = filter_var(env("PLATFORM_ADMIN_{$i}_ACTIVE", false), FILTER_VALIDATE_BOOLEAN);

    if ($active && is_string($email) && trim($email) !== '') {
        $notificationRecipients[] = [
            'name' => env("PLATFORM_ADMIN_{$i}_NAME"),
            'email' => $email,
        ];
    }
}

return [
    'seed' => [
        [
            'name' => env('PLATFORM_ADMIN_1_NAME'),
            'email' => env('PLATFORM_ADMIN_1_EMAIL'),
            'password' => env('PLATFORM_ADMIN_1_PASSWORD'),
            'role' => env('PLATFORM_ADMIN_1_ROLE', 'super_admin'),
            'active' => env('PLATFORM_ADMIN_1_ACTIVE', true),
        ],
        [
            'name' => env('PLATFORM_ADMIN_2_NAME'),
            'email' => env('PLATFORM_ADMIN_2_EMAIL'),
            'password' => env('PLATFORM_ADMIN_2_PASSWORD'),
            'role' => env('PLATFORM_ADMIN_2_ROLE', 'support_admin'),
            'active' => env('PLATFORM_ADMIN_2_ACTIVE', true),
        ],
    ],
    'notification_recipients' => $notificationRecipients,
];
