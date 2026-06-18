# Recherche fédérée multi-collections — `FederatedSearch`

Une **seule barre de recherche** qui cherche dans **plusieurs collections à la fois** (clients, produits, vendeurs, lieux…) et renvoie **une liste unique classée par pertinence** — sans que l'utilisateur ait à choisir où chercher.

Exemple : l'utilisateur tape « dupont » et voit, mélangés et triés du plus pertinent au moins pertinent, le **client** « Dupont SARL », le **produit** « Colle Dupont », le **vendeur** « Jean Dupont » et le **lieu** « Entrepôt Dupont ».

## Le problème, et comment on le résout

Il y a **deux difficultés distinctes** :

1. **Chercher dans plusieurs collections d'un coup** — c'est la mécanique, réglée par le substrat [`search-alias`](../clients/arangosearch.md) : une vue qui agrège **un index inversé par collection** et qu'on interroge en une seule requête.
2. **Reconstituer des résultats de formes différentes** — c'est le cœur du sujet. Un client, un produit, un lieu n'ont **pas les mêmes champs**, ni les mêmes données liées, ni les mêmes règles d'affichage ou de permission. On ne peut pas tous les afficher pareil : il faut **reconstruire chaque résultat avec la logique propre à son modèle**.

L'approche, comme un **bibliothécaire** : il vous donne d'abord une **liste classée de cotes** (pas les livres), puis on va chercher chaque livre **à son emplacement**, avec sa fiche complète. En deux temps :

- **Temps 1 — trouver** : une seule recherche sur la vue `search-alias` renvoie, pour chaque correspondance, **sa collection d'origine, son identifiant et son score** (BM25), classés et **paginés**. Pas les documents complets.
- **Temps 2 — reconstruire** : les identifiants sont regroupés par collection et **chaque collection est reconstruite en un seul appel** à son modèle (`list()` avec un filtre `_key IN […]`), en réutilisant tout son pipeline (champs, joins, skins, permissions). Les documents sont ensuite remis **dans l'ordre du score**.

Intérêt majeur : on **réutilise la machinerie déjà écrite** de chaque modèle au lieu de la réinventer dans une méga-requête ingérable.

## Configuration

`FederatedSearch` est un service autonome, *container-aware* — pas un modèle (il ne possède aucune collection).

```php
use oihana\arango\search\FederatedSearch ;
use oihana\arango\search\enums\FederatedSearchParam ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\Search ;

$engine = new FederatedSearch( $container ,
[
    // la vue search-alias à interroger
    FederatedSearchParam::VIEW => 'global_search' ,

    // ce qu'on cherche (champs + analyzer), appliqué uniformément
    FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' , 'label' ] , Search::ANALYZER => 'text_fr' ] ,

    // l'annuaire : quel modèle reconstruit quelle collection
    FederatedSearchParam::MODELS =>
    [
        'customers' => 'model.customers' ,
        'products'  => 'model.products' ,
        'sellers'   => 'model.sellers' ,
    ] ,

    // (facultatif) la permission exigée par collection — voir § Permissions
    FederatedSearchParam::REQUIRES =>
    [
        'customers' => 'customers:list' ,
        'sellers'   => 'sellers:list' ,
        // 'products' n'est pas listé → public
    ] ,

    // (facultatif) le skin par défaut de reconstruction (défaut : Skin::DEFAULT)
    FederatedSearchParam::SKIN => 'search' ,

    // la base (instance ArangoDB ou son id dans le container)
    Arango::DATABASE => $arango ,
]) ;
```

## Lancer une recherche

```php
$resultats = $engine->search(
[
    Arango::SEARCH => 'dupont' ,   // le terme (lié, jamais collé dans la requête)
    Arango::LIMIT  => 20 ,         // taille de page (défaut : 25)
    Arango::OFFSET => 0 ,          // décalage de page
    Arango::SKIN   => 'search' ,   // (facultatif) écrase le skin du moteur
] ) ;

$total = $engine->foundRows() ;    // nombre total de correspondances, pour « X résultats, page Y »
```

