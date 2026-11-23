# mailchimp-lib

PHP client library for the Mailchimp API, used by the Mailchimp for Magento
extension.

## Installation

```
composer require ebizmarts/mailchimp-lib
```

Requires PHP 5.2 or newer and the cURL extension.

## Reporting

**From 3.0.47 this library reports how the Mailchimp API is behaving for the
installation it runs in.** It is described here rather than only in the
changelog, because this is the page you see before installing it.

### What it reports

How many API calls were made, how many failed, how long they took, and which
endpoint families were used — plus enough context to tell one installation
from another and to know what it is running:

| | |
|---|---|
| Always | schema and library version, timestamp, PHP version and SAPI, a per-report id, an installation id, the Mailchimp datacenter, and counts: calls, errors, total and slowest time, bytes sent and received |
| When the account is known | store URL, Mailchimp account id, store id and audience id, subscriber count, the endpoint families used, the last error seen, the extension version and user agent |
| Only with consent | the account owner's name and email address |

The installation id is `sha256` of the API key, truncated — the key itself is
never sent, and the id cannot be turned back into it.

### What it does not report

Request bodies, response bodies, customer data, order data, subscriber lists,
and the API key. Only counts, timings, and the identifiers listed above.

### Consent

The owner's name and address are sent only while the host application says so.
The library reads `mailchimp/telemetry/share_contact`; in the Magento extension
that is **Stores → Configuration → Mailchimp → Diagnostics → Include your
account contact details**, and setting it to No leaves those two fields out.
Everything else keeps working.

**If the host cannot be asked, nothing is sent.** This library can be installed
on a host that has no such setting — its version constraint is a floor, so an
older extension can acquire this version without acquiring the switch. On those
installs the merchant has no way to decline, so the two fields are simply
withheld rather than treated as consented, and the report says
`contact_unconfigured` rather than `contact_opt_out`. A refusal and an
installation that was never able to ask are different facts, and only the first
is a decision by the merchant.

Where the switch does exist, an unanswered one is read as permission rather than
refusal: an installation upgrading from a version that predates the field has
never been asked, and that is not the same as declining.

### How often

The extension's sync cron reports on a schedule derived from a hash of the store
itself, so a busy installation does not report more often than a quiet one and
the whole population does not report at the same moment. That process runs every
five minutes, and one window in twelve is selected, which works out at **about
twenty-four reports per installation per day** — roughly one an hour.

Other background processes — webhook runs, cleanups — are sporadic rather than
regular, so a schedule would ask them to coincide with a firing window on top of
being selected. They are sampled **one in eight per process** instead.

Web requests report each time, because there are few of them and their timing is
the signal being measured.

At most four reports leave any one process.

### What it costs

Reporting is bounded and never raises. It has a total budget of 400 ms inside a
web request and 8000 ms in a background process, with per-request timeouts of
250 ms and 2500 ms. The background budget is the more generous of the two
because nobody is waiting on it and a cold DNS lookup has to fit inside it,
while the web budget is a latency cap first: a web report on a slow path is
dropped on purpose. Anything it cannot do within that, it stops doing: a report
that would be too large is dropped, a host that cannot be reached is not
retried, and any error in the reporting path is swallowed rather than allowed
to reach the store.

Reports go to `https://apps.ebizmarts.com/mc4magento/v1/qos`.

Setting the environment variable `MC_TELEMETRY=0` switches these reports off.
It does not switch off the status check described below, only the fields of it
that describe the store. `MC_TELEMETRY=force` skips the sampling so every
eligible process reports, which exists so the feature can be verified without
leaving cron running for hours; it changes nothing else.

## Service status

**From 3.0.54 the QoS service can return a service status, and the library
honours it.** Reporting quality of service is one half; this is the other.

### What it does

While the service status pauses this installation, every API call fails before
it leaves the store, with a `Mailchimp_Error` that says why and who to contact
(mailchimp@help.ebizmarts.com), for example:

> Mailchimp sync is paused by the service. Your data and settings are kept.
> Email mailchimp@help.ebizmarts.com. Reference: lh12.

Nothing is deleted: not data, not the API key, not the configuration. A pause
lifts when the service sends a resume or when it expires, at most 24 hours
after it was issued; until then it is sent again on every reply. A pause that
was lifted is not applied again if a copy of it arrives later.

A status can carry a start date. Until then calls keep working, and the host
can read it with `Mailchimp::getServiceStatus()` to warn the merchant.
`Mailchimp_ServiceStatus::report()` turns what that returns into the four
`svc_*` fields below, for a host that reports them on its own channel.

### How it arrives

A status is a claim signed with Ed25519 and checked against two public keys
pinned in `Mailchimp_ServiceStatus`. A claim that is not signed with one of
them, is expired, or names another installation is ignored. It reaches the
library in two ways:

- in the reply to a report (see Reporting), and
- from a **status check**: at most once an hour per API key, and again as soon
  as the extension or library version changes, the library posts to
  `https://apps.ebizmarts.com/mc4magento/v1/lib/hold`.

The status check always sends:

- `install_id`, `account_id`, `dc`, the extension version and the library
  version;
- what the library has stored right now: `svc_state` (`held`, `warning` or
  `none`), `svc_ref`, `svc_code` and `svc_since`.

Only while reports are on (not with `MC_TELEMETRY=0`) it also sends:

- `domain`: the store's host and path, as in a report's `store_url`;
- `first_payment` and `member_since`: the Mailchimp account's dates, as UTC
  `YYYY-MM-DDTHH:MM:SSZ`, or null.

Nothing else: no counts, no names, no contact details, no key.

The status check runs whether or not reports are switched off, because an
installation that is paused makes no API calls and so sends no reports. It has
the same timeouts as a report (250 ms inside a web request, 2500 ms in a
background process) and never raises.

With `MC_TELEMETRY=0` the library does not read the account id from Mailchimp's
answers, so the check sends it only when a stored claim carries it.

### Where it is kept

In one small file per API key and per system user in the system temp
directory, readable only by that user. A file owned by another user is
ignored. If the file cannot be written, or `open_basedir` leaves the temp
directory out, the status still applies to the process that received it and
the hourly check is skipped.

### Requirements

`sodium_crypto_sign_verify_detached()`: the sodium extension, which PHP
includes from 7.2, or the `sodium_compat` polyfill. Without either, every
status is ignored and no status check is made.

## License

MIT. See [LICENSE](LICENSE).
