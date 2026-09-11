# Change Log

## [3.0.50](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.50) (2026-09-11)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.49...3.0.50)

**Implemented enhancements:**

- Take an audience's counts off a response the caller already asked for [\#88](https://github.com/ebizmarts/mailchimp-lib/pull/88)

  `observeList()` mirrors `observeRoot()`: passive, on a response the caller
  already has. The extension's statistics job reads `lists/{id}` every twelve
  hours, so nothing new is requested -- no call, no quota, no latency. Measured
  on a running install: the same eight calls with the change as without it, and
  nineteen more bytes downloaded.

  `getLists()` gains `include_total_contacts`, appended last so every existing
  positional caller is unaffected and off by default. `total_contacts` is the
  billable figure and it is the one the payload cannot yield by arithmetic:
  `member_count + unsubscribe_count` omits non-subscribed contacts, which on an
  ecommerce account is most of the audience. Absent is left as null rather than
  derived, because a fabricated total that nearly matches is worse than one that
  is missing.

  `total_contacts` **includes cleaned**, measured on an audience with 631 of
  them. A consumer deriving the non-subscribed residual has to subtract cleaned
  explicitly, and even then the residual is an upper bound rather than an exact
  figure, since pending and archived land in it and archived are never billed.

  Only `lists/{id}` is observed, never a sub-resource: a member object carries
  its own `stats`, so a member fetch reached this and wrote nothing by luck
  rather than by design.

