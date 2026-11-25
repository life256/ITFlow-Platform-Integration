<?php

/*
 * End-to-end test of the bolt-on vendor kinds against a throwaway ITFlow install and the mock
 * APIs (mock.php on 127.0.0.1:8765). Run: php e2e.php /path/to/itflow
 * It goes through ITFlow's own code paths: rmmProviderCheck/Write, rmmTest, rmmSync.
 */

$root = rtrim($argv[1] ?? '', '/');
// MSP360's addresses are fixed in its vendor file; point them at the mock before anything loads it
define('RMM_MSP360_BACKUP_API', 'http://127.0.0.1:8765');
define('RMM_MSP360_RMM_API', 'http://127.0.0.1:8765');
chdir("$root/cron");
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

// This test empties tables. It only runs against a database whose name says it is for testing.
if (!preg_match('/(_e2e|_test)$/', (string) ($database ?? ''))) {
    fwrite(STDERR, "Refusing to run: database '" . ($database ?? '') . "' isn't named *_e2e or *_test. This test empties the client, asset, network and RMM tables.\n");
    exit(2);
}

$fails = 0;
$check = function (string $what, $ok, string $detail = '') use (&$fails) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $fails++;
    }
};
$one = function (string $sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$rows = function (string $sql) use ($mysqli) {
    $out = [];
    $result = mysqli_query($mysqli, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = $row;
    }
    return $out;
};
$mock = 'http://127.0.0.1:8765';
@unlink(sys_get_temp_dir() . '/mock_state.json');
@unlink(sys_get_temp_dir() . '/mock_requests.log');

// A clean slate - this is a throwaway install
mysqli_query($mysqli, 'SET FOREIGN_KEY_CHECKS = 0');
foreach (['rmm_seen', 'rmm_networks', 'rmm_alerts', 'rmm_devices', 'rmm_clients', 'rmm_providers', 'asset_interfaces', 'assets', 'networks', 'clients'] as $table) {
    mysqli_query($mysqli, "TRUNCATE TABLE `$table`");
}
mysqli_query($mysqli, 'SET FOREIGN_KEY_CHECKS = 1');

echo "== Kinds\n";
$kinds = rmmProviderKinds();
$check('action1, msp360, omada, omada_key are offered', isset($kinds['action1'], $kinds['msp360'], $kinds['omada'], $kinds['omada_key']), implode(',', array_keys($kinds)));
$check('Omada is in the network family, Action1 and MSP360 in the RMM family', rmmFamily('omada') === 'network' && rmmFamily('omada_key') === 'network' && rmmFamily('action1') === 'rmm' && rmmFamily('msp360') === 'rmm');
$check('ITFlow\'s own kinds are untouched', isset($kinds['tactical'], $kinds['unifi'], $kinds['unifi_os'], $kinds['unifi_cloud']));

// Two clients, and assets that are already there
mysqli_query($mysqli, "INSERT INTO clients SET client_name = 'Acme Test Client', client_currency_code = 'USD'");
$client_id = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli, "INSERT INTO assets SET asset_type = 'Desktop', asset_name = 'FRONTDESK-PC', asset_make = '', asset_client_id = $client_id");
$frontdesk = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli, "INSERT INTO asset_interfaces SET interface_name = '1', interface_mac = '11:22:33:44:55:66', interface_primary = 1, interface_asset_id = $frontdesk");
mysqli_query($mysqli, "INSERT INTO assets SET asset_type = 'Server', asset_name = 'fileserver (old name)', asset_make = '', asset_serial = 'SRV-SERIAL-9', asset_client_id = $client_id");
$fileserver = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli, "INSERT INTO networks SET network_name = 'Office LAN', network = '192.168.10.0/24', network_gateway = '192.168.10.1', network_client_id = $client_id");
$office_lan = intval(mysqli_insert_id($mysqli));

$add = function (array $in) use ($check) {
    [$after, $errors] = rmmProviderCheck($in, null);
    $check("provider \"{$in['name']}\" passes ITFlow's own checks", !$errors, json_encode($errors));
    return $errors ? null : rmmProviderRow(rmmProviderWrite($after));
};
$link = function (array $provider, string $remote_name, int $client_id) use ($mysqli, $one) {
    $pid = intval($provider['rmm_provider_id']);
    mysqli_query($mysqli, "UPDATE rmm_clients SET rmm_client_client_id = $client_id WHERE rmm_client_provider_id = $pid AND rmm_client_name = '" . escapeSql($remote_name) . "'");
    return mysqli_affected_rows($mysqli);
};

