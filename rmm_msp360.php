<?php

/*
 * MSP360 RMM - an RMM Providers kind (functions/rmm.php).
 *
 * MSP360 has two APIs, and this reads both, because neither has everything:
 *
 *   The Managed Backup API (api.mspbackups.com) knows the companies - a provider's clients
 *   here - and which company each computer belongs to, whether it is online and which agents
 *   it has. It signs in with the API login and password made in MSP360 under Settings >
 *   General > API (POST /api/Provider/Login, then Authorization: Bearer). /api/Companies lists
 *   the companies, /api/Computers/<offset>/<count> the computers.
 *
 *   The RMM API (api.rmm.mspbackups.com) knows what each computer is - maker, model, serial
 *   number, OS, addresses, CPU - and whether a reboot or updates are waiting, but not whose it
 *   is. It takes an RMM API token made under Settings > General > RMM API tokens, sent as
 *   Authorization: Bearer. /api/v1/computers/stat/host/latest and .../stat/summary/latest give
 *   every computer a page at a time ({items, total, pageNumber, pageSize}). It is read-only.
 *
 * A computer is the same one in both by its HID, which is its id here. Only computers with the
 * RMM agent are brought in. Without an RMM API token they still come in, with no hardware
 * details. The login is kept where another kind keeps its dashboard address
 * (rmm_provider_web_url), as text; the password and the token are both kept sealed.
 *
 * MSP360 doesn't publish what /api/Computers answers, so its fields are read by the names its
 * console uses, in either capitalisation, and a list is found whether or not it is wrapped.
 * MSP360 asks that RMM data isn't asked for more than hourly, and alerts not more than every
 * ten minutes; the sync's fifteen minutes is within the first.
 *
 * Not here yet: alerts opening tickets (the summary has them, with ids), and storage.
 */

// Where MSP360's APIs and console are
defined('RMM_MSP360_BACKUP_API') || define('RMM_MSP360_BACKUP_API', 'https://api.mspbackups.com');
defined('RMM_MSP360_RMM_API') || define('RMM_MSP360_RMM_API', 'https://api.rmm.mspbackups.com');
defined('RMM_MSP360_CONSOLE') || define('RMM_MSP360_CONSOLE', 'https://console.msp360.com');

/** What MSP360 RMM is, as rmmProviderKinds() describes a kind. */
function rmmMsp360Kind(): array
{
    return [
        'name' => 'MSP360 RMM',
        'short' => 'MSP360',
        'icon' => 'fas fa-laptop-medical',
        'about' => 'Its computers with the RMM agent become assets on the clients you link to its companies, with whether each is online, who is signed in and whether updates or a reboot are waiting.',
        'docs_url' => 'https://help.mspbackups.com/mbs-api-specification',
        'seal' => true,
        'fields' => [
            'web_url' => [
                'label' => 'API login', 'icon' => 'fa-user', 'type' => 'text', 'required' => true, 'placeholder' => '',
                'help' => 'In MSP360: Settings > General > API > Add Credentials. The login it makes - not the one you sign in to the console with.',
                'needed' => 'The API login is needed - make API credentials in MSP360 under Settings > General > API.',
            ],
            'api_key' => [
                'label' => 'API password', 'icon' => 'fa-lock', 'type' => 'secret', 'required' => true, 'placeholder' => '', 'spaces' => true,
                'help' => 'Made with the API login. Kept encrypted with this ITFlow\'s own key.',
                'needed' => 'The API password is needed - it is made with the API login in MSP360.',
            ],
            'api_secret' => [
                'label' => 'RMM API token', 'icon' => 'fa-key', 'type' => 'secret', 'required' => false, 'placeholder' => '',
                'help' => 'In MSP360: Settings > General > RMM API tokens. It brings in each computer\'s maker, model, serial number and OS; left empty, computers come in without them. Kept encrypted with this ITFlow\'s own key.',
                'needed' => '',
            ],
        ],
        'attention' => ['patches_pending' => 'Updates available'],
        'hints' => [
            'auth' => 'check the API login and password, and the RMM API token - each is made in MSP360 under Settings > General.',
            'not_found' => '',
            'not_json' => '',
        ],
        'links' => [],
        'clients' => 'rmmMsp360Clients',
        'devices' => 'rmmMsp360Devices',
        'dashboard' => 'rmmMsp360Dashboard',
    ];
}

