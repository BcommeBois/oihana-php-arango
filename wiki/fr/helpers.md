# Helpers `oihana\arango\helpers`

Petits utilitaires fonctionnels (une fonction = un fichier) qui vivent
sous [`src/oihana/arango/helpers/`](../../src/oihana/arango/helpers/).

À ne pas confondre avec [`oihana\arango\db\helpers`](../../src/oihana/arango/db/helpers/)
ni [`oihana\arango\db\operations`](../../src/oihana/arango/db/operations/),
qui produisent l'**output AQL** (`"FOR doc IN ..."`, `"doc.name DESC"`, etc.).
Les helpers de cette page travaillent côté **entrée** : grammaire textuelle
HTTP, identifiants de documents, formats de révision.

## Table des matières

- [Grammaire de tri textuelle](#grammaire-de-tri-textuelle)
  - [`ascKey()`](#asckey)
  - [`descKey()`](#desckey)
  - [`sortKeys()`](#sortkeys)
  - [Anti-pattern : magic strings](#anti-pattern--magic-strings)
  - [Distinction avec `aqlAsc` / `aqlDesc` / `aqlSort`](#distinction-avec-aqlasc--aqldesc--aqlsort)
- [Parsing d'identifiants ArangoDB](#parsing-didentifiants-arangodb)
  - [`parseIdentifier()`](#parseidentifier)
  - [`parseKey()`](#parsekey)
  - [`parseCollection()`](#parsecollection)
- [Encodage des révisions `_rev`](#encodage-des-révisions-_rev)
  - [`decodeRevision()`](#decoderevision)
  - [`encodeRevision()`](#encoderevision)

---

## Grammaire de tri textuelle

La même grammaire est consommée par deux endroits du framework :

1. Le paramètre HTTP `?sort=` côté client (`?sort=-created,name`).
2. La clé `AQL::SORT_DEFAULT` côté DI ainsi que `Arango::SORT`
   passés à un model `Documents` au runtime.

C'est dans les deux cas une **string** dont la grammaire est :

```
<expression> := <token> ( ',' <token> )*
<token>      := [-] <field>
```

Le préfixe `-` indique l'ordre descendant. Sans préfixe : ascendant.

C'est cette string que `SortTrait::prepareSort` parse pour produire
le `SORT ...` AQL final.

### `ascKey()`

```php
ascKey( string $key ) : string
```

Renvoie `$key` inchangé. La fonction existe pour la **symétrie**
avec `descKey()` et pour rendre l'intention explicite au call-site.

```php
use function oihana\arango\helpers\ascKey;

ascKey( Prop::NAME ) ;  // 'name'
```

### `descKey()`

```php
descKey( string $key ) : string
```

Renvoie `$key` préfixé d'un `-`. Centralise la convention « moins =
descendant » pour ne plus avoir à écrire `'-' . Prop::X` à la main.

```php
use function oihana\arango\helpers\descKey;

descKey( Prop::CREATED ) ;  // '-created'
descKey( Prop::_KEY ) ;     // '-_key'
```

### `sortKeys()`

```php
sortKeys( string ...$keys ) : string
```

Compose une expression de tri en joignant les tokens avec une virgule.
Les tokens vides sont **silencieusement écartés** via
[`oihana\core\strings\compile()`](https://github.com/BcommeBois/oihana-php-core/blob/main/src/oihana/core/strings/compile.php),
ce qui permet de passer des tokens conditionnels sans `array_filter()`
au call-site.

```php
use function oihana\arango\helpers\descKey;
use function oihana\arango\helpers\sortKeys;

sortKeys( descKey( Prop::CREATED ) )                         ; // '-created'
sortKeys( descKey( Prop::CREATED ) , Prop::NAME )            ; // '-created,name'
sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) ) ; // '-created,-name'
sortKeys()                                                   ; // ''
```

### Anti-pattern : magic strings

À proscrire — ces formes mélangent constantes typées et strings brutes
(`'-'`, `','`) qui sont précisément ce que le projet bannit (règle
générale **« pas de magic strings »** : toute valeur affectée à une clé
typée passe par un helper ou une constante) :

```php
// ❌ Ne jamais écrire
AQL::SORT_DEFAULT => '-' . Prop::CREATED ,
Arango::SORT      => '-' . Prop::CREATED . ',' . Prop::NAME ,
Arango::SORT      => '-' . Prop::CREATED . ',' . '-' . Prop::NAME ,
```

Équivalents corrects :

```php
// ✅ Forme canonique
AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,
Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , Prop::NAME ) ,
Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) ) ,
```

### Distinction avec `aqlAsc` / `aqlDesc` / `aqlSort`

Il existe aussi dans le framework un trio **homonyme mais différent**
qui vit dans [`oihana\arango\db\operations`](../../src/oihana/arango/db/operations/) :

| Helper                                       | Dossier         | Émet                              | Quand l'utiliser |
|----------------------------------------------|-----------------|-----------------------------------|------------------|
| `ascKey` / `descKey` / `sortKeys`            | `helpers/`      | `'name'`, `'-created'`, `'-created,name'` | DI (`AQL::SORT_DEFAULT`, `Arango::SORT`) — c'est la **grammaire d'entrée** |
| `aqlAsc` / `aqlDesc` / `aqlSort`             | `db/operations/`| `'doc.name ASC'`, `'SORT doc.name DESC'`  | Builders bas-niveau qui produisent **le SORT AQL final** envoyé à ArangoDB |

Règle de décision rapide :

- On écrit une **expression de tri pour un appelant HTTP** ou une
  **valeur par défaut dans la DI** → `ascKey` / `descKey` / `sortKeys`.
- On compose **du AQL à la main** dans un trait/model → `aqlAsc` /
  `aqlDesc` / `aqlSort`.

---

## Parsing d'identifiants ArangoDB

Un *document handle* ArangoDB a la forme `<collection>/<_key>` (par exemple `users/42`).
Le trio `parseIdentifier` / `parseKey` / `parseCollection` permet de découper cette chaîne
sans manipulation manuelle de `explode()`.

### `parseIdentifier()`

```php
parseIdentifier( ?string $id ) : ?array
```

Découpe un *document handle* en ses deux composants. Retourne `null` si l'entrée est
`null` ou ne respecte pas la grammaire `<collection>/<key>`.

```php
use function oihana\arango\helpers\parseIdentifier ;

parseIdentifier( 'users/42'    ) ;     // [ 'collection' => 'users' , 'key' => '42' ]
parseIdentifier( 'invalid'     ) ;     // null
parseIdentifier( null          ) ;     // null
```

### `parseKey()`

```php
parseKey( ?string $id ) : ?string
```

Renvoie uniquement le `_key` d'un *document handle*. Équivalent à la deuxième composante
de `parseIdentifier()`.

```php
use function oihana\arango\helpers\parseKey ;

parseKey( 'users/42' ) ;       // '42'
parseKey( 'invalid'  ) ;       // null
parseKey( null       ) ;       // null
```

### `parseCollection()`

```php
parseCollection( ?string $id ) : ?string
```

Renvoie uniquement le nom de la collection d'un *document handle*.

```php
use function oihana\arango\helpers\parseCollection ;

parseCollection( 'users/42' ) ;        // 'users'
parseCollection( 'invalid'  ) ;        // null
parseCollection( null       ) ;        // null
```

**Pattern d'usage typique** — extraction côté contrôleur quand on reçoit un `_id`
brut en paramètre et qu'on veut soit le `_key` pour un lookup, soit la collection
pour une vérification d'accès :

```php
$id  = $args[ 'id' ] ?? null ;
$key = parseKey( $id ) ;

if ( $key === null )
{
    return $this->fail( HttpStatusCode::BAD_REQUEST , 'invalid_id' ) ;
}

$document = $this->model->get( [ Arango::ID => $key ] ) ;
```

---

## Encodage des révisions `_rev`

Le champ `_rev` d'un document ArangoDB encode la date de la dernière écriture et un
compteur incrémental. Le format est interne à ArangoDB et **ne devrait pas être parsé
à la main**. Les deux helpers `decodeRevision` / `encodeRevision` exposent l'encodage
officiel pour les rares cas où on en a besoin (export, comparaison de fraîcheur, audit).

### `decodeRevision()`

```php
decodeRevision( ?string $revision , bool $throwable = false ) : ?array
```

Décompose une révision en ses deux composants. Retourne un tableau `[ 'date' => ... , 'count' => ... ]`
ou `null` si l'entrée est invalide (`$throwable = false`) ou lève une exception (`$throwable = true`).

```php
use function oihana\arango\helpers\decodeRevision ;

$parsed = decodeRevision( '_iVZdJZ--_S' ) ;
// [ 'date' => '2026-05-17T14:32:18.000Z' , 'count' => 1234 ]

decodeRevision( null )      ;           // null
decodeRevision( 'invalid' ) ;           // null
decodeRevision( 'invalid' , throwable: true ) ;  // jette une exception
```

### `encodeRevision()`

```php
encodeRevision( string $date , ?int $count = null , bool $throwable = false ) : string
```

Encode une date (et un compteur optionnel) au format `_rev` ArangoDB. Utile pour
produire un `_rev` synthétique en test, ou pour comparer deux dates dans le format
attendu par le moteur.

```php
use function oihana\arango\helpers\encodeRevision ;

encodeRevision( '2026-05-17T14:32:18.000Z' , 1234 ) ;  // '_iVZdJZ--_S'
encodeRevision( '2026-05-17T14:32:18.000Z'        ) ;  // '_iVZdJZ--__'
```

**À ne pas faire** — utiliser le `_rev` pour de la logique applicative (timestamp,
ordre d'écriture). ArangoDB **garantit l'unicité du `_rev` par document**, mais ne
garantit pas qu'il soit interprétable comme un timestamp monotone. Pour l'horodatage
métier, ajouter un champ `modified` explicite (la convention `Schema::MODIFIED` du
package [`oihana/php-schema`](dependencies.md), alignée sur Schema.org).
