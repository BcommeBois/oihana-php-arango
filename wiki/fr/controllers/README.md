# Contrôleurs Slim

Le dossier [`src/oihana/arango/controllers/`](../../../src/oihana/arango/controllers/) fournit trois contrôleurs HTTP prêts à l'emploi qui exposent un [modèle `Documents` ou `Edges`](../models.md) sous forme de routes RESTful. La couche est conçue pour Slim 4 et un conteneur PSR-11, mais ne dépend d'aucune implémentation spécifique au-delà des contrats PSR.

| Contrôleur | Rôle | Routes typiques |
|---|---|---|
| `DocumentsController` | CRUD complet sur une collection de documents. | `GET /resource`, `GET /resource/{id}`, `POST /resource`, `PATCH /resource/{id}`, `PUT /resource/{id}`, `DELETE /resource/{id}`, `GET /resource/count`, `GET /resource/last` |
| `EdgesController` | CRUD sur une collection d'arêtes. | Mêmes verbes, sémantique edge (validation `_from`/`_to`). |
| `PropertyController` | Exposition d'une propriété spécifique d'un document (GET / PATCH). | `GET /resource/{id}/{property}`, `PATCH /resource/{id}/{property}` |
| `ArrayPropertyController` | Opérations élément par élément d'une propriété [champ-tableau](../db/arrays.md) (ajout / retrait / déplacement / présence). | `POST /resource/{id}/{property}`, `DELETE\|PATCH\|GET /resource/{id}/{property}/{value}` |
| `TraversalController` | Navigue une arête **auto-référente** (arbre/graphe) : parent, enfants, ancêtres, descendants. | `GET /resource/{id}/{parent\|children\|ancestors\|descendants}` |
| `ConceptSchemeController` | Expose les **racines** d'un thésaurus hiérarchique en `ConceptScheme` SKOS. | `GET /resource/scheme` |

## Pages détaillées du dossier

Cette page reste la **vue d'ensemble** des contrôleurs (signature des verbes, hooks de cycle de vie, traits d'injection). Les **mécaniques spécialisées** consommées par les contrôleurs sont documentées chacune dans une page dédiée :

- [**Payloads**](payloads.md) — la couche `PayloadsTrait` qui extrait, type et transforme le *body* HTTP entrant. Catalogue `AQLType`, clés `Arango::PAYLOAD`, validation i18n pré-extraction, type `EDGE` et imbrication récursive.
- [**Rules**](rules.md) — la couche de validation qui s'applique après préparation du payload. `Arango::RULES` + `Arango::CUSTOM_RULES`, helpers `rules() / min() / max() / between()`, pattern « final tag », catalogue vendor `Rules::*` + catalogue projet `CustomRules::*`, format d'erreur 422.
- [**Skins**](skins.md) — la couche de projection en *sortie*. Catalogue des 12 *skins* canoniques, clés `Arango::SKINS` / `SKIN_DEFAULT` / `SKIN_METHODS`, cas particulier `Skin::INTERNAL` (projection serveur uniquement).
- [**Capabilities**](capabilities.md) — gating fin de la **valeur** d'un paramètre (`?skin=`, `?filter=`) ou d'un **champ** du body, orthogonal à Casbin. `Arango::CAPABILITIES`, 7 traits Capability, pattern *authorizer* injecté vers le modèle (`AQL::REQUIRES`).

## `DocumentsController`

### Méthodes HTTP exposées

`DocumentsController` est composé par agrégation de 8 traits CRUD, un par verbe HTTP. Chacun mappe le verbe sur la méthode correspondante du modèle.

| Méthode contrôleur | Verbe HTTP | Méthode modèle | Trait |
|---|---|---|---|
| `list()` | `GET /resource` | `list()` | `DocumentsControllerListTrait` |
| `get()` | `GET /resource/{id}` | `get()` | `DocumentsControllerGetTrait` |
| `last()` | `GET /resource/last` | `last()` | `DocumentsControllerLastTrait` |
| `count()` | `GET /resource/count` | `count()` | `DocumentsControllerCountTrait` |
| `post()` | `POST /resource` | `insert()` | `DocumentsControllerPostTrait` |
| `patch()` | `PATCH /resource/{id}` | `update()` | `DocumentsControllerPatchTrait` |
| `put()` | `PUT /resource/{id}` | `replace()` | `DocumentsControllerPutTrait` |
| `delete()` | `DELETE /resource/{id}` | `delete()` | `DocumentsControllerDeleteTrait` |

