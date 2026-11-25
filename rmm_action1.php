<?php

/*
 * Action1 - an RMM Providers kind (functions/rmm.php).
 *
 * Its API is at /api/3.0 on the address of its console for the account's region
 * (app.action1.com, app.eu.action1.com and so on). It signs in with API credentials made in
 * Action1 under Configuration > Users & API Credentials: a client ID and secret, posted as a
 * form to /oauth2/token for a token sent as Authorization: Bearer. The credentials act with the
 * role and the organizations they were given, so those decide what ITFlow can see.
 *
 * /organizations lists its organizations - a provider's clients here - and
 * /endpoints/managed/<organization> an organization's endpoints. Lists come a page at a time
 * ({items, total_items, next_page}), and it answers 429 when asked too often, saying how long
 * to wait.
 *
 * It has no alerts to read, so alerts opening tickets isn't offered for it.
 */

/** What Action1 is, as rmmProviderKinds() describes a kind. */
function rmmAction1Kind(): array
{
    return [
        'name' => 'Action1',
        'short' => 'Action1',
        'icon' => 'fas fa-laptop-medical',
        'about' => 'Its endpoints become assets on the clients you link to its organizations, with whether each is connected, who is signed in and whether updates or a reboot are waiting.',
        'docs_url' => 'https://www.action1.com/api-documentation/',
        'seal' => true,
        'fields' => [
            'api_url' => [
                'label' => 'Console address', 'icon' => 'fa-server', 'type' => 'url', 'required' => true, 'placeholder' => 'https://app.action1.com',
                'help' => 'The address your Action1 console opens at - it differs by region, like https://app.eu.action1.com.',
                'needed' => 'Action1\'s console address is needed - as it is in your browser, like https://app.action1.com.',
            ],
            'api_key' => [
                'label' => 'Client ID', 'icon' => 'fa-id-badge', 'type' => 'text', 'required' => true, 'placeholder' => '',
                'help' => 'In Action1: Configuration > Users & API Credentials > New API Credentials. Give them a role that can only view, and the organizations ITFlow should see.',
                'needed' => 'The client ID is needed - make API credentials in Action1 under Configuration > Users & API Credentials.',
            ],
            'api_secret' => [
                'label' => 'Client secret', 'icon' => 'fa-key', 'type' => 'secret', 'required' => true, 'placeholder' => '',
                'help' => 'Shown once, when the credentials are made. Kept encrypted with this ITFlow\'s own key.',
                'needed' => 'The client secret is needed - Action1 shows it once, when the credentials are made.',
            ],
        ],
        'attention' => ['patches_pending' => 'Updates missing'],
        'hints' => [
            'auth' => 'check the client ID and secret, and that their role can see organizations and endpoints.',
            'not_found' => 'is that your Action1 console\'s address, for your region?',
            'not_json' => 'is that your Action1 console\'s address, for your region?',
        ],
        'links' => [],
        'clients' => 'rmmAction1Clients',
        'devices' => 'rmmAction1Devices',
        'dashboard' => 'rmmAction1Dashboard',
    ];
}

/*
 * ------------------------------------------------------------------ Asking Action1
 */

/** The console's address - the one set, without the API's path when that was pasted with it. '' when it isn't set. */
function rmmAction1Console(array $provider): string
{
    $base = rmmUrlClean($provider['rmm_provider_api_url'] ?? '');
    return $base === '' ? '' : (string) preg_replace('#/(api(/3\.0)?|console(/.*)?)$#i', '', $base);
}

/**
 * Signed in - ['ok', 'token', 'error']. The client ID and secret go as a form, which
 * rmmHttp() doesn't send, so this asks for itself, with the same care: no redirects, http and
 * https only. The token is kept for the request; $again gets a new one.
 */
