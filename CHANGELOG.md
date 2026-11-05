# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versions follow [semver](https://semver.org). This is the `1.x`
line (PHP 7.2+); see the `main` branch for `2.x`/`3.x`.

## [Unreleased] (1.1.0)

### Added
- `Exception\TransitionApplied` marker (applied, do not retry) implemented by `AfterHookFailedException`;
  `ConcurrentTransitionException::$retryable`.
- `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, `.gitattributes` (lean install archive), `extra.branch-alias`.

### Fixed
- A self-loop (`from === to`) no longer writes to the store (MySQL reports zero affected rows for it).
- `PropertyStore` reports an uninitialized typed property as `UnknownStateException` and no longer calls
  `setAccessible()` on PHP 8.1+.

## [1.0.0]
Initial release.