Chaque résultat est une **enveloppe** qui sépare la provenance, le score et le document reconstruit :

```php
[
  [ 'collection' => 'customers' , 'score' => 9.1 , 'document' => /* le client reconstruit */ ] ,
  [ 'collection' => 'products'  , 'score' => 7.4 , 'document' => /* le produit reconstruit */ ] ,
  [ 'collection' => 'customers' , 'score' => 5.2 , 'document' => /* un autre client       */ ] ,
]
```

Les documents n'ont **pas la même forme** d'une collection à l'autre — c'est voulu : chacun est reconstruit par son propre modèle.

### Pagination et total

La **pagination est faite au temps 1** : le `LIMIT` s'applique **une fois**, au classement global de toutes les collections. Le temps 2 ne reconstruit donc **que la page** demandée — jamais des tas de résultats. Le **total** (avant le `LIMIT`) est calculé en même temps (option `fullCount`) et exposé par `foundRows()`.

### Skin

Le skin choisit **quels champs** chaque modèle renvoie pour un résultat de recherche. La résolution est, par priorité : le `?skin=` de la requête (`Arango::SKIN`) → le skin configuré sur le moteur (`FederatedSearchParam::SKIN`) → `Skin::DEFAULT`. Le même nom de skin est passé à chaque modèle, qui décide ce que *son* skin projette. Pour des fiches de résultats dédiées, déclarez un skin `Skin::SEARCH` sur les champs voulus de vos modèles et configurez le moteur avec.

## Permissions — un filtre par collection

Tout le monde ne doit pas voir toutes les collections : certaines sont sensibles (clients, vendeurs), d'autres publiques (produits, lieux). `FederatedSearch` ajoute un **garde-barrière par collection** : avant d'inclure une collection, il vérifie que l'utilisateur a le droit de la chercher. Sinon, **toute la collection est écartée** — ses documents n'apparaissent pas, ne sont pas reconstruits, et ne sont **pas comptés** dans le total.

Le filtre est appliqué **dès la recherche** (via `OPTIONS { collections }` sur la vue), donc pagination et total restent exacts.

### Déclarer la permission exigée par chaque collection

Chaque collection déclare **le ou les `subject` de permission** qu'elle exige — exactement comme `Field::REQUIRES` pour un champ. Ce sont **vos** subjects (ceux de vos seeds / vos routes), pas une convention imposée par le moteur :

```php
FederatedSearchParam::REQUIRES =>
[
    'customers' => 'customers:list' ,                     // un seul subject
    'users'     => [ 'users:list' , 'users:admin' ] ,     // une liste = OR (l'un OU l'autre suffit)
    // une collection absente de cette map est PUBLIQUE : cherchable par tous
]
```

### Fournir le décideur de droits (authorizer)

À chaque requête, on passe un **authorizer** : une fonction `fn(string $subject): bool` qui, pour un subject donné, dit si l'utilisateur courant l'a. C'est le même mécanisme que partout dans la lib (`isAuthorized()` / `Arango::AUTHORIZER`) — branchez-le sur votre enforcer (Casbin, etc.).

```php
// un commercial : a le droit de lister les clients, pas les vendeurs
$authorizer = fn( string $subject ) : bool => in_array( $subject , [ 'customers:list' ] , true ) ;

$resultats = $engine->search(
[
    Arango::SEARCH     => 'dupont' ,
    Arango::AUTHORIZER => $authorizer ,
] ) ;
```

Déroulé, collection par collection :

```
isAuthorized( 'customers:list' )         → oui  → on garde les clients
products n'exige rien (public)           →      → on garde les produits
isAuthorized( 'sellers:list' )           → non  → on écarte les vendeurs

collections autorisées = [ customers , products ]
→ la recherche ne porte QUE sur ces collections ; les vendeurs n'apparaissent jamais.
```

Un **visiteur anonyme** (mêmes données, autre authorizer) :

```php
$authorizer = fn( string $s ) => in_array( $s , [ 'products:list' ] , true ) ;
// → ne voit que les produits (et toute collection publique). Ni clients, ni vendeurs.
```

### Règles