/*
 * ------------------------------------------------------------------ Asking MSP360
 */

/** A value from one of MSP360's records by its name, however it is capitalised - null when it hasn't it. */
function rmmMsp360Value($row, string ...$names)
{
    if (!is_array($row)) {
        return null;
    }
    foreach ($names as $name) {
        foreach ([$name, ucfirst($name), lcfirst($name)] as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }
    }
    return null;
}

/** The list in an answer - the answer itself, or the first list it wraps under one of $names. null when there isn't one. */
function rmmMsp360Rows($json, string ...$names): ?array
{
    if (rmmIsList($json)) {
        return $json;
    }
    $rows = rmmMsp360Value($json, ...$names);
    return rmmIsList($rows) ? $rows : null;
}

/**
 * Signed in to the Managed Backup API - ['ok', 'token', 'error']. Kept for the request; $again
 * signs in afresh, for a token that was turned away.
 */
function rmmMsp360Login(array $provider, bool $again = false): array
{
    static $tokens = [];
    $id = intval($provider['rmm_provider_id'] ?? 0);
    if (!$again && isset($tokens[$id])) {
        return $tokens[$id];
    }
    $name = rmmProviderName($provider);
    $login = trim((string) ($provider['rmm_provider_web_url'] ?? ''));
    $password = rmmSecret($provider, 'api_key');
    if ($login === '' || $password === '') {
        $unread = $password === '' && (string) ($provider['rmm_provider_api_key'] ?? '') !== '' ? ' - its password can\'t be read, so type it again' : '';
        return ['ok' => false, 'token' => '', 'error' => "Its API login and password aren't set$unread."];
    }
    $answer = rmmHttp($provider, 'POST', rtrim(RMM_MSP360_BACKUP_API, '/') . '/api/Provider/Login', [], ['UserName' => $login, 'Password' => $password]);
    if (in_array($answer['code'], [400, 401, 403], true)) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => "$name turned the API login and password away (HTTP {$answer['code']}) - check them: the ones made in MSP360 under Settings > General > API."];
    }
    if (!$answer['ok']) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => $answer['error']];
    }
    $token = (string) (rmmMsp360Value($answer['json'], 'access_token', 'accessToken', 'token') ?? '');
    if ($token === '' || preg_match('/[\x00-\x20]/', $token)) {
        return $tokens[$id] = ['ok' => false, 'token' => '', 'error' => "$name answered the sign-in, but didn't sign ITFlow in."];
    }
    return $tokens[$id] = ['ok' => true, 'token' => $token, 'error' => ''];
}

/** Ask the Managed Backup API - GET <path>: rmmHttp()'s answer. A token that was turned away is got again, once. */
function rmmMsp360Backup(array $provider, string $path, int $timeout = 60): array
{
    $signed = rmmMsp360Login($provider);
    for ($try = 0; $try < 2; $try++) {
        if (!$signed['ok']) {
            return ['ok' => false, 'code' => 0, 'json' => null, 'error' => $signed['error'], 'headers' => []];
        }
        $answer = rmmHttp($provider, 'GET', rtrim(RMM_MSP360_BACKUP_API, '/') . '/' . ltrim($path, '/'), ['Authorization: Bearer ' . $signed['token']], null, $timeout);
        if ($answer['code'] === 401 && $try === 0) {
            $signed = rmmMsp360Login($provider, true);
            continue;
        }
        break;
    }
    return $answer;
}

/**
 * Every computer the Managed Backup API lists, kept for the request (two minutes at most) -
 * ['ok', 'computers' => [hid => computer], 'agents_said', 'error']. Each computer: hid, name,
 * company_id, company_name, online (true, false or null), os, ip, user, seen, version and rmm -
 * whether it has the RMM agent, null when the answer doesn't say which agents it has. Hidden
 * ones, and ones not authorized yet, are left out.
 */
