# Requêtes AQL et Cursors

Au-delà du CRUD par clé ([documents.md](documents.md)), toute lecture non triviale passe par une **requête AQL**. Cette page couvre comment en construire une de manière sûre, la lancer, puis consommer ses résultats paresseusement via un `Cursor`.

Elle suppose que vous avez lu [Démarrer](getting-started.md).

## Pourquoi ne pas écrire l'AQL à la main ?

Vous le pouvez. `Database::query()` accepte une chaîne brute. Mais dès qu'une valeur de la requête vient d'une variable, il vous faut des **bind variables** — des placeholders que le serveur substitue de manière sûre. Concaténer des valeurs dans une chaîne AQL est un bug d'injection en attente.

La bibliothèque offre trois briques, du plus bas au plus haut niveau :

| Outil | Quand l'utiliser |
|---|---|
| Chaîne brute + tableau `$bindVars` | Requête ad-hoc, vous contrôlez chaque placeholder. |
| Helper `aql()` | Vous voulez des placeholders sûrs sans nommer chaque bind à la main. |
| Value object `AqlQuery` | Vous composez la requête depuis des fragments ou la stockez comme constante. |

## Les bind variables : la convention

ArangoDB utilise deux préfixes :

- `@nom` — bind **de valeur**. Remplacé par la valeur, échappée correctement.
- `@@nom` — bind **de nom de collection**. Remplacé par un identifiant de collection (non quoté).

```php
$cursor = $db->query(
    'FOR u IN @@coll FILTER u.age > @minAge RETURN u' ,
    [ '@coll' => 'users' , 'minAge' => 18 ] ,
) ;
```

À noter : la clé du tableau `bindVars` porte **un** `@` pour les binds de collection — le parser réduit le `@@` initial à un seul à la consultation.

## Le helper `aql()`

Pour les requêtes ad-hoc, `aql()` vous évite de nommer les binds. Il utilise des placeholders positionnels `?` à la PDO.

```php
use function oihana\arango\clients\aql\helpers\aql ;

$query = aql(
    'FOR u IN users FILTER u.age > ? AND u.role == ? RETURN u' ,
    18 ,
    'admin' ,
) ;

// Équivalent à :
// new AqlQuery(
//     query    : 'FOR u IN users FILTER u.age > @value1 AND u.role == @value2 RETURN u' ,
//     bindVars : [ 'value1' => 18 , 'value2' => 'admin' ] ,
// )

$cursor = $db->query( $query ) ;
```

Helpers voisins dans le même namespace :

- `aqlLiteral( string $fragment ) : AqlLiteral` — emballe un fragment qui doit être inliné verbatim (un mot-clé, un nom de fonction, une direction de tri). À utiliser avec parcimonie — uniquement pour ce qui ne peut pas passer comme valeur.
- `join( array $fragments , string $separator = ' ' ) : AqlQuery` — fusionne plusieurs fragments (`AqlQuery` / `AqlLiteral` / scalaires) en renommant les binds qui collisionnent. Utile quand vous composez une requête depuis des morceaux réutilisables.

`aql()` ne supporte **pas** les binds de collection (`@@coll`). Si vous avez besoin d'un nom de collection dynamique, construisez directement l'`AqlQuery`.

## Le value object `AqlQuery`

```php
use oihana\arango\clients\aql\AqlQuery ;

$query = new AqlQuery(
    query    : 'FOR u IN @@coll FILTER u._key == @key RETURN u' ,
    bindVars : [ '@coll' => 'users' , 'key' => 'alice' ] ,
) ;
```

Il est `readonly` — vous le construisez une fois et le faites circuler. Ses deux propriétés publiques sont `query` et `bindVars`.

## Exécuter la requête

```php
$cursor = $db->query( $query ) ;
```

`Database::query()` accepte soit une instance `AqlQuery`, soit une chaîne brute. Quand vous passez une chaîne, posez les binds en deuxième argument ; quand vous passez un `AqlQuery`, le deuxième argument doit être vide (sinon le helper lève `InvalidArgumentException`).

Le troisième argument est un tableau d'options transmis au curseur serveur :

| Option | Type | Effet |
|---|---|---|
| `count` | `bool` | Si `true`, le serveur renvoie le nombre total de résultats d'emblée. Nécessaire pour appeler `$cursor->count()`. |
| `fullCount` | `bool` | Renvoie le compte **avant** toute clause `LIMIT`. Récupéré via `$cursor->getFullCount()`. |
| `batchSize` | `int` | Nombre de lignes par batch réseau. Le curseur va chercher les batches suivants transparenment au fil de l'itération. |
| `ttl` | `int` | Durée de vie du curseur côté serveur, en secondes. |
| `cache` | `bool` | Réutilise le cache de résultats AQL si applicable. |
| `memoryLimit` | `int` | Mémoire maximale (octets) que la requête peut utiliser. |
| `options` | `array` | Objet imbriqué pour les flags avancés : `profile`, `maxRuntime`, `failOnWarning`, `optimizer.rules`, etc. |