// ------------------------------------------------------------------ Action1
echo "\n== Action1\n";
[, $errors] = rmmProviderCheck(['kind' => 'action1', 'family' => 'rmm', 'name' => 'A1 empty'], null);
$check('missing fields are refused with the kind\'s own words', isset($errors['api_url'], $errors['api_key'], $errors['api_secret']));
$bad = $add(['kind' => 'action1', 'family' => 'rmm', 'name' => 'A1 wrong secret', 'api_url' => $mock, 'api_key' => 'api-key-test@example.invalid', 'api_secret' => 'nope']);
$test = rmmTest($bad);
$check('wrong secret: Test says so and nothing else', !$test['ok'] && str_contains($test['text'], 'turned the client ID and secret away'), $test['text']);
mysqli_query($mysqli, "DELETE FROM rmm_providers WHERE rmm_provider_id = " . intval($bad['rmm_provider_id']));

$a1 = $add(['kind' => 'action1', 'family' => 'rmm', 'name' => 'Action1', 'api_url' => "$mock/api/3.0", 'api_key' => 'api-key-test@example.invalid', 'api_secret' => 'a1-secret-test']);
$check('secret is stored sealed, not as typed', str_starts_with($a1['rmm_provider_api_secret'], 'enc1:') && !str_contains($a1['rmm_provider_api_secret'], 'a1-secret-test'));
$check('address pasted with /api/3.0 is understood', rmmAction1Console($a1) === $mock && rmmDashboardUrl($a1) === "$mock/console/dashboard");
$test = rmmTest($a1);
$check('Test connects and counts', $test['ok'] && str_contains($test['text'], '2 clients') && str_contains($test['text'], '4 devices'), $test['text']);
$sync = rmmSync($a1);
$check('first sync lists organizations, brings nothing in unlinked', $sync['counts']['clients'] === 2 && $sync['counts']['devices'] === 0, $sync['note']);
$check('linking an organization to a client', $link($a1, 'Acme Test Org', $client_id) === 1);
$sync = rmmSync($a1);
$check('linked sync is clean', $sync['ok'], $sync['note']);
$check('3 endpoints in (the one being uninstalled left out)', $sync['counts']['devices'] === 3, json_encode($sync['counts']));
$check('2 matched to assets already here, 1 made', $sync['counts']['linked'] === 2 && $sync['counts']['created'] === 1, json_encode($sync['counts']));
$pid = intval($a1['rmm_provider_id']);
$dev = $rows("SELECT rmm_devices.*, asset_name, asset_type, asset_make, asset_serial, asset_os FROM rmm_devices LEFT JOIN assets ON asset_id = rmm_device_asset_id WHERE rmm_device_provider_id = $pid ORDER BY rmm_device_hostname");
$by = array_column($dev, null, 'rmm_device_hostname');
$check('FRONTDESK-PC matched by MAC address to the asset that was there', intval($by['FRONTDESK-PC']['rmm_device_asset_id'] ?? 0) === $frontdesk);
$check('FILE01 matched by serial number, and the asset kept its own name', intval($by['FILE01']['rmm_device_asset_id'] ?? 0) === $fileserver && $by['FILE01']['asset_name'] === 'fileserver (old name)');
$check('hv01 made as a Server, its placeholder serial dropped', ($by['hv01']['asset_type'] ?? '') === 'Server' && (string) ($by['hv01']['asset_serial'] ?? '') === '', json_encode([$by['hv01']['asset_type'] ?? null, $by['hv01']['asset_serial'] ?? null]));
$check('status, reboot and updates read', $by['FRONTDESK-PC']['rmm_device_status'] === 'online' && intval($by['FRONTDESK-PC']['rmm_device_needs_reboot']) === 1 && intval($by['FRONTDESK-PC']['rmm_device_patches_pending']) === 1 && $by['FILE01']['rmm_device_status'] === 'offline');
// 2026-11-23_22-10-11 UTC is 16:10:11 in America/Chicago
$check('last seen converted from UTC to ITFlow\'s timezone', $by['FRONTDESK-PC']['rmm_device_last_seen_at'] === '2026-11-23 16:10:11', (string) $by['FRONTDESK-PC']['rmm_device_last_seen_at']);
$check('signed-in user and asset fill-in', $by['FRONTDESK-PC']['rmm_device_logged_user'] === 'ACME\\front.desk' && $by['FRONTDESK-PC']['asset_make'] === 'LENOVO' && $by['FRONTDESK-PC']['asset_serial'] === 'PF3ABCDE', json_encode([$by['FRONTDESK-PC']['asset_make'], $by['FRONTDESK-PC']['asset_serial']]));
$log = (string) file_get_contents(sys_get_temp_dir() . '/mock_requests.log');
$check('second page was asked of the console\'s own address, with the token', substr_count($log, 'from=2') >= 1 && !str_contains($log, 'host=evil'), 'pages asked: ' . substr_count($log, 'from=2'));
$check('asked too often (429): waited and asked again', substr_count($log, 'endpoints/managed/00000000-0000-4000-8000-000000000002') >= 2);
$check('token asked for once per run, as a form', substr_count($log, 'POST /api/3.0/oauth2/token') <= 4, 'token requests: ' . substr_count($log, 'POST /api/3.0/oauth2/token'));
$sync = rmmSync($a1);
$check('a second sync changes nothing', $sync['ok'] && $sync['counts']['created'] === 0 && $sync['counts']['linked'] === 0 && $sync['counts']['updated'] === 0, json_encode($sync['counts']));