function rmmMsp360Computers(array $provider): array
{
    static $kept = [];
    $id = intval($provider['rmm_provider_id'] ?? 0);
    if (isset($kept[$id]) && $kept[$id][0] >= time() - 120) {
        return $kept[$id][1];
    }
    $name = rmmProviderName($provider);
    $computers = [];
    $agents_said = false;
    $count = 100;
    $read = 0;
    for ($page = 0; $page < 500; $page++) {
        $answer = rmmMsp360Backup($provider, "api/Computers/$read/$count");
        if (!$answer['ok']) {
            return ['ok' => false, 'computers' => [], 'agents_said' => false, 'error' => $answer['error']];
        }
        $rows = rmmMsp360Rows($answer['json'], 'computers', 'items', 'data', 'result');
        if ($rows === null) {
            return ['ok' => false, 'computers' => [], 'agents_said' => false, 'error' => "$name answered, but not with a list of computers - nothing was changed"];
        }
        $total = rmmMsp360Value($answer['json'], 'total', 'totalCount', 'count');
        foreach ($rows as $row) {
            $hid = (string) (rmmMsp360Value($row, 'hid') ?? '');
            $status = strtolower((string) (rmmMsp360Value($row, 'authStatus') ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $hid) || !empty(rmmMsp360Value($row, 'hidden')) || !in_array($status, ['', 'authorized'], true)) {
                continue;
            }
            $company = rmmMsp360Value($row, 'company');
            $apps = rmmMsp360Value($row, 'apps', 'applications');
            $rmm = null;
            $rmm_app = null;
            if (rmmIsList($apps)) {
                $agents_said = true;
                $rmm = false;
                foreach ($apps as $app) {
                    if (strtolower((string) (rmmMsp360Value($app, 'applicationId', 'appId') ?? '')) === 'rmm') {
                        $rmm = true;
                        $rmm_app = $app;
                    }
                }
            }
            // Whether it is online is the RMM agent's word when it has one, the computer's otherwise
            $online = rmmMsp360Value($rmm_app, 'online') ?? rmmMsp360Value($row, 'online');
            $computers[$hid] = [
                'hid' => $hid,
                'name' => rmmText(rmmMsp360Value($row, 'name', 'computerName', 'displayName') ?? '', 200),
                'company_id' => (string) (rmmMsp360Value($company, 'id') ?? rmmMsp360Value($row, 'companyId') ?? ''),
                'company_name' => rmmText(rmmMsp360Value($company, 'name') ?? rmmMsp360Value($row, 'companyName') ?? '', 200),
                'online' => is_bool($online) ? $online : null,
                'os' => strtolower((string) (rmmMsp360Value($row, 'os') ?? '')),
                'ip' => rmmText(rmmMsp360Value($row, 'ipAddress', 'ip') ?? '', 100),
                'user' => rmmText(rmmMsp360Value($row, 'lastLoggedUser') ?? '', 255),
                'seen' => rmmMsp360Value($row, 'lastActivity'),
                'version' => rmmText(rmmMsp360Value($rmm_app, 'version') ?? '', 50),
                'rmm' => $rmm,
            ];
        }
        $read += count($rows);
        // All of them read - by its count when it gives one, or by a page that came back short
        if (!$rows || (is_numeric($total) ? $read >= intval($total) : count($rows) < $count)) {
            $result = ['ok' => true, 'computers' => $computers, 'agents_said' => $agents_said, 'error' => ''];
            $kept[$id] = [time(), $result];
            return $result;
        }
    }
    return ['ok' => false, 'computers' => [], 'agents_said' => false, 'error' => "$name has more computers than ITFlow reads in one go - nothing was changed"];
}

/**
 * One kind of the RMM API's statistics for every computer (host or summary), kept for the
 * request (two minutes at most) - ['ok', 'by_hid', 'by_name', 'asked', 'error']: asked is
 * false when no RMM API token is set. by_name has the ones only one computer has the name of,
 * for an answer that doesn't say each computer's HID.
 *
 * Pages are asked for from 0: an API that counts from 1 gives its first page twice or refuses
 * 0, and one read twice is kept once.
 */
