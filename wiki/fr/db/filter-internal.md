# Filtrage interne — `AQL::CONDITIONS` + `AQL::BINDS`

Cette page couvre les **filtres serveur-only** : conditions AQL qui doivent s'appliquer à une requête de modèle, mais qu'on ne veut **pas** exposer à l'URL `?filter=` parce qu'elles touchent un champ sensible, calculé, ou parce qu'elles relèvent de la logique métier interne du contrôleur.

Deux mécanismes complémentaires :

1. **`FilterType::VIRTUAL`** — pour qu'une clé soit acceptée depuis l'URL `?filter=` mais que **le framework n'émette aucune clause AQL** correspondante (à charge du contrôleur d'injecter la vraie condition).
2. **`AQL::CONDITIONS` + `AQL::BINDS`** — pour injecter directement des conditions AQL brutes dans l'appel à `list()`, `count()`, `get()`, etc., sans exposer la moindre clé à l'URL.

Pour le filtrage **exposé à l'URL** (whitelisté via `AQL::FILTERS`), voir [Filtres HTTP `?filter=`](filter.md).

## `FilterType::VIRTUAL` — clé URL sans clause AQL

### Quand l'utiliser

`FilterType::VIRTUAL` est utile quand on veut qu'un client puisse écrire `?filter={"key":"current","val":true}` dans l'URL — mais que la décision de ce qu'est « current » se prenne côté serveur, sans exposer le champ technique sous-jacent.

Cas typiques :

- **Champ calculé à la sortie** : `current` sur `/me/sessions`, dérivé du `tokenHash` de la requête courante (pas un champ persisté).
- **Champ sensible** : on veut filtrer sur un hash ou un token sans que le hash apparaisse jamais dans la requête de l'utilisateur.

### Pattern

Déclarer la clé en `FilterType::VIRTUAL` dans `AQL::FILTERS` du modèle. Le framework accepte la clé dans `?filter=`, la conserve dans la réponse (champ `url`), mais **n'émet aucune clause AQL**. Le contrôleur surcharge `beforeModelCall()` pour injecter la vraie condition via `AQL::CONDITIONS` + `AQL::BINDS`.

```php
// DI du modèle MeSessions
AQL::FILTERS =>
[
    // ... autres clés exposées
    Prop::CURRENT => FilterType::VIRTUAL ,
]

// MeSessionsController::beforeModelCall
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    parent::beforeModelCall( $request , $init ) ;

    if ( $this->isCurrentFilterRequested( $init ) )
    {
        $hash  = $this->extractTokenHashFromRequest( $request ) ;
        $binds = $init[ AQL::BINDS ] ?? [] ;

        $init[ AQL::CONDITIONS ][] = equal
        (
            key     ( Session::TOKEN_HASH , AQL::DOC )      ,
            aqlBind ( $hash , $binds , 'meSessionsCurrentHash' )
        ) ;

        $init[ AQL::BINDS ] = $binds ;
    }
}
```

Côté client, la réponse JSON contient toujours `"url": "https://.../me/sessions?filter=current:true"` — la traçabilité est préservée. Mais aucun `tokenHash` ne transite jamais dans la requête HTTP.

### Avantages

- **Confidentialité** : le champ technique (`tokenHash`) reste invisible côté client.
- **Traçabilité** : l'URL reste lisible et reproductible pour le client.
- **Découpage propre** : le modèle déclare l'intention (« cette clé est filtrable »), le contrôleur fournit la sémantique concrète.

## `AQL::CONDITIONS` + `AQL::BINDS` — conditions serveur uniquement

### Quand l'utiliser

Pour toute condition qui **ne doit pas être filtrable depuis l'URL** : code interne, champ sensible, condition complexe, restriction d'audience selon le contexte de la requête.

Trois cas typiques :

1. **Restriction par utilisateur** : un endpoint `/me/orders` filtre forcément sur `doc.userId == @currentUserId`. Le client ne doit même pas pouvoir essayer d'écrire `?filter={"key":"userId","val":"..."}`.
2. **Champ jamais exposé** : filtrer sur `doc.deletedAt == null` pour exclure les *soft-deletes*, sans déclarer `deletedAt` dans `AQL::FILTERS`.
3. **Condition complexe** : sous-requête, comparaison multi-champ, prédicat custom.

### Pattern

Injecter directement les conditions AQL et les *bind variables* dans l'appel au modèle :

