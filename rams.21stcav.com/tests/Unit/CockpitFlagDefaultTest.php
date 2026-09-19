<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Anti-rot guard for the cockpit kill-switch (Phase 45, VIS-10, T-45-03-01).
 *
 * This deliberately does NOT assert on config('cockpit.enabled'). The test
 * harness — or a `config:cache` artefact left in bootstrap/cache — can hold a
 * value that has nothing to do with what the file says, so a config()-based
 * assertion would pass while the shipped default was true. Instead this reads
 * the config FILE: once as raw text (the literal `env(..., false)`), and once
 * by requiring it with COCKPIT_ENABLED scrubbed from every environment
 * source.
 *
 * Why it matters: the cockpit renders against a `visits` table that is empty
 * until `visits:backfill --apply` has run, so a default of true would ship an
 * empty page to every PM — the "content gate defaulting ON is a deploy-order
 * trap" failure recorded at config/rams_tier1.php:119-134.
 */
class CockpitFlagDefaultTest extends TestCase
{
    public function test_cockpit_flag_literal_default_in_the_config_file_is_false(): void
    {
        $path = config_path('cockpit.php');

        $this->assertFileExists($path, 'config/cockpit.php must exist — it is the cockpit kill-switch.');

        // 1. The literal source text. Catches a flip even if nothing bootstraps.
        $source = file_get_contents($path);
        $this->assertMatchesRegularExpression(
            "/'enabled'\s*=>\s*env\(\s*'COCKPIT_ENABLED'\s*,\s*false\s*\)/",
            $source,
            "config/cockpit.php must read env('COCKPIT_ENABLED', false). The default MUST be false: "
            .'the cockpit reads an empty `visits` table until the backfill has run, so arming it by '
            .'default reproduces the deploy-order trap documented in config/rams_tier1.php:119-134.'
        );

        // 2. The evaluated default, with the variable removed from every source
        //    env() consults, so nothing in the environment can mask it.
        $previous = getenv('COCKPIT_ENABLED');
        putenv('COCKPIT_ENABLED');
        unset($_ENV['COCKPIT_ENABLED'], $_SERVER['COCKPIT_ENABLED']);

        try {
            $config = require $path;
        } finally {
            if ($previous !== false) {
                putenv('COCKPIT_ENABLED='.$previous);
                $_ENV['COCKPIT_ENABLED'] = $previous;
                $_SERVER['COCKPIT_ENABLED'] = $previous;
            }
        }

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertFalse(
            $config['enabled'],
            'With COCKPIT_ENABLED absent from the environment, config/cockpit.php must evaluate to false.'
        );
    }

    public function test_cockpit_flag_is_a_single_global_boolean(): void
    {
        $config = require config_path('cockpit.php');

        // No per-project / per-user / per-role scoping: 45-CONTEXT.md names a
        // global env flag as the default and every flag precedent here matches.
        $this->assertSame(['enabled'], array_keys($config));
        $this->assertIsBool($config['enabled']);
    }
}
