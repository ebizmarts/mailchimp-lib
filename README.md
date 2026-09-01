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

An unanswered setting is read as permission rather than refusal, because an
installation upgrading from a version that predates the switch has never been
asked, and that is not the same as declining.

### How often

Background processes report on a schedule derived from a hash of the store
itself, so a busy installation does not report more often than a quiet one and
the whole population does not report at the same moment. That works out at
about **eight reports per installation per day**, measured across 5000 store
URLs. Web requests report each time, because there are few of them and their
timing is the signal being measured.

At most four reports leave any one process.

### What it costs

Reporting is bounded and never raises. It has a total budget of 400 ms inside a
web request and 1500 ms in a background process, with per-request timeouts of
250 ms and 500 ms. Anything it cannot do within that, it stops doing: a report
that would be too large is dropped, a host that cannot be reached is not
retried, and any error in the reporting path is swallowed rather than allowed
to reach the store.

Reports go to `https://apps.ebizmarts.com/mc4magento/v1/qos`.

## License

MIT. See [LICENSE](LICENSE).
