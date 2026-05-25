# ArangoDB / `oihana/arango` tips & gotchas

A collection of invariants to respect when working on the models,
projections, or document/edge controllers of the `oihana/arango` framework.
Every breach we discover (with the related incident) should be added here
instead of staying buried in a session memory.

## Table of contents

### Models

- [`Skin::INTERNAL` — server-only projection](#skininternal--server-only-projection)

---

## Models

### `Skin::INTERNAL` — server-only projection

**Rule.** `Skin::INTERNAL` is a projection reserved for server-side PHP code.
It **must never** be registered in a controller's `Arango::SKINS` list, and
it must never have a matching Casbin permission.

In concrete terms: an HTTP caller must have no way — neither `?skin=internal`,
nor `?skin=full`, nor any other public projection — to obtain the fields it
exposes.

### Why

`Skin::INTERNAL` ([`SkinTrait.php`](../../../api/vendor/oihana/php-system/src/oihana/controllers/enums/traits/SkinTrait.php))
is the projection that surfaces sensitive document fields — the ones we need
to read server-side but that must never leak through the HTTP surface, not
even for a superadmin. Typical examples:

- `tokensInvalidBefore` on `User` (cutoff for revoking all sessions)
- The SHA-256 of a pending email-change verification code
- Any "business secret" field we don't want travelling over the wire

The security guarantee rests on **one single rule**: as long as `INTERNAL` is
not listed in the controller's `Arango::SKINS`, the
[`PrepareSkin::isValidSkin()`](../../../api/vendor/oihana/php-system/src/oihana/controllers/traits/prepare/PrepareSkin.php)
filter rejects `?skin=internal` and falls back to the default projection. No
HTTP caller can therefore force this projection.

**No Casbin permission either, by design.** If we created e.g.
`users:skin.internal`, a superadmin could attach it to any account via
`POST /users/{id}/permissions/{permKey}` and break the invariant in a single
request. The `INTERNAL` projection therefore has **no permission counterpart**.

### Server-side — how to use it

Server traits and middlewares call the model directly, bypassing the HTTP
layer:

```php
$user = $this->usersModel->get
([
    Arango::ID   => $userKey ,
    Arango::SKIN => Skin::INTERNAL ,
]) ;
```

The capability framework lives on the HTTP controller layer, **not** on the
model. Direct model calls are therefore not gated — they remain trusted
because they originate from server PHP code, not from user input.

### Where it's used today

| Location | INTERNAL field read | Reason |
|---|---|---|
| [`CheckJwtAuthentication`](../../../api/src/oihana/api/middlewares/CheckJwtAuthentication.php) | `tokensInvalidBefore` | Validate that the JWT has not been revoked by an admin force-logout |
| [`EmailChangeTrait`](../../../api/src/oihana/api/controllers/auth/traits/EmailChangeTrait.php) | pending email code hash | Compare the user-supplied code with the stored hash |
| [`AuthTestUsersSessionsRevokeCommand`](../../../api/src/oihana/api/commands/tests/auth/AuthTestUsersSessionsRevokeCommand.php) | `tokensInvalidBefore` | Asserts that `?skin=full` does **not** expose this field |

### Adding a new INTERNAL field

Three questions to ask, in order:

1. **Does a human need to read this field over HTTP, even a superadmin?** If
   yes, this is not an INTERNAL field — it's `FULL` or a dedicated
   business skin.
2. **Can server code do without it?** If yes, don't store it. The best
   secret is the one you never persist.
3. **If it has to be stored and read server-side only**: declare the field
   in the vendor schema, expose it under `Skin::INTERNAL` in the model, and
   **do not add anything** to `Arango::SKINS` on the controller side.

### If we ever really need to expose it over HTTP

Hypothetical use cases: admin audit page, internal debug tool, etc. The
vendor rule (see [`SkinTrait::INTERNAL`](../../../api/vendor/oihana/php-system/src/oihana/controllers/enums/traits/SkinTrait.php)
PHPDoc) is explicit — **all three layers**, not just one:

1. A dedicated Casbin permission (e.g. `users:skin.internal`)
2. A `Capability::PARAMS` gate on the controller that ties the permission
   to the skin value
3. **A hardcoded whitelist** preventing that permission from being attached
   to any account via the CRUD `POST /users/{id}/permissions/{permKey}` or
   `POST /roles/{id}/permissions/{permKey}`

Without layer 3, any superadmin can break the invariant.

### Symptoms when violated

- A field we thought private appears in a `?skin=full` response → check the
  model (is the field really only under `Skin::INTERNAL` and **not** under
  `Skin::FULL` too?) and the controller's `Arango::SKINS` (was `INTERNAL`
  added by mistake?).
- An `AuthTestUsersSessions*` E2E test fails the assertion "`tokensInvalidBefore`
  must be absent from `?skin=full`" → someone flipped the projection.

### Reference incident

None so far — the invariant was laid down during the admin force-logout
work (Phase 2, 2026-05-14) and the `auth:test:users:sessions:revoke` command
continuously verifies it.