function rmmMsp360Stats(array $provider, string $type): array
{
    static $kept = [];
    $key = intval($provider['rmm_provider_id'] ?? 0) . '|' . $type;
    if (isset($kept[$key]) && $kept[$key][0] >= time() - 120) {
        return $kept[$key][1];
    }
    $token = rmmSecret($provider, 'api_secret');
    if ($token === '') {
        $unread = (string) ($provider['rmm_provider_api_secret'] ?? '') !== '';
        return ['ok' => !$unread, 'by_hid' => [], 'by_name' => [], 'asked' => false, 'error' => $unread ? 'Its RMM API token can\'t be read, so type it again.' : ''];
    }
    $name = rmmProviderName($provider);
    $url = rtrim(RMM_MSP360_RMM_API, '/') . "/api/v1/computers/stat/$type/latest";
    $seen = [];
    $items = [];
    $idle = 0;
    for ($page = 0; $page < 200; $page++) {
        $answer = rmmHttp($provider, 'GET', "$url?" . http_build_query(['pageNumber' => $page, 'pageSize' => 100], '', '&'), ['Authorization: Bearer ' . $token], null, 60);
        if (!$answer['ok'] && $page === 0 && in_array($answer['code'], [400, 404, 422], true)) {
            continue;
        }
        if (!$answer['ok']) {
            return ['ok' => false, 'by_hid' => [], 'by_name' => [], 'asked' => true, 'error' => $answer['error']];
        }
        $rows = rmmMsp360Rows($answer['json'], 'items', 'data', 'result');
        if ($rows === null) {
            return ['ok' => false, 'by_hid' => [], 'by_name' => [], 'asked' => true, 'error' => "$name's RMM API answered, but not with a list - nothing was changed"];
        }
        $new = 0;
        foreach ($rows as $row) {
            $mark = is_array($row) ? md5(json_encode($row)) : '';
            if ($mark !== '' && !isset($seen[$mark])) {
                $seen[$mark] = true;
                $items[] = $row;
                $new++;
            }
        }
        $idle = $new ? 0 : $idle + 1;
        $total = rmmMsp360Value($answer['json'], 'total', 'totalCount');
        if ((is_numeric($total) && count($items) >= intval($total)) || ($page >= 1 && !$rows) || $idle >= 2) {
            break;
        }
    }
    $by_hid = [];
    $names = [];
    foreach ($items as $row) {
        $hid = (string) (rmmMsp360Value($row, 'hid') ?? '');
        if ($hid !== '') {
            $by_hid[strtolower($hid)] = $row;
        }
        $computer = mb_strtolower(rmmText(rmmMsp360Value($row, 'computerName') ?? '', 200));
        if ($computer !== '') {
            $names[$computer][] = $row;
        }
    }
    $by_name = [];
    foreach ($names as $computer => $rows) {
        if (count($rows) === 1) {
            $by_name[$computer] = $rows[0];
        }
    }
    $result = ['ok' => true, 'by_hid' => $by_hid, 'by_name' => $by_name, 'asked' => true, 'error' => ''];
    $kept[$key] = [time(), $result];
    return $result;
}

/** A computer's record in one kind of statistics - by its HID, or failing that by a name only it has. null when there isn't one. */
function rmmMsp360Stat(array $stats, array $computer, array $name_counts): ?array
{
    $row = $stats['by_hid'][strtolower($computer['hid'])] ?? null;
    if ($row === null && !$stats['by_hid'] && $computer['name'] !== '' && ($name_counts[mb_strtolower($computer['name'])] ?? 0) === 1) {
        $row = $stats['by_name'][mb_strtolower($computer['name'])] ?? null;
    }
    return is_array($row) ? $row : null;
}

/*
 * ------------------------------------------------------------------ Companies and computers
 */

/**
 * The computers ITFlow brings in - the ones with the RMM agent - by company:
 * ['ok', 'by_company' => [company id => [computer]], 'error', 'about']. One with no company is
 * under '_none'. When the Managed Backup API doesn't say which agents a computer has, the ones
 * the RMM API knows are taken as having it.
 */
