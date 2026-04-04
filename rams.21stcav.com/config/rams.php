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
    'company_name'  => env('RAMS_COMPANY_NAME',  '21st Century AV Ltd'),
    'company_short' => env('RAMS_COMPANY_SHORT', '21CAV'),
];
