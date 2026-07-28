# Contrôleurs Slim

Le dossier [`src/oihana/arango/controllers/`](../../../src/oihana/arango/controllers/) fournit trois contrôleurs HTTP prêts à l'emploi qui exposent un [modèle `Documents` ou `Edges`](../models.md) sous forme de routes RESTful. La couche est conçue pour Slim 4 et un conteneur PSR-11, mais ne dépend d'aucune implémentation spécifique au-delà des contrats PSR.

| Contrôleur | Rôle | Routes typiques |
|---|---|---|
| `DocumentsController` | CRUD complet sur une collection de documents. | `GET /resource`, `GET /resource/{id}`, `POST /resource`, `PATCH /resource/{id}`, `PUT /resource/{id}`, `DELETE /resource/{id}`, `GET /resource/count`, `GET /resource/last` |
| `EdgesController` | CRUD sur une collection d'arêtes. | Mêmes verbes, sémantique edge (validation `_from`/`_to`). |
| `PropertyController` | Exposition d'une propriété spécifique d'un document (GET / PATCH). | `GET /resource/{id}/{property}`, `PATCH /resource/{id}/{property}` |
| `ArrayPropertyController` | Opérations élément par élément d'une propriété [champ-tableau](../db/arrays.md) (ajout / retrait / déplacement / réordonnancement / édition / présence). | `POST\|PUT /resource/{id}/{property}`, `DELETE\|PATCH\|PUT\|GET /resource/{id}/{property}/{value}` |
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
| `Arango::META_ONLY` | Défaut durable du mode « métadonnées seules » (pas de documents) : `true` pour un endpoint de type sonde de facettes/bornes. Reste surchargeable par `?metaOnly=`. Voir les [facettes](../db/facets.md). |

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

### ⚠️ `Arango::CONDITIONS` ne veut pas dire la même chose selon l'opération

Cet avantage a un angle vif, et mieux vaut le connaître avant d'écrire son premier hook : `conditions` s'écrit pareil dans tous les `$init`, mais le modèle le lit avec **deux dictionnaires différents** selon l'opération.

> **En grande partie résolu.** `Arango::CONDITIONS` signifie désormais « prédicats AQL » sur `update()` et `replace()` aussi, et les prédicats de compression ont leur propre clé, `Arango::OMIT_WHEN`. Le tableau ci-dessous décrit l'état de transition : l'ancien sens d'écriture reste accepté, avec une dépréciation loguée, jusqu'à la prochaine version.

| Opération | Type attendu | Sens |
|---|---|---|
| `get()`, `list()`, `last()`, `count()`, `exist()`, `delete()`, `update()`, `replace()` | `string[]` | prédicats AQL ajoutés au `FILTER` de la requête |
| `insert()`, `update()`, `replace()`, `upsert()` | `callable[]` — **déprécié**, utiliser `Arango::OMIT_WHEN` | quels **attributs retirer du payload** avant l'écriture (les gardes de compression des nulls) |

La situation. Un hook qui pose un périmètre sur tous les appels modèle — exactement le patron que cette section recommande :

```php
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
    $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;

    parent::beforeModelCall( $request , $init ) ;
}
```

Il fait désormais ce qu'on attend sur `GET`, sur `PATCH` et sur `PUT` indifféremment — le prédicat rejoint le `FILTER` de l'écriture, donc un update visant un document hors périmètre n'apparie rien et n'écrit rien. Avant, la chaîne arrivait dans `compress()`, qui attend des callables, et répondait :

```
InvalidArgumentException: All conditions in the array must be callable.
→ HTTP 500
```

`POST` reste l'exception, et le restera : un `INSERT` crée un document, il n'y en a donc aucun d'existant à filtrer. Un périmètre sur une création se pose en amont, pas en rétrécissant une requête qui n'a pas de `FILTER`.

**Le sens « écriture » a désormais un nom à lui : `Arango::OMIT_WHEN`.** Utilisez-le pour les prédicats de compression, et la clé partagée cesse d'être ambiguë de votre côté :

```php
$model->update
([
    Arango::VALUE     => 'k1' ,
    Arango::DOC       => [ 'name' => 'Marc' , 'nickname' => null ] ,
    Arango::OMIT_WHEN => [ fn( $value ) => $value === null || $value === '' ] ,
]) ;
```

