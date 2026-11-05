# Contributing

- `main` is the `3.x` line (PHP 8.5+); `php72` is the `1.x` line (PHP 7.2+, plain-string states, hand-ported).
  A change to the library lands on `main` first and is ported to `php72` where it applies (see the gaps listed in the
  `php72` README).
- Run before you push: `composer check` (PHPStan + PHPUnit) and `mago fmt --check && mago lint && mago analyze`.
- Every behaviour change needs a test, and a line in `CHANGELOG.md` under `Unreleased`.
- Public API (see the README's "Compatibility promise") only changes in a major version; behaviour that callers can
  observe (hook order, exception types, what `send()` does under concurrency) counts as API.
- Commits: conventional (`fix(machine): ...`), signed.
- Never add a `conflict` or `replace` on `nandordudas/state-machine`, and never rename the package: Packagist would
  skip a branch.