- Notice a send that did not arrive, and say which windows were sampled [\#87](https://github.com/ebizmarts/mailchimp-lib/pull/87)

  The reporting path discarded `curl_exec()`'s return and never read the status,
  so a refused connection, a timeout at the fence and a rejected envelope were
  all indistinguishable from delivery -- from inside the library, which is the
  only place they can be seen at all. A send that did not arrive now increments
  a counter carried on the **next** envelope as `sfail`.

  It is a running total for the emitting process and nothing resets it: take the
  maximum per process, never a sum. A send cannot report its own failure, so a
  process whose every send fails still reports nothing; what becomes visible is
  the partial case.

  The envelope also carries the sampler's two constants on the lane the sampler
  governs, as `sr` and `sw`. A consumer predicting which windows an installation
  should have reported in could previously only do so by hardcoding them, which
  meant changing either one here would have made every modelled installation
  look like it had stopped reporting.

## [3.0.49](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.49) (2026-09-10)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.48...3.0.49)

**Implemented enhancements:**

- Share contact wherever nobody has declined, including hosts with no switch [\#84](https://github.com/ebizmarts/mailchimp-lib/pull/84)

  Contact sharing is on by default, and only an answer that says no is a refusal.
  The reporting path previously withheld the contact pair from a host it could
  not ask -- no helper, or one too old to know the setting -- on the grounds that
  a merchant with no switch in their admin has no way to decline. Every other
  kind of silence already read as permitted; this makes the four consistent.

  A host too old to have the switch cannot acquire one from this library, only
  by upgrading the extension, so those installations share until they do. The
  admin field ships in extension release 103.4.82, so the remedy exists in a
  published version.

  `contact_unconfigured` is now emitted **alongside** the pair rather than
  instead of it, and its meaning changes with it: it used to mean "no switch, so
  nothing was sent" and now means "no switch, sent anyway". Consumers reading it
  as "no contact for this installation" need to know.

- Fail the build when a deprecated call appears unguarded [\#83](https://github.com/ebizmarts/mailchimp-lib/pull/83)

  A deprecated call reached a release twice, and both times it was found by a
  merchant rather than by us. `tools/no-deprecated-calls.php` now runs on every
  push and fails the build for a deprecated call that is not wrapped in a version
  guard. It tokenises rather than matching text, so a call inside a comment or a
  string does not trip it, and it has its own test that pins both directions --
  a check that cannot fail is worse than no check at all.

  Development files are kept out of the installed package, so this costs nothing
  to anyone consuming the library.

**Documentation:**

- Say how to read an absent `mc_store_id` [\#85](https://github.com/ebizmarts/mailchimp-lib/pull/85)

  A null in that field means "no store was named in any request path this process
  made", which is not the same fact as "this installation has no Mailchimp
  store". The ecommerce cron submits its per-store work as a single POST to
  `batches`, with the store id inside the request body, which the reporting path
  deliberately never reads.

  Nothing changes on the wire: families are already counted per call, so a null
  with batch activity separates cleanly from a null without it. The docblock now
  says so, including the one case where that reading goes wrong -- a lean report
  carries no family block at all, so "no batches" and "no families" are different
  facts.

## [3.0.48](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.48) (2026-09-04)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.47...3.0.48)

**Fixed bugs:**

- Latest version (3.0.47) is not fully compatible with PHP 8.5 [\#79](https://github.com/ebizmarts/mailchimp-lib/issues/79)

  `curl_close()` does nothing on PHP 8 and is deprecated in 8.5, so the reporting
  path raised a deprecation notice on every report. The call is now guarded by
  `PHP_VERSION_ID < 80000` rather than deleted: the library still declares
  `php >=5.2.0`, and on those versions the handle is a resource that this call is
  what frees.

- Contain a failed report so it cannot cost the other buckets theirs [\#81](https://github.com/ebizmarts/mailchimp-lib/pull/81)

  A failure while reporting one store view unwound out of the reporting loop
  entirely, so every store view after it went unreported without any sign that it
  had. Each send is now contained on its own, and the destructor that drives the
  reporting catches `Throwable` as well as `Exception`, since an error escaping a
  destructor during shutdown is fatal.

**Documentation:**

The README described the reporting cadence and time budgets as they stood
before the last change in 3.0.47, which raised the cron lane to one report an
hour and widened its budget to absorb a cold DNS lookup. The numbers now match
the code. The 3.0.47 entry below has been corrected for the same reason.

## [3.0.47](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.47) (2026-09-01)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.46...3.0.47)

**Implemented enhancements:**

- Report how the Mailchimp API behaves for an installation [\#70](https://github.com/ebizmarts/mailchimp-lib/pull/70)

  Counts calls, failures and response times per endpoint family and reports them
  to the Ebizmarts service, so a connection problem can be diagnosed without
  asking the merchant for logs.

  No customer data, no order data, no request or response bodies, and never the
  API key — the installation id is a truncated `sha256` of it. The account
  owner's name and address are sent only while
  `mailchimp/telemetry/share_contact` allows it, and not at all on a host with
  no such setting, where the merchant would have no way to decline.

  The sync cron reports on a schedule derived from a hash of the store itself,
  so a busy installation does not report more often than a quiet one and the
  whole population does not report at the same moment: about twenty-four times a
  day, roughly one an hour.
  Other background processes are sporadic rather than regular, so asking them to
  coincide with a scheduled window would make them nearly silent; they are
  sampled one in eight per process instead. Web requests always report, because
  there are few of them and their timing is the signal.

  Reporting is bounded by its own time budget and never raises: anything it
  cannot do, it stops doing. `MC_TELEMETRY=0` switches it off entirely, and
  `MC_TELEMETRY=force` skips the sampling for testing.

## [3.0.46](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.46) (2026-06-19)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.45...3.0.46)

**Fixed bugs:**

- Undefined array key [\#68](https://github.com/ebizmarts/mailchimp-lib/issues/68)

## [3.0.45](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.45) (2026-01-26)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.44...3.0.45)

**Implemented enhancements:**

- Add member events

## [3.0.44](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.44) (2025-03-14)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.43...3.0.44)

**Fixed bugs:**

- Issue with versions + packagist [\#66](https://github.com/ebizmarts/mailchimp-lib/issues/66)

## [3.0.43](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.43) (2024-12-09)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.42...3.0.43)

**Implemented enhancements:**

- Add timestamp and url [\#60](https://github.com/ebizmarts/mailchimp-lib/issues/60)

## [3.0.42](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.42) (2024-12-04)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.41...3.0.42)

**Implemented enhancements:**

- Add SaveNotification enhancement [\#58](https://github.com/ebizmarts/mailchimp-lib/issues/58)

## [3.0.41](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.41) (2024-12-03)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.40...3.0.41)

**Implemented enhancements:**

- Structure response of getFriendlyMessage [\#56](https://github.com/ebizmarts/mailchimp-lib/issues/56)

## [3.0.40](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.40) (2024-11-20)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.39...3.0.40)

**Implemented enhancements:**

- Show the complete url when an error happens [\#54](https://github.com/ebizmarts/mailchimp-lib/issues/54)

## [3.0.39](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.39) (2024-10-10)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.38...3.0.39)

**Implemented enhancements:**

- Add the instance value to the log [\#53](https://github.com/ebizmarts/mailchimp-lib/issues/53)

## [3.0.38](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.38) (2024-02-20)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.37...3.0.38)

**Implemented enhancements:**

- Add the possibility to add actions to ListMemberActivity [\#50](https://github.com/ebizmarts/mailchimp-lib/issues/50)

## [3.0.37](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.37) (2023-04-17)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.36...3.0.37)

**Implemented enhancements:**

- Add possibility to change the timeout [\#47](https://github.com/ebizmarts/mailchimp-lib/issues/47) 

## [3.0.36](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.36) (2022-04-18)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.35...3.0.36)

**Fixed bugs:**

- Add php 8.2 compatibility [\#44](https://github.com/ebizmarts/mailchimp-lib/issues/44)

## [3.0.35](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.35) (2022-04-18)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.34...3.0.35)

**Fixed bugs:**

- Add php 8.1 compatibility [\#40](https://github.com/ebizmarts/mailchimp-lib/issues/40)


## [3.0.28](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.28) (2018-08-29)

[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.27...3.0.28)

**Fixed bugs:**

- Critical vendor/ebizmarts/mailchimp-lib/src/Mailchimp.php:222 [\#20](https://github.com/ebizmarts/mailchimp-lib/issues/20)

## [3.0.27](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.27) (2018-08-08)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.26...3.0.27)

**Fixed bugs:**

- In some circumstances $result is not an array [\#16](https://github.com/ebizmarts/mailchimp-lib/issues/16)

## [3.0.26](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.26) (2018-08-02)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.25...3.0.26)

## [3.0.25](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.25) (2018-08-02)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.24...3.0.25)

**Implemented enhancements:**

- Export array 'errors' on call ended with errors [\#12](https://github.com/ebizmarts/mailchimp-lib/issues/12)

**Fixed bugs:**

- Warning: Missing argument 2 for Mailchimp\_Error::\_\_construct\(\) [\#15](https://github.com/ebizmarts/mailchimp-lib/issues/15)

## [3.0.24](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.24) (2018-08-01)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.23...3.0.24)

## [3.0.23](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.23) (2018-07-23)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.22...3.0.23)

**Fixed bugs:**

- Php 7.2 icompatiblity with count [\#9](https://github.com/ebizmarts/mailchimp-lib/issues/9)

## [3.0.22](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.22) (2018-07-11)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.21...3.0.22)

**Fixed bugs:**

- Notice: Undefined index: detail in Mailchimp.php on line  222 [\#8](https://github.com/ebizmarts/mailchimp-lib/issues/8)

## [3.0.21](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.21) (2018-05-14)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.20...3.0.21)

## [3.0.20](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.20) (2018-05-11)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.19...3.0.20)

## [3.0.19](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.19) (2018-05-08)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.18...3.0.19)

## [3.0.18](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.18) (2018-03-14)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.17...3.0.18)

## [3.0.17](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.17) (2018-02-28)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.16...3.0.17)

**Fixed bugs:**

- Error in API get Call [\#1](https://github.com/ebizmarts/mailchimp-lib/issues/1)

## [3.0.16](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.16) (2017-10-25)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.15...3.0.16)

## [3.0.15](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.15) (2017-10-24)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.14...3.0.15)

## [3.0.14](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.14) (2017-05-31)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.13...3.0.14)

## [3.0.13](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.13) (2017-05-25)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.12...3.0.13)

## [3.0.12](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.12) (2017-05-09)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.11...3.0.12)

## [3.0.11](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.11) (2017-05-09)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.10...3.0.11)

## [3.0.10](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.10) (2017-05-09)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.9...3.0.10)

## [3.0.9](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.9) (2017-04-21)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.8...3.0.9)

## [3.0.8](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.8) (2017-04-04)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.7...3.0.8)

## [3.0.7](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.7) (2017-03-16)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.6...3.0.7)

## [3.0.6](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.6) (2016-11-01)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.5...3.0.6)

## [3.0.5](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.5) (2016-10-28)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.4...3.0.5)

## [3.0.4](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.4) (2016-10-27)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.3...3.0.4)

## [3.0.3](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.3) (2016-10-05)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.2...3.0.3)

## [3.0.2](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.2) (2016-05-25)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.1...3.0.2)

## [3.0.1](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.1) (2016-05-02)
[Full Changelog](https://github.com/ebizmarts/mailchimp-lib/compare/3.0.0...3.0.1)

## [3.0.0](https://github.com/ebizmarts/mailchimp-lib/tree/3.0.0) (2016-04-29)


\* *This Change Log was automatically generated by [github_changelog_generator](https://github.com/skywinder/Github-Changelog-Generator)*