`Arango::CONDITIONS` reste honoré sur les quatre écritures quand il porte des callables, avec une dépréciation loguée — de quoi mesurer une migration au lieu de la deviner. Un tableau mixte est séparé plutôt que refusé : les callables compressent le payload, les chaînes partent au `FILTER`.

Si vous avez malgré tout besoin de distinguer lectures et écritures dans un hook — pour ne poser un prédicat que sur les lectures, ou un différent sur chacune — les `$init` d'écriture portent `Arango::DOC`, le payload sur le point d'être écrit, et ceux de lecture jamais :

```php
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    if ( !array_key_exists( Arango::DOC , $init ) ) // une lecture
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;
    }

    parent::beforeModelCall( $request , $init ) ;
}
```

Conséquence à assumer si vous la gardez ainsi : l'écriture n'est alors pas périmétrée par son propre `FILTER`, et doit l'être en amont — sonder le document par un `exist()` périmétré avant d'écrire, ce que fait [`PropertyController`](#périmétrer-un-contrôleur-de-propriété). Sauf raison particulière, poser le prédicat sur les deux est plus simple et périmètre directement l'écriture.

## Les paramètres de route atteignent le modèle (`Arango::ARGS`)

Prenons la route `/workspaces/{workspace}/things/{id}`. Slim remet à l'action ses
*placeholders* — `[ 'workspace' => 'w1' , 'id' => '42' ]` — dans l'argument `$args`.
Chaque *handler* les replie dans le `$init` qu'il passe au modèle, sous la clé
`Arango::ARGS` :

```php
// DELETE /workspaces/w1/things/42
$init = [ Arango::ARGS => [ 'workspace' => 'w1' , 'id' => '42' ] , Arango::VALUE => [ '42' ] ] ;
```

Les lectures (`list`, `get`, `last`) le faisaient déjà ; **les écritures aussi** :
`post()` → `insert()`, `patch()` / `put()` → `update()` / `replace()`, `delete()` →
`delete()`, ainsi que la sonde d'existence (`exist()`) qui garde la mise à jour comme la
suppression. La clé est **toujours présente** (un tableau vide si la route ne porte aucun
*placeholder*), et les paramètres de route **l'emportent** sur une entrée `Arango::ARGS`
déjà posée dans le `$init` entrant.

Deux consommateurs en profitent :

- **Les champs `Filter::URL`**, dont les *placeholders* de `Field::PATH` sont résolus
  depuis `Arango::ARGS` (voir [Champs URL](../db/helpers.md#champs-url--filterurl)) — le
  document renvoyé par une écriture porte désormais la même `url` que celui renvoyé par
  une lecture.
- **Vos overrides `beforeModelCall()` / `afterModelCall()` et les signaux du modèle**
  (`BeforeInsert`, `AfterUpdate`, `BeforeDelete`, …), dont le `context` *est* ce `$init` —
  un segment de *tenant* ou d'espace de travail présent dans l'URL y est lisible sans
  retoucher à la requête.

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

### Périmétrer les liens

Ici un `POST` **crée** un lien et un `DELETE` en retire un : un contrôleur d'arêtes sans périmètre ne fait pas que fuir, il laisse un appelant rattacher un document que son périmètre masque. Ses trois refus répondaient aussi comme un oracle — `404` source, `404` cible, `409` l'arête existe disent à l'appelant ce qu'un `GET` périmétré lui refuse.

Le contrôleur porte le même **siège d'autorisation** que les autres, avec une différence qui compte : il parle à **trois** modèles — les sommets source, les sommets cible et les arêtes — et un prédicat écrit pour l'un n'a aucun sens sur les autres. Chaque appel du hook porte donc `EdgesController::CALL`, valué `FROM`, `TO` ou `EDGES` :

```php
final class ScopedLinksController extends EdgesController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        match ( $init[ self::CALL ] ?? null )
        {
            self::FROM , self::TO => $this->narrowVertex( $init ) ,  // Arango::CONDITIONS + Arango::BINDS
            self::EDGES           => $this->narrowEdge  ( $init ) ,  // AQL::FILTER + AQL::BINDS
            default               => null ,
        } ;

        parent::beforeModelCall( $request , $init ) ; // conserve l'autorisateur
    }
}
```

⚠ **Les deux moitiés ne lisent pas la même clé.** Une sonde de sommet est un `Documents::exist()` et lit `Arango::CONDITIONS` ; la sonde d'arête et la suppression sont des appels `Edges` et lisent `AQL::FILTER`. Se tromper de clé est silencieux — le périmètre ne s'applique simplement jamais.

| Appel | Encadré | Ce que le périmètre y fait |
|---|---|---|
| la sonde source (`CALL = FROM`) | ✅ | une source masquée se lit comme absente : 404, et aucun lien n'est créé |
| la sonde cible (`CALL = TO`) | ✅ | idem à l'autre bout |
| `existEdge()` puis `deleteEdge()` (`CALL = EDGES`) | ✅ | **un seul init partagé**, donc la sonde et la suppression ne peuvent pas diverger |
| la création elle-même | ❌ | voir ci-dessous |

**Pourquoi la création n'est pas encadrée.** Un `INSERT` n'a ni `FOR` ni `FILTER`, il n'y a rien à restreindre — et `Edges::insertEdge()` repasse son init au contrôle d'unicité `existEdge()` : un périmètre posé là rendrait le `409` aveugle et laisserait passer un doublon. Une création se refuse en amont, par les deux sondes de sommet. L'autorisateur de requête y est tout de même posé, donc l'arête renvoyée est projetée sous les mêmes verrous `Field::REQUIRES` qu'une lecture.

**Les conditions qui ne dépendent pas de la requête n'ont besoin d'aucune sous-classe** : déclarez-les une fois dans le `$init` du contrôleur, elles atteignent les deux sondes — `post()` et `delete()` ignoraient jusqu'ici totalement cet init.

**Une arête masquée est signalée absente, jamais à moitié supprimée.** Comme la sonde et la suppression partagent un init, la réponse est un 404 et l'arête survit — le trou « 404 sur la sonde, 200 sur une suppression qui n'a rien touché » ne peut pas apparaître ici.


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

### Périmétrer un contrôleur de propriété

Une route de sous-ressource est une porte sur le même document que `/resource/{id}`. Si cette route principale est périmétrée — seuls certains documents sont visibles pour l'appelant — la sous-ressource doit l'être aussi, sinon `/resource/{id}/avatar` répond pour des documents que `/resource/{id}` refuse de montrer.

`PropertyController` porte le même **siège d'autorisation** que `DocumentsController` : il résout dans le conteneur l'enforcer de capacités et le résolveur de sujets de permission, pose l'autorisateur de la requête sous `Arango::AUTHORIZER` (les verrous `Field::REQUIRES` / `AQL::REQUIRES` de la projection s'appliquent donc), et encadre ses lectures modèle des [hooks de cycle de vie](#hooks-de-cycle-de-vie).

La lib fournit le siège, jamais la règle. C'est le consommateur qui pose le prédicat :

```php
final class AvatarController extends PropertyController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;

        parent::beforeModelCall( $request , $init ) ; // conserve l'autorisateur
    }
}
```

`Arango::CONDITIONS` atterrit dans le `FILTER` de la requête, `Arango::BINDS` est fusionné aux variables de liaison — le modèle honore les deux tels quels, il n'y a rien d'autre à faire.

Une condition qui ne dépend pas de la requête n'a besoin d'aucune sous-classe : déclarez-la une fois dans le `$init` du contrôleur, elle atteint la lecture directement.

#### Où le hook passe

| Appel | Encadré | Ce que le périmètre y fait |
|---|---|---|
| `get()` | ✅ | rétrécit la lecture |
| l'`update()` de `patch()` | ✅ | rétrécit le `FILTER` de l'écriture : hors périmètre → n'apparie rien → `RETURN NEW` rend `null` → 404 |
| la relecture qui suit `patch()` | ✅ | c'est aussi une lecture — une réponse d'écriture qui contournerait le périmètre rendrait précisément ce que le périmètre retient |
| la sonde d'existence de `patch()` et des six opérations de tableau | ✅ | déclare le document absent avant que l'opération ne parte |
| les six écritures de tableau elles-mêmes | ❌ | elles compilent leur propre `FILTER` et ne lisent pas `Arango::CONDITIONS` ; la sonde ci-dessus est leur garde |

L'`update()` **et** la sonde sont périmétrés tous les deux, et ce n'est pas redondant. Deux requêtes peuvent se contredire : un document qui sortait du périmètre entre la sonde et l'écriture était mis à jour quand même. Désormais l'écriture revérifie le prédicat elle-même, atomiquement, et répond 404 au lieu d'écrire.

Les opérations de tableau gardent la sonde pour seul verrou : `arrayInsert()`, `arrayMove()` et leurs sœurs compilent leur propre `FILTER` et ne lisent jamais `Arango::CONDITIONS`, donc enrichir leur init ne changerait rien.

#### Un document filtré répond 200, pas 404

Quand le périmètre écarte le document, `get()` répond **200 avec un résultat nul** — exactement comme un identifiant inconnu, et exactement comme un document visible dont la propriété est simplement absente. Les trois cas sont indiscernables, et c'est voulu : répondre 404 sur les deux premiers dirait à l'appelant lequel il a touché, soit précisément l'inférence que le périmètre existe pour empêcher.

#### Sans pile d'autorisation

Pas d'enforcer, pas de résolveur, ou pas d'utilisateur authentifié (CLI, tests, une application qui n'a jamais câblé l'authentification) → aucun autorisateur n'est posé et la couche de projection reste ouverte. Un contrôleur qui ne dérive pas et ne porte pas la pile se comporte exactement comme avant l'existence du siège.

## `ArrayPropertyController`

Étend [`PropertyController`](#propertycontroller) pour exposer les **opérations élément par élément** d'une propriété déclarée comme **champ-tableau embarqué** ([`AQL::ARRAYS`](../db/arrays.md)) : ajouter, retirer, déplacer, réordonner, éditer un élément, tester sa présence — par-dessus le `get()` (lire tout le tableau) et `patch()` (remplacer tout le tableau) hérités.

| Verbe | Méthode | Route | Opération modèle |
|---|---|---|---|
| `addItem()` | `POST` | `/resource/{id}/{property}` | `arrayInsert` |
| `reorderItems()` | `PUT` | `/resource/{id}/{property}` | `arrayReorder` |
| `removeItem()` | `DELETE` | `/resource/{id}/{property}/{value}` | `arrayRemove` |
| `moveItem()` | `PATCH` | `/resource/{id}/{property}/{value}` | `arrayMove` |
| `updateItem()` | `PUT` | `/resource/{id}/{property}/{value}` | `arrayUpdate` |
| `hasItem()` | `GET` | `/resource/{id}/{property}/{value}` | `arrayContains` |

Les six méthodes vivent dans `ArrayPropertyControllerTrait`.

> `PATCH` et `PUT` partagent le chemin de l'**élément**, mais pas la même intention : c'est le **verbe** qui les distingue — `PATCH` **déplace** l'élément, `PUT` l'**édite**. Sur le chemin de la **propriété**, `PUT` remplace l'**ordre** du tableau entier.

### Valeur de l'élément : URL ou body

L'élément est résolu depuis le placeholder `{value}` de l'URL (pratique pour les **scalaires** : ids, tags), **sinon** depuis le **body** (clé `value`) — utilisez le body pour les valeurs **complexes** (objets) qui ne peuvent pas voyager dans une URL. `addItem` lit la valeur dans le body (+ un `side` `left`/`right` optionnel) ; `moveItem` lit `position` dans le body.

### Cibler un élément par sa clé

Si le modèle déclare un [`Arango::ITEM_KEY`](../db/arrays.md#cibler-un-élément-par-sa-clé-arangoitem_key) sur la propriété, `{value}` n'est plus l'élément : c'est **sa clé**. C'est précisément ce qui rend un tableau d'**objets** adressable en REST — `DELETE /playlists/42/chapters/c1` au lieu d'un objet complet dans le body.

Deux conséquences côté HTTP :

- `moveItem` et `updateItem` répondent **`404`** quand aucun élément ne porte la clé demandée. Le modèle transforme les deux cas en no-op (rien de fusionné, rien de réordonné), donc le document qu'il renvoie suffit à le constater : **aucune requête supplémentaire**.
- La comparaison est **stricte**, comme le `==` d'AQL sur un attribut de document. Une clé numérique demandée depuis une URL (donc une chaîne) ne matche rien — ni côté base, ni côté contrôleur. Les deux disent « introuvable » au même moment.

### `updateItem` : le corps EST le patch

`PUT /resource/{id}/{property}/{value}` fusionne un patch partiel dans l'élément désigné. Le corps de la requête **est** le patch, sans enveloppe :

```http
PUT /playlists/42/chapters/c1
Content-Type: application/json

{ "rating": 5 }
```

Le verbe dit déjà qu'on édite l'élément : rien n'a besoin de le renommer dans le corps. La fusion est partielle — les attributs du patch écrasent les leurs, les autres sont conservés.

### `reorderItems` : tout l'ordre en une requête

`PUT /resource/{id}/{property}` applique **tout un ordre d'un coup**, là où `moveItem` déplace un élément à la fois — ce dont une interface en glisser-déposer a besoin quand elle connaît déjà l'ordre final. Les clés ordonnées voyagent dans le **corps**, sous `value`, comme pour `addItem` — l'autre opération qui vise la **propriété** et non l'un de ses éléments :

```http
PUT /invoices/42/lines
Content-Type: application/json

{ "value": [ "l3", "l1", "l2" ] }
```

Une liste **partielle** réordonne ce qu'elle nomme et **conserve le reste**, rappendu derrière : un bug d'interface qui n'enverrait qu'un sous-ensemble ne peut pas effacer des lignes. Une clé inconnue est ignorée, une liste vide ne change rien. Voir [`arrayReorder`](../db/arrays.md#arrayreorder) pour le détail.

### Codes d'erreur

| Code | Quand |
|---|---|
| `400 Bad Request` | la propriété ciblée n'est pas déclarée dans `AQL::ARRAYS` du modèle |
| `404 Not Found` | le document propriétaire n'existe pas ; ou (`hasItem`) la valeur est absente du tableau ; ou (`moveItem`/`updateItem` par clé) aucun élément ne porte la clé demandée |
| `422 Unprocessable Entity` | l'opération **n'existe pas** sur cette propriété : `moveItem`/`reorderItems` sur un champ `sortedSet`, `updateItem`/`reorderItems` sur une propriété sans clé d'élément, ou une propriété déclarée à la fois `sortedSet` et [numérotée](../db/arrays.md#numéroter-les-éléments-arangoposition_key) |

Les six opérations — `hasItem` compris — passent d'abord par la sonde d'existence du document propriétaire. C'est la couture sur laquelle agit un [périmètre](#périmétrer-un-contrôleur-de-propriété) : un propriétaire hors périmètre est déclaré absent, et ni l'écriture ni la réponse d'appartenance ne sont atteintes. Dire « cette valeur est bien dans le tableau » d'un document que l'appelant n'a pas le droit de voir serait déjà une divulgation.

**La règle des 422, en une phrase :** « cette opération n'existe pas sur ce champ » est une **requête** que la propriété ne peut pas satisfaire, pas une panne serveur. Le modèle énonce la règle **une seule fois** — il lève une `UnsupportedOperationException` — et le squelette partagé du contrôleur traduit chacune d'elles en ce même statut. Aucune garde n'est réécrite opération par opération.

> **Pourquoi `updateItem` et `reorderItems` refusent une propriété sans clé.** Pour `updateItem`, l'élément ne pourrait être désigné que par une copie octet pour octet de lui-même — que le patch qu'on applique invalide aussitôt : le deuxième appel identique ne matcherait plus rien. Pour `reorderItems`, sans attribut identifiant les éléments, il n'y a tout simplement rien à ordonner. Mieux vaut refuser que de servir une opération qui ne marche qu'une fois, ou pas du tout.

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
//    Un tableau d'objets déclare en plus l'attribut qui identifie ses éléments,
//    ce qui rend `{value}` adressable : Arango::ITEM_KEY => 'id'.
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

Génère `POST|PUT /playlists/{id}/tracks` (addItem / reorderItems) et `DELETE|PATCH|PUT|GET /playlists/{id}/tracks/{value}` (removeItem / moveItem / updateItem / hasItem).

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

### Filtrer les sommets traversés (`?filter=`)

Les quatre méthodes acceptent un paramètre `?filter=` — le [même DSL JSON](../db/filter.md) que la surface `Documents` — qui restreint la traversée aux sommets qui matchent :

`GET /categories/5/descendants?filter={"key":"status","op":"eq","val":"published"}` → uniquement les descendants publiés.

**Pourquoi c'est sûr.** Une traversée inline son slot `FILTER` verbatim (un cran server-only), donc le JSON client n'est **jamais** déposé brut. Il est d'abord *compilé* par le moteur gardé du modèle d'arête, ciblé sur le sommet traversé `vertex`, en `FILTER vertex.status == @bind` — si bien que les deux garde-fous des `Documents` s'appliquent à l'identique :

- **Whitelist** — seuls les attributs déclarés dans le `AQL::FILTERS` du modèle d'arête sont filtrables ; un attribut non déclaré est écarté, donc `?filter=` ne peut jamais atteindre un champ non exposé.
- **Authorizer** — quand la pile d'autorisation est câblée, l'authorizer de requête verrouille `Field::REQUIRES` à la fois sur le prédicat compilé et sur la projection du sommet (`returnFields()`) : un attribut masqué ne peut pas être sondé à travers la traversée. Sans pile, il tombe ouvert (rétro-compatible).

**Sémantique — `?filter=` cache, il n'élague pas.** Sur une traversée transitive (`ancestors` / `descendants`), un sommet non-matchant est retiré de la **liste plate** renvoyée, mais la traversée continue de descendre *à travers* lui — donc un petit-enfant matchant survit même si son parent est filtré. C'est le bon comportement pour une liste plate ; pour en reconstruire un arbre `children[]`, voir la note sur les trous dans [`buildTree()`](../edges-joins-projection.md).

### Couper des branches entières (`?prune=`)

Là où `?filter=` cache un sommet mais continue de descendre, `?prune=` **coupe toute la branche sous un sommet non-matchant**. Même DSL JSON, mêmes garde-fous (whitelist + authorizer). Prenons `racine(publié) → A(publié) → B(brouillon) → C(publié)` :

| Requête | Résultat | Pourquoi |
|---|---|---|
| `?filter={status:publié}` | `racine, A, C` | B caché, mais la traversée descend à travers lui → C réapparaît |
| `?prune={status:publié}` | `racine, A` | la branche sous B est coupée → C jamais atteint |

`?prune=cond` exclut aussi le sommet-**frontière** non-matchant (sa condition rejoint le `FILTER`) et ne marche jamais son sous-arbre — il se compile en `FILTER vertex.status == @b` **et** `PRUNE !( vertex.status == @b )`. Tu obtiens le sous-arbre propre des sommets matchants, sans feuille-frontière parasite.

- **Direction** — `?prune=` est **rejeté avec `400`** sur les méthodes inbound (`getParent`, `getAncestors`) : élaguer en remontant vers la racine n'a pas de sens défini. Il s'applique à `getChildren` / `getDescendants` (sur `getChildren` direct c'est un no-op inoffensif — rien sous la profondeur 1).
- **Se compose avec `?filter=`** — les deux peuvent être envoyés ensemble. Chaque condition restreint l'ensemble renvoyé (elles s'`AND`ent dans le `FILTER`) ; seule celle de prune arrête aussi la descente. Ex. `?filter={lang:fr}&prune={status:publié}` renvoie le sous-arbre publié, encore restreint aux sommets français.

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

### Périmétrer les sommets traversés

`?filter=` et `?prune=` sont les leviers de l'**appelant**. Ils ne savent pas dire « masquer les sommets marqués inactifs, sauf si l'appelant détient telle permission » — une règle que le serveur impose et que l'appelant ne doit pas pouvoir élargir.

Le contrôleur porte le même **siège d'autorisation** que les autres : les quatre traversées sont encadrées par les [hooks de cycle de vie](#hooks-de-cycle-de-vie), et `beforeModelCall()` tourne **après** la compilation de `?filter=` et `?prune=`, donc le périmètre a le dernier mot.

⚠ **La charge n'a pas la même forme que sur les autres surfaces.** À cet endroit, `AQL::FILTER` ne porte plus un prédicat JSON mais une **liste de fragments AQL compilés** ciblant la variable `vertex`, accompagnés de leurs `AQL::BINDS` — parce que `getVertices()` lit cette fente-là et **jamais** `Arango::CONDITIONS`. Une surcharge ajoute donc un *fragment*, pas un prédicat :

```php
final class PublicTreeController extends TraversalController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        $init[ AQL::FILTER ] = [ ...( $init[ AQL::FILTER ] ?? [] ) , 'vertex.status == @scope' ] ;
        $init[ AQL::BINDS  ] = [ ...( $init[ AQL::BINDS  ] ?? [] ) , 'scope' => 'published' ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
```

Les fragments sont joints par `&&`, et un groupe client compilé garde ses propres parenthèses : un `?filter=` disjonctif rend `FILTER ( a || b ) && vertex.status == @scope` — il ne peut jamais dégrader le périmètre en alternative. Un fragment que vous écrivez vous-même reste votre responsabilité : parenthésez-le s'il contient un `||`.

**Ou compilez votre prédicat par le moteur verrouillé.** `compileVertexPredicate()` est `protected` pour qu'un périmètre exprimable dans le [DSL JSON](../db/filter.md) passe par la même whitelist `AQL::FILTERS` et le même verrou `Field::REQUIRES` que le filtre client, au lieu d'être de l'AQL écrit à la main :

```php
$binds    = $init[ AQL::BINDS ] ?? [] ;
$fragment = $this->compileVertexPredicate( [ 'key' => 'status' , 'val' => 'published' ] , $init[ Arango::AUTHORIZER ] ?? null , $binds ) ;

if ( $fragment === null )
{
    throw new LogicException( 'l\'attribut du périmètre n\'est pas déclaré filtrable' ) ; // erreur de câblage, PAS « pas de périmètre »
}

$init[ AQL::FILTER ] = [ ...( $init[ AQL::FILTER ] ?? [] ) , $fragment ] ;
$init[ AQL::BINDS  ] = $binds ; // fusionnés, jamais écrasés
```

Deux règles à l'appel : les binds sont **fusionnés** dans le tableau que vous passez, donc passez (et reprenez) `$init[ AQL::BINDS ]` ; et un retour `null` signifie que l'attribut n'est pas déclaré filtrable — une erreur de câblage, jamais « pas de périmètre », puisque le lire ainsi fait s'évaporer en silence un périmètre mal déclaré.

**Le périmètre rejoint le `FILTER`, pas le `PRUNE`.** Élaguer sur un périmètre serveur arrêterait la descente au premier sommet hors périmètre et masquerait ses descendants qui, eux, y sont. `?prune=` reste le levier de l'appelant, et reste refusé sur les traversées inbound.

L'autorisateur de requête est posé **avant** le hook (il verrouille la compilation du `?filter=`), donc une surcharge le lit dans `$init` — c'est ce qui permet de compiler un périmètre sous les permissions de l'appelant — et `parent::beforeModelCall()` atteint le no-op du trait. Un `Arango::AUTHORIZER` fourni par l'appelant reste prioritaire.

`afterModelCall( $request , $init , $result )` reçoit les sommets (une liste) ou le sommet unique, et peut les remplacer. Un remplacement qui n'est pas une liste, sur les verbes de liste, dégrade en résultat vide plutôt que de casser le `count` de l'enveloppe.

Un sommet hors périmètre **disparaît** simplement du résultat. Aucun code de statut ne change : `/parent` répond 200 avec un résultat nul, exactement comme pour une racine — un 404 dirait à l'appelant que le sommet existe.

## `ConceptSchemeController`

Expose un thésaurus hiérarchique en [`ConceptScheme`](https://www.w3.org/TR/skos-reference/#schemes) SKOS : son `hasTopConcept` est l'ensemble des **racines** (les concepts sans parent broader), assemblé à la volée depuis le modèle `Documents` sous-jacent. Lecture seule et générique — un seul point d'entrée, donc un simple `GetRoute` suffit (pas de classe de route dédiée).

| Clé d'init | Rôle | Défaut |
|---|---|---|
| `MODEL` | le modèle `Documents` du thésaurus | — |
| `TITLE` | le nom d'affichage du schéma | `''` |
| `RELATION` | la clé de relation broader dont l'**absence** marque une racine | `Oihana::BROADER` |
| `SKIN` | le skin utilisé pour projeter les racines | `Skin::FULL` |

Il honore `?sort` (ex. `id`, `name`, `created`, `modified`), `?search` et `?filter` sur les racines — le modèle applique sa propre whitelist `SORTABLE` / `SEARCHABLE` / `AQL::FILTERS`. Rien n'est persisté.

### Filtrer les racines (`?filter=`)

Le paramètre `?filter=` accepte le [même DSL JSON](../db/filter.md) que la surface `Documents` et restreint les racines. Il est combiné en **ET** avec la contrainte de racine (« n'a pas de parent broader »), qui reste toujours le premier opérande, non négociable :

`?filter={"key":"inScheme","op":"eq","val":"animals"}` → les racines **du schéma `animals`**, soit conceptuellement `FILTER ( <est une racine> ) && ( doc.inScheme == @value )`.

Un groupe `or` client ne peut pas relâcher la portée : le filtre d'URL entre comme opérande **unique**, donc `["or", … ]` garde ses propres parenthèses — on obtient `racine && ( a || b )`, jamais `racine || a || b`. Même invariant que `InjectFilterTrait`.

Deux garde-fous, identiques aux contrôleurs `Documents` :

- **Whitelist** — seuls les attributs déclarés dans le `AQL::FILTERS` du modèle sont filtrables ; tout le reste est écarté (loggé), donc `?filter=` ne peut jamais atteindre un champ non déclaré.
- **Authorizer** — quand la pile d'autorisation est câblée (`CapabilityEnforcerInterface` + `PermissionSubjectResolverInterface` dans le conteneur), l'authorizer de requête verrouille `Field::REQUIRES` : un attribut masqué au demandeur neutralise son prédicat à `false` au lieu de fuir, ce qui ferme l'oracle de filtre. Sans pile, il tombe ouvert (rétro-compatible).

### Périmétrer les racines

`?filter=` est le levier de l'**appelant**. Il ne sait pas dire « masquer les concepts marqués inactifs, sauf si l'appelant détient telle permission » — une règle que le serveur impose et que l'appelant ne doit pas pouvoir élargir.

`ConceptSchemeController` porte le même **siège d'autorisation** que `PropertyController` : son appel à `list()` est encadré par les [hooks de cycle de vie](#hooks-de-cycle-de-vie), et le hook tourne **après** l'intégration du `?filter=`, donc le périmètre a toujours le dernier mot.

La lib fournit le siège, jamais la règle. C'est le consommateur qui fournit le prédicat :

```php
final class PublicThesaurusController extends ConceptSchemeController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        $scope  = [ FilterParam::KEY => 'status' , FilterParam::VAL => 'published' ] ;
        $filter = $init[ Arango::FILTER ] ?? null ;

        // UN seul opérande, jamais épissé : le filtre client garde ses parenthèses.
        $init[ Arango::FILTER ] = $filter === null ? $scope : [ FilterLogic::AND , $scope , $filter ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
```

Deux leviers, tous deux honorés par `list()` :

| Levier | Forme | Quand |
|---|---|---|
| `Arango::FILTER` | un prédicat **structuré** (le même DSL JSON que `?filter=`) | le périmètre est exprimable dans le DSL — il passe alors par la whitelist du modèle et le verrou `Field::REQUIRES` |
| `Arango::CONDITIONS` | des **fragments AQL** bruts (`'doc.x == @x'`) + `Arango::BINDS` | tout ce que le DSL ne sait pas dire — une sous-requête, un `IN @allowed` |

⚠ On enveloppe, on n'épisse pas. `[ FilterLogic::AND , $scope , $filter ]` garde le filtre de l'appelant comme opérande unique ; `[ $scope , ...$filter ]` mettrait son `or` en tête et dégraderait le périmètre en alternative (`scope || a || b`). Même invariant que `InjectFilterTrait`.

L'autorisateur de requête est posé **avant** le hook (il verrouille la compilation du `?filter=`), donc `parent::beforeModelCall()` atteint le no-op du trait : sans effet, et l'autorisateur est déjà en place. Un autorisateur fourni par l'appelant dans `$init` reste prioritaire sur celui que construit le contrôleur.

`afterModelCall( $request , $init , $roots )` reçoit les racines et peut les remplacer. Un remplacement qui n'est pas une liste dégrade en `hasTopConcept` vide plutôt que de casser le `count` de l'enveloppe.

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