- **Collection sans `REQUIRES` déclaré** → publique, cherchable par tous.
- **Aucun authorizer fourni** → tout est autorisé (*fail-open*, cohérent avec le reste de la lib).
- **Liste de subjects** → sémantique **OR** (un seul suffit).
- **Aucune collection autorisée** → résultat vide, total 0.
- Les **permissions par champ** (tel champ caché à tel utilisateur) restent assurées **par chaque modèle** au moment de la reconstruction — ce filtre-ci ne gère que le niveau « collection entière ».

## Exposer en HTTP

Le moteur s'utilise tel quel en PHP. Pour le brancher derrière **une URL**, la lib fournit un **triplet read-only** calqué sur celui des documents (`route → contrôleur → modèle`), mais où le contrôleur tient un `FederatedSearch` au lieu d'un modèle de collection unique :

- [`FederatedSearchController`](../../../src/oihana/arango/controllers/FederatedSearchController.php) — la **prise HTTP** : il traduit la requête en `$init`, branche les permissions, appelle le moteur et renvoie le JSON. Une **seule action** read-only, `search()`.
- [`SearchRoute`](../../../src/oihana/arango/routes/SearchRoute.php) — déclare la route `GET` liée à l'action `search`.

### La requête

```
GET /search?search=dupont&limit=25&offset=0&skin=compact
```

```json
{
  "status": "success",
  "url": "/search?search=dupont&limit=25&offset=0&skin=compact",
  "count": 3,
  "total": 47,
  "result": [
    { "collection": "customers", "score": 4.2, "document": { "_key": "…", "name": "Dupont SARL" } },
    { "collection": "products",  "score": 3.1, "document": { "_key": "…", "name": "Colle Dupont" } },
    { "collection": "sellers",   "score": 2.8, "document": { "_key": "…", "name": "Jean Dupont" } }
  ]
}
```

`search` / `limit` / `offset` / `skin` sont lus dans la *query string* ; `total` (via `foundRows()`) accompagne la page pour afficher « X résultats, page Y ».

### Câblage DI

Le moteur est déclaré **une fois** comme service, puis référencé par son id dans le contrôleur, lui-même référencé par son id dans la route :

```php
use oihana\arango\controllers\FederatedSearchController ;
use oihana\arango\routes\SearchRoute ;
use oihana\arango\search\FederatedSearch ;
use oihana\routes\Route ;

// definitions/services.php — le moteur (cf. § Configuration)
'search.engine' => fn( Container $c ) => new FederatedSearch( $c, [ /* VIEW, SEARCHABLE, MODELS, REQUIRES, DATABASE … */ ] ) ,

// definitions/controllers.php
'search.controller' => fn( Container $c ) => new FederatedSearchController( $c,
[
    FederatedSearchController::ENGINE => 'search.engine' , // id du service moteur (ou une instance)
]) ,

// definitions/routes.php
'search.route' => fn( Container $c ) => new SearchRoute( $c,
[
    Route::CONTROLLER_ID => 'search.controller' ,
    Route::ROUTE         => '/search' ,
]) ,
```

### Et les permissions ?

**Rien de neuf à brancher.** Exactement comme `DocumentsController`, le contrôleur résout l'enforcer (Casbin…) et le résolveur de subjects depuis le container, construit à partir de la requête un authorizer `fn(string $subject): bool` et le **pose sous `Arango::AUTHORIZER`** dans l'`$init` du moteur. Le moteur applique alors son filtre par collection (voir § Permissions) tout seul. Sans enforcer (tests, CLI, auth désactivée) l'authorizer est `null` et le garde-barrière s'ouvre (*fail-open*) — comportement inchangé. Le contrôle d'accès par *query-param* (droit de chercher / d'utiliser tel skin) est réutilisé tel quel depuis le socle des contrôleurs documents.

## Voir aussi

- [Vues `search-alias`](../clients/arangosearch.md) — le substrat (un index inversé par collection, fédérable).
- [Recherche View (ArangoSearch)](search-views.md) — la recherche scorée par modèle.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — le builder de requête scorée réutilisé par le temps 1.
