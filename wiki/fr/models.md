# Modèles `Documents` et `Edges`

Le dossier [`src/oihana/arango/models/`](../../src/oihana/arango/models/) fournit la **couche haut-niveau** du framework : deux classes pivots (`Documents` et `Edges`) qui exposent un CRUD complet, le filtrage, la pagination, le tri, la recherche, les facettes, la projection par *skin*, les *edges* et les *joins* — toute la richesse d'une ressource REST en une seule définition.

Les classes sont composées par agrégation de **~50 traits à responsabilité unique**. Chaque modèle est instancié avec un conteneur PSR-11 et un tableau de configuration aux clés [`AQL::*`](enums.md#aql), qui paramètre la collection cible, la projection, les filtres, les *edges*, etc.

## Vue d'ensemble

| Classe | Rôle | Étend |
|---|---|---|
| `Documents` | Modèle générique pour une collection ArangoDB de **documents**. | — |
| `Edges` | Spécialisation pour une collection d'**arêtes** (vertex `_from` → vertex `_to`). | `Documents` |

Les deux classes consomment massivement les traits du sous-dossier `traits/`, qui couvrent chacun une responsabilité (`DocumentsGetTrait`, `FilterTrait`, `SortTrait`, ...). On peut consommer un trait isolé pour une *use case* spécifique sans hériter de tout `Documents` — voir [Composition fine](#composition-fine-réutiliser-un-trait-isolé).

## La classe `Documents`

### Instanciation

```php
use oihana\arango\models\Documents ;
use oihana\arango\enums\AQL ;
use oihana\arango\enums\Filter ;

$users = new Documents( $container ,
[
    AQL::COLLECTION   => 'users'                  ,
    AQL::DATABASE     => Databases::ARANGO        ,
    AQL::SCHEMA       => User::class              ,
    AQL::FIELDS       =>
    [
        Prop::_KEY    => Filter::DEFAULT  ,
        Prop::EMAIL   => Filter::DEFAULT  ,
        Prop::CREATED => Filter::DATETIME ,
        Prop::ACTIVE  => Filter::BOOL     ,
    ] ,
    AQL::FILTERS      =>
    [
        Prop::CREATED => FilterType::DATE   ,
        Prop::EMAIL   => FilterType::STRING ,
        Prop::ACTIVE  => FilterType::BOOL   ,
    ] ,
    AQL::SEARCHABLE   => [ Prop::EMAIL                          ] ,
    AQL::SORTABLE     => [ Prop::ID => Prop::_KEY , Prop::EMAIL ] ,
    AQL::SORT_DEFAULT => Prop::CREATED                            ,
]) ;
```

Le conteneur est utilisé pour résoudre les dépendances déclarées par identifiant de service : la `DATABASE` est résolue en une instance [`ArangoDB`](db/quickstart.md), le `SCHEMA` désigne une classe de mapping, etc.

### Catalogue complet des clés `AQL::*`

| Clé                 | Type            | Rôle                                                                                                   |
|---------------------|-----------------|--------------------------------------------------------------------------------------------------------|
| `AQL::COLLECTION`   | `string`        | Nom de la collection ArangoDB cible.                                                                   |
| `AQL::DATABASE`     | `string`        | Identifiant DI du service [`ArangoDB`](db/quickstart.md).                                              |
| `AQL::SCHEMA`       | `class-string`  | Classe schéma pour l'hydratation (`Thing` ou hydratable).                                              |
| `AQL::FIELDS`       | `array`         | Champs exposés et leur [`Filter::*`](enums.md#types) (cf. [Field](getting-started/glossary.md#field)). |
| `AQL::FILTERS`      | `array`         | Champs filtrables depuis l'URL et leur `FilterType::*` (cf. [filter.md](db/filter.md)).                |
| `AQL::SEARCHABLE`   | `array`         | Champs sur lesquels `?search=` opère.                                                                  |
| `AQL::SORTABLE`     | `array`         | Mapping clé URL → champ AQL pour `?sort=`.                                                             |
| `AQL::SORT_DEFAULT` | `string`        | Tri par défaut au format grammaire ([`sortKeys`](helpers.md)).                                         |
| `AQL::EDGES`        | `array`         | Définitions d'*edges* (cf. [edges-joins-projection.md](edges-joins-projection.md)).                    |
| `AQL::JOINS`        | `array`         | Définitions de *joins* (même page).                                                                    |
| `AQL::RESOLVE`      | `array`         | *Edges* internes non exposés (utilisés pour la cascade).                                               |
| `AQL::REQUIRES`     | `string\|array` | Permission requise pour exposer un *edge*/*join*.                                                      |
| `AQL::FACETS`       | `array`         | Définitions de facettes (`?facet=`).                                                                   |
| `AQL::FILLABLE`     | `array`         | Champs assignables en masse à l'insertion/mise à jour.                                                 |
| `AQL::ALTERS`       | `array`         | Transformations post-requête sur les documents renvoyés.                                               |
| `AQL::INDEXES`      | `array`         | Index à créer à la première instanciation (lazy).                                                      |
| `AQL::CONDITIONS`   | `array`         | Conditions AQL injectées côté serveur (cf. [filter-internal.md](db/filter-internal.md)).               |
| `AQL::BINDS`        | `array`         | *Bind variables* injectées côté serveur.                                                               |

### Méthodes principales

| Méthode | Retour | Description |
|---|---|---|
| `list( $init )` | `array` | Liste paginée, filtrée, triée. |
| `get( $init )` | `?object` | Document unique par clé. |
| `last( $init )` | `?object` | Dernier document selon le `SORT_DEFAULT`. |
| `count( $init )` | `int` | Nombre de documents matchant les filtres. |
| `exist( $init )` | `bool` | Existence d'un document. |
| `insert( $init )` | `?object` | Insertion d'un nouveau document. |
| `update( $init )` | `?object` | Mise à jour partielle. |
| `replace( $init )` | `?object` | Remplacement complet. |
| `upsert( $init )` | `?object` | Insert ou update selon présence. |
| `repsert( $init )` | `?object` | Insert ou replace selon présence. |
| `delete( $init )` | `null\|array\|object` | Suppression d'un document (cascade *edges* si déclarés). |
| `truncate()` | `void` | Vide la collection. |
| `stream( $init )` | `Generator` | Itération paresseuse (utile pour gros volumes). |
| `foundRows()` | `int` | *Full count* après une `list` paginée. |

Chaque méthode accepte un tableau `$init` aux clés `Arango::*` (différentes des `AQL::*` de la définition) qui surcharge le comportement par appel : `Arango::ID`, `Arango::LIMIT`, `Arango::OFFSET`, `Arango::SKIN`, `Arango::SORT`, `Arango::FILTER`, etc.

```php
$users->list([
    Arango::LIMIT  => 50              ,
    Arango::OFFSET => 0               ,
    Arango::SORT   => '-created,email' ,
]) ;

$users->get( [ Arango::ID => 'abc' , Arango::SKIN => Skin::FULL ] ) ;

$total = $users->count( [ Arango::FILTER => '{"key":"active","val":true}' ] ) ;
```

## La classe `Edges`

Étend `Documents` avec quatre spécificités :

1. **`AQL::FROM` et `AQL::TO`** déclarent les modèles des vertex de chaque côté de l'arête. Permet la validation et les requêtes typées.
2. **Traits dédiés** : `EdgesGetTrait`, `EdgesCountTrait`, `EdgesInsertTrait`, `EdgesDeleteTrait`, `EdgesPurgeTrait`, `EdgesExistTrait`, plus `EdgesFromTrait` et `EdgesToTrait` qui exposent la cascade automatique.
3. **Cascade par signal** : un `Documents::delete()` sur un vertex émet un signal `afterDelete` capturé par `EdgesFromTrait`/`EdgesToTrait`, qui purgent les arêtes pointant vers le vertex supprimé. Aucune ligne d'application à écrire — c'est la garantie d'intégrité référentielle.
4. **`purge()`** : suppression en bloc de toutes les arêtes d'une collection (utile pour les *resets*).

```php
use oihana\arango\models\Edges ;

$userHasRoles = new Edges( $container ,
[
    AQL::COLLECTION => 'user_has_roles'   ,
    AQL::DATABASE   => Databases::ARANGO  ,
    AQL::FROM       => Models::USERS      ,
    AQL::TO         => Models::ROLES      ,
]) ;
```

## Architecture des traits

Le cœur du framework. Les ~50 traits sont regroupés en quatre familles, chacune sous un sous-dossier de `traits/`.

### Traits CRUD `documents/` (14 traits)

Un trait par opération. `DocumentsMethodsTrait` est une *umbrella* qui agrège tout, consommée par la classe `Documents`.

| Trait | Méthode exposée |
|---|---|
| `DocumentsListTrait` | `list()` |
| `DocumentsGetTrait` | `get()` |
| `DocumentsLastTrait` | `last()` |
| `DocumentsCountTrait` | `count()` |
| `DocumentsExistTrait` | `exist()` |
| `DocumentsInsertTrait` | `insert()` |
| `DocumentsUpdateTrait` | `update()` |
| `DocumentsReplaceTrait` | `replace()` |
| `DocumentsUpsertTrait` | `upsert()` |
| `DocumentsRepsertTrait` | `repsert()` |
| `DocumentsDeleteTrait` | `delete()` (avec cascade *edges*) |
| `DocumentsTruncateTrait` | `truncate()` |
| `DocumentsStreamTrait` | `stream()` |
| `DocumentsMethodsTrait` | *Agrège les précédents* |

### Traits CRUD `edges/` (9 traits)

Ciblent les particularités d'une collection d'arêtes (vertex `_from`/`_to`).

| Trait | Méthode exposée |
|---|---|
| `EdgesGetTrait` | `get()` adapté aux arêtes |
| `EdgesCountTrait` | `count()` avec filtre sur `_from`/`_to` |
| `EdgesExistTrait` | `exist()` |
| `EdgesInsertTrait` | `insert()` avec validation de vertex |
| `EdgesDeleteTrait` | `delete()` |
| `EdgesPurgeTrait` | `purge()` (bulk delete) |
| `EdgesFromTrait` | Hook `afterDelete` du vertex source |
| `EdgesToTrait` | Hook `afterDelete` du vertex cible |
| `EdgesTrait` | *Umbrella* |

### Traits *query builders* `queries/` (6 traits)

Génèrent le texte AQL d'une opération de lecture. Indépendants du modèle haut-niveau : on peut les consommer dans un service ou un contrôleur custom pour produire de l'AQL sans passer par `Documents`.

| Trait | Produit |
|---|---|
| `ListQueryTrait` | Requête `FOR ... [FILTER ...] [SORT ...] [LIMIT ...] RETURN ...` |
| `GetQueryTrait` | Requête de récupération unique |
| `LastQueryTrait` | Variante avec tri inverse + `LIMIT 1` |
| `CountQueryTrait` | Variante avec `COLLECT WITH COUNT INTO ...` |
| `ExistQueryTrait` | Optimisée pour l'existence (`LIMIT 1 RETURN 1`) |
| `UpsertQueryTrait` | Requête `UPSERT` paramétrée |

### Traits composition AQL `aql/` (10 + 22 sous-traits)

Le grand bloc fonctionnel : chaque trait apporte une capacité (filtrage, tri, recherche, projection, *binds*, ...).

| Trait                  | Apport                                                                                                                                  |
|------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `FieldsTrait`          | Construction du `RETURN { ... }` en routant chaque champ vers son [*field builder*](db/helpers.md#field-builders--sous-dossier-fields). |
| `FilterTrait`          | Conversion de `?filter=` JSON en `FILTER ...` AQL avec *binds*.                                                                         |
| `SortTrait`            | Conversion de `?sort=` grammaire textuelle en `SORT ...`.                                                                               |
| `SearchTrait`          | Conversion de `?search=` en filtre `LIKE` ou `STARTS_WITH` selon le champ.                                                              |
| `LimitTrait`           | Pagination `LIMIT offset, count`.                                                                                                       |
| `BindTrait`            | Accumulation centralisée des *bind variables*.                                                                                          |
| `FacetTrait`           | Génération des facettes (agrégations sur champs).                                                                                       |
| `PrepareDocumentTrait` | Validation et normalisation du document avant insertion/mise à jour.                                                                    |
| `PositionTrait`        | Gestion des champs de position (réordonnancement).                                                                                      |
| `ActiveTrait`          | Helper pour filtrer sur le champ `active`.                                                                                              |

**Sous-traits `aql/filters/`** (8 traits) : routent les filtres par type — `HasFilterString`, `HasFilterNumber`, `HasFilterDate`, `HasFilterBoolean`, `HasFilterArray`, `HasFilterDocumentation`, `HasFilterConditions`, `HasHierarchicalFilter`.

**Sous-traits `aql/facets/`** (7 traits) et **`aql/fields/`** (7 traits) : spécialisations pour la richesse de facettes et de projection.

## Composition fine — réutiliser un trait isolé

L'intérêt de la composition fine : on peut hériter d'un trait sans consommer toute la machinerie `Documents`. Exemple — un service de *batch* qui a juste besoin de produire une requête `LIST` :

```php
use oihana\arango\models\traits\queries\ListQueryTrait ;

class UserStatsBatch
{
    use ListQueryTrait ;

    public function build( array $filters ) : string
    {
        return $this->buildListQuery
        ([
            AQL::COLLECTION => 'users'   ,
            AQL::CONDITIONS => $filters  ,
        ]) ;
    }
}
```

Aucune dépendance à `Documents`, à un conteneur DI, à un `ArangoDB`. Juste de l'AQL généré.

## Intégration DI

Convention typique : un fichier par modèle sous `api/definitions/@arango/`. Chaque fichier retourne un tableau de définitions DI.

```php
// api/definitions/@arango/models/users.php
use DI\Container ;
use oihana\arango\models\Documents ;

return
[
    Models::USERS => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION   => 'users'                  ,
        AQL::DATABASE     => Databases::ARANGO        ,
        AQL::SCHEMA       => User::class              ,
        AQL::FIELDS       => [ /* ... */ ]            ,
        AQL::FILTERS      => [ /* ... */ ]            ,
        AQL::SEARCHABLE   => [ /* ... */ ]            ,
        AQL::EDGES        => [ /* ... */ ]            ,
        AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,
    ]) ,
] ;
```

Du côté consommateur (contrôleur, commande, autre modèle), le modèle se résout par son identifiant :

```php
$users = $container->get( Models::USERS ) ;
$list  = $users->list( [ Arango::LIMIT => 50 ] ) ;
```

Le pattern recommandé est de garder les *definitions* **plates** (un fichier = un modèle = un service DI) — la composition se fait par référence dans `AQL::EDGES` et `AQL::JOINS`, jamais par héritage entre fichiers de définition.

## Cycle de vie et hooks

Les opérations CRUD passent par des hooks de cycle de vie consommables par sous-classement ou *traits* de contrôleur :

| Hook | Phase | Usage typique |
|---|---|---|
| `beforeModelCall( $request , array &$init )` | Avant chaque opération CRUD du contrôleur. | Injection de filtres, d'*authorizer*, validation transverse. |
| `afterModelCall( $request , array &$init , mixed &$result )` | Après chaque opération. | Enrichissement de la réponse, *logging*, *audit*. |
| `afterDelete` (*signal*) | Après une `delete()` de vertex. | Cascade des *edges* (`EdgesFromTrait`/`EdgesToTrait`). |

Les hooks `beforeModelCall`/`afterModelCall` viennent du trait `ModelCallTrait` côté contrôleur (cf. [Contrôleurs Slim](controllers/README.md)). Le signal `afterDelete` vient du *bus* [`oihana/php-signals`](getting-started/dependencies.md#oihanaphp-signals).

## Voir aussi

- [Projection des edges et joins](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, `Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`.
- [Filtres HTTP `?filter=`](db/filter.md) — syntaxe URL des filtres, transformations `alt`, opérateurs.
- [Filtrage interne](db/filter-internal.md) — `AQL::CONDITIONS` + `AQL::BINDS` pour les conditions serveur-only.
- [Contrôleurs Slim](controllers/README.md) — exposition HTTP du modèle.
- [Référence des enums](enums.md#aql) — `AQL`, `Filter`, `Skin`, `Traversal` consommés ici.
- [Quickstart `ArangoDB`](db/quickstart.md) — la couche bas niveau sous-jacente.
- [CRUD côté client](clients/documents.md) — la couche bas niveau sur laquelle ce modèle est bâti.
- [AQL côté client](clients/aql.md) — helper `aql()` et sémantique paresseuse du `Cursor` sous `prepare/execute`.
