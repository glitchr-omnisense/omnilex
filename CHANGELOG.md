# Changelog

## [Unreleased]

### Fixed

- `InvalidConfigException` extends `\InvalidArgumentException` (a `\LogicException`, as before): the
  sources throw it for an option they refuse (EUR-Lex's `language`, Judilibre's and the
  administrative courts' `recent`), where they threw a plain `\InvalidArgumentException` that
  `Registry::usable()`, `having()` and `reading()` did not skip; a `catch` of either keeps working.

### Added

- `Config::bool()`: a boolean option as an environment variable gives it, `"false"`, `"0"`, `"no"`
  and empty read as false (PHP's `(bool) "false"` is true). The factories read `sandbox`,
  `content` and `resolve_references` with it.
