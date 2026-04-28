<?php

namespace Database\Seeders;

use App\Models\Manufacturer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Phase 2 — initial manufacturer support catalogue.
 *
 * Best-effort UK support details for the brands the O&M generator currently
 * encounters. Each entry has at minimum a public support_url so the resolver
 * can return a non-empty record without web fallback. Where a UK direct
 * phone or email is published, it is recorded here. Where contact is
 * dealer-mediated (e.g. Sony Pro, Barco), only the URL is populated and the
 * resolver will attempt a web lookup before throwing.
 *
 * Idempotent — uses updateOrCreate keyed on slug, safe to run repeatedly.
 */
class ManufacturerSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name'           => 'Biamp',
                'support_phone'  => null,
                'support_email'  => 'support@biamp.com',
                'support_url'    => 'https://support.biamp.com',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Logitech',
                'support_phone'  => '0800 0322 5251',
                'support_email'  => null,
                'support_url'    => 'https://support.logi.com/hc/en-gb',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Shure',
                'support_phone'  => '01923 816500',
                'support_email'  => 'service@shure.de',
                'support_url'    => 'https://www.shure.com/en-GB/support',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Sony',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://pro.sony/en_GB/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'iiyama',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://iiyama.com/gb_en/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Unicol',
                'support_phone'  => '+44 18 6586 7300',
                'support_email'  => 'sales@unicol.com',
                'support_url'    => 'https://www.unicol.com/contact',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Samsung',
                'support_phone'  => '0330 726 7864',
                'support_email'  => null,
                'support_url'    => 'https://www.samsung.com/uk/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'LG',
                'support_phone'  => '0344 847 5454',
                'support_email'  => null,
                'support_url'    => 'https://www.lg.com/uk/business/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Barco',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.barco.com/en/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Crestron',
                'support_phone'  => '+44 121 241 3780',
                'support_email'  => null,
                'support_url'    => 'https://www.crestron.com/Support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'QSC',
                'support_phone'  => '+44 20 8752 8600',
                'support_email'  => null,
                'support_url'    => 'https://www.qsc.com/about-qsc/contact',
                'warranty_years' => 6,
            ],
            [
                'name'           => 'Q-SYS',
                'support_phone'  => '+44 20 8752 8600',
                'support_email'  => 'support@qsys.com',
                'support_url'    => 'https://support.qsys.com',
                'warranty_years' => 6,
            ],
            [
                'name'           => 'Sennheiser',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.sennheiser.com/en-gb/contact',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Yealink',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.yealink.com/en/onepage/contact-us',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Panasonic',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://business.panasonic.co.uk/visual-system/support',
                'warranty_years' => 3,
            ],
        ];

        foreach ($rows as $row) {
            $row['slug'] = Str::slug($row['name']);
            Manufacturer::updateOrCreate(['slug' => $row['slug']], $row);
        }
    }
}
