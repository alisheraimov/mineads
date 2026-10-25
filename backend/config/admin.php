<?php

return [
    'roles' => [
        'admin-owner' => ['label' => 'Owner', 'description' => 'Full console access. Create and manage admins, assign roles, and manage commercial users.'],
        'ad-ops' => ['label' => 'Ad Operations', 'description' => 'View and manage campaigns, review creatives, and view analytics.'],
        'finance' => ['label' => 'Finance', 'description' => 'View analytics and financial information, and manage finance.'],
        'support' => ['label' => 'Support', 'description' => 'View users and accounts, campaigns and plugin information. No account-management or finance access.'],
    ],
    'health_invalid_rate_percent' => 10,
    'health_minimum_processed_impressions' => 100,
];
