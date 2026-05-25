# Payloads

## Qu'est-ce qu'un *payload* ?

Quand un client envoie une requête HTTP qui crée ou modifie une ressource — typiquement un `POST`, un `PATCH` ou un `PUT` — il transporte les nouvelles données dans le **corps** (*body*) de la requête, le plus souvent au format JSON. On appelle ce contenu le **payload**, littéralement « charge utile » : c'est la donnée applicative à proprement parler, à distinguer des *headers* (métadonnées de transport, authentification) et des paramètres d'URL (`?filter=...&limit=...`).

Exemple concret. Pour créer un rôle dans l'API, le client envoie :

```http
POST /roles HTTP/1.1
Content-Type: application/json
Authorization: Bearer <token>

{
    "name": "editor",
    "description": { "fr": "Éditeur", "en": "Editor" },
    "color": "#3498db",
    "level": 50
}
```

Le bloc JSON de fin est le *payload*. Pour le serveur, ce n'est **qu'un tableau associatif brut** : aucun champ n'est garanti présent, aucune valeur n'est typée, et un client malveillant ou simplement bogué peut envoyer n'importe quoi (`{ "system": true }`, un nombre dans `name`, une chaîne plate dans `description`...).

## Pourquoi une couche dédiée

Avant qu'un *payload* puisse être passé au [modèle `Documents`](../models.md) pour insertion ou mise à jour en base, il faut le **discipliner** :

1. **Whitelist** — n'accepter que les champs explicitement autorisés pour la collection cible. Tout le reste est silencieusement ignoré (sécurité contre l'injection de champ : un client ne doit pas pouvoir écrire dans `_key`, `system`, `internalNotes`...).
2. **Typage** — convertir une valeur HTTP (toujours chaîne ou tableau JSON) en type PHP / AQL natif : `int`, `bool`, `array`, ou un objet `i18n` typé.
3. **Transformation** — appliquer des valeurs par défaut (`level: 0` si absent), des valeurs forcées (`system: false` quoi que dise le body), des fonctions de normalisation (lowercase, *trim*, génération de `_key`...).

Tant qu'on écrit ces trois choses en code impératif éparpillé dans chaque contrôleur, on a deux problèmes : c'est verbeux, et c'est facile d'oublier une étape sur un endpoint nouvellement ajouté. Le framework `oihana/php-arango` les regroupe en une **structure déclarative** unique — un tableau PHP — dans la définition DI du contrôleur. Toute la mécanique d'extraction, validation de forme, typage et transformation tourne automatiquement à partir de cette déclaration.

## De quoi parle cette page