function rmmAction1Token(array $provider, bool $again = false): array
{
    static $tokens = [];
    $id = intval($provider['rmm_provider_id'] ?? 0);
    if (!$again && isset($tokens[$id])) {
        return $tokens[$id];
    }
    $name = rmmProviderName($provider);
    $console = rmmAction1Console($provider);
    $client_id = trim((string) ($provider['rmm_provider_api_key'] ?? ''));
    $secret = rmmSecret($provider, 'api_secret');
    if ($console === '' || $client_id === '' || $secret === '') {
        $unread = $secret === '' && (string) ($provider['rmm_provider_api_secret'] ?? '') !== '' ? ' - its secret can\'t be read, so type it again' : '';
        return ['ok' => false, 'token' => '', 'error' => "Its address, client ID and secret aren't set$unread."];
    }
    $host = (string) parse_url($console, PHP_URL_HOST);
    $ch = curl_init("$console/api/3.0/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => $client_id, 'client_secret' => $secret], '', '&'),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'ITFlow',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $transport = curl_error($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    if ($raw === false) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => "Couldn't reach $host - $transport"];
    }
    $json = json_decode((string) $raw, true);
    $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    if (in_array($code, [400, 401, 403], true)) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => "$name turned the client ID and secret away (HTTP $code) - check them, and that the console address is the one for your region."];
    }
    if ($code < 200 || $code > 299 || $token === '' || preg_match('/[\x00-\x20]/', $token)) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => "$host answered the sign-in (HTTP $code), but didn't sign ITFlow in - is that your Action1 console's address?"];
    }
    return $tokens[$id] = ['ok' => true, 'token' => $token, 'error' => ''];
}

/**
 * Ask Action1's API - GET /api/3.0<path>: rmmHttp()'s answer. A token that was turned away is
 * got again, once; asked too often (429), it waits as long as Action1 says - a quarter of a
 * minute at most, twice at most - and asks again.
 */
function rmmAction1Request(array $provider, string $path, array $query = [], int $timeout = 60): array
{
    $url = rmmAction1Console($provider) . '/api/3.0/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&') : '');
    $signed = rmmAction1Token($provider);
    $again = false;
    $waits = 0;
    while (true) {
        if (!$signed['ok']) {
            return ['ok' => false, 'code' => 0, 'json' => null, 'error' => $signed['error'], 'headers' => []];
        }
        $answer = rmmHttp($provider, 'GET', $url, ['Authorization: Bearer ' . $signed['token']], null, $timeout);
        if ($answer['code'] === 401 && !$again) {
            $again = true;
            $signed = rmmAction1Token($provider, true);
            continue;
        }
        if ($answer['code'] === 429 && $waits < 2) {
            $waits++;
            $wait = intval(is_array($answer['json']) ? ($answer['json']['retry_after'] ?? 0) : 0) ?: intval($answer['headers']['retry-after'] ?? 0);
            sleep(max(1, min(15, $wait ?: 5)));
            continue;
        }
        return $answer;
    }
}

/**
 * Ask Action1 for a list, page by page - ['ok', 'items', 'total', 'error']. A page that isn't a
 * list is an error, never "it has none": read as none, every endpoint would be marked gone.
 * $first is the first page when it was asked for already (rmmAction1FirstPage()).
 */
function rmmAction1List(array $provider, string $path, array $query = [], ?array $first = null): array
{
    $items = [];
    $next_path = $path;
    $next_query = $query + ['limit' => 50];
    for ($page = 0; $page < 200; $page++) {
        $answer = $page === 0 && $first !== null ? $first : rmmAction1Request($provider, $next_path, $next_query);
        if (!$answer['ok']) {
            return ['ok' => false, 'items' => [], 'total' => 0, 'error' => $answer['error']];
        }
        $rows = $answer['json']['items'] ?? null;
        if (!rmmIsList($rows)) {
            return ['ok' => false, 'items' => [], 'total' => 0, 'error' => rmmProviderName($provider) . ' answered, but not with a list - nothing was changed'];
        }
        $items = array_merge($items, $rows);
        $total = intval($answer['json']['total_items'] ?? count($items));
        if (!$rows || count($items) >= $total) {
            return ['ok' => true, 'items' => $items, 'total' => max($total, count($items)), 'error' => ''];
        }
        // The page after this one: where Action1 says it is, or failing that the next from where this one ended
        $next = rmmAction1NextPage($answer['json']['next_page'] ?? '');
        [$next_path, $next_query] = $next ?? [$path, $query + ['from' => count($items), 'limit' => 50]];
    }
    return ['ok' => false, 'items' => [], 'total' => 0, 'error' => rmmProviderName($provider) . ' has more than ITFlow reads in one go - nothing was changed'];
}

