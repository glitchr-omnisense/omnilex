# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

### Added

- On omnisense/contracts, the kernel of the omni families, with usage policies. Every HTTP request
  a source makes goes through its usage policy (`Policy\SourceGuard`): the `policy.json` its
  package declares (`extra.omnisense.policy`; `HttpSource::usagePolicy()`, the kernel's
  `DeclaresUsagePolicyInterface`), tightened by the site's (`policy`, never looser), checked
  against what the site is (`usage`: a use the terms forbid fails at the first request,
  `UsageNotPermitted`), counted in the registry's limiter (in memory by default, a shared one as
  `Registry`'s new third argument). `Registry::guard()`, `policy()`, `policies()`;
  `HttpSource::guard()`, `sourceGuard()`, `operation()`; `Policy\PackagePolicy`; the options
  `policy`, `usage`, `max_wait`; the bundle's `policy`, `usage` (per source) and `limiter`. The
  token request to PISTE's authorisation server is not counted as a source's.
- `RateLimitedException::notSent()`, `wasSent()`, `$limit`, and a fourth and fifth optional
  constructor argument (the limit, the message).
- Every source declares its policy, read at the service's pages on 2026-11-10 (docs/policies.md):
  the data under the Licence Ouverte 2.0 (Légifrance, Judilibre, the administrative courts) or
  CC BY 4.0 (EUR-Lex), each credit as the terms word it; PISTE's quotas are shown only inside a
  PISTE application, and EUR-Lex's and the administrative courts' publish none: unknown, not
  invented.
- `Testing\BareTestCase` (the kernel's bare check, the family's core allowed) and
  `Tests/LoadAllTest`: every class of the core loads with omnisense/contracts and the HTTP client
  contracts alone; the Symfony bundle throws a `LogicException` naming its components when they
  are missing (they stay suggested).
- PHPStan at level max on the core and every source (`phpstan.neon`), each baseline what was there
  on 2026-11-10; the harness runs it (`phpstan`), installs omnisense/contracts from its checkout
  (`OMNISENSE_CONTRACTS`) or GitHub, and runs on Docker's default bridge.

### Changed

- Requires `omnisense/contracts: ^1.0`: stable versions, so `composer require omnisense/omnilex` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
- The package passes its CI alone, without the source packages: the bundle test asserts the sources registered for those installed (`Composer\InstalledVersions`), and PHPStan leaves the bundle's list of the source packages it knows, optional, to `class_exists()` (its baseline loses the `is_subclass_of()` entry, true only with every source installed). The README's plain-PHP block is a whole first call.
- **A limit reached waits or refuses.** A request over a limit of the source's policy (a site's
  tightening, today: no source publishes a figure) waits when the limit's window ends within
  `max_wait` seconds (10 by default), as the `throttle` sleep did; longer, it is not sent: a
  `RateLimitedException` (an `UnavailableException`, as the service's 429 was) with `wasSent()`
  false. The sources' `throttle` defaults stay.
- **A 429 holds the next requests** for its Retry-After (and a 503's Retry-After, a `RateLimit`
  field with nothing left): waited out within `max_wait`, refused beyond (`wasSent()` false),
  where they were sent before - a PISTE quota spent is no longer asked again at once. Its
  `retryAfter` reads a Retry-After given as a date too (null before).
- `Registry::get()` gives the factory the options configured plus `omnilex.name` and
  `omnilex.limiter`; `options()` is unchanged.
- `OmnilexException` extends omnisense/contracts' `ExceptionInterface`; `InvalidConfigException`
  its `InvalidConfigException` (still an `\InvalidArgumentException`); `Config` its `Config`
  (prefix `omnilex`, the same methods and messages); `Http\Redaction` its `Redaction`.

### Fixed

- No credential in what a source throws. An unreachable service or authorisation server quoted the
  HTTP client's message (the whole URL, a key in its query) and wrapped the client's own exception,
  whose trace kept the options - the bearer, `ClientCredentials`' client secret in the body - and
  every exception `HttpSource::call()` threw (refused, rate limited, down, an error) kept its query,
  body and headers in its trace's arguments (`zend.exception_ignore_args` off). The messages and the
  previous - now a copy of the client's exception, the same class and code (`Http\Redaction`) - have
  them masked, and none of them keeps its trace's arguments.
- `InvalidConfigException` extends `\InvalidArgumentException` (a `\LogicException`, as before): the
  sources throw it for an option they refuse (EUR-Lex's `language`, Judilibre's and the
  administrative courts' `recent`), where they threw a plain `\InvalidArgumentException` that
  `Registry::usable()`, `having()` and `reading()` did not skip; a `catch` of either keeps working.

### Added

- `Config::bool()`: a boolean option as an environment variable gives it, `"false"`, `"0"`, `"no"`
  and empty read as false (PHP's `(bool) "false"` is true). The factories read `sandbox`,
  `content` and `resolve_references` with it.
