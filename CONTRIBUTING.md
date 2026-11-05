# Contributing

- `main` is the `3.x` line (PHP 8.5+). Keep the library small: prefer one way to do a thing over another option.
- Run before you push: `composer check` (PHPStan + PHPUnit) and `mago fmt --check && mago lint && mago analyze`.
- Every behaviour change needs a test, and a line in `CHANGELOG.md` under `Unreleased`.
- Public API (see the README's "Compatibility promise") only changes in a major version; behaviour that callers can
  observe (hook order, exception types, what `send()` does under concurrency) counts as API.
- Commits: conventional (`fix(machine): ...`), signed.
- Never add a `conflict` or `replace` on `nandordudas/state-machine`, and never rename the package.
- Releasing: merge a dated `## [X.Y.Z] - YYYY-MM-DD` entry in `CHANGELOG.md` (with a new empty `Unreleased` above it),
  wait for CI on `main`, then run the **Release** workflow (Actions → Release → Run workflow) with `X.Y.Z`. It tags
  `vX.Y.Z` and publishes the GitHub release with that entry as the notes.
