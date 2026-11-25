# ITFlow-Platform-Integration
For 3rd party platform integration

## Vendor kinds for ITFlow: Omada, Action1 and MSP360 RMM (draft)

Draft of 2026-11-25, written against ITFlow 26.12.1 (master `2451183ff`, database 4.1.7).
Not part of ITFlow. Not yet run against a real Omada controller, Action1 account or MSP360 account.

## What is here

| File | What it is | Lines |
|---|---|---|
| `rmm_omada.php` | Omada as a Network Platform, two kinds: client ID (controller 5.12+) and API key (6.4+) | 463 |
| `rmm_action1.php` | Action1 as an RMM Provider | 351 |
| `rmm_msp360.php` | MSP360 RMM as an RMM Provider (reads the Managed Backup API and the RMM API) | 484 |
| `rmm_kinds.php` | Bolt-on only: registers the three files above with ITFlow | 51 |
| `itflow_vendor_bolt_on.php` | Bolt-on only: installs, checks, re-applies and removes | 296 |
| `upstream-form.diff` | The same three vendor files as an upstream change would carry them | 1,304 added |
| `test/mock.php`, `test/e2e.php` | Mock APIs and the end-to-end test (93 checks) | |
| `test/msp360_shape_check.php` | Shows the field names your MSP360 account's APIs return, never the values | |

The three vendor files are identical in both routes.

## Route A: upstream (what a pull request would contain)

- 3 new files: `functions/rmm_omada.php`, `functions/rmm_action1.php`, `functions/rmm_msp360.php`
- 2 files edited, 3 lines each: `functions.php` (the `require_once` lines) and `functions/rmm.php` (the lines in `rmmProviderKinds()`)
- No database change, no new page, no new cron job, no new library.

ITFlow's README says community pull requests are paused (since 2026-10-29). Ask on the forum first.

## Route B: bolt-on (until upstream has it)

Use a test copy of ITFlow first. On the server, as the user that owns the ITFlow files, with this folder outside the web root:

```
php itflow_vendor_bolt_on.php status /var/www/your-itflow
php itflow_vendor_bolt_on.php apply  /var/www/your-itflow
php itflow_vendor_bolt_on.php remove /var/www/your-itflow
```

`apply` copies four files into `functions/custom/` (new, untracked) and adds one marked block to `rmmProviderKinds()` in `functions/rmm.php`: four lines of code between two comment lines. That block is the only change to a file ITFlow tracks.

An ITFlow update runs `git reset --hard`, which removes the block and leaves `functions/custom/` alone. So after every update the bolt-on is off until `apply` is run again.

`apply` refuses, changing nothing, when:

- ITFlow has gained its own version of a vendor (it steps aside for that one and keeps the others);
- a function the vendor files call is gone from `functions/rmm.php`, or `rmmHttp()` takes different arguments;
- the place the block goes can't be found exactly once;
- a file fails PHP's syntax check, or ITFlow doesn't load with the vendor files in a separate test process.

If ITFlow doesn't load after the block is added, the block is taken out again.

Maintenance > Update will show `functions/rmm.php` as a local change while the bolt-on is on. That is expected.

## Setting up a provider

**Action1** (Admin > Integrations > RMM Providers > Add): console address for your region, client ID, client secret. Make the credentials in Action1 under Configuration > Users & API Credentials with a view-only role.

**MSP360 RMM** (same place): API login and API password from Settings > General > API in MSP360, and optionally an RMM API token from Settings > General > RMM API tokens. Without the token, computers come in with no make, model or serial number. Only computers with the RMM agent are brought in; hidden and not-yet-authorized ones are left out.

**Omada** (Admin > Network Platforms > Add): interface access address, Omada ID, then either client ID and secret (Client mode application) or an API key (6.4+, View permission). All of these are shown under View on the application or key at Global View > Settings > Platform Integration > Open API. Add one platform per controller. Fusion gateways have no Open API.

## Check MSP360 against your real account first

MSP360 does not publish what its computers list returns, so that part of `rmm_msp360.php` is written from the field names MSP360's console uses. Before trusting it, run on your own machine:

```
php test/msp360_shape_check.php
```

It asks for the three credentials without showing them, sends them only to MSP360's addresses, and prints field names and types only. The things to look for: that each computer has `hid`, a company, and a list of agents; that RMM items have `hid`; and whether RMM pages count from 0 or 1.

## Choices made in the code that you may want changed

- An Omada device's make is written as `Omada`. To change it, put `define('RMM_OMADA_MAKE', 'TP-Link');` in `config.php`.
- Action1 Linux endpoints come in as Server, as the earlier sync did.
- Action1 timestamps are read as UTC.
- The Omada ID and the MSP360 API login are stored in the column other kinds use for a dashboard address. Nothing needs a database change this way; an upstream reviewer may prefer columns of their own.
- MSP360 computers that belong to no company appear under a "No company" entry so they can still be linked.
- If MSP360 doesn't say which computers have the RMM agent and the RMM API can't be asked, the sync refuses instead of guessing.

## Not built yet

- Tickets when an Omada device goes offline.
- Tickets from MSP360 alerts (the RMM API's summary has them, with ids).
- MSP360 storage details.
- Omada MSP View credentials (different API paths).
- Omada firmware-update-waiting flag (the device list doesn't say).
- A per-device "Open in" link for any of the three.

## Running the test

Needs a throwaway ITFlow install with its own database, named `*_e2e` or `*_test`. The test refuses any other database, because it empties the asset, client, network and RMM tables.

```
php -S 127.0.0.1:8765 test/mock.php &
php test/e2e.php /path/to/throwaway-itflow
```
