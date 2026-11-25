<?php

/*
 * Bolt-on for ITFlow's RMM Providers / Network Platforms: adds this install's own vendor kinds
 * (Action1, MSP360 RMM, Omada) without waiting for ITFlow to ship them.
 *
 *   php itflow_vendor_bolt_on.php status /var/www/your-itflow
 *   php itflow_vendor_bolt_on.php apply  /var/www/your-itflow
 *   php itflow_vendor_bolt_on.php remove /var/www/your-itflow
 *
 * Run it as the user that owns the ITFlow files (sudo -u www-data php ...). Keep this folder
 * outside the web root.
 *
 * What apply does:
 *   1. Refuses when ITFlow itself has gained a kind this would add, when the functions the
 *      vendor files call are no longer in functions/rmm.php, or when the place the hook goes
 *      can't be found exactly once. Nothing is changed when it refuses.
 *   2. Copies rmm_kinds.php and the vendor files into functions/custom/ (new, untracked files -
 *      an ITFlow update leaves them alone).
 *   3. Loads ITFlow with the vendor files in a separate process, before anything else loads
 *      them. When that fails it stops there - the hook is never added.
 *   4. Adds one marked block of four lines to rmmProviderKinds() in functions/rmm.php - the only
 *      change to a file ITFlow tracks. An ITFlow update (git reset --hard) removes it, which
 *      switches the bolt-on off until apply is run again.
 *   5. Loads ITFlow again with the hook in and checks the kinds are there. When that fails, the
 *      block is taken out again, so a broken bolt-on never stays live.
 *
 * remove takes the block out and deletes functions/custom/'s bolt-on files. Providers of these
 * kinds stay in the database; ITFlow shows them as a kind it doesn't know until apply is run.
 */

if (php_sapi_name() !== 'cli') {
    exit("Command line only.\n");
}

const BOLT_ON_BEGIN = '    // BOLT-ON BEGIN (itflow_vendor_bolt_on.php) - this install\'s own kinds; an ITFlow update removes these lines';
const BOLT_ON_END = '    // BOLT-ON END';
const BOLT_ON_ANCHOR = "\n    if (\$family === null) {\n";

// vendor file => [the function that says ITFlow has this kind itself, the kinds it adds]
const BOLT_ON_VENDORS = [
    'rmm_action1.php' => ['rmmAction1Kind', ['action1']],
    'rmm_msp360.php' => ['rmmMsp360Kind', ['msp360']],
    'rmm_omada.php' => ['rmmOmadaKinds', ['omada', 'omada_key']],
];

// What the vendor files call in functions/rmm.php - gone or renamed, they would stop ITFlow loading
const BOLT_ON_NEEDS = ['rmmHttp', 'rmmText', 'rmmMac', 'rmmTime', 'rmmSecret', 'rmmUrlClean', 'rmmProviderName', 'rmmIsList', 'rmmIsPlaceholder', 'rmmNetworkReady', 'rmmProviderKinds'];

function boltStop(string $message, int $code = 1): void
{
    fwrite($code ? STDERR : STDOUT, $message . "\n");
    exit($code);
}

function boltLint(string $file): string
{
    $output = [];
    $status = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    return $status === 0 ? '' : implode("\n", $output);
}

function boltBlock(): string
{
    return BOLT_ON_BEGIN . "\n"
        . "    if (is_file(__DIR__ . '/custom/rmm_kinds.php')) {\n"
        . "        require_once __DIR__ . '/custom/rmm_kinds.php';\n"
        . "        \$kinds = rmmCustomKinds(\$kinds);\n"
        . "    }\n"
        . BOLT_ON_END . "\n";
}

/** functions/rmm.php without the block - as ITFlow has it. */
function boltStrip(string $code): string
{
    $pattern = '/' . preg_quote(BOLT_ON_BEGIN, '/') . '\n.*?' . preg_quote(BOLT_ON_END, '/') . '\n/s';
    return (string) preg_replace($pattern, '', $code);
}

/** Write a file by way of a temporary one beside it, keeping the owner its folder has when run as root. */
function boltWrite(string $path, string $content): bool
{
    $temp = $path . '.bolt-on-' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($temp, $content, LOCK_EX) === false) {
        return false;
    }
    chmod($temp, is_file($path) ? (fileperms($path) & 0777) : 0644);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($temp, fileowner(dirname($path)));
        @chgrp($temp, filegroup(dirname($path)));
    }
    if (!rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    return true;
}

