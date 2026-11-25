<?php

/*
 * Omada - a network platform (Admin > Network Platforms), kinds of the RMM providers' engine
 * (functions/rmm.php) in the network family, as UniFi's are (functions/rmm_unifi.php): a
 * controller's sites are linked to clients, and its gateways, switches and access points become
 * assets kept up to date, each known by its MAC address.
 *
 * It reads Omada's Open API - on the controller under Global View > Settings > Platform
 * Integration > Open API. Hardware and software controllers have it from version 5.12; the
 * Fusion gateways' built-in controller does not have it at all. Two ways in, one kind each:
 *
 *   omada      An application in Client mode: a client ID and secret, exchanged for an access
 *              token that lasts two hours (POST /openapi/authorize/token?grant_type=
 *              client_credentials, then Authorization: AccessToken=<token>). What it can see
 *              is the role and the sites picked for the application.
 *   omada_key  An API key, on a controller of version 6.4 or later, sent as it is
 *              (Authorization: Bearer <key>). One made with View permission can change nothing.
 *
 * Both need two things the controller shows under View on the application or key: its
 * Interface Access Address, and its Omada ID - which is in every path. The Omada ID is kept
 * where another kind keeps its dashboard address (rmm_provider_web_url), as text.
 *
 * Answers are {errorCode, msg, result}, and a list's result is a page of it: {totalRows, data}.
 * A site's id there is its siteId. Credentials made in MSP View use other paths
 * (/openapi/v1/msp/...) and are not read here.
 *
 * Not here yet: tickets for devices that go offline (the 'alerts' functions), and firmware
 * updates waiting, which the device list doesn't say.
 */

// What an Omada device's make is written as on its asset
defined('RMM_OMADA_MAKE') || define('RMM_OMADA_MAKE', 'Omada');

/** The Omada kinds - one for each way in. */
function rmmOmadaKinds(): array
{
    return [
        'omada' => rmmOmadaKind('omada'),
        'omada_key' => rmmOmadaKind('omada_key'),
    ];
}