Toute la mécanique vit dans [`PayloadsTrait`](../../../src/oihana/arango/controllers/traits/PayloadsTrait.php), consommé par `DocumentsController`, `EdgesController` et `PropertyController`. La configuration applicative se fait via la clé [`Arango::PAYLOAD`](../enums.md#aql) dans la définition DI du contrôleur.

Ce qu'on documente ici :

- Le **format** de la clé `Arango::PAYLOAD` (structure par méthode HTTP, options par champ).
- Le **catalogue des types** acceptés par les champs (`AQLType`).
- Le **cycle de vie** complet, du *body* brut au document prêt à insérer.
- Les **pièges** récurrents (`COMPRESS` pour PATCH, `VALUE` vs `DEFAULT`, validation i18n).

Pour la **validation des valeurs** au sens contraintes métier (regex, longueur min/max, *required*), voir [Rules](rules.md) — c'est une couche distincte qui s'applique **après** la préparation du payload.

## Vue d'ensemble du cycle

```
HTTP body
  → enforceI18nShape($init)                       (422 si un champ i18n est une string plate)
  → preparePayload($init, &$relations)            extraction + typage + alter
  → validator->validate($payload, $rules)         (cf. rules.md — 422 si invalide)
  → beforeModelCall($request, &$init)             hook utilisateur
  → model->insert/update($payload, $relations)    écriture AQL
  → afterModelCall($request, &$init, &$result)    hook utilisateur
  → response                                       sérialisation finale
```

Cette page couvre les **deux premières étapes** (`enforceI18nShape` et `preparePayload`). La validation par `rules` est documentée en [Rules](rules.md), les hooks et les *skins* dans [README](README.md) et [Skins](skins.md).

## Format de définition `Arango::PAYLOAD`

La clé `Arango::PAYLOAD` accepte un tableau **double-indexé** : par méthode HTTP, puis par nom de champ.

```php
Arango::PAYLOAD =>
[
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,     // option globale (cf. plus bas)

    HttpMethod::ALL  => [ /* champs partagés par toutes les méthodes */ ] ,
    HttpMethod::POST => [ /* champs ajoutés / surchargés en POST  */ ] ,
    HttpMethod::PATCH => [ /* champs ajoutés / surchargés en PATCH */ ] ,
    HttpMethod::PUT   => [ /* champs ajoutés / surchargés en PUT   */ ] ,
] ,
```

**Règle de fusion** : pour une requête `POST`, le framework concatène `ALL` et `POST` via `array_merge` — les clés définies dans `POST` **remplacent** celles définies dans `ALL`. Idem pour `PATCH` et `PUT`.

### Forme courte vs forme étendue

Pour un champ qui se contente d'un type, on écrit le type AQL directement :

```php
HttpMethod::ALL => [
    Prop::NAME  => AQLType::STRING ,
    Prop::EMAIL => AQLType::STRING ,
] ,
```

Pour un champ qui a besoin d'options supplémentaires (`DEFAULT`, `VALUE`, `ALTER`, `PAYLOAD`, ...), on passe à la forme tableau :

```php
HttpMethod::POST => [
    Prop::LEVEL  => [ Arango::TYPE => AQLType::INT , Arango::DEFAULT => 0 ] ,
    Prop::SYSTEM => [ Arango::VALUE => false ] ,    // valeur forcée — body ignoré
] ,
```

## Catalogue `AQLType`

L'enum [`AQLType`](../../../src/oihana/arango/controllers/enums/AQLType.php) déclare les types reconnus par `preparePayload`. Le type contrôle deux choses : l'extraction (quel `getParam*()` HTTP appeler) et le typage côté payload de sortie.

| `AQLType::*` | Sémantique | Extracteur HTTP utilisé |
|---|---|---|
| `STRING` | Chaîne de caractères | `getParamString` |
| `INT` | Entier | `getParamInt` |
| `INT_WITH_RANGE` | Entier borné par `MIN_RANGE` / `MAX_RANGE` | `getParamInt` + clamp |
| `FLOAT` | Réel | `getParamFloat` |
| `FLOAT_WITH_RANGE` | Réel borné | `getParamFloat` + clamp |
| `BOOL` | Booléen | `getParamBool` |
| `NULL` | `null` forcé | — |
| `DATE` | ISO 8601 | `getParamString` + validation date |
| `ARRAY` | Tableau plat | `getParamArray` |
| `OBJECT` | Objet libre (associatif) | `getParamArray` |
| `I18N` | Objet i18n typé (`{ fr: ... , en: ... }`) | `getParamArray` + `filterLanguages` |
| `PAYLOAD` | Sous-payload imbriqué (récursif) | `generatePayload` |
| `EDGE` | Référence à un vertex — stocké dans `$relations`, pas dans le payload | extracteur d'`_id`/`_key` |
| `JOIN` | Référence simple à un autre document | similaire à `EDGE` |
| `JOINS` | Tableau de références | similaire à `EDGE` (multiple) |
| `DOCUMENT` | Document complet imbriqué | recursive |
| `MODEL` | Délégué à un modèle nommé | resolve depuis le conteneur DI |
| `PATH` | Chemin AQL `<collection>/<key>` | validation handle |

> Note : l'enum vit sous `oihana\arango\controllers\enums\AQLType` (pas dans `db/enums/`). Ne pas confondre avec `Arango::TYPE` (la **clé de configuration** dans la définition de champ) qui *contient* une valeur `AQLType`.

## Clés de définition de champ

Quand un champ a besoin de plus que son type, on passe en forme tableau avec ces clés :

| Clé | Type | Rôle |
|---|---|---|
| `Arango::TYPE` | `AQLType::*` | Type du champ. Obligatoire si on est en forme tableau et qu'il n'y a pas de `Arango::VALUE`. |
| `Arango::VALUE` | `mixed` | Valeur **fixe**, ignore complètement le body. Utile pour forcer un drapeau (`system: false` sur tout POST HTTP). |
| `Arango::DEFAULT` | `mixed` | Valeur de **fallback** si le champ est absent du body. Le body peut quand même fournir une valeur explicite. |
| `Arango::NAME` | `string` | Nom du paramètre HTTP si différent de la clé d'objet (rare). |
| `Arango::ALTER` | `callable\|array` | Transformation appliquée à la valeur extraite (lowercase, *trim*, *slugify*, custom...). |
| `Arango::PAYLOAD` | `array` | Sous-payload imbriqué pour les types `PAYLOAD`, `OBJECT`, `DOCUMENT`. |
| `Arango::SANITIZE` | `bool\|array` | Options de *sanitization* (suppression de caractères dangereux). |
| `FilterOption::MIN_RANGE` / `MAX_RANGE` | `int\|float` | Bornes pour `INT_WITH_RANGE` / `FLOAT_WITH_RANGE`. |

**`VALUE` vs `DEFAULT`** : `VALUE` ignore le body, `DEFAULT` n'agit que si le body ne fournit pas la clé. Distinction critique pour la sécurité — si on veut forcer un champ à `false` sur HTTP (par exemple un *flag* CLI-only), c'est `VALUE`, pas `DEFAULT`.

## Option globale `Arango::COMPRESS`

Au même niveau que `HttpMethod::ALL/POST/PATCH/PUT`, l'option `Arango::COMPRESS` contrôle si les valeurs `null` sont **strippées** du payload final :

```php
Arango::PAYLOAD =>
[
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,     // strip nulls sur PATCH
    // ...
] ,
```

**Pourquoi c'est important sur PATCH** : sans `COMPRESS`, tout champ déclaré mais absent du body finit comme `null` dans le payload. L'AQL `UPDATE doc WITH { name: null, email: null }` écrase ces champs en base. La compression résout le problème en éliminant ces clés du payload final.

**Valeurs acceptées** :

- `false` ou absent — pas de compression (défaut).
- `true` — compression sur toutes les méthodes.
- `[ HttpMethod::PATCH ]` ou `[ HttpMethod::PATCH , HttpMethod::PUT ]` — compression uniquement sur ces méthodes.

En pratique : **toujours `[ HttpMethod::PATCH ]`** pour les contrôleurs CRUD standards. Une mise à jour partielle ne doit jamais écraser un champ absent du body.

## Validation i18n pré-extraction

Les champs de type `AQLType::I18N` représentent un objet typé `{ fr: "...", en: "...", es: null, ... }`. Un client mal écrit peut envoyer une chaîne plate (`"description": "Bonjour"`) à la place — sans détection, cette chaîne se retrouve en base et casse toutes les requêtes de projection.

`enforceI18nShape()` est appelé **avant** `preparePayload()` et lève une 422 dès qu'un champ i18n n'a pas la forme objet attendue :

```php
// Réponse 422
{
    "errors":
    {
        "description": "must be an object with locale keys (fr, en, ...), not a flat string"
    }
}
```

C'est un *fail-fast* qui évite la corruption silencieuse de la base. Les contrôleurs `DocumentsControllerPost`/`Patch`/`Put` l'appellent automatiquement.

## Type `EDGE` — relations en sortie séparée

Quand un champ est typé `AQLType::EDGE`, la valeur extraite **n'atterrit pas dans le payload** mais dans un tableau séparé `$relations`, passé par référence :

```php
$payload = $this->preparePayload( $request , HttpMethod::POST , $init , $relations ) ;
// $payload   = [ name, email, color, ... ]                — champs du document
// $relations = [ 'roles' => [ 'roles/123', 'roles/456' ] ] — edges à créer après insertion
```

Le modèle `Documents::insert($payload, $relations)` insère d'abord le document, puis crée les *edges* en *cascade*. Idem pour `update()` (qui peut ajouter des *edges* sans modifier le document) et `delete()` (qui purge les *edges* via le signal `afterDelete`).

Types apparentés : `JOIN` (référence simple sans collection d'arêtes), `JOINS` (plusieurs références).

## Récursivité — type `PAYLOAD` imbriqué

Pour les sous-objets structurés (adresse, coordonnées, métadonnées), on imbrique récursivement :

```php
HttpMethod::ALL => [
    Prop::ADDRESS =>
    [
        Arango::TYPE    => AQLType::PAYLOAD ,
        Arango::PAYLOAD =>
        [
            HttpMethod::ALL =>
            [
                Prop::STREET      => AQLType::STRING ,
                Prop::CITY        => AQLType::STRING ,
                Prop::POSTAL_CODE => AQLType::STRING ,
                Prop::COUNTRY     => AQLType::STRING ,
            ] ,
        ] ,
    ] ,
] ,
```

`generatePayload()` est appelé récursivement sur chaque sous-bloc. Les `AQLType::PAYLOAD` peuvent s'imbriquer arbitrairement profond.

## Exemple complet — `roles.php`

Définition annotée de la collection `roles`, qui couvre la plupart des cas réels :

```php
Arango::PAYLOAD =>
[
    // Strip nulls on PATCH so the update stays truly partial.
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,

    HttpMethod::ALL =>
    [
        Prop::NAME        => AQLType::STRING ,
        Prop::DESCRIPTION => AQLType::I18N   ,   // validé par enforceI18nShape
        Prop::COLOR       => AQLType::STRING ,
    ] ,

    HttpMethod::POST =>
    [
        Prop::LEVEL => [ Arango::TYPE => AQLType::INT , Arango::DEFAULT => 0 ] ,

        // Flags CLI-only — HTTP creation force à false, peu importe le body.
        Prop::SYSTEM    => [ Arango::VALUE => false ] ,
        Prop::PROTECTED => [ Arango::VALUE => false ] ,
        Prop::DEFAULT   => [ Arango::VALUE => false ] ,
    ] ,

    HttpMethod::PATCH =>
    [
        // LEVEL est mutable en PATCH mais sans default (sinon UPDATE écraserait).
        Prop::LEVEL => AQLType::INT ,
    ] ,
] ,
```

Lecture :

- `POST /roles` accepte `name`, `description`, `color`, `level`. Les *flags* `system`, `protected`, `default` sont toujours `false`.
- `PATCH /roles/{id}` accepte `name`, `description`, `color`, `level`. Tout `null` est strippé. Les *flags* CLI-only ne sont **même pas dans la liste blanche** — un body `{ "system": true }` est silencieusement ignoré.
- Les types `STRING` et `I18N` sont validés par la couche [Rules](rules.md) pour la longueur, la regex, etc.

## Méthodes publiques de `PayloadsTrait`

Pour les cas avancés (extension, hook custom, tests), les méthodes publiques exposées :

| Méthode | Rôle |
|---|---|
| `initializePayload( array $init ) : static` | Stocke la définition `Arango::PAYLOAD` dans `$this->payload`. Appelée au constructeur du contrôleur. |
| `preparePayload( ?Request , ?string $method , array $init , array &$relations ) : array` | Construit le payload depuis le body HTTP selon la définition. |
| `enforceI18nShape( ?Request , ?Response , ?string $method , array $init ) : ?Response` | Pré-validation : retourne une 422 si un champ i18n a une forme plate. |
| `validateI18nShape( ?Request , ?string $method , array $init ) : array` | Variante sans réponse : retourne `[]` ou `[ field => errorMsg ]`. Utile pour les tests. |
| `propertyPayload( Request , ?string $property , array &$relations ) : mixed` | Variante pour `PropertyController::patch` — extrait une seule propriété. |
| `generatePayload( Request , ?array $definitions , array $args , array &$relations , bool $throwable ) : array` | Cœur récursif. Rarement appelé directement — `preparePayload` est le point d'entrée standard. |

## Voir aussi

- [Vue d'ensemble des contrôleurs](README.md) — signature des verbes, hooks, traits d'injection.
- [Rules](rules.md) — validation appliquée au payload après préparation.
- [Skins](skins.md) — projection de la réponse (sortie, parallèle à l'entrée payload).
- [Modèles `Documents` et `Edges`](../models.md) — consommateur du payload (`insert`, `update`, `replace`).
- [Filtrage interne](../filter-internal.md) — pattern proche pour les conditions serveur-only.
- [Référence des enums](../enums.md) — `AQLType`, `Arango::*`, `HttpMethod::*`.