/** Which vendors ITFlow has itself now - their file names. */
function boltShipped(string $root): array
{
    $shipped = [];
    foreach (glob($root . '/functions/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);
        foreach (BOLT_ON_VENDORS as $vendor => [$function]) {
            if (preg_match('/^\s*function\s+' . preg_quote($function, '/') . '\s*\(/m', $code)) {
                $shipped[$vendor] = basename($file);
            }
        }
    }
    return $shipped;
}

/**
 * Load ITFlow in another process and say which of $expect kinds are there - [ok, what it said].
 * $before_hook loads the kinds file by hand, to try the vendor files before the hook is added.
 */
function boltSmoke(string $root, array $expect, bool $before_hook = false): array
{
    $script = 'chdir(' . var_export($root . '/cron', true) . ');'
        . 'require_once "../config.php"; require_once "../includes/inc_set_timezone.php"; require_once "../functions.php";'
        . ($before_hook ? 'require_once "../functions/custom/rmm_kinds.php"; $k = array_keys(rmmCustomKinds(rmmProviderKinds()));' : '$k = array_keys(rmmProviderKinds());')
        . '$missing = array_diff(' . var_export(array_values($expect), true) . ', $k);'
        . 'echo $missing ? "BOLT-ON-MISSING " . implode(",", $missing) : "BOLT-ON-OK " . implode(",", $k);';
    $output = [];
    $status = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    $said = trim(implode("\n", $output));
    return [$status === 0 && str_contains($said, 'BOLT-ON-OK'), $said];
}

$action = $argv[1] ?? '';
$root = rtrim((string) ($argv[2] ?? ''), '/');
if (!in_array($action, ['status', 'apply', 'remove'], true) || $root === '') {
    boltStop("Usage: php itflow_vendor_bolt_on.php status|apply|remove /path/to/itflow");
}
$root = realpath($root) ?: '';
$rmm = "$root/functions/rmm.php";
if ($root === '' || !is_file($rmm) || !is_file("$root/includes/app_version.php") || !is_file("$root/functions.php")) {
    boltStop("That isn't an ITFlow with RMM Providers: functions/rmm.php not found under " . ($root ?: $argv[2]));
}
if (str_starts_with(__DIR__ . '/', $root . '/')) {
    boltStop("Keep this folder outside the ITFlow folder - it is in $root.");
}
$version = preg_match('/APP_VERSION["\']\s*,\s*["\']([^"\']+)/', (string) file_get_contents("$root/includes/app_version.php"), $m) ? $m[1] : 'unknown';
$code = (string) file_get_contents($rmm);
$hooked = str_contains($code, BOLT_ON_BEGIN);
$custom = "$root/functions/custom";
$shipped = boltShipped($root);

if ($action === 'status') {
    echo "ITFlow $version at $root\n";
    echo 'Hook in functions/rmm.php: ' . ($hooked ? 'present' : 'absent (bolt-on is off)') . "\n";
    foreach (array_merge(['rmm_kinds.php'], array_keys(BOLT_ON_VENDORS)) as $file) {
        $there = is_file("$custom/$file");
        $same = $there && is_file(__DIR__ . "/$file") && hash_file('sha256', "$custom/$file") === hash_file('sha256', __DIR__ . "/$file");
        echo "functions/custom/$file: " . (!$there ? 'absent' : ($same ? 'in place, same as here' : 'in place, DIFFERENT from the copy here')) . "\n";
    }
    foreach ($shipped as $vendor => $file) {
        echo "ITFlow now has its own " . basename($vendor, '.php') . " (functions/$file) - this bolt-on steps aside for it.\n";
    }
    exit(0);
}

if ($action === 'remove') {
    if ($hooked) {
        $clean = boltStrip($code);
        $temp = tempnam(sys_get_temp_dir(), 'bolt');
        file_put_contents($temp, $clean);
        $lint = boltLint($temp);
        unlink($temp);
        if ($lint !== '' || str_contains($clean, 'BOLT-ON')) {
            boltStop("The block couldn't be taken out cleanly - restore the file with: git -C $root checkout -- functions/rmm.php");
        }
        if (!boltWrite($rmm, $clean)) {
            boltStop("functions/rmm.php couldn't be written - run this as the user that owns it.");
        }
        echo "Hook removed from functions/rmm.php.\n";
    }
    foreach (array_merge(['rmm_kinds.php'], array_keys(BOLT_ON_VENDORS)) as $file) {
        if (is_file("$custom/$file")) {
            unlink("$custom/$file");
            echo "Removed functions/custom/$file.\n";
        }
    }
    if (is_dir($custom) && count(scandir($custom)) === 2) {
        rmdir($custom);
    }
    boltStop("Bolt-on removed. Providers of its kinds stay in the database and show as a kind ITFlow doesn't know.", 0);
}

// ---- apply ----

// 1. Refuse before changing anything
$wanted = array_diff_key(BOLT_ON_VENDORS, $shipped);
foreach ($shipped as $vendor => $file) {
    echo "ITFlow $version has its own " . basename($vendor, '.php') . " now (functions/$file) - leaving that one to ITFlow.\n";
}
if (!$wanted) {
    if ($hooked || is_dir($custom)) {
        echo "Nothing left for this bolt-on to add. Run: php " . basename(__FILE__) . " remove $root\n";
    }
    boltStop("ITFlow has every kind this bolt-on adds. Nothing was changed.", 0);
}
$plain = boltStrip($code);
foreach (BOLT_ON_NEEDS as $function) {
    if (!preg_match('/^function\s+' . preg_quote($function, '/') . '\s*\(/m', $plain)) {
        boltStop("ITFlow $version no longer has $function() in functions/rmm.php - the vendor files need updating for this version. Nothing was changed.");
    }
}
if (!preg_match('/^function rmmHttp\(array \$provider, string \$method, string \$url, array \$headers = \[\], \?array \$body = null, int \$timeout = 30, array \$opts = \[\]\): array/m', $plain)) {
    boltStop("rmmHttp() takes different arguments in ITFlow $version - the vendor files need updating for this version. Nothing was changed.");
}
$start = strpos($plain, 'function rmmProviderKinds(');
$end = $start === false ? false : strpos($plain, "\n}\n", $start);
$body = $start === false || $end === false ? '' : substr($plain, $start, $end - $start);
if (substr_count($body, BOLT_ON_ANCHOR) !== 1) {
    boltStop("rmmProviderKinds() isn't written the way this bolt-on expects in ITFlow $version - the hook has nowhere certain to go. Nothing was changed.");
}
foreach (array_merge(['rmm_kinds.php'], array_keys($wanted)) as $file) {
    if (!is_file(__DIR__ . "/$file")) {
        boltStop("$file is missing from " . __DIR__ . ". Nothing was changed.");
    }
    $lint = boltLint(__DIR__ . "/$file");
    if ($lint !== '') {
        boltStop("$file doesn't pass PHP's syntax check - nothing was changed.\n$lint");
    }
}

// 2. The files - with the hook still out, they are loaded by nothing
if ($hooked && !boltWrite($rmm, $plain)) {
    boltStop("functions/rmm.php couldn't be written - run this as the user that owns it. Nothing was changed.");
}
if (!is_dir($custom) && !mkdir($custom, 0755)) {
    boltStop("functions/custom couldn't be made - run this as the user that owns the ITFlow files. Nothing was changed.");
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($custom, fileowner("$root/functions"));
    @chgrp($custom, filegroup("$root/functions"));
}
foreach (array_keys($shipped) as $file) {
    if (is_file("$custom/$file")) {
        unlink("$custom/$file");
    }
}
foreach (array_merge(['rmm_kinds.php'], array_keys($wanted)) as $file) {
    if (!boltWrite("$custom/$file", (string) file_get_contents(__DIR__ . "/$file"))) {
        boltStop("functions/custom/$file couldn't be written. The hook is out, so ITFlow is as it was.");
    }
}

// 3. Try the vendor files in a separate process before anything loads them, then add the hook
$expect = array_merge(...array_values(array_map(function ($vendor) {
    return $vendor[1];
}, $wanted)));
$can_load = is_file("$root/config.php");
if ($can_load) {
    [$ok, $said] = boltSmoke($root, $expect, true);
    if (!$ok && !str_contains($said, 'BOLT-ON-MISSING')) {
        boltStop("The vendor files don't load with ITFlow $version, so the hook was not added and ITFlow is as it was.\nWhat it said:\n$said");
    }
}
$insert_at = $start + strpos($body, BOLT_ON_ANCHOR) + 1;
$patched = substr($plain, 0, $insert_at) . boltBlock() . substr($plain, $insert_at);
$temp = tempnam(sys_get_temp_dir(), 'bolt');
file_put_contents($temp, $patched);
$lint = boltLint($temp);
unlink($temp);
if ($lint !== '') {
    boltStop("The patched functions/rmm.php doesn't pass PHP's syntax check - the hook was not added.\n$lint");
}
if (!boltWrite($rmm, $patched)) {
    boltStop("functions/rmm.php couldn't be written - run this as the user that owns it. The hook was not added.");
}

// 4. Prove it loads with the hook in, or take the hook back out
if (!$can_load) {
    echo "No config.php here, so ITFlow can't be loaded to check - check Admin > RMM Providers yourself.\n";
} else {
    [$ok, $said] = boltSmoke($root, $expect);
    // Network platforms only show once the 4.1.5 database update is in - tell that apart from a failure
    if (!$ok && str_contains($said, 'BOLT-ON-MISSING') && !array_diff(explode(',', trim(substr($said, strpos($said, 'BOLT-ON-MISSING') + 16))), ['omada', 'omada_key'])) {
        echo "Loaded. The Omada kinds aren't offered yet - Network Platforms need ITFlow's 4.1.5 database update.\n";
    } elseif (!$ok) {
        boltWrite($rmm, $plain);
        boltStop("ITFlow didn't load cleanly with the bolt-on, so the hook was taken out again and ITFlow is as it was.\nWhat it said:\n$said");
    } else {
        echo "Loaded: " . substr($said, strpos($said, 'BOLT-ON-OK') + 11) . "\n";
    }
}

echo "Bolt-on applied to ITFlow $version: " . implode(', ', $expect) . ".\n";
echo "functions/rmm.php now shows as a local change on Maintenance > Update - that is the hook, and the next update removes it.\n";
echo "After every ITFlow update, run this again:  php " . basename(__FILE__) . " apply $root\n";
exit(0);