/** One Omada kind, as rmmProviderKinds() describes a kind. */
function rmmOmadaKind(string $key): array
{
    $kind = [
        'family' => 'network',
        'short' => 'Omada',
        'icon' => 'fas fa-wifi',
        'seal' => true,
        'device_kinds' => ['Firewall/Router' => 'Gateways', 'Switch' => 'Switches', 'Access Point' => 'Access points', 'Other' => 'Other Omada devices'],
        'links' => ['open' => 'Open in Omada'],
        'clients' => 'rmmOmadaSites',
        'devices' => 'rmmOmadaDevices',
        'networks' => 'rmmOmadaNetworks',
        'seen' => 'rmmOmadaSeen',
        'dashboard' => 'rmmOmadaDashboard',
        'docs_url' => 'https://support.omadanetworks.com/us/document/139218/',
    ];
    $fields = [
        'api_url' => [
            'label' => 'Interface access address', 'icon' => 'fa-server', 'type' => 'url', 'required' => true, 'placeholder' => 'https://192.168.0.2:8043',
            'help' => 'Shown under View on the application or key in Omada, at Settings > Platform Integration > Open API. ITFlow needs to be able to reach it.',
            'needed' => 'The controller\'s interface access address is needed - it is under View on the application or key in Omada.',
        ],
        'web_url' => [
            'label' => 'Omada ID', 'icon' => 'fa-fingerprint', 'type' => 'text', 'required' => true, 'placeholder' => '',
            'help' => 'The controller\'s Omada ID, shown beside the interface access address.',
            'needed' => 'The Omada ID is needed - it is under View on the application or key in Omada.',
        ],
    ];
    $insecure = [
        'label' => 'Accept the certificate it made for itself', 'icon' => 'fa-certificate', 'type' => 'switch', 'required' => false, 'placeholder' => '',
        'help' => 'A controller you run yourself makes its own certificate, which nothing trusts - turn this on for one reached by its own address. Leave it off for an address with a real certificate.',
        'needed' => '', 'on' => 'its own certificate accepted', 'off' => 'its certificate checked',
    ];
    $hints = [
        'not_found' => 'is that the interface access address Omada shows, and is its Open API on?',
        'not_json' => 'is that the interface access address Omada shows under View?',
        'certificate' => ' - a controller with a certificate it made for itself needs "Accept the certificate it made for itself" on.',
    ];
    if ($key === 'omada_key') {
        return array_merge($kind, [
            'name' => 'Omada controller (API key)',
            'default_name' => 'Omada Controller',
            'about' => 'An Omada controller of version 6.4 or later, with an API key - its sites, and their gateways, switches and access points as assets.',
            'fields' => $fields + [
                'api_secret' => [
                    'label' => 'API key', 'icon' => 'fa-key', 'type' => 'secret', 'required' => true, 'placeholder' => '',
                    'help' => 'In Omada: Settings > Platform Integration > Open API > Add New Credential, as an API key with View permission. Kept encrypted with this ITFlow\'s own key.',
                    'needed' => 'The API key is needed - make one in Omada under Settings > Platform Integration > Open API.',
                ],
                'insecure' => $insecure,
            ],
            'hints' => $hints + ['auth' => 'check the key, made in Omada under Settings > Platform Integration > Open API, and the sites it may see.'],
        ]);
    }
    return array_merge($kind, [
        'name' => 'Omada controller (client ID)',
        'default_name' => 'Omada Controller',
        'about' => 'An Omada controller, with an Open API application in Client mode - its sites, and their gateways, switches and access points as assets.',
        'fields' => $fields + [
            'api_key' => [
                'label' => 'Client ID', 'icon' => 'fa-id-badge', 'type' => 'text', 'required' => true, 'placeholder' => '',
                'help' => 'In Omada: Settings > Platform Integration > Open API > Add New App, in Client mode, with a role that can only view.',
                'needed' => 'The client ID is needed - make an application in Client mode in Omada under Settings > Platform Integration > Open API.',
            ],
            'api_secret' => [
                'label' => 'Client secret', 'icon' => 'fa-key', 'type' => 'secret', 'required' => true, 'placeholder' => '',
                'help' => 'Shown beside the client ID. Kept encrypted with this ITFlow\'s own key.',
                'needed' => 'The client secret is needed - it is beside the client ID in Omada.',
            ],
            'insecure' => $insecure,
        ],
        'hints' => $hints + ['auth' => 'check the client ID and secret, and the role and sites picked for the application.'],
    ]);
}

/*
 * ------------------------------------------------------------------ Asking Omada
 */

/** The options every request of a controller takes - its own certificate accepted, when it says so. */
function rmmOmadaOpts(array $provider): array
{
    return ['insecure' => intval($provider['rmm_provider_insecure'] ?? 0) === 1];
}

