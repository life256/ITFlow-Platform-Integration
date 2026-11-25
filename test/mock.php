<?php

/*
 * Mock Omada Open API and Action1 API for testing the vendor files - php -S 127.0.0.1:8765 mock.php
 * Shapes follow the Omada Open API specification and Action1's documented sample records.
 * Every value here is made up.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$state_file = sys_get_temp_dir() . '/mock_state.json';
$state = is_file($state_file) ? json_decode(file_get_contents($state_file), true) : [];
$log = sys_get_temp_dir() . '/mock_requests.log';
file_put_contents($log, "$method {$_SERVER['REQUEST_URI']} | auth=" . substr($auth, 0, 28) . " | host={$_SERVER['HTTP_HOST']}\n", FILE_APPEND);
$save = function () use (&$state, $state_file) {
    file_put_contents($state_file, json_encode($state));
};
$json = function ($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};
$page = function (array $rows) use ($json) {
    $p = max(1, intval($_GET['page'] ?? 1));
    $size = max(1, intval($_GET['pageSize'] ?? 10));
    if (!isset($_GET['page'], $_GET['pageSize'])) {
        $json(['errorCode' => -1001, 'msg' => 'Invalid request parameters.']);
    }
    $json(['errorCode' => 0, 'msg' => 'Success.', 'result' => ['totalRows' => count($rows), 'currentPage' => $p, 'currentSize' => $size, 'data' => array_slice($rows, ($p - 1) * $size, $size)]]);
};

const OMADA_ID = 'abc123def4567890abc123def4567890';
const SITE_MAIN = '64a1f0c2e4b0a1b2c3d4e5f6';
const SITE_DEFAULT = '64a1f0c2e4b0a1b2c3d4e5f7';

// ---------------------------------------------------------------- Omada
if ($path === '/openapi/authorize/token') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($method !== 'POST' || ($_GET['grant_type'] ?? '') !== 'client_credentials') {
        $json(['errorCode' => -44111, 'msg' => 'The Grant Type is Invalid.']);
    }
    if (($body['omadacId'] ?? '') !== OMADA_ID || ($body['client_id'] ?? '') !== 'cid-test' || ($body['client_secret'] ?? '') !== 'csecret-test') {
        $json(['errorCode' => -44106, 'msg' => 'The client id or client secret is invalid.']);
    }
    $state['omada_tokens'] = ($state['omada_tokens'] ?? 0) + 1;
    $save();
    $json(['errorCode' => 0, 'msg' => 'Open API Get Access Token successfully.', 'result' => ['accessToken' => 'AT-test-' . $state['omada_tokens'], 'tokenType' => 'bearer', 'expiresIn' => 7200, 'refreshToken' => 'RT-test']]);
}
if (preg_match('#^/openapi/(v\d)/([^/]+)/(.*)$#', $path, $m)) {
    [, $version, $omada_id, $rest] = $m;
    $token_ok = preg_match('/^AccessToken=AT-test-\d+$/', $auth) || $auth === 'Bearer AK-test-key';
    if (!$token_ok) {
        $json(['errorCode' => -44113, 'msg' => 'The access token is Invalid.']);
    }
    // The first token ever issued is treated as expired once, to exercise the re-auth path
    if ($auth === 'AccessToken=AT-test-1' && empty($state['expired_once'])) {
        $state['expired_once'] = true;
        $save();
        $json(['errorCode' => -44112, 'msg' => 'The access token has expired. Please re-initiate the refreshToken process to obtain the access token.']);
    }
    if ($omada_id !== OMADA_ID) {
        $json(['errorCode' => -7131, 'msg' => 'Controller ID not exist.']);
    }
    if ($rest === 'sites' && $version === 'v1') {
        $page([
            ['siteId' => SITE_MAIN, 'name' => 'Main Campus', 'region' => 'United States', 'timeZone' => 'America/Chicago', 'scenario' => 'Office', 'type' => 0, 'primary' => true],
            ['siteId' => SITE_DEFAULT, 'name' => 'Annex', 'region' => 'United States', 'timeZone' => 'America/Chicago', 'scenario' => 'Office', 'type' => 0, 'primary' => false],
        ]);
    }
    if ($rest === 'sites/' . SITE_MAIN . '/devices' && $version === 'v1') {
        $gone = !empty($state['omada_drop_ap']);
        $devices = [
            ['mac' => 'AA-BB-CC-00-00-01', 'name' => 'GW-Main', 'type' => 'gateway', 'model' => 'ER7206 v2.0', 'modelName' => 'ER7206', 'modelVersion' => '2.0', 'firmwareVersion' => '2.2.3 Build 20260301', 'ip' => '192.168.10.1', 'publicIp' => '203.0.113.10', 'status' => 1, 'detailStatus' => 14, 'sn' => 'Y2440000001', 'lastSeen' => 1795493000000, 'uptime' => '12day(s) 3h 2m 1s'],
            ['mac' => 'AA-BB-CC-00-00-02', 'name' => 'SW-Core', 'type' => 'switch', 'subtype' => 'smart', 'model' => 'SG2428P v5.20', 'modelName' => 'SG2428P', 'firmwareVersion' => '5.20.8', 'ip' => '192.168.10.2', 'status' => 1, 'sn' => 'Y2440000002', 'lastSeen' => 1795493000000, 'uplinkDeviceMac' => 'AA-BB-CC-00-00-01', 'uplinkDeviceName' => 'GW-Main', 'uplinkDevicePort' => '2'],
            // Never renamed - Omada calls it by its MAC address
            ['mac' => 'AA-BB-CC-00-00-03', 'name' => 'AA-BB-CC-00-00-03', 'type' => 'ap', 'model' => 'EAP650 v1.0', 'modelName' => 'EAP650', 'firmwareVersion' => '1.1.2', 'ip' => '192.168.10.3', 'status' => 3, 'sn' => 'Y2440000003', 'lastSeen' => 1795400000000, 'uplinkDeviceMac' => 'AA-BB-CC-00-00-02', 'uplinkDevicePort' => '5'],
            // Pending - not adopted, so not the site's yet
            ['mac' => 'AA-BB-CC-00-00-04', 'name' => 'AA-BB-CC-00-00-04', 'type' => 'ap', 'model' => 'EAP610 v3.0', 'modelName' => 'EAP610', 'firmwareVersion' => '1.0.0', 'ip' => '192.168.10.40', 'status' => 2],
            ['mac' => 'AA-BB-CC-00-00-05', 'name' => 'OLT-Rack <b>1</b>', 'type' => 'olt', 'model' => 'DS-P7001-04', 'modelName' => 'DS-P7001-04', 'firmwareVersion' => '1.0.3', 'ip' => '192.168.10.5', 'status' => 1, 'sn' => '0'],
        ];
        if ($gone) {
            unset($devices[2]);
        }
        $page(array_values($devices));
    }
    if ($rest === 'sites/' . SITE_DEFAULT . '/devices' && $version === 'v1') {
        $page([]);
    }
    if ($rest === 'sites/' . SITE_MAIN . '/lan-networks' && $version === 'v2') {
        $page([
            ['id' => 'net000000000000000000001', 'name' => 'LAN', 'purpose' => 1, 'vlan' => 1, 'vlanType' => 0, 'gatewaySubnet' => '192.168.10.1/24', 'primary' => true,
                'dhcpSettingsVO' => ['enable' => true, 'dhcpns' => 'auto', 'ipRangePool' => [['ipaddrStart' => '192.168.10.100', 'ipaddrEnd' => '192.168.10.199']], 'leasetime' => 120]],
            ['id' => 'net000000000000000000002', 'name' => 'Guest WiFi', 'purpose' => 1, 'vlan' => 20, 'vlanType' => 0, 'gatewaySubnet' => '192.168.20.1/255.255.255.0',
                'dhcpSettingsVO' => ['enable' => true, 'dhcpns' => 'manual', 'priDns' => '1.1.1.1', 'sndDns' => '9.9.9.9', 'ipRangePool' => [['ipaddrStart' => '192.168.20.10', 'ipaddrEnd' => '192.168.20.250']]]],
            // A VLAN with no subnet of its own - not one of the client's networks
            ['id' => 'net000000000000000000003', 'name' => 'Cameras', 'purpose' => 0, 'vlan' => 30, 'vlanType' => 0],
            ['id' => 'net000000000000000000004', 'name' => 'Trunk set', 'purpose' => 1, 'vlanType' => 1, 'vlans' => '200,300-310', 'gatewaySubnet' => '10.50.0.1/22', 'dhcpSettingsVO' => ['enable' => false]],
        ]);
    }
    if ($rest === 'sites/' . SITE_MAIN . '/clients' && $version === 'v1') {
        $page([
            ['mac' => '11-22-33-44-55-66', 'name' => 'FRONTDESK-PC', 'hostName' => 'FRONTDESK-PC', 'ip' => '192.168.10.110', 'wireless' => false, 'active' => true, 'connectDevType' => 'switch', 'switchMac' => 'AA-BB-CC-00-00-02', 'switchName' => 'SW-Core', 'port' => 7, 'networkName' => 'LAN', 'vid' => 1],
            ['mac' => '11-22-33-44-55-77', 'name' => '11-22-33-44-55-77', 'hostName' => 'LAPTOP-07', 'ip' => '192.168.20.31', 'wireless' => true, 'active' => true, 'connectDevType' => 'ap', 'apMac' => 'AA-BB-CC-00-00-03', 'apName' => 'EAP650', 'ssid' => 'Guest', 'networkName' => 'Guest WiFi', 'vid' => 20],
            ['mac' => '11-22-33-44-55-88', 'name' => 'Unknown phone', 'ip' => '192.168.20.44', 'wireless' => true, 'active' => true, 'apMac' => 'AA-BB-CC-00-00-03', 'ssid' => 'Guest'],
            ['mac' => '11-22-33-44-55-99', 'name' => 'OLD-PC', 'ip' => '192.168.10.111', 'wireless' => false, 'active' => false, 'switchMac' => 'AA-BB-CC-00-00-02', 'port' => 9],
        ]);
    }
    $json(['timestamp' => time(), 'status' => 404, 'error' => 'Not Found', 'path' => $path], 404);
}

// ---------------------------------------------------------------- Action1
const ORG_ONE = '00000000-0000-4000-8000-000000000001';
const ORG_TWO = '00000000-0000-4000-8000-000000000002';

if ($path === '/api/3.0/oauth2/token') {
    $form = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded');
    if ($method !== 'POST' || !$form || ($_POST['client_id'] ?? '') !== 'api-key-test@example.invalid' || ($_POST['client_secret'] ?? '') !== 'a1-secret-test') {
        $json(['error' => 'invalid_client', 'error_description' => 'Bad client credentials'], 401);
    }
    $state['a1_tokens'] = ($state['a1_tokens'] ?? 0) + 1;
    $save();
    $json(['access_token' => 'a1tok-' . $state['a1_tokens'], 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'a1refresh']);
}
if (str_starts_with($path, '/api/3.0/')) {
    if (!preg_match('/^Bearer a1tok-\d+$/', $auth)) {
        $json(['type' => 'Error', 'user_message' => 'Unauthorized'], 401);
    }
    $rest = substr($path, 9);
    $self = 'https://app.action1.com/api/3.0';
    if ($rest === 'organizations') {
        $json(['type' => 'ResultPage', 'name' => '', 'self' => "$self/organizations?from=0&limit=50", 'items' => [
            ['id' => ORG_ONE, 'type' => 'Organization', 'self' => "$self/organizations/" . ORG_ONE, 'name' => 'Acme Test Org', 'description' => ''],
            ['id' => ORG_TWO, 'type' => 'Organization', 'self' => "$self/organizations/" . ORG_TWO, 'name' => 'Second Org', 'description' => ''],
        ], 'total_items' => '2', 'limit' => '50', 'next_page' => '', 'prev_page' => '']);
    }
    if ($rest === 'endpoints/managed/' . ORG_ONE) {
        $all = [
            ['id' => '11111111-0000-4000-8000-00000000a001', 'type' => 'Endpoint', 'self' => "$self/endpoints/managed/" . ORG_ONE . '/11111111-0000-4000-8000-00000000a001/general', 'status' => 'Connected', 'last_seen' => '2026-11-23_22-10-11', 'name' => 'FRONTDESK-PC', 'address' => '192.168.10.110', 'external_address' => '203.0.113.10', 'OS' => 'Windows 11 (24H2)', 'platform' => 'Windows_64', 'agent_version' => '5.216.631.1', 'user' => 'ACME\\front.desk', 'comment' => 'None', 'device_name' => '', 'MAC' => '11:22:33:44:55:66', 'serial' => 'PF3ABCDE', 'reboot_required' => 'Yes', 'online_status' => 'UNDEFINED', 'CPU_name' => 'Intel(R) Core(TM) i5-1235U', 'CPU_size' => '1x1.3 GHz, 10/12 Cores', 'disk' => '512Gb NVMe SAMSUNG', 'manufacturer' => 'LENOVO', 'RAM' => '16Gb', 'last_boot_time' => '2026-11-20_08-00-00', 'update_status' => 'UNDEFINED', 'missing_critical_updates' => 2, 'missing_other_updates' => 1],
            ['id' => '11111111-0000-4000-8000-00000000a002', 'type' => 'Endpoint', 'status' => 'Disconnected', 'last_seen' => '2026-11-20_01-00-00', 'name' => 'FILE01', 'address' => '192.168.10.20', 'OS' => 'Windows Server 2022', 'platform' => 'Windows_64', 'agent_version' => '5.216.631.1', 'user' => '', 'MAC' => '11:22:33:44:66:01', 'serial' => 'SRV-SERIAL-9', 'reboot_required' => 'No', 'CPU_name' => 'Intel(R) Xeon(R) Silver 4310', 'CPU_size' => '2x2.1 GHz, 24/48 Cores', 'disk' => '2x960Gb SSD', 'manufacturer' => 'Dell Inc.', 'missing_critical_updates' => 0, 'missing_other_updates' => 0],
            ['id' => '11111111-0000-4000-8000-00000000a003', 'type' => 'Endpoint', 'status' => 'Connected', 'last_seen' => '2026-11-23_22-12-00', 'name' => 'hv01', 'address' => '192.168.10.30', 'OS' => 'Debian GNU/Linux 12', 'platform' => 'Linux_64', 'agent_version' => '5.216.631.1', 'user' => 'root', 'MAC' => '11:22:33:44:66:02', 'serial' => '0', 'reboot_required' => 'No', 'manufacturer' => 'Supermicro'],
            ['id' => '11111111-0000-4000-8000-00000000a004', 'type' => 'Endpoint', 'status' => 'Pending Uninstall', 'last_seen' => '2026-10-01_00-00-00', 'name' => 'RETIRED-PC', 'address' => '192.168.10.99', 'OS' => 'Windows 10', 'platform' => 'Windows_64', 'serial' => 'OLD1'],
        ];
        // Pages of two, whatever was asked for, and a next_page that names another host - which must not be followed
        $from = intval($_GET['from'] ?? 0);
        $items = array_slice($all, $from, 2);
        $next = $from + 2 < count($all) ? 'https://evil.example/api/3.0/endpoints/managed/' . ORG_ONE . '?fields=*&from=' . ($from + 2) . '&limit=2' : '';
        if (($_GET['fields'] ?? '') !== '*') {
            $items = array_map(function ($e) {
                return array_intersect_key($e, array_flip(['id', 'type', 'status', 'last_seen', 'name', 'address', 'OS', 'platform']));
            }, $items);
        }
        $json(['type' => 'ResultPage', 'items' => $items, 'total_items' => (string) count($all), 'limit' => '2', 'next_page' => $next, 'prev_page' => '']);
    }
    if ($rest === 'endpoints/managed/' . ORG_TWO) {
        // Asked too often, once
        if (empty($state['a1_limited_once'])) {
            $state['a1_limited_once'] = true;
            $save();
            $json(['type' => 'Error', 'user_message' => 'Too many requests', 'retry_after' => 1], 429);
        }
        $json(['type' => 'ResultPage', 'items' => [], 'total_items' => '0', 'limit' => '50', 'next_page' => '']);
    }
    $json(['type' => 'Error', 'user_message' => 'Not found'], 404);
}

// ---------------------------------------------------------------- MSP360
// The Managed Backup API: companies as its documentation shows them (Id, Name); computers as
// MSP360's console names their fields, wrapped with a total - its documentation gives no shape.
const CO_A = 'c0000000-0000-4000-8000-000000000001';
const CO_B = 'c0000000-0000-4000-8000-000000000002';
$hid = function (int $n) {
    return sprintf('d0000000-0000-4000-8000-%012d', $n);
};
if ($path === '/api/Provider/Login') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($method !== 'POST' || !in_array($body['UserName'] ?? '', ['apilogin-test', 'apilogin-pascal'], true) || ($body['Password'] ?? '') !== 'apipass test 1') {
        $json(['Message' => 'The user name or password is incorrect.'], 400);
    }
    $state['mbs_tokens'] = ($state['mbs_tokens'] ?? 0) + 1;
    $save();
    // The second login gets the other shape the computers list might have - see below
    $json(['access_token' => ($body['UserName'] === 'apilogin-pascal' ? 'mbs-pascal-' : 'mbs-tok-') . $state['mbs_tokens'], 'token_type' => 'bearer', 'expires_in' => 1209599]);
}
if ($path === '/api/Companies' || preg_match('#^/api/Computers/(\d+)/(\d+)$#', $path, $m)) {
    if (!preg_match('/^Bearer mbs-(tok|pascal)-\d+$/', $auth)) {
        $json(['Message' => 'Authorization has been denied for this request.'], 401);
    }
    if ($path === '/api/Companies') {
        $json([
            ['Id' => CO_A, 'Name' => 'Acme Test Company', 'StorageLimit' => -1, 'LicenseSettings' => 0, 'Destinations' => []],
            ['Id' => CO_B, 'Name' => 'Beta Test Company', 'StorageLimit' => -1, 'LicenseSettings' => 0, 'Destinations' => []],
        ]);
    }
    $rmm = function (bool $online, string $version = '2.6.0.211') {
        return ['applicationId' => 'rmm', 'version' => $version, 'versionStatus' => 'Latest', 'online' => $online];
    };
    $backup = ['applicationId' => 'backup', 'version' => '7.9.1', 'online' => false];
    $all = [
        ['id' => 'x1', 'hid' => $hid(1), 'name' => 'FRONTDESK-PC', 'online' => true, 'hidden' => false, 'company' => ['id' => CO_A, 'name' => 'Acme Test Company'], 'os' => 'windows', 'ipAddress' => '203.0.113.10', 'lastLoggedUser' => 'ACME\\front.desk', 'apps' => [$rmm(true), $backup], 'authStatus' => 'authorized', 'lastActivity' => '2026-11-25T11:00:00.487+00:00'],
        ['id' => 'x2', 'hid' => $hid(2), 'name' => 'FILE01', 'online' => true, 'hidden' => false, 'company' => ['id' => CO_A, 'name' => 'Acme Test Company'], 'os' => 'windows', 'ipAddress' => '203.0.113.10', 'apps' => [$rmm(false), $backup], 'authStatus' => 'authorized', 'lastActivity' => '2026-11-20T01:00:00+00:00'],
        ['id' => 'x3', 'hid' => $hid(3), 'name' => 'BACKUP-ONLY', 'online' => true, 'hidden' => false, 'company' => ['id' => CO_A, 'name' => 'Acme Test Company'], 'os' => 'windows', 'apps' => [$backup], 'authStatus' => 'authorized'],
        ['id' => 'x4', 'hid' => $hid(4), 'name' => 'HIDDEN-PC', 'online' => false, 'hidden' => true, 'company' => ['id' => CO_A, 'name' => 'Acme Test Company'], 'os' => 'windows', 'apps' => [$rmm(false)], 'authStatus' => 'authorized'],
        ['id' => 'x5', 'hid' => $hid(5), 'name' => 'PENDING-PC', 'online' => true, 'hidden' => false, 'company' => ['id' => CO_A, 'name' => 'Acme Test Company'], 'os' => 'windows', 'apps' => [$rmm(true)], 'authStatus' => 'pending'],
        ['id' => 'x6', 'hid' => $hid(6), 'name' => 'LONE-MAC', 'online' => true, 'hidden' => false, 'os' => 'apple', 'apps' => [$rmm(true, '2.3.0.94')], 'authStatus' => 'authorized', 'lastActivity' => '2026-11-25T11:01:00+00:00'],
        ['id' => 'x7', 'hid' => $hid(7), 'name' => 'B-PC01', 'online' => true, 'hidden' => false, 'company' => ['id' => CO_B, 'name' => 'Beta Test Company'], 'os' => 'windows', 'apps' => [$rmm(true)], 'authStatus' => 'authorized', 'lastActivity' => '2026-11-25T11:02:00+00:00'],
    ];
    // The other shape: a bare list, names capitalised as the documented models are, company not nested, and no agents listed
    if (str_starts_with($auth, 'Bearer mbs-pascal-')) {
        $json(array_map(function ($c) {
            return ['Hid' => $c['hid'], 'Name' => $c['name'], 'Online' => $c['online'], 'Hidden' => $c['hidden'], 'CompanyId' => $c['company']['id'] ?? null, 'CompanyName' => $c['company']['name'] ?? null,
                'Os' => $c['os'], 'IpAddress' => $c['ipAddress'] ?? '', 'AuthStatus' => $c['authStatus'], 'LastActivity' => $c['lastActivity'] ?? null];
        }, array_slice($all, intval($m[1]), intval($m[2]))));
    }
    // Three at a time at most, whatever was asked for
    $json(['total' => count($all), 'computers' => array_slice($all, intval($m[1]), min(3, intval($m[2])))]);
}
if (preg_match('#^/api/v1/computers/stat/(host|summary)/latest$#', $path, $m)) {
    if (!in_array($auth, ['Bearer rmm-token-test', 'Bearer rmm-token-nohid'], true)) {
        $json(['type' => 'https://tools.ietf.org/html/rfc7235#section-3.1', 'title' => 'Unauthorized', 'status' => 401], 401);
    }
    $host = [
        ['hid' => $hid(1), 'computerName' => 'FRONTDESK-PC', 'osName' => 'Microsoft Windows 11 Pro', 'productType' => 'WinNT', 'x64' => true, 'version' => '10.0.26100', 'userName' => 'front.desk', 'userDomainName' => 'ACME', 'network' => ['ip4Address' => '192.168.10.110', 'macAddress' => '11:22:33:44:55:66'], 'lastBoot' => '2026-11-20T14:00:00Z', 'coresCount' => 10, 'logicalProcessorsCount' => 12, 'cpuName' => 'Intel(R) Core(TM) i5-1235U', 'updatesInfo' => ['updateServiceFound' => true, 'availableUpdatesCount' => 3], 'serialNumber' => 'PF3ABCDE', 'model' => '21C1S0QH00', 'manufactured' => 'LENOVO', 'computerState' => 1],
        ['hid' => $hid(2), 'computerName' => 'FILE01', 'osName' => 'Microsoft Windows Server 2022 Standard', 'productType' => 'Server', 'network' => ['ip4Address' => '192.168.10.20', 'macAddress' => '11-22-33-44-66-01'], 'lastBoot' => '0001-01-01T00:00:00', 'coresCount' => 24, 'logicalProcessorsCount' => 48, 'cpuName' => 'Intel(R) Xeon(R) Silver 4310', 'updatesInfo' => ['availableUpdatesCount' => 0], 'serialNumber' => 'SRV-SERIAL-9', 'model' => 'PowerEdge R450', 'manufactured' => 'Dell Inc.', 'computerState' => 0],
        ['hid' => $hid(6), 'computerName' => 'LONE-MAC', 'osName' => 'macOS 15.1', 'network' => ['ip4Address' => '10.0.0.5', 'macAddress' => ''], 'wakeOnLanInfos' => [['macAddress' => 'AA:11:22:33:44:06', 'wakeOnLanEnabled' => false]], 'serialNumber' => 'C02TEST0006', 'model' => 'MacBookAir10,1', 'manufactured' => 'Apple Inc.'],
        ['hid' => $hid(7), 'computerName' => 'B-PC01', 'osName' => 'Microsoft Windows 11 Pro', 'productType' => 'WinNT', 'network' => ['ip4Address' => '192.168.50.11', 'macAddress' => 'BB:11:22:33:44:07'], 'serialNumber' => 'BPC-SER-7', 'model' => 'OptiPlex 7010', 'manufactured' => 'Dell Inc.', 'updatesInfo' => ['availableUpdatesCount' => 0]],
    ];
    $summary = [
        ['hid' => $hid(1), 'computerName' => 'FRONTDESK-PC', 'pendingReboot' => true, 'computerState' => 'Online', 'alerts' => []],
        ['hid' => $hid(2), 'computerName' => 'FILE01', 'pendingReboot' => false, 'computerState' => 'Offline', 'alerts' => []],
        ['hid' => $hid(6), 'computerName' => 'LONE-MAC', 'pendingReboot' => null, 'computerState' => 'Online'],
        ['hid' => $hid(7), 'computerName' => 'B-PC01', 'pendingReboot' => false, 'computerState' => 'Online'],
    ];
    $items = $m[1] === 'host' ? $host : $summary;
    if ($auth === 'Bearer rmm-token-nohid') {
        $items = array_map(function ($item) {
            unset($item['hid']);
            return $item;
        }, $items);
    }
    // Pages count from 1 here, 0 is taken as 1, and a page is two at most
    $page_number = max(1, intval($_GET['pageNumber'] ?? 1));
    $size = min(2, max(1, intval($_GET['pageSize'] ?? 2)));
    $json(['items' => array_slice($items, ($page_number - 1) * $size, $size), 'total' => count($items), 'pageNumber' => $page_number, 'pageSize' => $size]);
}

$json(['error' => 'not mocked', 'path' => $path], 404);
