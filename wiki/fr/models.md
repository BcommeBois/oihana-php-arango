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
| `AQL::SKIN_FIELDS`  | `array`         | Projections alternatives par *skin* (table `skin => champs`, cf. [projection.md](projection.md#au-niveau-du-modèle--une-projection-par-skin-pour-la-racine)). |
| `AQL::FILTERS`      | `array`         | Champs filtrables depuis l'URL et leur `FilterType::*` (cf. [filter.md](db/filter.md)).                |
| `AQL::SEARCHABLE`   | `array`         | Champs sur lesquels `?search=` opère.                                                                  |
| `AQL::SORTABLE`     | `array`         | *Whitelist* de tri pour `?sort=` (voir [Tri](db/sort.md)).                                             |
| `AQL::SORT_DEFAULT` | `string`        | Tri par défaut au format grammaire ([`sortKeys`](helpers.md)).                                         |
| `AQL::SORT_TIEBREAK` | `string`       | Critère qui ferme l'ordre, ajouté en dernier à tout tri (voir [Tri](db/sort.md)).                      |
| `AQL::EDGES`        | `array`         | Définitions d'*edges* (cf. [edges-joins-projection.md](edges-joins-projection.md)).                    |
| `AQL::JOINS`        | `array`         | Définitions de *joins* (même page).                                                                    |
| `AQL::RESOLVE`      | `array`         | *Edges* internes non exposés (utilisés pour la cascade).                                               |
| `AQL::REQUIRES`     | `string\|array` | Permission requise pour exposer un *edge*/*join*.                                                      |
| `AQL::FACETS`       | `array`         | Définitions de facettes (`?facet=`).                                                                   |
| `AQL::FILLABLE`     | `array`         | Champs assignables en masse à l'insertion/mise à jour.                                                 |
| `AQL::ARRAYS`       | `array`         | Champs-tableaux embarqués et leur mode/compteur (cf. [arrays.md](db/arrays.md)).                       |
| `AQL::ALTERS`       | `array`         | Transformations post-requête sur les documents renvoyés.                                               |
| `AQL::INDEXES`      | `array`         | Index à créer à la première instanciation (lazy).                                                      |
| `AQL::CONDITIONS`   | `array`         | Conditions AQL injectées côté serveur (cf. [filter-internal.md](db/filter-internal.md)).               |
| `AQL::BINDS`        | `array`         | *Bind variables* injectées côté serveur.                                                               |

### Tri — `AQL::SORTABLE` / `AQL::SORT_DEFAULT` / `AQL::SORT_TIEBREAK`

Le tri (`?sort=` et `?near=`) a sa **page dédiée** : [Tri](db/sort.md). L'essentiel :

- **Fail-closed** — `AQL::SORTABLE` résout chaque clé de `?sort=` vers un champ AQL (3 notations mélangeables : raccourci indexé, alias, associatif). Une clé hors *whitelist* est **silencieusement ignorée** ; **`null` (pas de *whitelist*) = rien n'est triable** (jamais « tout »). `AQL::SORT_DEFAULT` traverse la même *whitelist* et doit donc nommer des clés déclarées.
- **Ordre total** — `AQL::SORT_TIEBREAK` ajoute en dernier le critère qui **ferme** l'ordre, à tout tri, y compris un `?sort=` explicite (sauf s'il y figure déjà). Sans lui, une pagination sur une clé non unique peut servir deux fois le même document et n'en servir jamais un autre. `null` par défaut : le mécanisme s'active modèle par modèle.
- **Permission** — une clé de tri peut être **soumise à un contrôle d'autorisation** (`Field::REQUIRES`), hérité du champ homonyme de la projection ou déclaré explicitement, pour qu'un champ caché à la lecture ne soit pas triable (pas d'oracle de tri).

Détail des trois notations, du contrôle d'autorisation et du tri par distance `?near=` dans [Tri](db/sort.md).

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

### Les options de curseur — `Arango::CURSOR_OPTIONS`

Quand un modèle lit, il ouvre un **curseur** sur le serveur : ArangoDB calcule le résultat et le
renvoie par lots. Quelques réglages de ce curseur appartiennent à l'appelant — la taille des lots,
la durée de vie du curseur entre deux lots, un plafond de mémoire ou de durée, le mode flux. Ils se
donnent dans **`Arango::CURSOR_OPTIONS`**, un tableau indexé par les constantes `CursorField`, et
`list()`, `get()`, `count()` et `stream()` les honorent de la même façon. Sans la clé, chaque
lecture envoie exactement ce qu'elle envoyait avant.

**Le décor.** Une collection `events` — un journal d'événements de deux millions de fiches. Chaque
nuit, une tâche la parcourt avec `stream()` et écrit chaque événement dans un fichier d'export. La
configuration de l'application fixe `batchSize = 80000`.

| option | constante | type | ce qu'elle règle |
|---|---|---|---|
| `batchSize` | `CursorField::BATCH_SIZE` | entier > 0 | le nombre de lignes par lot |
| `ttl` | `CursorField::TTL` | entier > 0 | les secondes que le curseur survit **entre deux lots** |
| `memoryLimit` | `CursorField::MEMORY_LIMIT` | entier > 0 | le plafond de mémoire de la requête, en octets |
| `maxRuntime` | `CursorField::MAX_RUNTIME` | nombre > 0 | le plafond de durée de la requête, en secondes |
| `stream` | `CursorField::STREAM` | booléen | le serveur calcule chaque lot quand on le demande, au lieu de tout calculer d'avance |

#### Borner ce que PHP garde en mémoire

**La situation.** La tâche de nuit grossit à chaque export. Avec `batchSize = 80000`, ses lots sont
énormes : `stream()` rend les lignes une à une, mais le lot dont elles viennent reste entier en
mémoire pendant qu'on le parcourt.

```php
foreach ( $events->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::BATCH_SIZE => 1000 ] ] ) as $event )
{
    $export->write( $event ) ;
}
```

PHP ne tient plus que mille fiches à la fois. Le défaut de la configuration n'est qu'un défaut :
l'option de l'appelant le remplace, et la bibliothèque ne remplit que ce que l'appelant n'a pas donné
(`batchSize`, `maxRuntime`).

#### Laisser le curseur survivre à une boucle lente

**La situation.** L'export n'écrit plus dans un fichier mais dans un service distant, et chaque
écriture prend du temps. Entre deux lots, le curseur attend sur le serveur ; s'il attend plus que sa
durée de vie, le serveur le supprime, et le lot suivant répond **« cursor not found »**.

```php
$options = [ CursorField::BATCH_SIZE => 1000 , CursorField::TTL => 600 ] ;

foreach ( $events->stream( [ Arango::CURSOR_OPTIONS => $options ] ) as $event )
{
    $remote->send( $event ) ; // dix minutes pour finir un lot de mille
}
```

Le `ttl` se règle sur **le temps passé à traiter un lot**, pas sur la durée totale de la lecture.

#### Faire calculer le serveur au fil de l'eau

**La situation.** On veut que le serveur ne prépare pas les deux millions de fiches d'un coup.

```php
$events->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true , CursorField::BATCH_SIZE => 1000 ] ] ) ;
```

🚨 **Le mode flux se mesure avant d'y croire.** Il n'a jamais été mesuré sur une requête lourde :
un curseur en flux garde sa requête vivante sur le serveur pendant tout le parcours, et une lecture
qui dépassait déjà un délai d'un seul tenant peut le dépasser de nouveau. Il ne devient jamais un
défaut.

Un parcours abandonné en route — un `break`, une exception — **ferme son curseur sur le serveur** :
la requête en flux ne reste pas vivante jusqu'à l'expiration de son `ttl`.

#### Plafonner une lecture

**La situation.** Un tableau de bord trie toute la collection. On préfère une erreur franche à une
requête qui s'emballe.

```php
$events->list
([
    Arango::SORT           => '-date' ,
    Arango::LIMIT          => 50 ,
    Arango::CURSOR_OPTIONS => [ CursorField::MEMORY_LIMIT => 256_000_000 , CursorField::MAX_RUNTIME => 5 ] ,
]) ;
```

Au-delà, la lecture lève une `HttpException` (voir la limite 3).

#### Ce qui est refusé — et pourquoi c'est nous qui refusons

Le serveur **ne dit rien** d'une option qu'il ne connaît pas, ni d'une valeur du mauvais type :
`{"batchsize": 1000}` (minuscule fautive), `batchSize: "1000"`, `ttl: -5` ou `stream: "yes"`
tournent comme si l'option était absente (mesuré sur ArangoDB 3.12). Une faute de frappe deviendrait
un réglage invisible ; la lecture lève donc une `ValidationException` :

| ce que l'appelant donne | pourquoi c'est refusé |
|---|---|
| une option hors des cinq | le serveur l'ignorerait sans un mot |
| une valeur du mauvais type, nulle ou ≤ 0 | idem |
| `query`, `bindVars` | elles **remplaceraient la requête que le modèle construit**, et toutes ses gardes avec |
| `fullCount` | c'est la lecture qui le décide, d'après sa `LIMIT` : il alimente le total d'une liste paginée |
| `profile` | il passe par [`Arango::PROFILE`](db/explain-and-profiling.md) |
| `count` | aucune lecture du modèle ne rend le compte du curseur |
| `stream: true` avec une `LIMIT` (`list()`, `stream()`) | voir la limite 2 |

#### Les limites

**Limite 1 — `batchSize` ne borne aucune mémoire dans `list()`.** `list()` rassemble tous les lots
avant de rendre son tableau : des lots plus petits font plus d'allers-retours, pas moins de mémoire.
Seul `stream()` en profite — et seulement si `batchSize` est plus petit que le résultat. *Ça te
concerne* si tu cherches à alléger une grosse lecture : passe par `stream()`.

**Limite 2 — Un curseur en flux ne compte pas au-delà de sa `LIMIT`.** Mesuré sur 3.12 : en mode flux,
`fullCount` reste `null`, même une fois le curseur vidé. Une liste paginée en flux annoncerait un
total de **0** sans erreur ; la combinaison est donc refusée. Sans `LIMIT`, `stream` passe — c'est
justement le parcours complet d'une collection.

**Limite 3 — Les plafonds se voient comme des erreurs du serveur.** Un `memoryLimit` dépassé lève une
`HttpException` de code 500, un `maxRuntime` dépassé une de code 410, un `ttl` écoulé une de code
**404** (« cursor not found ») — au lot suivant, au milieu du parcours. Aucun contrôleur ne lit
cette clé dans l'URL ; *ça te concerne* seulement si ton application la câble dans un contrôleur :
`HttpStatusCode::fromException()` traduit l'exception par son code, et le client recevrait un 404
pour un curseur expiré.

**Limite 4 — `stream()` refuse à la première itération.** C'est un générateur : rien ne s'exécute
avant qu'on le parcoure, ni la requête ni la vérification des options. Une option refusée se voit
au premier tour de boucle, comme toute autre erreur de la lecture.

**Limite 5 — Un plafond de mémoire ne mord que sur une requête qui alloue.** Une lecture simple
(`FOR … RETURN`, un comptage, une fiche par sa clé) consomme si peu que même un plafond d'un octet
passe le plus souvent. `memoryLimit` est un garde-fou pour les lectures qui trient, groupent ou
rassemblent.

### Les dates automatiques — et comment une écriture garde les siennes

Chaque écriture tamponne `modified` avec le moment de l'écriture, et une insertion tamponne aussi
`created`. **`Arango::TOUCH => false`** sur `upsert()` / `repsert()` désactive les deux tampons, sur
les deux branches de l'opération : le document soumis est rangé exactement tel quel.

C'est ce qu'exigent une réplication ou un import — une fiche copiée d'un autre système n'est pas
*modifiée* par sa copie, et ses dates appartiennent à la source. Sans le drapeau, chaque passe d'une
réplication marque tout son périmètre comme changé à la même seconde, et noie les fiches qui ont
réellement bougé.

```php
$mirror = [ ...$record , 'created' => '2024-05-12' , 'modified' => '2025-11-03' ] ;

$model->repsert
([
    Arango::FILTER  => [ 'identifier == @identifier' ] ,
    Arango::BINDS   => [ 'identifier' => $record[ 'identifier' ] ] ,
    Arango::INSERT  => $mirror ,
    Arango::REPLACE => $mirror ,
    Arango::TOUCH   => false ,
]) ;
```

La même clé gouverne déjà le rafraîchissement de `modified` par les verbes de tableau (défaut
`true`) et par `updateEdgeRelation()` (défaut `false`). `insert()` / `update()` / `replace()`
continuent de tamponner sans condition.

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
| `SearchTrait`          | Conversion de `?search=` en filtre `LIKE` multi-champs (insensible à la casse) sur les champs `AQL::SEARCHABLE`.                         |
| `LimitTrait`           | Pagination `LIMIT offset, count`.                                                                                                       |
| `BindTrait`            | Accumulation centralisée des *bind variables*.                                                                                          |
| `FacetTrait`           | Génération des facettes (agrégations sur champs).                                                                                       |
| `PrepareDocumentTrait` | Validation et normalisation du document avant insertion/mise à jour.                                                                    |
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
| `beforeModelCall( $request , array &$init )` | Avant chaque opération CRUD du contrôleur. | Injection de filtres, *authorizer* custom (le défaut est déjà posé par la base), validation transverse. |
| `afterModelCall( $request , array &$init , mixed &$result )` | Après chaque opération. | Enrichissement de la réponse, *logging*, *audit*. |
| `afterDelete` (*signal*) | Après une `delete()` de vertex. | Cascade des *edges* (`EdgesFromTrait`/`EdgesToTrait`). |

Les hooks `beforeModelCall`/`afterModelCall` viennent du trait `ModelCallTrait` côté contrôleur (cf. [Contrôleurs Slim](controllers/README.md)). Le signal `afterDelete` vient du *bus* [`oihana/php-signals`](getting-started/dependencies.md#oihanaphp-signals).

> Le tableau ci-dessus ne montre que `afterDelete` ; les modèles exposent en réalité **6 paires** de signaux (`before*`/`after*` pour insert, update, replace, upsert, delete, truncate) et une **cascade de suppression dirigée** (purge des arêtes + purge des documents liés `Purge::INBOUND`/`OUTBOUND`/`BOTH`). Tout est détaillé dans [Signaux & cascade](lifecycle-signals-cascade.md).

## Voir aussi

- [La projection des champs](projection.md) — `Field::SKINS` (honoré aussi sur les sous-champs imbriqués), `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, `Field::ALTERS`.
- [Projection des edges et joins](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, `Field::SCOPE`, `Filter::WRAP`, traversées hiérarchiques.
- [Recherche & filtrage](db/search-and-filtering.md) — vue d'ensemble des 3 leviers (`?search` / `?filter` / `?facets`).
- [Recherche HTTP `?search=`](db/search/README.md) — recherche multi-champs `LIKE`.
- [Filtres HTTP `?filter=`](db/filter.md) — syntaxe URL des filtres, transformations `alt`, opérateurs.
- [Facettes HTTP `?facets=`](db/facets.md) — multi-sélection et existentiels/agrégats sur relations.
- [Champs-tableaux embarqués](db/arrays.md) — `AQL::ARRAYS`, mutation atomique d'un tableau dans un document.
- [Filtrage interne](db/filter-internal.md) — `AQL::CONDITIONS` + `AQL::BINDS` pour les conditions serveur-only.
- [Contrôleurs Slim](controllers/README.md) — exposition HTTP du modèle.
- [Référence des enums](enums.md#aql) — `AQL`, `Filter`, `Skin`, `Traversal` consommés ici.
- [Quickstart `ArangoDB`](db/quickstart.md) — la couche bas niveau sous-jacente.
- [CRUD côté client](clients/documents.md) — la couche bas niveau sur laquelle ce modèle est bâti.
- [AQL côté client](clients/aql.md) — helper `aql()` et sémantique paresseuse du `Cursor` sous `prepare/execute`.