// ------------------------------------------------------------------ Omada (client ID)
echo "\n== Omada (client ID)\n";
$bad = $add(['kind' => 'omada', 'family' => 'network', 'name' => 'Omada wrong', 'api_url' => $mock, 'web_url' => 'abc123def4567890abc123def4567890', 'api_key' => 'cid-test', 'api_secret' => 'wrong']);
$test = rmmTest($bad);
$check('wrong secret: Test says so', !$test['ok'] && str_contains($test['text'], 'turned the client ID and secret away'), $test['text']);
mysqli_query($mysqli, "DELETE FROM rmm_providers WHERE rmm_provider_id = " . intval($bad['rmm_provider_id']));

$om = $add(['kind' => 'omada', 'family' => 'network', 'name' => 'Omada Main', 'api_url' => $mock, 'web_url' => 'abc123def4567890abc123def4567890', 'api_key' => 'cid-test', 'api_secret' => 'csecret-test']);
$check('Omada ID kept as text, secret sealed', $om['rmm_provider_web_url'] === 'abc123def4567890abc123def4567890' && str_starts_with($om['rmm_provider_api_secret'], 'enc1:'));
$check('Open in Omada goes to the controller, not to the Omada ID', rmmDashboardUrl($om) === $mock, rmmDashboardUrl($om));
$test = rmmTest($om);
$check('Test connects and counts (after the expired token was replaced)', $test['ok'] && str_contains($test['text'], '2 sites') && str_contains($test['text'], '5 devices'), $test['text']);
rmmSync($om);
$check('linking a site to a client', $link($om, 'Main Campus', $client_id) === 1);
$sync = rmmSync($om);
$check('linked sync is clean', $sync['ok'], $sync['note']);
$check('4 devices in (the pending one left out), all new assets', $sync['counts']['devices'] === 4 && $sync['counts']['created'] === 4, json_encode($sync['counts']));
$pid = intval($om['rmm_provider_id']);
$dev = $rows("SELECT rmm_devices.*, asset_name, asset_type, asset_make, asset_model, asset_serial, asset_os FROM rmm_devices LEFT JOIN assets ON asset_id = rmm_device_asset_id WHERE rmm_device_provider_id = $pid ORDER BY rmm_device_mac");
$by = array_column($dev, null, 'rmm_device_mac');
$check('gateway, switch, access point and other typed', $by['aabbcc000001']['asset_type'] === 'Firewall/Router' && $by['aabbcc000002']['asset_type'] === 'Switch' && $by['aabbcc000003']['asset_type'] === 'Access Point' && $by['aabbcc000005']['asset_type'] === 'Other', json_encode(array_column($dev, 'asset_type')));
$check('make, model, serial and firmware written', $by['aabbcc000002']['asset_make'] === 'Omada' && $by['aabbcc000002']['asset_model'] === 'SG2428P' && $by['aabbcc000002']['asset_serial'] === 'Y2440000002' && $by['aabbcc000002']['asset_os'] === '5.20.8', json_encode($by['aabbcc000002']));
$check('a device Omada names by its MAC gets a readable name', $by['aabbcc000003']['asset_name'] === 'EAP650 000003', $by['aabbcc000003']['asset_name']);
$check('markup in a name is stripped, placeholder serial dropped', $by['aabbcc000005']['asset_name'] === 'OLT-Rack 1' && (string) $by['aabbcc000005']['asset_serial'] === '', $by['aabbcc000005']['asset_name'] . '|' . $by['aabbcc000005']['asset_serial']);
$check('online and offline read (heartbeat missed is offline)', $by['aabbcc000001']['rmm_device_status'] === 'online' && $by['aabbcc000003']['rmm_device_status'] === 'offline');
$check('each asset has its MAC on an interface', intval($one("SELECT COUNT(*) FROM asset_interfaces JOIN rmm_devices ON rmm_device_asset_id = interface_asset_id WHERE rmm_device_provider_id = $pid AND REPLACE(LOWER(interface_mac), ':', '') = rmm_device_mac")) === 4);

