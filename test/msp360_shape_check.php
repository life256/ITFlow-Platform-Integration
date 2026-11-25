<?php

/*
 * Shows what MSP360's two APIs answer for YOUR account - field names and value types only,
 * never a value - so the draft vendor file can be checked against the real thing.
 *
 * Run it on your own machine. It asks for the three credentials without showing them, sends
 * them only to MSP360's own addresses, and stores nothing.
 *
 *   php msp360_shape_check.php
 *
 * The output is safe to paste into a chat: it holds no names, addresses, serial numbers or
 * tokens. Read it over before you paste it anyway.
 */

if (php_sapi_name() !== 'cli') {
    exit("Command line only.\n");
}

function ask(string $label): string
{
    $env = getenv('MSP360_' . strtoupper(str_replace(' ', '_', $label)));
    if ($env !== false && $env !== '') {
        return $env;
    }
    fwrite(STDERR, "$label: ");
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return $value;
}

function call(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    return [$code, $raw === false ? null : json_decode((string) $raw, true), $error];
}

/** The shape of a value: names and types, one level of a list's first entry, never the value itself. */
function shape($value, int $depth = 0): string
{
    if (is_array($value)) {
        if ($value === []) {
            return 'empty list or object';
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return 'list of ' . count($value) . ($depth < 5 ? ' x ' . shape($value[0], $depth + 1) : '');
        }
        if ($depth >= 5) {
            return 'object';
        }
        $parts = [];
        foreach ($value as $key => $inner) {
            $parts[] = preg_replace('/[^A-Za-z0-9_.-]/', '?', (string) $key) . ': ' . shape($inner, $depth + 1);
        }
        return '{' . implode(', ', $parts) . '}';
    }
    return $value === null ? 'null' : gettype($value);
}

$login = ask('API login');
$password = ask('API password');
$token = ask('RMM token');

echo "== Managed Backup API\n";
[$code, $json, $error] = call('POST', 'https://api.mspbackups.com/api/Provider/Login', [], ['UserName' => $login, 'Password' => $password]);
echo "Login: HTTP $code" . ($error ? " ($error)" : '') . ', answer: ' . shape(is_array($json) ? array_map(function ($v) {
    return is_string($v) ? 'x' : $v;
}, $json) : $json) . "\n";
$access = is_array($json) ? (string) ($json['access_token'] ?? $json['AccessToken'] ?? $json['token'] ?? '') : '';
if ($access !== '') {
    $auth = ['Authorization: Bearer ' . $access];
    [$code, $json] = call('GET', 'https://api.mspbackups.com/api/Companies', $auth);
    echo "Companies: HTTP $code, " . shape($json) . "\n";
    [$code, $json] = call('GET', 'https://api.mspbackups.com/api/Computers/0/2', $auth);
    echo "Computers/0/2: HTTP $code, " . shape($json) . "\n";
    [$code, $json] = call('GET', 'https://api.mspbackups.com/api/Computers/2/2', $auth);
    echo "Computers/2/2: HTTP $code, " . shape($json) . "\n";
} else {
    echo "No access token came back, so the rest of the Managed Backup API wasn't asked.\n";
}

echo "\n== RMM API\n";
if ($token === '') {
    echo "No RMM token given - skipped.\n";
    exit(0);
}
foreach (['host', 'summary'] as $type) {
    $marks = [];
    foreach ([0, 1, 2] as $page) {
        [$code, $json, $error] = call('GET', "https://api.rmm.mspbackups.com/api/v1/computers/stat/$type/latest?pageNumber=$page&pageSize=1", ['Authorization: Bearer ' . $token]);
        $first = is_array($json) && is_array($json['items'] ?? null) && isset($json['items'][0]) && is_array($json['items'][0]) ? $json['items'][0] : null;
        echo "$type page $page: HTTP $code" . ($error ? " ($error)" : '') . ', echoes pageNumber=' . json_encode($json['pageNumber'] ?? null) . ' total=' . (isset($json['total']) ? 'number' : 'none')
            . ', item has hid: ' . ($first === null ? 'no item' : (array_key_exists('hid', $first) || array_key_exists('Hid', $first) ? 'yes' : 'NO')) . "\n";
        $marks[$page] = $first === null ? null : md5(json_encode($first));
        if ($page > 0 && $first !== null) {
            echo '   same computer as page 0: ' . ($marks[$page] === $marks[0] ? 'yes' : 'no') . "\n";
        }
        if ($page === 0 || ($page === 1 && $first !== null)) {
            echo '   shape: ' . shape($json) . "\n";
        }
    }
}
echo "\nDone. Nothing was stored.\n";
