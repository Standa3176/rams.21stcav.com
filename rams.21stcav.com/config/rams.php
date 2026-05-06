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

    /*
    |--------------------------------------------------------------------------
    | Mini O&M Support Boilerplate (260506-qa9 / D-LOCK-7)
    |--------------------------------------------------------------------------
    |
    | Client-facing copy embedded into every Mini O&M PDF generated via the
    | on-demand `projects.mini-om.pdf` route. Lives here (rather than in a
    | new config file or a hard-coded constant inside the Blade) so the
    | warranty terms and service-ticket instructions can be edited per
    | install or per region without touching code.
    |
    | Cascade rules:
    |  - support_phone / support_email fall back to the company-identity
    |    values above so a fresh deploy ships meaningful defaults without
    |    any .env edits.
    |  - warranty_terms / service_ticket_instructions are multi-line strings
    |    (use \n separators); the Blade renders them via
    |    nl2br(htmlspecialchars(...)) so client-facing punctuation survives.
    |
    */
    'mini_om_support' => [
        'support_phone' => env('RAMS_SUPPORT_PHONE', env('RAMS_COMPANY_PHONE', '01189 977770')),
        'support_email' => env('RAMS_SUPPORT_EMAIL', env('RAMS_COMPANY_EMAIL', 'support@21stcenturyav.com')),
        'warranty_terms' => env('RAMS_WARRANTY_TERMS',
            "All installed equipment is covered by the original manufacturer's warranty for the period stated on the product datasheet. "
            ."21st Century AV Ltd provides a 12-month workmanship warranty on installation labour from the project handover date.\n\n"
            ."Warranty does NOT cover: damage from misuse, unauthorised modifications, power surges where surge protection was declined, "
            ."or wear-and-tear consumables (lamps, filters, batteries)."
        ),
        'service_ticket_instructions' => env('RAMS_SERVICE_TICKET_INSTRUCTIONS',
            "To raise a service ticket:\n"
            ."1. Email support@21stcenturyav.com with the project reference, room name, and a clear description of the fault.\n"
            ."2. Attach a photo or short video of the problem when possible.\n"
            ."3. Our team will respond within one working day during business hours (Mon-Fri 09:00-17:30)."
        ),
    ],
];