function rmmMsp360Managed(array $provider): array
{
    $listed = rmmMsp360Computers($provider);
    if (!$listed['ok']) {
        return ['ok' => false, 'by_company' => [], 'error' => $listed['error'], 'about' => ''];
    }
    $host = rmmMsp360Stats($provider, 'host');
    $about = !$host['asked'] && $host['ok'] ? 'No RMM API token is set, so computers come in without hardware details.'
        : (!$host['ok'] ? 'The RMM API didn\'t answer (' . $host['error'] . '), so hardware details are left as they were.'
        : ($host['by_hid'] ? 'Hardware details come from the RMM API.' : 'The RMM API doesn\'t say each computer\'s HID, so hardware details are matched by computer name.'));
    // The agents weren't said - with the RMM API to ask, the computers it knows have its agent; without it, every one is taken to
    $by_rmm_api = !$listed['agents_said'] && $host['asked'];
    if ($by_rmm_api && (!$host['ok'] || !$host['by_hid'])) {
        // Guessing here would bring in computers that only have the backup agent, as managed devices
        return ['ok' => false, 'by_company' => [], 'error' => rmmProviderName($provider) . ' doesn\'t say which computers have the RMM agent, and the RMM API ' . (!$host['ok'] ? 'didn\'t answer (' . $host['error'] . ')' : 'doesn\'t say each computer\'s HID') . ' - nothing was changed', 'about' => ''];
    }
    if (!$listed['agents_said'] && !$host['asked']) {
        $about = 'MSP360 doesn\'t say which computers have the RMM agent and no RMM API token is set, so every computer comes in, without hardware details.';
    }
    $by_company = [];
    foreach ($listed['computers'] as $computer) {
        $has = $computer['rmm'];
        if ($has === null) {
            $has = $by_rmm_api ? isset($host['by_hid'][strtolower($computer['hid'])]) : true;
        }
        if ($has) {
            $by_company[$computer['company_id'] !== '' ? $computer['company_id'] : '_none'][] = $computer;
        }
    }
    return ['ok' => true, 'by_company' => $by_company, 'error' => '', 'about' => $about];
}

/** MSP360's companies - ['ok', 'clients' => [id, name, sites, devices], 'error', 'about']. */
function rmmMsp360Clients(array $provider): array
{
    $answer = rmmMsp360Backup($provider, 'api/Companies');
    if (!$answer['ok']) {
        return ['ok' => false, 'clients' => [], 'error' => $answer['error']];
    }
    $rows = rmmMsp360Rows($answer['json'], 'companies', 'items', 'data', 'result');
    if ($rows === null) {
        return ['ok' => false, 'clients' => [], 'error' => rmmProviderName($provider) . ' answered, but not with a list of its companies - nothing was changed'];
    }
    $managed = rmmMsp360Managed($provider);
    if (!$managed['ok']) {
        return ['ok' => false, 'clients' => [], 'error' => $managed['error']];
    }
    $clients = [];
    foreach ($rows as $company) {
        $id = (string) (rmmMsp360Value($company, 'id') ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $id)) {
            continue;
        }
        $name = rmmText(rmmMsp360Value($company, 'name') ?? '', 200);
        $clients[] = ['id' => $id, 'name' => $name !== '' ? $name : "Company $id", 'sites' => 0, 'devices' => count($managed['by_company'][$id] ?? [])];
    }
    // Computers that are in no company have somewhere to be linked from too
    if (!empty($managed['by_company']['_none'])) {
        $clients[] = ['id' => '_none', 'name' => 'No company', 'sites' => 0, 'devices' => count($managed['by_company']['_none'])];
    }
    return ['ok' => true, 'clients' => $clients, 'error' => '', 'about' => $managed['about']];
}

/** An MSP360 company's computers - ['ok', 'devices', 'error'], each device as rmmDeviceSave() takes it. */
function rmmMsp360Devices(array $provider, string $client_remote_id): array
{
    $managed = rmmMsp360Managed($provider);
    if (!$managed['ok']) {
        return ['ok' => false, 'devices' => [], 'error' => $managed['error']];
    }
    $host = rmmMsp360Stats($provider, 'host');
    $summary = rmmMsp360Stats($provider, 'summary');
    // A computer's name counts for matching only when one computer of the account has it
    $name_counts = [];
    foreach ($managed['by_company'] as $computers) {
        foreach ($computers as $computer) {
            $name_counts[mb_strtolower($computer['name'])] = ($name_counts[mb_strtolower($computer['name'])] ?? 0) + 1;
        }
    }
    $devices = [];
    foreach ($managed['by_company'][$client_remote_id] ?? [] as $computer) {
        $devices[] = rmmMsp360Device($computer, $host['ok'] ? rmmMsp360Stat($host, $computer, $name_counts) : null, $summary['ok'] ? rmmMsp360Stat($summary, $computer, $name_counts) : null);
    }
    return ['ok' => true, 'devices' => $devices, 'error' => ''];
}