```php
$cursor = $db->query(
    aql( 'FOR u IN users LIMIT @offset , @limit RETURN u' , 0 , 100 ) ,
    [] ,
    [ 'count' => true , 'fullCount' => true , 'batchSize' => 50 ] ,
) ;
```

## Le `Cursor`

`Cursor` implémente `IteratorAggregate` et `Countable`. Il tire les batches du serveur **paresseusement** — le suivant n'est chargé que quand vous avez épuisé le courant.

### Itérer

```php
foreach ( $cursor as $row )
{
    handle( $row ) ;
}
```

Chaque `$row` est ce que produit votre clause `RETURN` — souvent un tableau, parfois un scalaire, parfois le tableau associatif type `Document`.

### Helpers eager (consomment toute la suite)

| Méthode | Effet |
|---|---|
| `all() : array` | Charge tous les batches restants et renvoie l'ensemble en un tableau. |
| `count() : int` | Compte total côté serveur. **Requiert `count: true`** à la création de la requête. |
| `getFullCount() : int` | Compte avant `LIMIT`. **Requiert `fullCount: true`**. Renvoie `0` si non demandé. |
| `forEach( callable $cb ) : bool` | Appelle `$cb( $row , $index , $cursor )` pour chaque ligne. Renvoyer `false` depuis le callback pour court-circuiter. |
| `reduce( callable $reducer , mixed $initial = null ) : mixed` | Plie chaque ligne via `( $accumulator , $row , $index , $cursor )`. |
| `flatMap( callable $cb ) : array` | Applique `$cb` par ligne, puis aplatit d'un niveau. |

### Helpers paresseux

| Méthode | Effet |
|---|---|
| `map( callable $cb ) : Generator` | Renvoie un générateur paresseux. Rien ne se passe avant l'itération. |
| `getIterator() : Generator` | Ce que `foreach` appelle en interne. |
| `hasMore() : bool` | `true` tant que des batches restent côté serveur. |
| `getId() : ?string` | Id du curseur serveur, ou `null` une fois pleinement consommé. |
| `getExtra() : array` | Métadonnées du batch le plus récent (warnings, stats, profile). |
| `close() : void` | Libère le curseur serveur tôt. No-op s'il est déjà drainé. |

### Un exemple de pipeline

```php
$names = $db->query( aql( 'FOR u IN users FILTER u.active == ? RETURN u' , true ) )
    ->map  ( fn( array $u ) => $u[ 'name' ] )
    ->forEach( fn( string $name ) => echo $name . PHP_EOL ) ;
```

Ou eagerly avec `reduce` :

```php
$totalAge = $db->query( aql( 'FOR u IN users RETURN u.age' ) )
    ->reduce( fn( int $sum , int $age ) => $sum + $age , 0 ) ;
```

## Diagnostiquer une requête

Deux endpoints qui n'exécutent rien aident à déboguer l'AQL sans payer le coût d'exécution.

### `explain()` — montrer le plan d'exécution

```php
$plan = $db->explain( $query ) ;

print_r( $plan[ 'plan' ][ 'nodes' ] ) ;          // nœuds d'exécution
print_r( $plan[ 'plan' ][ 'estimatedCost' ] ) ;  // estimation de l'optimiseur
print_r( $plan[ 'warnings' ] ) ;
```

### `parse()` — validation syntaxique légère

```php
$ast = $db->parse( 'FOR u IN users RETURN u' ) ;

print_r( $ast[ 'collections' ] ) ;   // [ 'users' ]
print_r( $ast[ 'bindVars' ] ) ;      // noms de binds référencés
```

`parse()` ne valide que la grammaire ; la requête n'est pas exécutée et les valeurs de binds ne sont pas requises.

## Quand ça se passe mal

`Database::query()` lève :

- `InvalidArgumentException` — quand vous passez un `AqlQuery` avec des binds non vides (le helper considère cela comme une erreur de programmation).
- `ArangoException` — quand le serveur rejette la requête (erreur de parsing, collection manquante, type incompatible, autorisation, etc.). L'exception porte `errorNum` et `getCode()` pour affiner.

Pendant l'itération en batch, les erreurs transitoires (coupure réseau, bascule de leader) remontent comme sous-classes de `ArangoException`. Beaucoup posent `isSafeToRetry()` à `true` — le cursor ne retente pas tout seul, mais vous le pouvez.

## Aller plus loin

- [Graphes](graphs.md) — graphes nommés *gharial* et requêtes de traversal.
- [Transactions](transactions.md) — grouper plusieurs requêtes AQL en une unité atomique.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
- [Référence des fonctions AQL](../aql/aql-functions-strings.md) — le catalogue des fonctions AQL exposées comme helpers PHP.