/**
 * Action1's next_page as a path and query to ask for - [path, query], or null when it gives
 * none ITFlow can use. It comes as an address or a path; only its path under /api/3.0 and its
 * query are taken, and asked of the console's own address - the token is never sent to an
 * address an answer named.
 */
function rmmAction1NextPage($next): ?array
{
    $next = is_string($next) ? trim($next) : '';
    if ($next === '') {
        return null;
    }
    $path = '/' . ltrim((string) parse_url($next, PHP_URL_PATH), '/');
    $path = ltrim((string) preg_replace('#^/api/3\.0/#i', '/', $path), '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('#^[A-Za-z0-9_./%-]+$#', $path)) {
        return null;
    }
    parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
    $query = array_filter($query, 'is_scalar');
    return isset($query['from']) || isset($query['limit']) ? [$path, $query] : null;
}

/**
 * The first page of an organization's endpoints, kept for the request - asked for once to count
 * them for the clients list, and read again when the sync brings them in, so an organization
 * costs one request and not two. $again asks afresh.
 */
function rmmAction1FirstPage(array $provider, string $organization, bool $again = false): array
{
    static $pages = [];
    $key = intval($provider['rmm_provider_id'] ?? 0) . '|' . $organization;
    // Kept two minutes at most - long enough for one sync, never long enough to bring in what has since changed
    if ($again || !isset($pages[$key]) || $pages[$key][0] < time() - 120) {
        $pages[$key] = [time(), rmmAction1Request($provider, 'endpoints/managed/' . rawurlencode($organization), ['fields' => '*', 'limit' => 50])];
    }
    return $pages[$key][1];
}

/*
 * ------------------------------------------------------------------ Organizations and endpoints
 */

/** Action1's organizations - ['ok', 'clients' => [id, name, sites, devices], 'error']. */
function rmmAction1Clients(array $provider): array
{
    $answer = rmmAction1List($provider, 'organizations');
    if (!$answer['ok']) {
        return ['ok' => false, 'clients' => [], 'error' => $answer['error']];
    }
    $clients = [];
    foreach ($answer['items'] as $organization) {
        $id = is_array($organization) ? (string) ($organization['id'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
            continue;
        }
        $name = rmmText($organization['name'] ?? '', 200);
        $page = rmmAction1FirstPage($provider, $id);
        $clients[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : "Organization $id",
            'sites' => 0,
            'devices' => $page['ok'] && is_array($page['json']) ? intval($page['json']['total_items'] ?? count((array) ($page['json']['items'] ?? []))) : 0,
        ];
    }
    return ['ok' => true, 'clients' => $clients, 'error' => ''];
}

/** An Action1 organization's endpoints - ['ok', 'devices', 'error'], each device as rmmDeviceSave() takes it. */
function rmmAction1Devices(array $provider, string $client_remote_id): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $client_remote_id)) {
        return ['ok' => false, 'devices' => [], 'error' => 'That isn\'t one of ' . rmmProviderName($provider) . '\'s organizations'];
    }
    $first = rmmAction1FirstPage($provider, $client_remote_id);
    if (!$first['ok']) {
        // One that failed when it was counted is asked for again, not taken as it was
        $first = rmmAction1FirstPage($provider, $client_remote_id, true);
    }
    $answer = rmmAction1List($provider, 'endpoints/managed/' . rawurlencode($client_remote_id), ['fields' => '*'], $first);
    if (!$answer['ok']) {
        return ['ok' => false, 'devices' => [], 'error' => $answer['error']];
    }
    $devices = [];
    foreach ($answer['items'] as $endpoint) {
        // One being uninstalled is on its way out - not brought in
        if (is_array($endpoint) && isset($endpoint['id']) && stripos((string) ($endpoint['status'] ?? ''), 'uninstall') === false) {
            $devices[] = rmmAction1Device($endpoint);
        }
    }
    return ['ok' => true, 'devices' => $devices, 'error' => ''];
}

