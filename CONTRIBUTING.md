# Contributing

- This branch (`php72`) is the `1.x` line: PHP 7.2+, plain-string states, hand-ported from `main` (`3.x`, PHP 8.5+).
  It is **maintenance only**: security and bug fixes land here; new features land on `main` and are ported only on
  request (see the gaps in the README).
- Every line must parse on PHP 7.2: no typed properties, arrow functions, union types, `mixed`, named arguments, `match`,
  enums, `readonly`, constructor promotion or trailing commas in parameter lists (CI runs PHP 7.2).
- Run before you push: `composer check` and `mago fmt --check && mago lint && mago analyze`.
- Every behaviour change needs a test and a line in `CHANGELOG.md` under `Unreleased`.
- Commits: conventional, signed. Never add a `conflict` or `replace` on `nandordudas/state-machine`, and never rename
  the package: Packagist would skip a branch.
