# Recherche fédérée multi-collections — `FederatedSearch`

Une **seule barre de recherche** qui cherche dans **plusieurs collections à la fois** (clients, produits, vendeurs, lieux…) et renvoie **une liste unique classée par pertinence** — sans que l'utilisateur ait à choisir où chercher.

Exemple : l'utilisateur tape « dupont » et voit, mélangés et triés du plus pertinent au moins pertinent, le **client** « Dupont SARL », le **produit** « Colle Dupont », le **vendeur** « Jean Dupont » et le **lieu** « Entrepôt Dupont ».

## Le problème, et comment on le résout

Il y a **deux difficultés distinctes** :

1. **Chercher dans plusieurs collections d'un coup** — c'est la mécanique, réglée par le substrat [`search-alias`](../../clients/arangosearch.md) : une vue qui agrège **un index inversé par collection** et qu'on interroge en une seule requête.
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

### Collections polymorphes — routage par `additionalType`

Parfois une seule collection contient des documents de **plusieurs types**,
chacun reconstruit par un modèle différent — ex. `organizations` servie par un
modèle `Customer`, un `Provider` et un `Subsidiary`, sans modèle générique. Une
valeur de `MODELS` peut alors être une spec **composite** au lieu d'un simple
service-id, et router chaque résultat selon un champ discriminant (`additionalType`
par défaut) :

```php
FederatedSearchParam::MODELS =>
[
    'products'      => 'model.products' , // direct : un seul modèle pour toute la collection

    'organizations' =>                    // composite : routé par type
    [
        FederatedSearchParam::DISCRIMINATOR => 'additionalType' , // facultatif (c'est le défaut)
        FederatedSearchParam::MAP =>
        [
            'https://schema.org/Customer'   => 'model.customers' ,
            'https://schema.org/Provider'   => 'model.providers' ,
            'https://schema.org/Subsidiary' => 'model.subsidiaries' ,
        ] ,
        FederatedSearchParam::FALLBACK => 'model.organizations' , // facultatif ; absent → un type non mappé est ignoré
    ] ,
]
```

À la reconstruction, le moteur lit le discriminateur de chaque clé matchée en
**une requête légère** (`FOR d IN organizations FILTER d._key IN @keys RETURN { _key, additionalType }`)
— sans `storedValues` d'index inversé, et sans rien changer à la recherche —
range les clés par modèle résolu, puis reconstruit chaque paquet via son propre
modèle, en préservant l'ordre de score et le total.

- **Priorité** — un document peut porter **plusieurs** types (`additionalType` en
  tableau) ; la table est parcourue dans son **ordre de déclaration**, donc le
  premier type listé que le document possède gagne, de façon déterministe,
  indépendamment de l'ordre du document.