/** One of MSP360's computers as a device - $host and $summary are its records in the RMM API, when it has them. */
function rmmMsp360Device(array $computer, ?array $host, ?array $summary): array
{
    $text = function ($key, $max = 255) use ($host) {
        $value = rmmText(rmmMsp360Value($host, $key) ?? '', $max);
        return rmmIsPlaceholder($value) ? '' : $value;
    };
    $network = rmmMsp360Value($host, 'network');
    $os = $text('osName', 200);
    $family = $computer['os'] === 'windows' || stripos($os, 'windows') !== false ? 'windows'
        : ($computer['os'] === 'apple' || stripos($os, 'mac') !== false ? 'darwin' : ($computer['os'] === 'linux' ? 'linux' : ''));
    $mac = rmmMac(rmmMsp360Value($network, 'macAddress') ?? '');
    if ($mac === '') {
        foreach ((array) (rmmMsp360Value($host, 'wakeOnLanInfos') ?? []) as $card) {
            if (rmmMac(rmmMsp360Value($card, 'macAddress') ?? '') !== '') {
                $mac = rmmMac(rmmMsp360Value($card, 'macAddress'));
                break;
            }
        }
    }
    $local_ip = rmmText(rmmMsp360Value($network, 'ip4Address') ?? '', 100);
    $user = $computer['user'] !== '' ? $computer['user'] : $text('lastLogin');
    if ($user === '' && $text('userName') !== '') {
        $user = ($text('userDomainName') !== '' ? $text('userDomainName') . '\\' : '') . $text('userName');
    }
    $cpu = trim(str_replace(['(R)', '(TM)', '(tm)', '(r)'], '', $text('cpuName', 300)));
    $cores = intval(rmmMsp360Value($host, 'coresCount') ?? 0);
    $threads = intval(rmmMsp360Value($host, 'logicalProcessorsCount') ?? 0);
    $updates = rmmMsp360Value($host, 'updatesInfo');
    // A time it was never told reads as year 1
    $boot = (string) (rmmMsp360Value($host, 'lastBoot') ?? '');
    $state = strtolower((string) (rmmMsp360Value($summary, 'computerState') ?? ''));
    $status = $computer['online'] === true ? 'online' : ($computer['online'] === false ? 'offline' : (str_contains($state, 'offline') ? 'offline' : (str_contains($state, 'online') ? 'online' : '')));
    $name = $computer['name'] !== '' ? $computer['name'] : $text('computerName', 200);
    return [
        'remote_id' => $computer['hid'],
        'mac' => $mac,
        'hostname' => $name,
        'site' => '',
        'role' => stripos($os, 'server') !== false || stripos($text('productType', 50), 'server') !== false ? 'server' : 'workstation',
        'platform' => $family,
        'os' => $os,
        'status' => $status,
        'last_seen_at' => rmmTime($computer['seen']),
        'boot_at' => $boot !== '' && intval(substr($boot, 0, 4)) > 1990 ? rmmTime($boot) : null,
        'logged_user' => $user,
        'public_ip' => $computer['ip'],
        'local_ips' => $local_ip,
        'needs_reboot' => rmmMsp360Value($summary, 'pendingReboot') === true,
        'patches_pending' => intval(rmmMsp360Value($updates, 'availableUpdatesCount') ?? 0) > 0,
        'agent_version' => $computer['version'],
        'asset' => [
            'make' => $text('manufactured', 200) !== '' ? $text('manufactured', 200) : $text('manufacturer', 200),
            'model' => $text('model', 200) !== '' ? $text('model', 200) : $text('computerSystemProduct', 200),
            'serial' => (string) (is_scalar(rmmMsp360Value($host, 'serialNumber')) ? rmmMsp360Value($host, 'serialNumber') : ''),
            'os' => $os,
            'ip' => $local_ip,
            'cpu' => mb_substr($cpu . ($cpu !== '' && $cores > 0 ? ", {$cores}C" . ($threads > 0 ? "/{$threads}T" : '') : ''), 0, 255),
            'storage' => '',
            'mac' => $mac,
        ],
    ];
}

/** Where Open in MSP360 goes - its console. */
function rmmMsp360Dashboard(array $provider): string
{
    return rtrim(RMM_MSP360_CONSOLE, '/');
}
