<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RamsDocument;
use App\Services\PdfService;

$rams = new RamsDocument();
$rams->id = 999;
$rams->project_name = '21CQ30143 – Worley Europe Ltd';
$rams->project_ref = '21CQ30143';
$rams->client_name = 'Worley Europe Ltd';
$rams->site_address = 'Chester Road, CH7 4HB Mold';
$rams->created_at = now();
$rams->generated_data = [
    'project' => [
        'name' => '21CQ30143 – Worley Europe Ltd',
        'ref' => '21CQ30143',
        'client' => 'Worley Europe Ltd',
        'site_address' => 'Chester Road, CH7 4HB Mold',
        'document_status' => 'For Construction',
    ],
    'hazards' => [
        [
            'hazard' => 'Manual Handling',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
            'pre_likelihood' => 3,
            'pre_severity' => 3,
            'controls' => [
                'Use mechanical aids where possible.',
                'Team lift items over 40kg.',
            ],
            'post_likelihood' => 2,
            'post_severity' => 2,
        ],
    ],
    'ppe' => [
        'Safety Boots (steel toe cap)',
        'Hi-Visibility Vest',
        'Safety Glasses',
        'Latex / Nitrile Gloves',
        'Hard Hat',
        'Dust Mask (FFP2)',
        'Hearing Protection',
    ],
    'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Others'],
    'team' => [
        ['role' => 'Lead Engineer', 'name' => 'To be confirmed', 'mobile' => ''],
        ['role' => 'Supervisor', 'name' => 'To be confirmed', 'mobile' => ''],
    ],
    'method_statement' => [
        'phases' => [
            ['title' => '1. Pre-Start Checks', 'steps' => [
                'Confirm site access arrangements and permits.',
                'Check tools and PPE are serviceable.',
            ]],
            ['title' => '2. Installation Works', 'steps' => [
                'Install containment and cabling routes.',
                'Mount displays and connect equipment.',
            ]],
        ],
    ],
    'quote' => [
        'line_items' => [
            ['qty' => 1, 'description' => 'Sony 85\" 4K UHD commercial display'],
            ['qty' => 1, 'description' => 'Chief X-Large Fusion Micro-Adjustable Tilt Wall Mount'],
        ],
    ],
];

$pdfPath = app(PdfService::class)->buildRams($rams);
echo $pdfPath . PHP_EOL;