- **Repli (`FALLBACK`)** — un type non mappé utilise le repli s'il est présent,
  sinon le résultat est ignoré (il n'atteint jamais un mauvais modèle).
- **Rétro-compatible** — une entrée directe `collection => 'model.id'` est
  inchangée.
- **Permissions** : applicables au niveau **collection** et, pour une collection
  polymorphe, **par type** — voir § Permissions par type.

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

## Permissions par type dans une collection polymorphe

Le garde ci-dessus est tout-ou-rien **par collection**. Or une [collection polymorphe](#collections-polymorphes--routage-par-additionaltype) — `organizations` contenant des `Customer`, `Provider`, `Subsidiary` — demande parfois une règle plus fine : *cet utilisateur voit les `Customer` mais **pas** les `Provider`, à l'intérieur de la même collection `organizations`.*

Imaginez **deux portes** : la collection est la porte de l'immeuble (niveau 1), chaque type est une porte d'étage (niveau 2). Pour voir un document, il faut passer **les deux** — d'abord la collection, puis son type. C'est une **cascade (ET)** : le garde collection est le socle partout, le garde par type est un raffinement **optionnel** sur les collections polymorphes.

### Le déclarer

Une valeur de `REQUIRES` accepte une troisième forme, **structurée** (les formes courtes — texte et liste-OU au niveau collection — sont inchangées) :

```php
FederatedSearchParam::REQUIRES =>
[
    'customers'     => 'customers:list' ,                  // forme 1 : un subject (niveau collection)
    'users'         => [ 'users:list' , 'users:admin' ] ,  // forme 2 : une liste-OU (niveau collection)

    'organizations' =>                                     // forme 3 : la cascade
    [
        FederatedSearchParam::COLLECTION => 'org:list' ,   // niveau 1 : entrer dans la collection
        FederatedSearchParam::MAP =>                       // niveau 2 : par type
        [
            'Customer' => 'cust:list' ,
            'Provider' => [ 'prov:list' , 'prov:admin' ] , // un subject ou une liste-OU
        ] ,
        FederatedSearchParam::FALLBACK => 'org:list' ,     // niveau 2 : les types non listés
    ] ,
]
```

Trois cases :

| Case | Niveau | Accepte | Sens |
|---|---|---|---|
| `COLLECTION` | 1 | subject \| liste-OU \| absent | le droit d'**entrer** dans la collection. Absent → la collection elle-même est publique (ses types peuvent rester gardés). |
| `MAP` | 2 | `type => (subject \| liste-OU)` | le droit exigé pour chaque type **listé**. |
| `FALLBACK` | 2 | absent \| subject \| liste-OU \| `true` | gouverne les types **non listés**. |

Les quatre états de `FALLBACK` — un type **non listé** est… :

| `FALLBACK` | Résultat |
|---|---|
| absent / `null` | **caché** (*fail-closed* — le défaut strict) |
| `'org:list'` | exige ce subject |
| `[ 'org:list' , 'org:admin' ]` | liste-OU (un seul suffit) |
| `true` | **visible** (la porte collection suffit — « permissif ») |

Le champ discriminant est **réutilisé** depuis l'entrée composite de `MODELS` (`additionalType` par défaut) — jamais redéclaré ici. La permission par type ne s'applique donc qu'à une collection déjà déclarée composite dans `MODELS`.

#### Omettre `COLLECTION` — une collection publique, filtrée par type

`COLLECTION` est **optionnel**. On l'omet quand tout le monde peut *chercher* dans la collection mais que chaque **type** à l'intérieur reste réservé — la porte de niveau 1 reste ouverte, seules les portes de niveau 2 (par type) filtrent les documents :

```php
'organizations' =>
[
    // pas de COLLECTION → entrer dans la collection est public (niveau 1 ouvert)
    FederatedSearchParam::MAP =>
    [
        'Customer' => 'cust:list' ,
        'Provider' => [ 'prov:list' , 'prov:admin' ] ,
    ] ,
    FederatedSearchParam::FALLBACK => 'org:list' ,  // les types non listés
] ,
```

Tout le monde peut chercher dans `organizations`, mais ne voit un `Customer` qu'avec `cust:list`, un `Provider` qu'avec `prov:list` **ou** `prov:admin`, et tout autre type qu'avec `org:list`. Le seul cas interdit est une entrée structurée qui ne filtre **rien** (ni `COLLECTION`, ni `MAP`, ni `FALLBACK`) : elle équivaut à une collection entièrement publique et est **supprimée** à la construction — autant ne pas la déclarer. À noter : sans `COLLECTION`, le moteur ne peut plus écarter la collection entière en amont ; il pèse alors chaque type (sans danger, mais un cran moins efficace). Garde `COLLECTION` quand l'entrée dans la collection doit déjà exiger une permission.

### Pré-requis — indexer le discriminateur

Le garde par type filtre sur le discriminateur **dans la recherche** : le champ doit donc être dans l'index inversé de la collection, avec l'analyzer `identity`. La seule subtilité est sa **forme** :

```php
// une collection dont additionalType est un TEXTE
new InvertedIndex( fields: [ 'name', 'additionalType' ] , analyzer: 'identity' )

// une collection dont additionalType est un TABLEAU
new InvertedIndex( fields: [ 'name', 'additionalType[*]' ] , analyzer: 'identity' )
```

Une collection est cohérente (une seule forme), donc on en choisit une. Le filtre de la bibliothèque est **identique** dans les deux cas (`doc.additionalType IN (…)`) — seule la déclaration de l'index change, et aucun `storeValues` n'est nécessaire.

> **Pourquoi la forme compte.** Un champ d'index inversé sur un tableau exige l'expansion `[*]` ; sur un texte, non (et `[*]` n'indexerait pas un texte). ArangoDB ne peut pas indexer un champ « tantôt texte, tantôt tableau » — d'où une seule forme par collection.

### Comment ça marche, de bout en bout

Supposons que la vue contienne ces documents, que l'utilisateur cherche `dupont`, et qu'il puisse voir les `Customer` mais **pas** les `Provider` :

| document | collection | `additionalType` |
|---|---|---|
| c1 | customers | *(aucun)* |
| o1 | organizations | `["Customer"]` |
| o2 | organizations | `["Provider"]` |
| o3 | organizations | `["Provider","Customer"]` |

Configuration + requête :

```php
FederatedSearchParam::REQUIRES =>
[
    'organizations' =>
    [
        FederatedSearchParam::MAP => [ 'Customer' => 'cust:list' , 'Provider' => 'prov:list' ] ,
        // pas de FALLBACK → strict : types non listés cachés
    ] ,
] ,

$engine->search([ Arango::SEARCH => 'dupont' , Arango::AUTHORIZER => fn( $s ) => $s === 'cust:list' ]);
```

Le moteur ajoute (ET) un prédicat de type à la recherche (**avant le `LIMIT`**, donc le total reste exact). Le discriminateur est comparé sous l'analyzer `identity` ; un document d'une autre collection n'a pas `additionalType` indexé, il passe donc sans être touché (absence de champ) :

```aql
SEARCH ANALYZER(doc.name IN TOKENS(@search,"text_fr"),"text_fr")
       && ( ANALYZER(doc.additionalType IN ["Customer"],"identity") || ! EXISTS(doc.additionalType) )
```

Résultat :

| document | gardé ? | pourquoi |
|---|---|---|
| c1 (customers) | ✅ | autre collection — pas de `additionalType` indexé |
| o1 `["Customer"]` | ✅ | type autorisé |
| o2 `["Provider"]` | ❌ | type refusé |
| o3 `["Provider","Customer"]` | ✅ | porte un type autorisé (`Customer`) |

### Les deux modes

Le même registre produit deux formes de prédicat, choisies par collection selon les droits de la requête :

- **permissif** — `FALLBACK => true` (ou un fallback accordé) : les types non listés restent visibles, seuls les refusés sont cachés — `! ANALYZER(doc.additionalType IN @denied,"identity")`.
- **strict** — pas de `FALLBACK` (le défaut) : seuls les types autorisés sont visibles, plus les documents sans discriminateur — `( ANALYZER(doc.additionalType IN @allowed,"identity") || ! EXISTS(doc.additionalType) )`.

Un document **multi-type** (un tableau portant plusieurs types) est comparé élément par élément : il est **visible en strict** dès qu'il porte un type autorisé, et **caché en permissif** dès qu'il porte un type refusé.

### Règles

- **Cascade** — la porte `COLLECTION` décide si la collection est cherchée du tout ; le garde par type affine ensuite. Les deux s'appliquent **avant le `LIMIT`**, donc page et `foundRows()` restent exacts.
- **Documents sans type** (un document polymorphe sans aucun `additionalType`) suivent la forme de l'index en mode strict : sur un index `additionalType[*]` ils sont **cachés** (*fail-closed*) ; sur un index `additionalType` simple ils restent visibles. Une collection polymorphe bien remplie porte toujours un type — c'est un cas limite.
- **Défense en profondeur** — la même règle de niveau 2 est ré-appliquée à la reconstruction, donc un type refusé n'atteint jamais un modèle même si `rebuild()` est appelé seul.
- **fail-open** — sans authorizer, tout est autorisé, comme partout dans la bibliothèque.
- **Discriminateur texte** — tout ce qui précède marche à l'identique quand `additionalType` est un simple texte ; seul l'index se déclare `additionalType` au lieu de `additionalType[*]`.

## Exposer en HTTP

Le moteur s'utilise tel quel en PHP. Pour le brancher derrière **une URL**, la lib fournit un **triplet read-only** calqué sur celui des documents (`route → contrôleur → modèle`), mais où le contrôleur tient un `FederatedSearch` au lieu d'un modèle de collection unique :

- [`FederatedSearchController`](../../../../src/oihana/arango/controllers/FederatedSearchController.php) — la **prise HTTP** : il traduit la requête en `$init`, branche les permissions, appelle le moteur et renvoie le JSON. Une **seule action** read-only, `search()`.
- [`SearchRoute`](../../../../src/oihana/arango/routes/SearchRoute.php) — déclare la route `GET` liée à l'action `search`.

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

- [Vues `search-alias`](../../clients/arangosearch.md) — le substrat (un index inversé par collection, fédérable).
- [Recherche View (ArangoSearch)](overview.md) — la recherche scorée par modèle.
- [`aqlScoredSearch()`](../../aql/aql-operations.md) — le builder de requête scorée réutilisé par le temps 1.