$nets = $rows("SELECT network_id, network_name, network, network_gateway, network_vlan, network_dhcp_range, network_primary_dns, network_secondary_dns FROM networks WHERE network_client_id = $client_id ORDER BY network");
$bynet = array_column($nets, null, 'network');
$check('3 networks for the client: 1 matched by subnet, 2 made, the VLAN without a subnet left out', count($nets) === 3 && $sync['counts']['networks_linked'] === 1 && $sync['counts']['networks_created'] === 2, json_encode($sync['counts']));
$check('the network already there kept its name and gained its DHCP range', ($bynet['192.168.10.0/24']['network_name'] ?? '') === 'Office LAN' && intval($bynet['192.168.10.0/24']['network_id']) === $office_lan && $bynet['192.168.10.0/24']['network_dhcp_range'] === '192.168.10.100 - 192.168.10.199', json_encode($bynet['192.168.10.0/24'] ?? null));
$check('a dotted mask is read as a prefix; VLAN, gateway and manual DNS written', isset($bynet['192.168.20.0/24']) && $bynet['192.168.20.0/24']['network_vlan'] == 20 && $bynet['192.168.20.0/24']['network_gateway'] === '192.168.20.1' && $bynet['192.168.20.0/24']['network_primary_dns'] === '1.1.1.1' && $bynet['192.168.20.0/24']['network_secondary_dns'] === '9.9.9.9', json_encode($bynet['192.168.20.0/24'] ?? null));
$check('an interface with several VLANs comes in without one written down', isset($bynet['10.50.0.0/22']) && (string) $bynet['10.50.0.0/22']['network_vlan'] === '', json_encode($bynet['10.50.0.0/22'] ?? null));

$seen = $rows("SELECT rmm_seen.*, asset_name FROM rmm_seen JOIN assets ON asset_id = rmm_seen_asset_id WHERE rmm_seen_provider_id = $pid ORDER BY rmm_seen_mac");
$byseen = array_column($seen, null, 'asset_name');
$check('FRONTDESK-PC is seen on the switch, port 7', isset($byseen['FRONTDESK-PC']) && $byseen['FRONTDESK-PC']['rmm_seen_via_mac'] === 'aabbcc000002' && $byseen['FRONTDESK-PC']['rmm_seen_port'] === '7' && intval($byseen['FRONTDESK-PC']['rmm_seen_wired']) === 1, json_encode($byseen['FRONTDESK-PC'] ?? null));
$check('the switch is seen on the gateway, the access point on the switch', ($byseen['SW-Core']['rmm_seen_via_mac'] ?? '') === 'aabbcc000001' && ($byseen['SW-Core']['rmm_seen_port'] ?? '') === '2' && ($byseen['EAP650 000003']['rmm_seen_via_mac'] ?? '') === 'aabbcc000002', json_encode(array_keys($byseen)));
$check('clients that are not assets, or not active, are not kept', count($seen) === 3, 'kept: ' . count($seen));
$log = (string) file_get_contents(sys_get_temp_dir() . '/mock_requests.log');
$check('expired token (-44112) was replaced once and the request repeated', str_contains($log, 'AccessToken=AT-test-2'));

