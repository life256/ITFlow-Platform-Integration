<?php

/*
 * Site-specific RMM provider and network platform kinds - not part of ITFlow.
 *
 * Put in place by itflow_vendor_bolt_on.php, which also adds the three lines in
 * rmmProviderKinds() (functions/rmm.php) that load this file. An ITFlow update removes those
 * lines and leaves this folder alone, so after an update these kinds are switched off until the
 * script is run again - and it refuses to when ITFlow has since gained a kind of its own.
 *
 * Each kind's file sits beside this one. One whose functions ITFlow already has is left out:
 * ITFlow's own wins.
 */

/** $kinds with this install's own kinds added - never in place of one ITFlow has. */
function rmmCustomKinds(array $kinds): array
{
    static $custom = null;
    if ($custom === null) {
        $custom = [];
        // file => [the function it must not find already there, how its kinds are got]
        $files = [
            'rmm_action1.php' => ['rmmAction1Kind', function () {
                return ['action1' => rmmAction1Kind()];
            }],
            'rmm_msp360.php' => ['rmmMsp360Kind', function () {
                return ['msp360' => rmmMsp360Kind()];
            }],
            'rmm_omada.php' => ['rmmOmadaKinds', function () {
                return rmmOmadaKinds();
            }],
        ];
        foreach ($files as $file => [$function, $get]) {
            $path = __DIR__ . '/' . $file;
            if (function_exists($function) || !is_file($path)) {
                continue;
            }
            require_once $path;
            if (function_exists($function)) {
                $custom += $get();
            }
        }
    }
    foreach ($custom as $key => $kind) {
        // Network platforms need the 4.1.5 database update, as ITFlow's own do
        if (!isset($kinds[$key]) && (($kind['family'] ?? 'rmm') !== 'network' || rmmNetworkReady())) {
            $kinds[$key] = $kind;
        }
    }
    return $kinds;
}