```php
use oihana\arango\enums\AQL ;
use oihana\arango\enums\Boolean ;
use function oihana\arango\db\binds\aqlBind ;
use function oihana\arango\db\helpers\functions\key ;
use function oihana\arango\db\operators\equal ;

$binds = [] ;

$sessions = $this->model->list
([
    AQL::CONDITIONS =>
    [
        equal( key( Session::USER_ID , AQL::DOC ) , aqlBind( $userKey , $binds , 'sessionUserId' ) ) ,
        equal( key( Schema::ACTIVE   , AQL::DOC ) , Boolean::TRUE                                  ) ,
    ] ,
    AQL::BINDS => $binds ,
]) ;
```

Les conditions injectées sont ajoutées au `FILTER` AQL généré, **après** les conditions issues de `?filter=`. Les *bind variables* sont fusionnées avec celles auto-générées par le pipeline `FilterTrait`.

### Sucre syntaxique — `isProperty()` / `isAdditionalType()`

L'idiome `equal( key( ... ) , aqlValue( ... ) )` ci-dessus revient assez souvent pour que deux helpers de `oihana\arango\helpers\conditions` ([`src/oihana/arango/helpers/conditions/`](../../../src/oihana/arango/helpers/conditions/)) le condensent en un seul appel — un tableau prêt à être *spread* dans `AQL::CONDITIONS`.

```php
function isProperty( string $property , null|string|array $value , string $docRef = AQL::DOC ) : array
```

Construit un tableau à une condition : un `IN` quand `$value` est un tableau, un `==` sinon. La partie droite passe par `aqlValue()` : une valeur scalaire littérale est donc échappée/quotée, tandis qu'un *bind* (`'@var'`) ou la référence à un autre document (`'doc2.name'`) reste brute.

```php
use function oihana\arango\helpers\conditions\isProperty ;

isProperty( 'status' , 'active' )                 ; // [ "doc.status == 'active'" ]
isProperty( 'status' , [ 'active' , 'pending' ] )  ; // [ "doc.status IN ['active','pending']" ]
isProperty( 'tags'   , '@tags' )                   ; // [ 'doc.tags == @tags' ]
isProperty( 'name'   , 'doc2.name' )               ; // [ 'doc.name == doc2.name' ]
isProperty( 'status' , 'active' , 'other' )        ; // [ "other.status == 'active'" ]
```

Lève `InvalidArgumentException` sur une valeur `null`, `''` ou `[]`, et — par transitivité via `aqlValue()` — `UnsupportedOperationException` sur un type PHP non supporté.

```php
function isAdditionalType( array|string $schemaType , string $docRef = AQL::DOC ) : array
```

Un simple habillage de `isProperty()` centré sur `Schema::ADDITIONAL_TYPE` (`additionalType`) — pratique pour filtrer une collection polymorphe par type schema.org.

```php
use function oihana\arango\helpers\conditions\isAdditionalType ;

isAdditionalType( 'Person' )                     ; // [ "doc.additionalType == 'Person'" ]
isAdditionalType( [ 'Person' , 'Organization' ] ) ; // [ "doc.additionalType IN ['Person','Organization']" ]
```

> **Ce n'est pas un bind.** Contrairement à `aqlBind()`, ces deux helpers injectent la valeur via `aqlValue()` directement — approprié pour une valeur **connue et de confiance** (un littéral d'enum, une liste de types pilotée par la config, le champ d'un autre document), jamais pour une entrée client non validée. Une valeur venant de la requête continue de passer par `aqlBind()` comme montré ci-dessus.

Les deux renvoient un tableau, donc s'insèrent directement par *spread* dans `AQL::CONDITIONS` :

```php
$init[ AQL::CONDITIONS ] =
[
    ...isAdditionalType( [ 'Person' , 'Organization' ] ) ,
    ...isProperty( Schema::ACTIVE , 'true' ) ,
] ;
```

### `isSchemaType()` — depuis une classe `Thing` plutôt qu'un littéral

`isAdditionalType()` ci-dessus prend le type schema.org sous forme de **littéral chaîne** (`'Person'`), qu'il faut recopier à la main et maintenir synchronisé avec la classe `org\schema\Thing` correspondante. `isSchemaType()` — même dossier, `oihana\arango\helpers\conditions` — prend la **classe** elle-même à la place et résout son URI de type canonique via `Thing::getSchemaType()` (la constante `CONTEXT` de la classe plus son nom court), avant de déléguer à `isAdditionalType()` :

```php
function isSchemaType( string|array $class , string $docRef = AQL::DOC ) : array
```

```php
use function oihana\arango\helpers\conditions\isSchemaType ;
use xyz\oihana\schema\organizations\Customer ;
use xyz\oihana\schema\places\CustomerSite ;
use xyz\oihana\schema\places\Warehouse ;

isSchemaType( Customer::class )                            ; // [ "doc.additionalType == 'https://schema.oihana.xyz/Customer'" ]
isSchemaType( [ CustomerSite::class , Warehouse::class ] )  ; // [ "doc.additionalType IN ['https://schema.oihana.xyz/CustomerSite','https://schema.oihana.xyz/Warehouse']" ]
```