$sync = rmmSync($om);
$check('a second sync changes nothing', $sync['ok'] && $sync['counts']['created'] === 0 && $sync['counts']['updated'] === 0 && $sync['counts']['networks_created'] === 0 && $sync['counts']['networks_updated'] === 0, json_encode($sync['counts']));
mysqli_query($mysqli, "UPDATE assets SET asset_name = 'Lobby AP' WHERE asset_id = " . intval($by['aabbcc000003']['rmm_device_asset_id']));
$state = json_decode((string) file_get_contents(sys_get_temp_dir() . '/mock_state.json'), true);
file_put_contents(sys_get_temp_dir() . '/mock_state.json', json_encode($state + ['omada_drop_ap' => true]));
$sync = rmmSync($om);
$check('a device Omada no longer lists is marked gone, its asset kept with the name given by hand', $sync['counts']['removed'] === 1 && $one("SELECT asset_name FROM assets WHERE asset_id = " . intval($by['aabbcc000003']['rmm_device_asset_id'])) === 'Lobby AP' && $one("SELECT asset_archived_at FROM assets WHERE asset_id = " . intval($by['aabbcc000003']['rmm_device_asset_id'])) === null, json_encode($sync['counts']));

// ------------------------------------------------------------------ Omada (API key)
echo "\n== Omada (API key)\n";
$key = $add(['kind' => 'omada_key', 'family' => 'network', 'name' => 'Omada Key', 'api_url' => $mock, 'web_url' => 'abc123def4567890abc123def4567890', 'api_secret' => 'AK-test-key']);
$before = substr_count((string) file_get_contents(sys_get_temp_dir() . '/mock_requests.log'), 'POST /openapi/authorize/token');
$test = rmmTest($key);
$after = substr_count((string) file_get_contents(sys_get_temp_dir() . '/mock_requests.log'), 'POST /openapi/authorize/token');
$check('Test connects with the key alone - no token asked for', $test['ok'] && $before === $after, $test['text']);
$wrong = $add(['kind' => 'omada_key', 'family' => 'network', 'name' => 'Omada Key wrong ID', 'api_url' => $mock, 'web_url' => 'ffffffffffffffffffffffffffffffff', 'api_secret' => 'AK-test-key']);
$test = rmmTest($wrong);
$check('wrong Omada ID: Test repeats what Omada said', !$test['ok'] && str_contains($test['text'], 'Controller ID not exist'), $test['text']);

// ------------------------------------------------------------------ MSP360 RMM
echo "\n== MSP360 RMM\n";
mysqli_query($mysqli, "INSERT INTO clients SET client_name = 'Beta Test Client', client_currency_code = 'USD'");
$beta_id = intval(mysqli_insert_id($mysqli));
[, $errors] = rmmProviderCheck(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'M empty'], null);
$check('login and password are needed, the RMM API token is not', isset($errors['web_url'], $errors['api_key']) && !isset($errors['api_secret']), json_encode(array_keys($errors)));
$bad = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 wrong', 'web_url' => 'apilogin-test', 'api_key' => 'nope nope']);
$test = rmmTest($bad);
$check('wrong password: Test says so', !$test['ok'] && str_contains($test['text'], 'turned the API login and password away'), $test['text']);
mysqli_query($mysqli, "DELETE FROM rmm_providers WHERE rmm_provider_id = " . intval($bad['rmm_provider_id']));

