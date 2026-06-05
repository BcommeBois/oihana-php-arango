# Contributing to oihana/php-arango

Thanks for your interest in improving this library! This guide covers the
essentials. Most topics link to the detailed wiki rather than duplicating it.

## Requirements

- **PHP ≥ 8.4** (the codebase uses modern syntax: enums, readonly, `match`, named args).
- **Composer**.
- **Xdebug** or **PCOV** — only needed to measure test coverage (see below).
- A reachable **ArangoDB** server — only needed for the live smoke tests, never for unit tests.

## Setup

```shell
git clone https://github.com/BcommeBois/oihana-php-arango.git
cd oihana-php-arango
composer install
```

For the live smoke tests (optional), copy the example config and fill in your
server credentials:

```shell
cp configs/config.example.toml configs/config.toml
```

`configs/config.toml` is gitignored — never commit credentials.

## Running the tests

```shell
composer test           # full PHPUnit suite (no ArangoDB server needed)
composer coverage       # suite + coverage report (text + Clover + HTML under build/coverage/)
composer coverage:md    # regenerate build/coverage/COVERAGE.md, a readable Markdown summary
```

The suite runs in **strict mode**: warnings, risky tests (no assertion), and
skipped tests all fail the run. A test that checks nothing protects nothing.

Coverage output lives under `build/coverage/` and is **gitignored** — it is a
snapshot that goes stale at the next commit, so we regenerate it on demand
rather than committing it.

The library also ships two **live smoke test commands** (`composer test:clients`,
`composer test:facade`) that exercise the full stack against a real `arangod` on
an ephemeral, throwaway database.

👉 **Full testing reference** — unit tiers, mocked transport, the
characterization-testing approach, and the live commands — lives in
[`wiki/en/testing.md`](wiki/en/testing.md) (French: [`wiki/fr/testing.md`](wiki/fr/testing.md)).

A short reminder of the testing philosophy:

- Prefer tests that **assert a precise result** over tests that merely walk
  through the code — 100% coverage is not zero bugs.
- When you discover a surprising behaviour in existing code, **freeze it in a
  test** first. Do not change a public API's behaviour without discussing it:
  other libraries may rely on it.

## Coding conventions

- **Match the surrounding code.** Naming, spacing, comment density and idioms
  should be indistinguishable from the file you are editing.
- **Member ordering inside a class/trait**: constructor → used traits
  (alphabetical) → constants → properties → public → protected → private
  methods. Each group is sorted alphabetically by name.
- Keep public surface documented with PHPDoc; regenerate the API docs with
  `composer doc` if you change it.

## Commits & pull requests

- Commit messages follow **Conventional Commits**, scoped to the touched area:

  ```
  feat(aql): unify array quantifiers under the `quant` filter key
  fix(filters): allow callable $type in FilterPath
  test(filters): cover FilterFunction::apply remaining match arms
  docs(testing): document the coverage workflow
  ```

- Keep each PR focused on a single concern.
- Before opening a PR: `composer test` must be green, and any behaviour change
  must come with tests.
- **Documentation is bilingual.** Any user-facing doc change must update both
  `wiki/fr/` and `wiki/en/`, kept in sync.

## Reporting issues

Open an issue at
<https://github.com/BcommeBois/oihana-php-arango/issues> with a minimal
reproduction (input, expected vs. actual). For AQL/filter bugs, the generated
AQL string and bind vars are the most useful thing to include.

## License

By contributing, you agree that your contributions are licensed under the
project's **MPL-2.0** license.
