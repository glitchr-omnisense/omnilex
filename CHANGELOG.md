# Changelog

## [Unreleased]

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