$ms = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360', 'web_url' => 'apilogin-test', 'api_key' => 'apipass test 1', 'api_secret' => 'rmm-token-test']);
$check('a password containing spaces is accepted', $ms !== null);
$check('login kept as text; password and token both sealed', $ms && $ms['rmm_provider_web_url'] === 'apilogin-test' && str_starts_with($ms['rmm_provider_api_key'], 'enc1:') && str_starts_with($ms['rmm_provider_api_secret'], 'enc1:'));
$check('Open in MSP360 goes to the console, not to the login', rmmDashboardUrl($ms) === 'https://console.msp360.com', rmmDashboardUrl($ms));
$test = rmmTest($ms);
$check('Test connects: 2 companies and "No company", 4 computers with the RMM agent', $test['ok'] && str_contains($test['text'], '3 clients') && str_contains($test['text'], '4 devices') && str_contains($test['text'], 'Hardware details come from the RMM API'), $test['text']);
rmmSync($ms);
$check('linking a company to a client', $link($ms, 'Acme Test Company', $client_id) === 1);
$sync = rmmSync($ms);
$check('linked sync is clean', $sync['ok'], $sync['note']);
$check('2 computers in - backup-only, hidden and not-yet-authorized ones left out', $sync['counts']['devices'] === 2, json_encode($sync['counts']));
$check('both matched to the assets already here, none made', $sync['counts']['linked'] === 2 && $sync['counts']['created'] === 0, json_encode($sync['counts']));
$pid = intval($ms['rmm_provider_id']);
$dev = $rows("SELECT rmm_devices.*, asset_name, asset_model, asset_make, asset_serial FROM rmm_devices LEFT JOIN assets ON asset_id = rmm_device_asset_id WHERE rmm_device_provider_id = $pid ORDER BY rmm_device_hostname");
$by = array_column($dev, null, 'rmm_device_hostname');
$check('FRONTDESK-PC and FILE01 are the same assets Action1 found', intval($by['FRONTDESK-PC']['rmm_device_asset_id'] ?? 0) === $frontdesk && intval($by['FILE01']['rmm_device_asset_id'] ?? 0) === $fileserver);
$check('a blank the other RMM left is filled in: the model', ($by['FRONTDESK-PC']['asset_model'] ?? '') === '21C1S0QH00' && ($by['FRONTDESK-PC']['asset_make'] ?? '') === 'LENOVO', json_encode([$by['FRONTDESK-PC']['asset_make'] ?? null, $by['FRONTDESK-PC']['asset_model'] ?? null]));
$check('online is the RMM agent\'s word, not the computer\'s', $by['FRONTDESK-PC']['rmm_device_status'] === 'online' && $by['FILE01']['rmm_device_status'] === 'offline');
$check('reboot waiting, updates available, user, agent version read', intval($by['FRONTDESK-PC']['rmm_device_needs_reboot']) === 1 && intval($by['FRONTDESK-PC']['rmm_device_patches_pending']) === 1 && intval($by['FILE01']['rmm_device_patches_pending']) === 0 && $by['FRONTDESK-PC']['rmm_device_logged_user'] === 'ACME\\front.desk' && $by['FRONTDESK-PC']['rmm_device_agent_version'] === '2.6.0.211', json_encode($by['FRONTDESK-PC']));
// 11:00:00 UTC is 05:00:00 in America/Chicago; a boot time of year 1 is no boot time
$check('times converted; a never-told boot time is left empty', $by['FRONTDESK-PC']['rmm_device_last_seen_at'] === '2026-11-25 05:00:00' && $by['FRONTDESK-PC']['rmm_device_boot_at'] === '2026-11-20 08:00:00' && $by['FILE01']['rmm_device_boot_at'] === null, json_encode([$by['FRONTDESK-PC']['rmm_device_last_seen_at'], $by['FRONTDESK-PC']['rmm_device_boot_at'], $by['FILE01']['rmm_device_boot_at']]));
$check('role read from the OS', $by['FILE01']['rmm_device_role'] === 'server' && $by['FRONTDESK-PC']['rmm_device_role'] === 'workstation');
$log = (string) file_get_contents(sys_get_temp_dir() . '/mock_requests.log');
$check('computers read in pages until its count was reached', str_contains($log, 'GET /api/Computers/0/100') && str_contains($log, 'GET /api/Computers/3/100') && str_contains($log, 'GET /api/Computers/6/100'));
$check('RMM pages read from 0 without keeping the repeated first page twice', str_contains($log, 'stat/host/latest?pageNumber=0') && str_contains($log, 'stat/host/latest?pageNumber=2'));
$check('the two tokens went to the right APIs', !preg_match('#/api/v1/computers[^\n]*auth=Bearer mbs-tok#', $log) && !preg_match('#/api/(Companies|Computers)[^\n]*auth=Bearer rmm-token#', $log));
$check('linking "No company" brings in the computer that has none', $link($ms, 'No company', $beta_id) === 1 && ($s2 = rmmSync($ms))['counts']['created'] === 1 && $one("SELECT asset_serial FROM assets WHERE asset_name = 'LONE-MAC'") === 'C02TEST0006', json_encode($s2['counts'] ?? null));
$check('its MAC came from the wake-on-LAN list when the network had none', $one("SELECT rmm_device_mac FROM rmm_devices WHERE rmm_device_hostname = 'LONE-MAC'") === 'aa1122334406');
$sync = rmmSync($ms);
$check('a second sync changes nothing', $sync['ok'] && $sync['counts']['created'] === 0 && $sync['counts']['linked'] === 0 && $sync['counts']['updated'] === 0, json_encode($sync['counts']));

