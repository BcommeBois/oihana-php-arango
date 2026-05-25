# Tips & pièges ArangoDB / `oihana/arango`

Recueil des règles d'or à respecter quand on touche aux models, projections,
ou contrôleurs documents/edges du framework `oihana/arango`. Toute violation
découverte (avec son incident associé) doit venir grossir cette page plutôt
que rester dans la mémoire d'une session.

## Table des matières

### Models

- [`Skin::INTERNAL` — projection serveur uniquement](#skininternal--projection-serveur-uniquement)

---

## Models

### `Skin::INTERNAL` — projection serveur uniquement

**Règle.** `Skin::INTERNAL` est une projection réservée au code PHP serveur.
Elle **ne doit jamais** être enregistrée dans la liste `Arango::SKINS` d'un
contrôleur, ni avoir une permission Casbin associée.

Concrètement : un appelant HTTP ne doit avoir aucun moyen — ni `?skin=internal`,
ni `?skin=full`, ni aucune autre projection publique — d'obtenir les champs
qu'elle expose.

### Pourquoi

`Skin::INTERNAL` ([`SkinTrait.php`](../../../api/vendor/oihana/php-system/src/oihana/controllers/enums/traits/SkinTrait.php))
est la projection qui rend visibles les champs sensibles d'un document — ceux
qu'on a besoin de lire côté serveur mais qui ne doivent jamais sortir par la
surface HTTP, y compris pour un superadmin. Exemples typiques :

- `tokensInvalidBefore` sur `User` (cutoff de révocation de toutes les sessions)
- Le SHA-256 du code de vérification d'un changement d'email pending
- Tout champ « secret métier » qu'on ne veut pas voir transiter par le réseau

La garantie de sécurité repose sur **une seule règle** : tant que `INTERNAL`
n'est pas listé dans la `Arango::SKINS` du contrôleur, le filtre
[`PrepareSkin::isValidSkin()`](../../../api/vendor/oihana/php-system/src/oihana/controllers/traits/prepare/PrepareSkin.php)
rejette `?skin=internal` et tombe sur la projection par défaut. Aucun appelant
HTTP ne peut donc forcer cette projection.

**Pas de permission Casbin non plus, by design.** Si on créait par exemple
`users:skin.internal`, un superadmin pourrait l'attribuer à un compte via
`POST /users/{id}/permissions/{permKey}` et casser l'invariant en une seule
requête. La projection `INTERNAL` n'a donc **aucun équivalent permission**.

### Côté serveur — comment l'utiliser

Les traits et middlewares serveur appellent le model directement, en
court-circuitant la couche HTTP :

```php
$user = $this->usersModel->get
([
    Arango::ID   => $userKey ,
    Arango::SKIN => Skin::INTERNAL ,
]) ;
```

Le framework de capabilities vit sur la couche contrôleur HTTP, **pas** sur
le model. Les appels directs au model ne sont donc pas restreints — ils
restent de confiance parce qu'ils proviennent du code PHP serveur et non
d'un input utilisateur.

### Où c'est utilisé aujourd'hui

| Endroit | Champ INTERNAL lu | Pourquoi |
|---|---|---|
| [`CheckJwtAuthentication`](../../../api/src/oihana/api/middlewares/CheckJwtAuthentication.php) | `tokensInvalidBefore` | Valider que le JWT n'a pas été révoqué par un force-logout admin |
| [`EmailChangeTrait`](../../../api/src/oihana/api/controllers/auth/traits/EmailChangeTrait.php) | hash du code email pending | Comparer le code confirmé par l'utilisateur au hash stocké |
| [`AuthTestUsersSessionsRevokeCommand`](../../../api/src/oihana/api/commands/tests/auth/AuthTestUsersSessionsRevokeCommand.php) | `tokensInvalidBefore` | Vérifie justement que `?skin=full` n'expose **pas** ce champ |

### Ajouter un nouveau champ INTERNAL

Trois questions à se poser dans l'ordre :

1. **Est-ce que ce champ doit être lisible par un humain via HTTP, même
   superadmin ?** Si oui, ce n'est pas un champ INTERNAL — c'est `FULL` ou un
   skin métier dédié.
2. **Est-ce que le code serveur peut s'en passer ?** Si oui, ne pas le
   stocker en base. Le meilleur secret est celui qu'on n'a pas.
3. **Si on doit le stocker et le relire côté serveur uniquement** : déclarer
   le champ dans le schéma vendor, l'exposer via `Skin::INTERNAL` dans le
   model, et **ne rien ajouter** dans `Arango::SKINS` côté contrôleur.

### Si un jour on doit vraiment l'exposer en HTTP

Cas d'usage hypothétique : page d'audit admin, outil de debug interne, etc.
La règle vendor (voir PHPDoc de [`SkinTrait::INTERNAL`](../../../api/vendor/oihana/php-system/src/oihana/controllers/enums/traits/SkinTrait.php))
est explicite — il faut **les trois couches**, pas une seule :

1. Une permission Casbin dédiée (par exemple `users:skin.internal`)
2. Une gate `Capability::PARAMS` sur le contrôleur qui rattache la permission
   à la valeur de skin
3. **Une whitelist en dur** qui empêche cette permission d'être attribuée à
   un compte via le CRUD `POST /users/{id}/permissions/{permKey}` ou
   `POST /roles/{id}/permissions/{permKey}`

Sans la couche 3, n'importe quel superadmin peut faire sauter l'invariant.

### Symptômes d'un oubli

- Un champ qu'on croyait privé apparaît dans une réponse `?skin=full` →
  vérifier le model (le champ est-il bien sous `Skin::INTERNAL` et **pas**
  sous `Skin::FULL` aussi ?) et la `Arango::SKINS` du contrôleur (est-ce
  que `INTERNAL` aurait été ajouté par erreur ?).
- Un test E2E `AuthTestUsersSessions*` casse sur l'assertion « `tokensInvalidBefore`
  doit être absent de `?skin=full` » → quelqu'un a inversé la projection.

### Incident de référence

Aucun à ce jour — l'invariant a été posé au moment du chantier admin
force-logout (Phase 2, 2026-05-14) et la commande
`auth:test:users:sessions:revoke` vérifie en continu qu'il tient.
