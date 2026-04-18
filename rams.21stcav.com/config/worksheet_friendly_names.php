<?php

/**
 * SKU → friendly-description map used by App\Services\Worksheet\FriendlyNameResolver.
 *
 * Only consulted when an item's rendered name looks like a bare part number.
 * Extend freely — this file is deliberately separate from the taxonomy config
 * so adding a new friendly name doesn't touch classification logic.
 *
 * Keys are matched case-insensitively.
 */
return [
    // Shure wireless audio
    'MXW2X/SM86'    => 'Shure Microflex Wireless 2 Handheld Transmitter (SM86 capsule)',
    'MXW1X/O'       => 'Shure Microflex Wireless 1 Bodypack Transmitter',
    'MXWAPXD2UK'    => 'Shure Microflex Wireless Access Point Dock (UK)',
    'UL4B/C-MTQG-A' => 'Shure UniPlex UL4B Lavalier Microphone',

    // Display mounts (Chief)
    'CH-MTM1U'      => 'Chief Fusion Flat-Panel Wall Mount',
    'XSM1U'         => 'Chief X-Large Heavy-Duty Wall Mount',

    // Extron control / sensors
    '60-1705-03'    => 'Extron Partition Sensor',

    // Q-SYS audio
    '16207'         => 'Q-SYS Core 8 Flex Integrated Processor',
    '12254'         => 'Q-SYS AD-P4TB Pendant Loudspeaker',

    // Netgear managed switches
    'GSM4230PX'     => 'Netgear M4250 24-Port PoE+ Managed Switch',

    // Keystone rack infrastructure
    '12752'         => 'Keystone 24-Port Patch Panel',

    // LEA amplifiers
    '19351'         => 'LEA Connect Series CS 64D Amplifier',
];