Chaque méthode partage la signature :

```php
public function <verb>
(
    ?Request  $request  = null ,
    ?Response $response = null ,
    array     $args     = []   ,
    array     $init     = []
) : mixed
```

Le paramètre `$init` est un point d'extension : un override peut le pré-remplir pour modifier le comportement de l'appel sans toucher la requête HTTP.

### Définition DI

```php
use DI\Container ;
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\enums\Arango ;
use oihana\controllers\enums\Skin ;

return
[
    Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
    [
        Arango::MODEL        => Models::USERS         ,
        Arango::LIMIT        => 50                    ,
        Arango::SKINS        => [ Skin::DEFAULT , Skin::FULL ] ,
        Arango::SKIN_DEFAULT => Skin::DEFAULT         ,
        Arango::SKIN_METHODS =>
        [
            HttpMethod::list => Skin::DEFAULT ,
            HttpMethod::get  => Skin::FULL    ,
        ] ,
    ]) ,
] ;
```

Clés de configuration principales :

| Clé | Description |
|---|---|
| `Arango::MODEL` | Identifiant DI du modèle [`Documents`/`Edges`](../models.md) consommé. |
| `Arango::LIMIT` | Limite de pagination par défaut. |
| `Arango::SKINS` | Liste blanche des *skins* acceptés via `?skin=`. |
| `Arango::SKIN_DEFAULT` | *Skin* appliqué en l'absence de `?skin=`. |
| `Arango::SKIN_METHODS` | *Skin* par défaut différent selon le verbe (typiquement `default` pour `list`, `full` pour `get`). |

### Déclarer les routes

Les contrôleurs sont consommés par les *routes* Slim définies dans `definitions/routes.php`. Convention :

```php
use oihana\api\routes\GetRoute  ;
use oihana\api\routes\PostRoute ;
use oihana\api\routes\DeleteRoute ;

return
[
    // GET /users — liste
    // Attention : GetRoute appelle `get()` par défaut, donc OBLIGATOIRE pour le listing
    Routes::USERS_LIST => fn( Container $c ) => new GetRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS ,
        Route::ROUTE         => '/users'            ,
        Route::METHOD        => 'list'              ,        // OBLIGATOIRE
    ]) ,

    // GET /users/{id}
    Routes::USERS_GET => fn( Container $c ) => new GetRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS         ,
        Route::ROUTE         => '/users/{id:[a-z0-9-]+}' ,
    ]) ,

    // POST /users
    Routes::USERS_POST => fn( Container $c ) => new PostRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS ,
        Route::ROUTE         => '/users'            ,
    ]) ,

    // ... etc.
] ;
```

> Piège classique : `GetRoute` route par défaut sur la méthode `get()`. Pour le **listing**, il faut explicitement préciser `Route::METHOD => 'list'`. Oublier ce détail fait que `GET /users` (sans `id`) plante en cherchant un document inexistant.

## Étendre `DocumentsController`

