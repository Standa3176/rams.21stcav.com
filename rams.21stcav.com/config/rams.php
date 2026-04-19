<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Company Identity
    |--------------------------------------------------------------------------
    |
    | These values appear in generated .docx documents (title block, footer,
    | document control table) and in the browser UI (nav brand).
    |
    | Override them in .env without touching code:
    |   RAMS_COMPANY_NAME="Acme Safety Ltd"
    |   RAMS_COMPANY_SHORT="ACME"
    |
    */
    'company_name'    => env('RAMS_COMPANY_NAME',    '21st Century AV Ltd'),
    'company_short'   => env('RAMS_COMPANY_SHORT',   '21CAV'),
    'company_address' => env('RAMS_COMPANY_ADDRESS', 'Thames Court, 2 Richfield Avenue, Reading, Berkshire'),
    'company_phone'   => env('RAMS_COMPANY_PHONE',   '01189 977770'),
    'company_website' => env('RAMS_COMPANY_WEBSITE', 'www.21stcenturyav.com'),
    'company_email'   => env('RAMS_COMPANY_EMAIL',   'info@21stcenturyav.com'),

    /*
    |--------------------------------------------------------------------------
    | Notifications (Phase 09)
    |--------------------------------------------------------------------------
    |
    | Global BCC address applied to every system email (completion, failure,
    | review-needed, survey-submitted). Leave RAMS_NOTIFICATION_BCC unset in
    | dev/staging — a null value means no BCC is attached (the shared
    | dispatcher treats null / empty string as "skip BCC").
    |
    */
    'notifications' => [
        'bcc' => env('RAMS_NOTIFICATION_BCC'),   // null/empty = no BCC applied to system mail
    ],
];