/** One of Action1's endpoints as a device. */
function rmmAction1Device(array $endpoint): array
{
    $text = function ($key, $max = 255) use ($endpoint) {
        $value = rmmText($endpoint[$key] ?? '', $max);
        return rmmIsPlaceholder($value) || strcasecmp($value, 'UNDEFINED') === 0 ? '' : $value;
    };
    $os = $text('OS', 200);
    $platform = strtolower($text('platform', 50));
    $family = str_contains($platform, 'win') || stripos($os, 'windows') === 0 ? 'windows'
        : (str_contains($platform, 'mac') || stripos($os, 'mac') === 0 ? 'darwin' : (str_contains($platform, 'linux') ? 'linux' : ''));
    $state = strtolower($text('status', 50));
    $address = $text('address', 500);
    $mac = '';
    foreach (preg_split('/[\s,;]+/', (string) (is_scalar($endpoint['MAC'] ?? null) ? $endpoint['MAC'] : '')) as $one) {
        if (rmmMac($one) !== '') {
            $mac = rmmMac($one);
            break;
        }
    }
    $cpu = trim(str_replace(['(R)', '(TM)', '(tm)', '(r)'], '', $text('CPU_name', 300)));
    $cores = $text('CPU_size', 100);
    $name = $text('name', 200);
    return [
        'remote_id' => (string) ($endpoint['id'] ?? ''),
        'mac' => $mac,
        'hostname' => $name !== '' ? $name : $text('device_name', 200),
        'site' => '',
        // Action1 doesn't say which it watches as a server - Windows Server does, and Linux is taken as one
        'role' => stripos($os, 'server') !== false || $family === 'linux' ? 'server' : 'workstation',
        'platform' => $family,
        'os' => $os,
        'status' => $state === 'connected' ? 'online' : ($state === 'disconnected' ? 'offline' : ''),
        'last_seen_at' => rmmAction1Time($endpoint['last_seen'] ?? null),
        'boot_at' => rmmAction1Time($endpoint['last_boot_time'] ?? null),
        'logged_user' => $text('user'),
        'public_ip' => $text('external_address', 100),
        'local_ips' => $address,
        'needs_reboot' => strcasecmp($text('reboot_required', 20), 'yes') === 0,
        'patches_pending' => intval($endpoint['missing_critical_updates'] ?? 0) + intval($endpoint['missing_other_updates'] ?? 0) > 0,
        'agent_version' => $text('agent_version', 50),
        'asset' => [
            'make' => $text('manufacturer', 200),
            'model' => $text('model', 200),
            'serial' => (string) (is_scalar($endpoint['serial'] ?? null) ? $endpoint['serial'] : ''),
            'os' => $os,
            'ip' => $address,
            'cpu' => mb_substr(trim($cpu . ($cpu !== '' && $cores !== '' ? ', ' : '') . $cores), 0, 255),
            'storage' => $text('disk', 255),
            'mac' => $mac,
        ],
    ];
}

/**
 * Action1's times - "2023-12-09_01-44-11", taken as UTC - as one in ITFlow's timezone. Anything
 * else is left to rmmTime(); null when there isn't one.
 */
function rmmAction1Time($value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value === '' || strcasecmp($value, 'UNDEFINED') === 0) {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})$/', $value, $parts)) {
        return rmmTime("$parts[1]T$parts[2]:$parts[3]:$parts[4]Z");
    }
    return rmmTime($value);
}

/** Where Open in Action1 goes - its console. */
function rmmAction1Dashboard(array $provider): string
{
    $console = rmmAction1Console($provider);
    return $console === '' ? '' : "$console/console/dashboard";
}