Le pattern recommandé pour ajouter de la logique custom (filtrage transverse, validation, enrichissement, hooks d'autorisation) est de **sous-classer** le contrôleur et de surcharger le verbe approprié — en préservant scrupuleusement la signature parent.

```php
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\controllers\traits\inject\InjectFilterTrait ;
use Psr\Http\Message\ServerRequestInterface as Request ;
use Psr\Http\Message\ResponseInterface as Response ;

final class MyUsersController extends DocumentsController
{
    use InjectFilterTrait ;

    public function list
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
        array     $args     = []   ,
        array     $init     = []
    ) : mixed
    {
        $userKey = $this->getCurrentUserKey( $request ) ;
        $init    = $this->injectFilter( $init , 'agent' , $userKey ) ;

        return parent::list( $request , $response , $args , $init ) ;
    }
}
```

**Important** : respecter la **signature exacte** du parent (y compris `$init = []` à la fin). Une signature dégradée casse le polymorphisme et empêche les hooks de cycle de vie de fonctionner.

## Hooks de cycle de vie

`DocumentsController` consomme [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php), qui pose deux *hooks* automatiquement invoqués autour de chaque opération CRUD : `beforeModelCall` et `afterModelCall`.

```php
final class UsersController extends DocumentsController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        // ... validation, filtre transverse (l'authorizer de permission est déjà posé par la base)
    }

    protected function afterModelCall
    (
        ?Request  $request          ,
        array     &$init            ,
        mixed     &$result
    ) : void
    {
        parent::afterModelCall( $request , $init , $result ) ;
        // ... enrichissement de la réponse, logging, audit
    }
}
```

Avantage : **un seul override couvre tous les verbes HTTP**. Pas besoin de répéter la logique transverse dans `list()`, `get()`, `post()`, etc.

## Trait `InjectFilterTrait`

**Namespace** : `oihana\arango\controllers\traits\inject\InjectFilterTrait`

Permet d'injecter des filtres programmatiquement via `$init`. Les filtres injectés sont fusionnés avec les filtres URL mais **n'apparaissent pas** dans l'URL de réponse (champ `url` du JSON).

```php
use oihana\arango\controllers\traits\inject\InjectFilterTrait ;
use oihana\arango\models\enums\filters\FilterComparator ;
use oihana\arango\models\enums\filters\FilterParam ;

// Filtre simple
$init = $this->injectFilter( $init , 'userId' , $userKey ) ;

// Avec opérateur
$init = $this->injectFilter
(
    $init , 'created' , '2026-01-01' , FilterComparator::GE
) ;

// Avec altération
$init = $this->injectFilter
(
    $init , 'name' , 'john' , FilterComparator::EQ , 'lower'
) ;

// Plusieurs filtres d'un coup
$init = $this->injectFilters( $init ,
[
    [ FilterParam::KEY => 'agent'   , FilterParam::VAL => $userKey ] ,
    [ FilterParam::KEY => 'method'  , FilterParam::VAL => 'DELETE' ] ,
    [ FilterParam::KEY => 'created' , FilterParam::VAL => '2026-01-01' , FilterParam::OP => FilterComparator::GE ] ,
]) ;
```

**Fonctionnement** : surcharge `prepareFilter()` pour fusionner les filtres URL (visibles dans l'URL de réponse) avec les filtres injectés (invisibles, stockés dans `$init['__injectedFilters']`).

## Trait `InjectAuthorizerTrait`

**Namespace** : `oihana\arango\controllers\traits\inject\InjectAuthorizerTrait`

Permet d'injecter un *authorizer* `Closure(string $subject): bool` qui sera consulté par le framework AQL pour décider d'inclure ou non un *edge* / *join* marqué `AQL::REQUIRES`. Voir [Projection des edges et joins](../projection.md#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires).

> **Note.** En production (Casbin + *request-scoped*), vous n'avez généralement **rien à câbler** : `DocumentsController` pose déjà l'authorizer de permission automatiquement dès que la *stack* d'autorisation est enregistrée dans le conteneur DI (cf. [Projection — câblage automatique](../projection.md#câblage-côté-contrôleur--automatique-depuis-la-base)). `InjectAuthorizerTrait` ne sert que pour un callable **stable** connu à la construction et non lié au *request* (batch CLI, test, callable issu directement du conteneur).

```php
final class BatchController extends DocumentsController
{
    use InjectAuthorizerTrait ;

    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;
        $this->initializeArangoAuthorizer( $init , fn() : bool => true ) ;
    }

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        $this->injectAuthorizer( $init ) ;
    }
}
```

Pour le pattern *request-scoped* avec Casbin (le plus courant en production), rien à faire : la base le câble automatiquement (voir la note ci-dessus).

## `EdgesController`

Variante de `DocumentsController` adossée à un modèle [`Edges`](../models.md#la-classe-edges). Mêmes 8 verbes, sémantique adaptée :

- `post()` valide les `_from`/`_to` avant insertion.
- `delete()` déclenche la cascade *signal* `afterDelete`.
- Routes paramétrées différentes : `/users/{from}/has-roles/{to}` pour cibler une arête précise.

```php
return
[
    Controllers::USER_HAS_ROLES => fn( Container $c ) => new EdgesController( $c ,
    [
        Arango::MODEL => Models::USER_HAS_ROLES ,
    ]) ,
] ;
```

## `PropertyController`

Expose **une propriété spécifique** d'un document comme une sous-ressource. Utile pour les propriétés qui ont leur propre logique (validation, calculs) sans justifier une collection séparée.

| Verbe | Méthode | Trait |
|---|---|---|
| `get()` | `GET /resource/{id}/{property}` | `PropertyControllerGetTrait` |
| `patch()` | `PATCH /resource/{id}/{property}` | `PropertyControllerPatchTrait` |

```php
return
[
    Controllers::USERS_AVATAR => fn( Container $c ) => new PropertyController( $c ,
    [
        Arango::MODEL    => Models::USERS  ,
        Arango::PROPERTY => 'avatar'        ,
    ]) ,
] ;
```

## `ArrayPropertyController`

Étend [`PropertyController`](#propertycontroller) pour exposer les **opérations élément par élément** d'une propriété déclarée comme **champ-tableau embarqué** ([`AQL::ARRAYS`](../db/arrays.md)) : ajouter, retirer, déplacer un élément, tester sa présence — par-dessus le `get()` (lire tout le tableau) et `patch()` (remplacer tout le tableau) hérités.

| Verbe | Méthode | Route | Opération modèle |
|---|---|---|---|
| `addItem()` | `POST` | `/resource/{id}/{property}` | `arrayInsert` |
| `removeItem()` | `DELETE` | `/resource/{id}/{property}/{value}` | `arrayRemove` |
| `moveItem()` | `PATCH` | `/resource/{id}/{property}/{value}` | `arrayMove` |
| `hasItem()` | `GET` | `/resource/{id}/{property}/{value}` | `arrayContains` |

Les quatre méthodes vivent dans `ArrayPropertyControllerTrait`.

### Valeur de l'élément : URL ou body

L'élément est résolu depuis le placeholder `{value}` de l'URL (pratique pour les **scalaires** : ids, tags), **sinon** depuis le **body** (clé `value`) — utilisez le body pour les valeurs **complexes** (objets) qui ne peuvent pas voyager dans une URL. `addItem` lit la valeur dans le body (+ un `side` `left`/`right` optionnel) ; `moveItem` lit `position` dans le body.

### Codes d'erreur

| Code | Quand |
|---|---|
| `400 Bad Request` | la propriété ciblée n'est pas déclarée dans `AQL::ARRAYS` du modèle |
| `404 Not Found` | le document propriétaire n'existe pas ; ou (`hasItem`) la valeur est absente du tableau |
| `422 Unprocessable Entity` | `moveItem` sur un champ `sortedSet` (le tri par valeur rend la position absurde) |

### Câblage complet (modèle + controller + routes)

```php
use oihana\arango\controllers\ArrayPropertyController ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\ArrayMode ;
use oihana\arango\routes\ArrayPropertyRoute ;
use oihana\routes\Route ;

// 1. Le modèle déclare le champ-tableau (mode + compteur). Bonus : à la création
//    d'un document (POST /playlists), `tracks` est initialisé à [] automatiquement
//    (et `numberOfTracks` à 0).
Models::PLAYLIST => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'Playlist' ,
    AQL::ARRAYS     => [ 'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ] ,
]) ,

// 2. Le controller, configuré pour la propriété 'tracks'.
Controllers::PLAYLIST_TRACKS => fn( Container $c ) => new ArrayPropertyController( $c ,
[
    Arango::MODEL    => Models::PLAYLIST ,
    Arango::PROPERTY => 'tracks' ,
]) ,

// 3. Les routes : une seule entrée via ArrayPropertyRoute.
Routes::PLAYLIST_TRACKS => fn( Container $c ) => new ArrayPropertyRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::PLAYLIST_TRACKS ,
    Route::ROUTE         => '/playlists/{id}/tracks' ,
]) ,
```

Génère `POST /playlists/{id}/tracks` (addItem) et `DELETE|PATCH|GET /playlists/{id}/tracks/{value}` (removeItem / moveItem / hasItem).

> `arrayPurgeRef` (retirer une valeur de **tous** les documents qui la référencent) n'est **pas** exposé en HTTP : c'est une opération de cascade, à déclencher côté application via un listener `afterUpdate`/`afterDelete` (cf. [Champs-tableaux embarqués](../db/arrays.md#propager-une-modification-aux-documents-parents)).

## `TraversalController`

Navigue une arête **auto-référente** — un graphe dont les deux bouts ciblent la même collection de sommets (un arbre de catégories, un organigramme, un fil de discussion) — et renvoie les sommets traversés, hydratés avec le schéma de la collection cible. Une **seule instance** expose les quatre méthodes de navigation ; l'arête est injectée une fois via `TraversalController::EDGE`.

| Méthode | Verbe | Route | Direction | Transitif |
|---|---|---|---|---|
| `getParent()` | `GET` | `/resource/{id}/parent` | INBOUND | non (un seul, ou `null`) |
| `getChildren()` | `GET` | `/resource/{id}/children` | OUTBOUND | non (direct) |
| `getAncestors()` | `GET` | `/resource/{id}/ancestors` | INBOUND | oui (jusqu'à la racine) |
| `getDescendants()` | `GET` | `/resource/{id}/descendants` | OUTBOUND | oui (sous-arbre complet) |

Les méthodes transitives acceptent un paramètre `?depth=N`, borné par `TraversalController::DEFAULT_MAX_DEPTH` (défaut : le sous-arbre complet). Les sommets sont hydratés via le modèle cible de l'arête (`Edges::get*Vertices()`), donc un **champ projeté en requête survit à la traversée**.

L'enveloppe des méthodes plurielles (enfants, ancêtres, descendants) porte `count` **et** `total`, tous deux égaux au nombre de sommets traversés : la traversée n'est pas paginée, donc `count == total`.

### Câblage complet (arête + contrôleur + routes)

Les quatre sous-routes sont déclarées en une seule entrée avec [`TraversalRoute`](../../../src/oihana/arango/routes/TraversalRoute.php), qui mappe chaque suffixe vers la méthode correspondante (via `Route::METHOD`, sans magic string) — le jumeau d'`ArrayPropertyRoute`.

```php
use oihana\arango\controllers\TraversalController ;
use oihana\arango\routes\TraversalRoute ;
use oihana\routes\Route ;

// 1. Le modèle d'arête auto-référente (les deux bouts ciblent la même collection).
Models::CATEGORY_TREE => fn( Container $c ) => new Edges( $c ,
[
    AQL::COLLECTION => 'category_has_subcategory' ,
    // … from = to = la collection des catégories …
]) ,

// 2. Le contrôleur, configuré avec cette arête.
Controllers::CATEGORIES_TRAVERSAL => fn( Container $c ) => new TraversalController( $c ,
[
    TraversalController::EDGE => Models::CATEGORY_TREE ,
]) ,

// 3. Les quatre sous-routes, en une seule entrée. À déclarer AVANT la route
//    document générique pour que les suffixes littéraux soient matchés d'abord.
Routes::CATEGORIES_TREE => fn( Container $c ) => new TraversalRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::CATEGORIES_TRAVERSAL ,
    Route::ROUTE         => '/categories' ,
]) ,
```

Génère `GET /categories/{id:[0-9]+}/{parent|children|ancestors|descendants}`. Le placeholder `{id}` est configurable via `Route::ROUTE_PLACEHOLDER`.

## `ConceptSchemeController`

Expose un thésaurus hiérarchique en [`ConceptScheme`](https://www.w3.org/TR/skos-reference/#schemes) SKOS : son `hasTopConcept` est l'ensemble des **racines** (les concepts sans parent broader), assemblé à la volée depuis le modèle `Documents` sous-jacent. Lecture seule et générique — un seul point d'entrée, donc un simple `GetRoute` suffit (pas de classe de route dédiée).

| Clé d'init | Rôle | Défaut |
|---|---|---|
| `MODEL` | le modèle `Documents` du thésaurus | — |
| `TITLE` | le nom d'affichage du schéma | `''` |
| `RELATION` | la clé de relation broader dont l'**absence** marque une racine | `Oihana::BROADER` |
| `SKIN` | le skin utilisé pour projeter les racines | `Skin::FULL` |

Il honore `?sort` (ex. `id`, `name`, `created`, `modified`) et `?search` sur les racines — le modèle applique sa propre whitelist `SORTABLE` / `SEARCHABLE`. Rien n'est persisté.

```php
use oihana\arango\controllers\ConceptSchemeController ;
use oihana\routes\http\GetRoute ;
use oihana\routes\Route ;

Controllers::CATEGORIES_SCHEME => fn( Container $c ) => new ConceptSchemeController( $c ,
[
    ConceptSchemeController::MODEL => Models::CATEGORIES ,
    ConceptSchemeController::TITLE => 'Product categories' ,
]) ,

Routes::CATEGORIES_SCHEME => fn( Container $c ) => new GetRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::CATEGORIES_SCHEME ,
    Route::ROUTE         => '/categories/scheme' ,
]) ,
```

Renvoie `{ "@type": "ConceptScheme", "name": "Product categories", "hasTopConcept": [ … racines … ] }`.

L'enveloppe de réponse porte aussi `count` **et** `total`, tous deux égaux au nombre de top-concepts (`count(hasTopConcept)`). Le `/scheme` n'est pas paginé — chaque racine est renvoyée —, donc `count == total` et il n'y a ni `limit` ni `offset`. Pratique pour afficher « N grandes familles » sans recompter côté UI.

## `PayloadsTrait`

**Namespace** : `oihana\arango\controllers\traits\PayloadsTrait`

Trait transverse consommé par tous les contrôleurs. Centralise la normalisation des payloads HTTP entrants (body JSON, *form-urlencoded*) et la validation contre les `AQL::FILLABLE` du modèle. Documenté en détail dans [Modèles](../models.md) (clé `AQL::FILLABLE`).

## Catalogue récapitulatif des traits

| Trait | Famille | Rôle |
|---|---|---|
| `DocumentsControllerListTrait` | Verbe | `list()` |
| `DocumentsControllerGetTrait` | Verbe | `get()` |
| `DocumentsControllerLastTrait` | Verbe | `last()` |
| `DocumentsControllerCountTrait` | Verbe | `count()` |
| `DocumentsControllerPostTrait` | Verbe | `post()` |
| `DocumentsControllerPatchTrait` | Verbe | `patch()` |
| `DocumentsControllerPutTrait` | Verbe | `put()` |
| `DocumentsControllerDeleteTrait` | Verbe | `delete()` |
| `DocumentsControllerUpdateTrait` | Verbe | helper interne, factorise `patch`/`put` |
| `PropertyControllerGetTrait` | Verbe | `get()` propriété |
| `PropertyControllerPatchTrait` | Verbe | `patch()` propriété |
| `PayloadsTrait` | Transverse | Normalisation et validation des payloads. |
| `InjectFilterTrait` | Extension | Injection de filtres transparents. |
| `InjectAuthorizerTrait` | Extension | Injection d'un *authorizer* sur les *edges*/*joins*. |

## Voir aussi

- [Modèles `Documents` et `Edges`](../models.md) — la couche métier sous-jacente.
- [Filtres HTTP `?filter=`](../db/filter.md) — syntaxe URL consommée par les contrôleurs.
- [Filtrage interne](../db/filter-internal.md) — `InjectFilterTrait` et `AQL::CONDITIONS`.
- [La projection des champs](../projection.md) — `Skin`, `AQL::REQUIRES`, *authorizer*.
- [Commandes Symfony Console](../commands.md) — exposition CLI parallèle.