Une classe unique s'aplatit en condition `==`, plusieurs classes construisent un `IN` — exactement la règle d'`isAdditionalType()`, héritée gratuitement. Accepte `Thing` lui-même ou n'importe laquelle de ses sous-classes ; toute autre classe lève `InvalidArgumentException` — une seule classe invalide parmi plusieurs suffit à rejeter tout l'appel, rien n'est construit partiellement.

> **Pourquoi pas juste une chaîne ?** Un renommage de sous-classe `Thing` ou un changement de `CONTEXT` (un type qui passe de `schema.org` au namespace propre du projet `xyz.oihana`) est répercuté automatiquement partout où `isSchemaType()` est utilisé — un littéral `'Person'` recopié à la main dériverait silencieusement.

### Composition avec `?filter=`

Les deux mécanismes cohabitent sans conflit :

```php
// Le contrôleur expose ?filter=, mais force un userId côté serveur
public function list( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
{
    $userKey = $this->getCurrentUserKey( $request ) ;
    $binds   = [] ;

    $init[ AQL::CONDITIONS ][] = equal
    (
        key( 'userId' , AQL::DOC ) ,
        aqlBind( $userKey , $binds , 'currentUserId' )
    ) ;
    $init[ AQL::BINDS ] = $binds ;

    return parent::list( $request , $response , $args , $init ) ;
}
```

Le client peut continuer à utiliser `?filter={"key":"created","val":"2026-01-01","op":"ge"}` ; le contrôleur ajoute silencieusement `&& doc.userId == @currentUserId`.

Le `InjectFilterTrait` (cf. [Contrôleurs Slim](../controllers/README.md)) fournit un sucre syntaxique pour ce cas exact.

## Règle de décision URL vs interne

| Besoin | Mécanisme |
|---|---|
| Le client doit pouvoir filtrer librement sur ce champ | `AQL::FILTERS` + `?filter=` (cf. [filter.md](filter.md)) |
| Le client passe une clé `?filter=`, le serveur traduit en vraie condition cachée | `FilterType::VIRTUAL` + `AQL::CONDITIONS` |
| Le serveur impose une condition jamais visible côté client | `AQL::CONDITIONS` + `AQL::BINDS` |
| Le serveur veut conserver la lisibilité de la condition côté client mais sans exposer le champ technique | `FilterType::VIRTUAL` |

## Anti-patterns

### Concaténer une valeur PHP dans une condition AQL

```php
// JAMAIS — risque d'injection AQL
$init[ AQL::CONDITIONS ][] = "doc.userId == '$userKey'" ;
```

Toujours passer par `aqlBind()` :

```php
$init[ AQL::CONDITIONS ][] = equal
(
    key( 'userId' , AQL::DOC ) ,
    aqlBind( $userKey , $binds , 'currentUserId' )
) ;
$init[ AQL::BINDS ] = $binds ;
```

### Exposer un champ sensible via `AQL::FILTERS`

```php
// Mauvais — le client peut maintenant filtrer sur le hash
AQL::FILTERS =>
[
    Prop::TOKEN_HASH => FilterType::STRING ,
]
```

Pour les champs sensibles, utiliser `FilterType::VIRTUAL` sur une clé sémantique (`current`) et masquer le champ technique (`tokenHash`).

### Oublier de fusionner `$binds`

```php
// Mauvais — $binds modifié par aqlBind() n'est pas re-affecté à $init
$init[ AQL::CONDITIONS ][] = equal( ... , aqlBind( ... , $binds , ... ) ) ;
// $init[ AQL::BINDS ] manquant ici
```

Toujours réassigner `$init[ AQL::BINDS ] = $binds` après une série d'appels à `aqlBind()`.

## Voir aussi

- [Filtres HTTP `?filter=`](filter.md) — système exposé à l'URL, complémentaire à cette page.
- [Modèles `Documents` et `Edges`](../models.md) — déclaration `AQL::FILTERS`.
- [Bind variables `db/binds/`](binds.md) — `aqlBind()` et la convention d'injection sûre.
- [Helpers AQL `db/helpers/`](helpers.md) — `key()`, `equal()` et compagnie pour composer les conditions.
- [Helpers `oihana\arango\helpers`](../helpers.md) — le catalogue parent de `oihana\arango\helpers` (`isProperty()` / `isAdditionalType()` vivent dans son sous-dossier `conditions/`).
- [Contrôleurs Slim](../controllers/README.md) — `InjectFilterTrait`, hooks `beforeModelCall`.