$plain = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 no token', 'web_url' => 'apilogin-test', 'api_key' => 'apipass test 1']);
$test = rmmTest($plain);
$check('without an RMM API token it still connects, and says what is missing', $test['ok'] && str_contains($test['text'], 'No RMM API token is set'), $test['text']);
rmmSync($plain);
$link($plain, 'Beta Test Company', $beta_id);
$sync = rmmSync($plain);
$check('its computers come in without hardware details', $sync['ok'] && $sync['counts']['created'] === 1 && (string) $one("SELECT asset_serial FROM assets WHERE asset_name = 'B-PC01'") === '', $sync['note']);

$nohid = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 names', 'web_url' => 'apilogin-test', 'api_key' => 'apipass test 1', 'api_secret' => 'rmm-token-nohid']);
$test = rmmTest($nohid);
$check('an RMM API that names no HID is matched by computer name, and says so', $test['ok'] && str_contains($test['text'], 'matched by computer name'), $test['text']);
rmmSync($nohid);
$link($nohid, 'Beta Test Company', $beta_id);
$sync = rmmSync($nohid);
$check('matched by name: the asset gains its serial number and model', $sync['ok'] && $one("SELECT asset_serial FROM assets WHERE asset_name = 'B-PC01'") === 'BPC-SER-7' && $one("SELECT asset_model FROM assets WHERE asset_name = 'B-PC01'") === 'OptiPlex 7010' && intval($one("SELECT COUNT(*) FROM assets WHERE asset_name = 'B-PC01'")) === 1, $sync['note']);
$wrongtoken = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 bad token', 'web_url' => 'apilogin-test', 'api_key' => 'apipass test 1', 'api_secret' => 'not-a-token']);
$test = rmmTest($wrongtoken);
$check('a wrong RMM API token doesn\'t stop the companies coming in, and Test says why details are missing', $test['ok'] && str_contains($test['text'], 'The RMM API didn\'t answer'), $test['text']);

// The other shape /api/Computers might have: a bare list, capitalised names, no agents listed
$pascal = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 other shape', 'web_url' => 'apilogin-pascal', 'api_key' => 'apipass test 1', 'api_secret' => 'rmm-token-test']);
$test = rmmTest($pascal);
$check('other shape: read all the same, and the RMM API says which computers have its agent', $test['ok'] && str_contains($test['text'], '3 clients') && str_contains($test['text'], '4 devices'), $test['text']);
$pascal_bad = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 other shape bad token', 'web_url' => 'apilogin-pascal', 'api_key' => 'apipass test 1', 'api_secret' => 'not-a-token']);
$test = rmmTest($pascal_bad);
$check('other shape with the RMM API failing: refuses to guess which computers are managed', !$test['ok'] && str_contains($test['text'], 'doesn\'t say which computers have the RMM agent'), $test['text']);
$pascal_none = $add(['kind' => 'msp360', 'family' => 'rmm', 'name' => 'MSP360 other shape no token', 'web_url' => 'apilogin-pascal', 'api_key' => 'apipass test 1']);
$test = rmmTest($pascal_none);
$check('other shape with no token: every computer comes in, and Test says so', $test['ok'] && str_contains($test['text'], 'every computer comes in'), $test['text']);

echo "\n== Both on one asset\n";
$count = intval($one("SELECT COUNT(DISTINCT rmm_device_provider_id) FROM rmm_devices WHERE rmm_device_asset_id = $frontdesk"));
$check('FRONTDESK-PC is one asset: an Action1 device, an MSP360 device, and seen on the Omada switch', $count === 2 && intval($one("SELECT COUNT(*) FROM rmm_seen WHERE rmm_seen_asset_id = $frontdesk")) === 1 && intval($one("SELECT COUNT(*) FROM assets WHERE asset_name = 'FRONTDESK-PC'")) === 1);

echo "\n" . ($fails ? "$fails CHECK(S) FAILED" : 'ALL CHECKS PASSED') . "\n";
exit($fails ? 1 : 0);
