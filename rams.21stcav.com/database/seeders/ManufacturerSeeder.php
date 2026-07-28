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

            // ─── 260728-mfr1 batch — vendors 21CAV commonly touches ────────────
            // Added after Trent Park House O&M generation failed on BTech
            // ("Could not resolve UK support details for manufacturer 'BTech'").
            // Every entry carries at least a support_url so the resolver's
            // isUsable() check (phone OR email OR URL) passes without falling
            // through to a web lookup. Phone/email left null where a UK direct
            // channel isn't published — user can refine per-manufacturer later.

            // Mounts
            [
                'name'           => 'BTech',
                'support_phone'  => '+44 1279 501111',
                'support_email'  => 'sales@btechavmounts.com',
                'support_url'    => 'https://www.btechavmounts.com/contact',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Chief',
                'support_phone'  => null,
                'support_email'  => 'chieftechsupport@legrand.com',
                'support_url'    => 'https://www.legrandav.com/support',
                'warranty_years' => 10,
            ],
            [
                'name'           => 'Peerless-AV',
                'support_phone'  => null,
                'support_email'  => 'sales@peerless-av.co.uk',
                'support_url'    => 'https://www.peerless-av.com/en-uk/professional/contact',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Vogels',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.vogels.com/service-support',
                'warranty_years' => 5,
            ],

            // Displays / interactive
            [
                'name'           => 'Clevertouch',
                'support_phone'  => '+44 20 8319 7777',
                'support_email'  => 'help@clevertouch.com',
                'support_url'    => 'https://www.clevertouch.com/uk/support',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'NEC',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.sharpnecdisplays.eu/p/uk/en/support/support.xhtml',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Sharp',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.sharpnecdisplays.eu/p/uk/en/support/support.xhtml',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Philips',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.philips.co.uk/c-w/support-home.html',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Hisense',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://uk.hisense.com/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'BenQ',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.benq.eu/en-uk/support.html',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'ViewSonic',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.viewsonic.com/uk/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Newline',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://newline-interactive.com/eur/support/',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Promethean',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://support.prometheanworld.com/',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Epson',
                'support_phone'  => '+44 20 8081 6790',
                'support_email'  => null,
                'support_url'    => 'https://www.epson.co.uk/en_GB/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Optoma',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.optoma.co.uk/support',
                'warranty_years' => 3,
            ],

            // Video conferencing
            [
                'name'           => 'AVer',
                'support_phone'  => null,
                'support_email'  => 'eusupport.info@aver.com',
                'support_url'    => 'https://communication.aver.com/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Cisco',
                'support_phone'  => '+44 800 015 5109',
                'support_email'  => null,
                'support_url'    => 'https://www.cisco.com/c/en_uk/support/index.html',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Poly',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://support.hp.com/gb-en/poly',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Polycom',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://support.hp.com/gb-en/poly',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Neat',
                'support_phone'  => null,
                'support_email'  => 'support@neat.no',
                'support_url'    => 'https://support.neat.no',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Huddly',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://support.huddly.com/hc/en-us',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Jabra',
                'support_phone'  => '+44 20 3535 2054',
                'support_email'  => null,
                'support_url'    => 'https://www.jabra.co.uk/supportpages/business-support',
                'warranty_years' => 3,
            ],

            // Control / Automation
            [
                'name'           => 'Extron',
                'support_phone'  => '+44 3333 207 700',
                'support_email'  => 'techsupport@extron.com',
                'support_url'    => 'https://www.extron.com/company/support.aspx',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'AMX',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://help.harman.com/pkb_home?pkb_category=amx',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Kramer',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www1.kramerav.com/support-technical/',
                'warranty_years' => 7,
            ],
            [
                'name'           => 'Atlona',
                'support_phone'  => null,
                'support_email'  => 'techsupport@atlona.com',
                'support_url'    => 'https://atlona.com/support/',
                'warranty_years' => 10,
            ],
            [
                'name'           => 'Lightware',
                'support_phone'  => null,
                'support_email'  => 'support@lightware.com',
                'support_url'    => 'https://lightware.com/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Blustream',
                'support_phone'  => '+44 1279 621866',
                'support_email'  => 'support@blustream.co.uk',
                'support_url'    => 'https://www.blustream.co.uk/support',
                'warranty_years' => 3,
            ],

            // Audio
            [
                'name'           => 'Bose',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://pro.bose.com/en_gb/support.html',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Bosch',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.boschsecurity.com/gb/en/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'JBL',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://uk.harmanpro.com/product-support',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'LEA',
                'support_phone'  => null,
                'support_email'  => 'support@leaprofessional.com',
                'support_url'    => 'https://leaprofessional.com/support',
                'warranty_years' => 6,
            ],
            [
                'name'           => 'Ampetronic',
                'support_phone'  => '+44 1636 610062',
                'support_email'  => 'sales@ampetronic.com',
                'support_url'    => 'https://www.ampetronic.com/contact/',
                'warranty_years' => 5,
            ],

            // Network
            [
                'name'           => 'Netgear',
                'support_phone'  => '+44 344 875 4000',
                'support_email'  => null,
                'support_url'    => 'https://www.netgear.com/uk/support/',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'Ubiquiti',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://help.ui.com/hc/en-us',
                'warranty_years' => 1,
            ],
            [
                'name'           => 'HPE',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://support.hpe.com/hpesc/public/home',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Aruba',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.arubanetworks.com/support-services/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'Meraki',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://meraki.cisco.com/support/',
                'warranty_years' => 3,
            ],

            // Rack / power
            [
                'name'           => 'Middle Atlantic',
                'support_phone'  => null,
                'support_email'  => 'techsupport@legrand.com',
                'support_url'    => 'https://www.legrandav.com/support',
                'warranty_years' => 5,
            ],
            [
                'name'           => 'APC',
                'support_phone'  => '0800 279 7254',
                'support_email'  => null,
                'support_url'    => 'https://www.apc.com/gb/en/support/',
                'warranty_years' => 3,
            ],
            [
                'name'           => 'SurgeX',
                'support_phone'  => null,
                'support_email'  => 'support@surgex.com',
                'support_url'    => 'https://www.surgex.com/support/',
                'warranty_years' => 10,
            ],
            [
                'name'           => 'Tripp Lite',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.tripplite.com/support',
                'warranty_years' => 2,
            ],
            [
                'name'           => 'Furman',
                'support_phone'  => null,
                'support_email'  => null,
                'support_url'    => 'https://www.furmanpower.com/support',
                'warranty_years' => 3,
            ],
            [
                'name'           => '21st Century AV',
                'support_phone'  => '01189 977770',
                'support_email'  => 'support@21stcenturyav.com',
                'support_url'    => 'https://www.21stcenturyav.com',
                'warranty_years' => 1,
            ],
        ];

        foreach ($rows as $row) {
            $row['slug'] = Str::slug($row['name']);
            Manufacturer::updateOrCreate(['slug' => $row['slug']], $row);
        }
    }
}