/** A controller's Omada ID as it goes into a path - '' when it isn't one. */
function rmmOmadaId(array $provider): string
{
    $id = trim((string) ($provider['rmm_provider_web_url'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) ? $id : '';
}

/**
 * What a request is signed with - ['ok', 'header', 'error']: the API key as it is (omada_key),
 * or an access token got with the client ID and secret (omada). A token is kept for the
 * request; $again gets a new one, for one that was turned away.
 */
function rmmOmadaAuth(array $provider, bool $again = false): array
{
    static $tokens = [];
    $name = rmmProviderName($provider);
    $base = rmmUrlClean($provider['rmm_provider_api_url'] ?? '');
    $omada_id = rmmOmadaId($provider);
    $secret = rmmSecret($provider, 'api_secret');
    $unread = $secret === '' && (string) ($provider['rmm_provider_api_secret'] ?? '') !== '' ? ' - its secret can\'t be read, so type it again' : '';
    if ($base === '' || $omada_id === '' || $secret === '') {
        return ['ok' => false, 'header' => '', 'error' => "Its address, Omada ID and key aren't set$unread."];
    }
    if ((string) ($provider['rmm_provider_kind'] ?? '') === 'omada_key') {
        return ['ok' => true, 'header' => 'Authorization: Bearer ' . $secret, 'error' => ''];
    }

    $id = intval($provider['rmm_provider_id'] ?? 0);
    if (!$again && isset($tokens[$id])) {
        return $tokens[$id];
    }
    $client_id = trim((string) ($provider['rmm_provider_api_key'] ?? ''));
    if ($client_id === '') {
        return ['ok' => false, 'header' => '', 'error' => 'Its client ID isn\'t set.'];
    }
    $answer = rmmHttp($provider, 'POST', "$base/openapi/authorize/token?grant_type=client_credentials", [],
        ['omadacId' => $omada_id, 'client_id' => $client_id, 'client_secret' => $secret], 30, rmmOmadaOpts($provider));
    if (!$answer['ok']) {
        return $tokens[$id] = ['ok' => false, 'header' => '', 'error' => $answer['error']];
    }
    $token = is_array($answer['json']['result'] ?? null) ? (string) ($answer['json']['result']['accessToken'] ?? '') : '';
    if (intval($answer['json']['errorCode'] ?? -1) !== 0 || $token === '' || preg_match('/[\x00-\x20]/', $token)) {
        $said = rmmText($answer['json']['msg'] ?? '', 200);
        return $tokens[$id] = ['ok' => false, 'header' => '', 'error' => "$name turned the client ID and secret away" . ($said !== '' ? " ($said)" : '') . ' - check them, the Omada ID, and that the application is in Client mode.'];
    }
    return $tokens[$id] = ['ok' => true, 'header' => 'Authorization: AccessToken=' . $token, 'error' => ''];
}

/**
 * Ask the Open API for one thing - GET /openapi/<version>/<Omada ID>/<path>: ['ok', 'result',
 * 'error', 'code']. An access token that was turned away is got again, once.
 */
function rmmOmadaAsk(array $provider, string $path, array $query = [], string $version = 'v1', int $timeout = 60): array
{
    $base = rmmUrlClean($provider['rmm_provider_api_url'] ?? '');
    $url = "$base/openapi/$version/" . rawurlencode(rmmOmadaId($provider)) . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&') : '');
    $auth = rmmOmadaAuth($provider);
    for ($try = 0; $try < 2; $try++) {
        if (!$auth['ok']) {
            return ['ok' => false, 'result' => null, 'error' => $auth['error'], 'code' => 0];
        }
        $answer = rmmHttp($provider, 'GET', $url, [$auth['header']], null, $timeout, rmmOmadaOpts($provider));
        // -44112 and -44113: the access token expired, or isn't one
        $code = is_array($answer['json']) ? intval($answer['json']['errorCode'] ?? 0) : 0;
        if ($try === 0 && ($answer['code'] === 401 || in_array($code, [-44112, -44113], true)) && (string) ($provider['rmm_provider_kind'] ?? '') !== 'omada_key') {
            $auth = rmmOmadaAuth($provider, true);
            continue;
        }
        break;
    }
    if (!$answer['ok']) {
        return ['ok' => false, 'result' => null, 'error' => $answer['error'], 'code' => $answer['code']];
    }
    if (!array_key_exists('errorCode', $answer['json']) || intval($answer['json']['errorCode']) !== 0) {
        $said = rmmText($answer['json']['msg'] ?? '', 200);
        return ['ok' => false, 'result' => null, 'error' => rmmProviderName($provider) . ' answered, but not with what was asked for' . ($said !== '' ? " ($said)" : '') . ' - nothing was changed', 'code' => $answer['code']];
    }
    return ['ok' => true, 'result' => $answer['json']['result'] ?? null, 'error' => '', 'code' => $answer['code']];
}

/**
 * Ask the Open API for a list, page by page - ['ok', 'data', 'total', 'error', 'code']. A page
 * that isn't a list is an error, never "it has none": read as none, every device would be
 * marked gone. $only_count asks for one entry, for how many there are.
 */
function rmmOmadaList(array $provider, string $path, string $version = 'v1', bool $only_count = false): array
{
    $data = [];
    $size = $only_count ? 1 : 200;
    for ($page = 1; $page <= 50; $page++) {
        $answer = rmmOmadaAsk($provider, $path, ['page' => $page, 'pageSize' => $size], $version);
        if (!$answer['ok']) {
            return ['ok' => false, 'data' => [], 'total' => 0, 'error' => $answer['error'], 'code' => $answer['code']];
        }
        $rows = is_array($answer['result']) ? ($answer['result']['data'] ?? null) : null;
        if (!rmmIsList($rows)) {
            return ['ok' => false, 'data' => [], 'total' => 0, 'error' => rmmProviderName($provider) . ' answered, but not with a list - nothing was changed', 'code' => $answer['code']];
        }
        $total = intval($answer['result']['totalRows'] ?? count($rows));
        $data = array_merge($data, $rows);
        if ($only_count || !$rows || count($data) >= $total) {
            return ['ok' => true, 'data' => $data, 'total' => $total, 'error' => '', 'code' => $answer['code']];
        }
    }
    return ['ok' => false, 'data' => [], 'total' => 0, 'error' => rmmProviderName($provider) . ' has more than ITFlow reads in one go - nothing was changed', 'code' => 0];
}

/*
 * ------------------------------------------------------------------ Sites, devices, networks
 */

/** A controller's sites - ['ok', 'clients' => [id, name, sites, devices], 'error', 'about'], as a kind's clients function gives them. */
function rmmOmadaSites(array $provider): array
{
    $answer = rmmOmadaList($provider, 'sites');
    if (!$answer['ok']) {
        return ['ok' => false, 'clients' => [], 'error' => $answer['error']];
    }
    $sites = [];
    foreach ($answer['data'] as $site) {
        $id = is_array($site) ? (string) ($site['siteId'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
            continue;
        }
        $name = rmmText($site['name'] ?? '', 200);
        $count = rmmOmadaList($provider, 'sites/' . rawurlencode($id) . '/devices', 'v1', true);
        $sites[] = ['id' => $id, 'name' => $name !== '' ? $name : "Site $id", 'sites' => 1, 'devices' => $count['ok'] ? $count['total'] : 0];
    }
    // A controller with the one site it was given, never renamed, goes by the platform's name
    if (count($sites) === 1 && strtolower($sites[0]['name']) === 'default') {
        $sites[0]['name'] = rmmText($provider['rmm_provider_name'] ?? '', 200) ?: $sites[0]['name'];
    }
    return ['ok' => true, 'clients' => $sites, 'error' => '',
        'about' => (string) ($provider['rmm_provider_kind'] ?? '') === 'omada_key' ? 'It reads Omada\'s Open API with an API key.' : 'It reads Omada\'s Open API as an application in Client mode.'];
}

/** A site's devices as Omada gave them, kept for the request - the sync asks for them, then for where things plug in. $set keeps them; null when not asked yet. */
function rmmOmadaSiteDevices(array $provider, string $site, ?array $set = null): ?array
{
    static $kept = [];
    $key = intval($provider['rmm_provider_id'] ?? 0) . '|' . $site;
    if ($set !== null) {
        $kept[$key] = $set;
    }
    return $kept[$key] ?? null;
}

/** One site's devices - ['ok', 'devices', 'error'], each as rmmDeviceSave() takes it. One waiting to be adopted isn't the site's yet. */
function rmmOmadaDevices(array $provider, string $site): array
{
    $answer = rmmOmadaList($provider, 'sites/' . rawurlencode($site) . '/devices');
    if (!$answer['ok']) {
        return ['ok' => false, 'devices' => [], 'error' => $answer['error']];
    }
    rmmOmadaSiteDevices($provider, $site, $answer['data']);
    $devices = [];
    foreach ($answer['data'] as $device) {
        if (is_array($device) && rmmMac($device['mac'] ?? '') !== '' && intval($device['status'] ?? -1) !== 2) {
            $devices[] = rmmOmadaDevice($device);
        }
    }
    return ['ok' => true, 'devices' => $devices, 'error' => ''];
}

/** The Open API's device (sites/{id}/devices) as a device. */
function rmmOmadaDevice(array $device): array
{
    $mac = rmmMac($device['mac'] ?? '');
    $type = strtolower((string) ($device['type'] ?? ''));
    $model = rmmText($device['modelName'] ?? '', 200);
    if ($model === '') {
        $model = rmmText($device['model'] ?? '', 200);
    }
    $firmware = rmmText($device['firmwareVersion'] ?? '', 100);
    // 1 connected is up; 0 disconnected, 3 heartbeat missed and 4 isolated are down. 2, pending, isn't brought in
    $state = intval($device['status'] ?? -1);
    $status = $state === 1 ? 'online' : (in_array($state, [0, 3, 4], true) ? 'offline' : '');
    // Its last seen is in milliseconds
    $seen = is_numeric($device['lastSeen'] ?? null) ? intdiv(intval($device['lastSeen']), intval($device['lastSeen']) > 99999999999 ? 1000 : 1) : 0;
    $name = rmmText($device['name'] ?? '', 200);
    $ip = rmmText($device['ip'] ?? '', 100);
    return [
        'remote_id' => $mac,
        'mac' => $mac,
        'hostname' => $name !== '' && rmmMac($name) === '' ? $name : trim($model . ' ' . strtoupper(substr($mac, -6))),
        'site' => '',
        'role' => '',
        'platform' => 'omada',
        'os' => $firmware,
        'status' => $status,
        'last_seen_at' => $seen > 0 ? rmmTime($seen) : null,
        'public_ip' => $type === 'gateway' ? rmmText($device['publicIp'] ?? '', 100) : '',
        'local_ips' => $ip,
        'agent_version' => '',
        'asset' => [
            'type' => $type === 'gateway' ? 'Firewall/Router' : ($type === 'switch' ? 'Switch' : ($type === 'ap' ? 'Access Point' : 'Other')),
            'make' => RMM_OMADA_MAKE,
            'model' => $model,
            'serial' => (string) ($device['sn'] ?? ''),
            'os' => $firmware,
            'ip' => $ip,
            'mac' => $mac,
        ],
    ];
}

/**
 * One site's networks and VLANs - ['ok', 'networks', 'error', 'unoffered'], each as rmmNetworkSave()
 * takes it (functions/rmm_network.php). Only the ones with a subnet of their own - an interface,
 * in Omada's word: a VLAN with no subnet isn't one of the client's networks. The second list
 * (v2) says each DHCP range; a controller without it has the first (v1).
 */
function rmmOmadaNetworks(array $provider, string $site): array
{
    $answer = rmmOmadaList($provider, 'sites/' . rawurlencode($site) . '/lan-networks', 'v2');
    if (!$answer['ok'] && in_array($answer['code'], [404, 405], true)) {
        $answer = rmmOmadaList($provider, 'sites/' . rawurlencode($site) . '/lan-networks', 'v1');
    }
    if (!$answer['ok']) {
        // A controller older than its networks list - nothing to bring in, and nothing wrong
        return in_array($answer['code'], [404, 405], true) ? ['ok' => true, 'networks' => [], 'error' => '', 'unoffered' => true] : ['ok' => false, 'networks' => [], 'error' => $answer['error'], 'unoffered' => false];
    }
    $networks = [];
    foreach ($answer['data'] as $net) {
        $id = is_array($net) ? (string) ($net['id'] ?? '') : '';
        $subnet = is_array($net) ? rmmOmadaSubnet($net['gatewaySubnet'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) || $subnet === '') {
            continue;
        }
        $dhcp = is_array($net['dhcpSettingsVO'] ?? null) && !empty($net['dhcpSettingsVO']['enable']) ? $net['dhcpSettingsVO'] : [];
        $range = is_array($dhcp['ipRangePool'][0] ?? null) ? $dhcp['ipRangePool'][0] : $dhcp;
        $dns = [];
        if ($dhcp && strtolower((string) ($dhcp['dhcpns'] ?? '')) === 'manual') {
            foreach (['priDns', 'sndDns'] as $field) {
                if (trim((string) ($dhcp[$field] ?? '')) !== '') {
                    $dns[] = (string) $dhcp[$field];
                }
            }
        }
        $networks[] = [
            'remote_id' => $id,
            'name' => (string) ($net['name'] ?? ''),
            'purpose' => 'lan',
            // One VLAN (vlanType 0) is written down; several on one interface are not
            'vlan' => intval($net['vlanType'] ?? 0) === 0 ? intval($net['vlan'] ?? 0) : 0,
            'subnet' => $subnet,
            'dhcp_start' => (string) ($range['ipaddrStart'] ?? ''),
            'dhcp_stop' => (string) ($range['ipaddrEnd'] ?? ''),
            'dns' => $dns,
        ];
    }
    return ['ok' => true, 'networks' => $networks, 'error' => '', 'unoffered' => false];
}

/** Omada's gateway subnet - "192.168.0.1/24", or with its mask written out - as the gateway's address and prefix. '' when it isn't one. */
function rmmOmadaSubnet($value): string
{
    [$host, $mask] = array_pad(explode('/', trim((string) $value), 2), 2, '');
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask === '') {
        return '';
    }
    if (ctype_digit($mask)) {
        return intval($mask) >= 1 && intval($mask) <= 32 ? "$host/" . intval($mask) : '';
    }
    $long = filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($mask) : false;
    if ($long === false) {
        return '';
    }
    $bits = decbin($long & 0xFFFFFFFF);
    return preg_match('/^1+0*$/', $bits) && strlen($bits) === 32 ? "$host/" . substr_count($bits, '1') : '';
}

/**
 * What a site's switches and access points see - ['ok', 'seen', 'error'], each as rmmSeenSync()
 * takes it (functions/rmm_network.php): what its own devices are plugged into, and the clients
 * on it now - by switch and port, or access point and Wi-Fi network.
 */
function rmmOmadaSeen(array $provider, string $site): array
{
    $seen = [];
    $devices = rmmOmadaSiteDevices($provider, $site);
    if ($devices === null) {
        $answer = rmmOmadaList($provider, 'sites/' . rawurlencode($site) . '/devices');
        if (!$answer['ok']) {
            return ['ok' => false, 'seen' => [], 'error' => $answer['error']];
        }
        $devices = $answer['data'];
    }
    foreach ($devices as $device) {
        if (!is_array($device) || rmmMac($device['mac'] ?? '') === '' || rmmMac($device['uplinkDeviceMac'] ?? '') === '') {
            continue;
        }
        $port = rmmText($device['uplinkDevicePort'] ?? '', 50);
        $seen[] = [
            'mac' => (string) $device['mac'], 'hostname' => (string) ($device['name'] ?? ''), 'ip' => (string) ($device['ip'] ?? ''),
            'via_mac' => (string) $device['uplinkDeviceMac'], 'port' => $port, 'wired' => true, 'ssid' => '', 'network' => '', 'vlan' => 0,
        ];
    }

    $answer = rmmOmadaList($provider, 'sites/' . rawurlencode($site) . '/clients');
    if (!$answer['ok']) {
        // A controller without its clients list - nothing to say, and nothing wrong
        return in_array($answer['code'], [404, 405], true) ? ['ok' => true, 'seen' => $seen, 'error' => ''] : ['ok' => false, 'seen' => [], 'error' => $answer['error']];
    }
    foreach ($answer['data'] as $client) {
        if (!is_array($client) || rmmMac($client['mac'] ?? '') === '' || ($client['active'] ?? true) === false) {
            continue;
        }
        $wired = empty($client['wireless']);
        $via = $wired ? (rmmMac($client['switchMac'] ?? '') !== '' ? $client['switchMac'] : ($client['gatewayMac'] ?? '')) : ($client['apMac'] ?? '');
        $port = $wired && intval($client['port'] ?? 0) > 0 ? (string) intval($client['port']) : ($wired ? rmmText($client['portName'] ?? '', 50) : '');
        $name = rmmText($client['name'] ?? '', 200);
        $seen[] = [
            'mac' => (string) $client['mac'],
            // Omada names a client it knows nothing of by its MAC address - that isn't a name to match on
            'hostname' => $name !== '' && rmmMac($name) === '' ? $name : (string) ($client['hostName'] ?? ''),
            'ip' => (string) ($client['ip'] ?? ''),
            'via_mac' => (string) $via, 'port' => $port, 'wired' => $wired, 'ssid' => $wired ? '' : (string) ($client['ssid'] ?? ''),
            'network' => (string) ($client['networkName'] ?? ''), 'vlan' => intval($client['vid'] ?? 0),
        ];
    }
    return ['ok' => true, 'seen' => $seen, 'error' => ''];
}

/** Where Open in Omada goes - the cloud portal for an address of Omada's cloud, or the controller's own. */
function rmmOmadaDashboard(array $provider): string
{
    $base = rmmUrlClean($provider['rmm_provider_api_url'] ?? '');
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    if ($host !== '' && (str_ends_with($host, '.tplinkcloud.com') || str_ends_with($host, '.omadanetworks.com'))) {
        return 'https://omada.tplinkcloud.com';
    }
    return $base;
}
