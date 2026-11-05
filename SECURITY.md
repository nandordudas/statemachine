# Security policy

## Supported versions

| Line | PHP | Status |
|---|---|---|
| `3.x` (`main`) | 8.5+ | security and bug fixes |
| `1.x` (`php72`) | 7.2+ | security fixes only |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting
(<https://github.com/nandordudas/statemachine/security/advisories/new>) and include a minimal reproduction.

Race conditions, a way to persist a transition the definition does not allow, and compensation (`abort`) hooks running
for a transition that never started count as security-relevant here. Expect an answer within a few days.
