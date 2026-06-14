# Dump / restore strategies

This page **ties the building blocks** of the `command:arangodb` command into
concrete, end-to-end recipes. For the details of each option, see the reference
page [arangodb.md](arangodb.md).

> 💡 **Composer shortcuts** — the examples below use the long form
> `php bin/console.php command:arangodb …`. The library ships equivalent
> **composer aliases**; pass the options **after `--`**:
>
> ```bash
> composer arango:dump    -- --profile staging-extract --dry-run
> composer arango:restore -- --last --yes
> composer arango:list                          # = dump --list
> ```
>
> `composer arango:dump` ≡ `php bin/console.php command:arangodb dump` (same for
> `arango:restore`, `arango:views`, `arango:doctor`).

## Key concepts

A bit of vocabulary used throughout below:

- **Archive** — a timestamped `.tar.gz` file produced by a `dump`, named
  `{date}-{database}[-partial][-{label}].tar.gz` (e.g. `2026-06-01T14:30:00-mydb.tar.gz`).
- **Bucket** — a group of archives **of the same nature** (same database, same
  targeting, same label). Rotation reasons **per bucket**: the complete backups of
  `mydb` form one bucket, the `mydb-partial-staging` extractions another, etc.
- **Pruning (rotation)** — the **deletion of old archives** according to a retention
  policy. "Pruning" = removing the archives that fall outside the policy (too many
  and/or too old). Nothing is ever deleted while no policy is configured: rotation
  is **opt-in**.
- **`auto = true`** — runs the pruning **automatically after each successful dump**.
  Without it, pruning happens only when you explicitly run `dump --prune`.
- **`--dry-run`** — prints the plan (what would be dumped / restored / pruned)
  **without doing anything**. Available on `dump`, `restore` and `dump --prune`.

## Where are the archives written?

Three possible sources, from lowest to highest priority:

- **By default**: the `[app].dumps` directory from `config.toml` (see
  [Configuration](arangodb.md#configuration)).
- **Per profile**: a profile can now carry its own `directory` key
  (`[arango.profiles.X] directory = "/backups/X"`) — any dump using that profile
  writes its archive there, with no need to repeat `--directory`. **Dump only**:
  the restore always writes locally (see
  [Restore guard-rails](arangodb.md#restore-guard-rails)).
- **Per run**: the `--directory /path` option overrides everything for a single
  command.

**Output-directory precedence** (highest wins):

| Source | Example | Priority |
|---|---|---|
| `--directory` (CLI) | `--directory /tmp/x` | highest |
| profile `directory` | `[arango.profiles.X] directory = "/backups/X"` | middle |
| `[app].dumps` (global) | `[app] dumps = "…/dumps"` | lowest |

```bash
# Routes the profile's dump to /backups/staging automatically:
php bin/console.php command:arangodb dump --profile staging-extract

# …unless --directory forces another folder for that run:
php bin/console.php command:arangodb dump --profile staging-extract --directory /tmp/oneshot
```

(The directory finally selected also acts as the rotation target for the run, and
for `dump --prune --profile X`.)

## Which block for which need?

| Need | Block | Detail |
|---|---|---|
| Persistent option defaults (threads, views, …) | `[arango.dump]` / `[arango.restore]` | [Configuration](arangodb.md#configuration) |
| Always extract the same subset | **Profile** (`--profile`) | [Profiles](arangodb.md#profiles) |
| A truly complete backup (+ `_analyzers`/`_graphs`) | `--complete` | [dump](arangodb.md#dump--backup) |
| Prevent a restore from clobbering auth | `protected` + confirmation | [Restore guard-rails](arangodb.md#restore-guard-rails) |
| Anonymize PII (GDPR) | **Masking** (PHP engine, any edition) | [Masking](arangodb.md#masking--anonymizing-the-dump) |
| Keep the dump directory from growing | **Rotation** (`[arango.dump.retention]`) | [Archive rotation](arangodb.md#archive-rotation) |
| Replay everything non-interactively (CI / bun) | `--yes` (+ `auto = true`) | [Restore guard-rails](arangodb.md#restore-guard-rails) |

---

## Recipe A — Recurring complete backup

The "nightly backup" case: a complete, encrypted backup whose old archives are
pruned on their own.

```toml
# config.toml
[arango.dump]
complete = true                 # every collection + _analyzers / _graphs

[arango.dump.retention]
keep = 30                       # keep the 30 most recent archives (per bucket)
auto = true                     # prune automatically after each successful dump
```

```bash
# Encrypted backup; pruning runs right after, automatically:
php bin/console.php command:arangodb dump --encrypt --passphrase "$BACKUP_KEY"

# Restore onto a fresh server — re-include the system collections:
php bin/console.php command:arangodb restore --last --include-system --yes \
    --encrypt --passphrase "$BACKUP_KEY"
```

What happens:

- `--complete` adds **only** `_analyzers` and `_graphs` (never `_users`).
- After today's archive is written, `auto = true` runs the pruning: it **keeps the
  30 most recent archives** of that bucket and **deletes the oldest**. The
  just-created archive is **never** deleted, and **at least 1** archive per bucket
  always remains.
- Without `[arango.dump.retention]`, **nothing is pruned** (opt-in).

> **`keep` is a number of archives, not a duration.** At one backup a day,
> `keep = 30` ≈ a month; at two a day, ≈ two weeks. To reason in terms of **age**,
> use `max_age` instead (an ISO 8601 duration):
>
> ```toml
> [arango.dump.retention]
> max_age = "P1M"   # delete archives older than one month (P30D, P6M, P1Y…)
> auto    = true
> ```
>
> You may set **both**: the rule is then **conservative** — an archive is deleted
> only if it is **both** beyond `keep` **and** older than `max_age` (i.e. it is kept
> if it is among the N most recent **or** younger than `max_age`). Handy for a double
> floor: "at least 30 archives, and at least a month of history".

---

## Recipe B — Test extraction (staging → local, anonymized)

The GDPR case: pull a subset of **staging** into the **local** database to test
against realistic data, **without PII** and **without touching authentication**.

```toml
# config.toml — the profile carries its SOURCE (staging) and its anonymization
[arango.profiles.staging-extract]
collections = ["thesaurus", "products", "clients"]
edges       = ["product_thesaurus"]
endpoint    = "tcp://staging.internal:8529"   # source: used for the dump ONLY
database    = "app_staging"
user        = "readonly"
password    = ""

[arango.profiles.staging-extract.masking]
"clients.email"        = "email"
"clients.phone"        = "phone"
"clients.address.city" = "random"

# guard-rail: forbid overwriting the local auth
[arango.restore]
protected = ["users", "sessions", "permissions"]
```

```bash
# 1) Anonymized dump FROM staging (the PHP engine masks — any edition):
php bin/console.php command:arangodb dump --profile staging-extract

# 2) Restore LOCALLY (the profile's connection is ignored on restore):
php bin/console.php command:arangodb restore --last
```

What happens:

- A profile's connection is the **source**: `dump` connects to it; `restore`
  **ignores** it and always writes to the local target (`[arango]`). A profile can
  therefore never push its data back onto the server it came from.
- Masking happens **at dump time**: the archive is already clean, safe to move
  around (GDPR-wise).
- `protected` blocks any overwrite of `users`/`sessions`/`permissions` on restore
  (unless `--force`). The restore also asks for confirmation and warns if the target
  is not local.

> Preview before running: add `--dry-run` to **both** the dump and the restore.

---

## Recipe C — Automated local refresh (CI / bun)

The **same extraction** as recipe B, but **non-interactive** and self-pruned — for a
script that refreshes the local database regularly (continuous integration,
`bun pull`, …).

```toml
# Reuses the staging-extract profile from recipe B.
# Keep only a few recent refreshes:
[arango.dump.retention]
keep = 3                        # keep the 3 latest extractions (per bucket)
auto = true                     # same pruning mechanism as recipe A
```

```bash
php bin/console.php command:arangodb dump    --profile staging-extract
php bin/console.php command:arangodb restore --last --yes
```

What happens:

- `auto = true` is **the exact same pruning** as recipe A — only the policy differs
  (here `keep = 3` instead of 30): it keeps the 3 latest extractions and deletes the
  older ones.
- `--yes` skips the restore confirmation. **Without `--yes`, a non-interactive run
  stops** (by safety — never a silent overwrite).
- A warning is printed when the restore target is **not local**.

---

## Safety at a glance

- **Opt-in rotation**: no deletion without a retention policy (nor `--prune`).
- **Floor ≥ 1 per bucket** + **never the current archive**.
- **Restore**: `protected` collections refused unless `--force`; confirmation
  required (or `--yes`); stops if non-interactive without `--yes`; non-local target
  warning; the source archive is consumed only on **success**.
- **`--dry-run` everywhere** (`dump`, `restore`, `dump --prune`): shows the plan,
  runs nothing.
