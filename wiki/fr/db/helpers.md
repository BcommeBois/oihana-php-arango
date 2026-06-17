# Helpers AQL `db/helpers/`

Le dossier [`src/oihana/arango/db/helpers/`](../../../src/oihana/arango/db/helpers/) rassemble les fonctions standalone qui composent les **fragments de texte AQL** : encodage d'une valeur, construction d'un document inline, sérialisation d'expressions, sous-expressions des opérations de modification, et *field builders* pour les `RETURN { ... }`.

> Ne pas confondre avec deux autres dossiers homonymes :
>
> - [`db/operations/`](../../../src/oihana/arango/db/operations/) — opérations AQL complètes (`aqlFor`, `aqlFilter`, `aqlReturn`, ...), documentées en [Construire une requête AQL pas à pas](../aql/aql-building-queries.md).
> - [`db/functions/`](../../../src/oihana/arango/db/functions/) — fonctions AQL côté valeur (`CONCAT`, `LOWER`, `DATE_NOW`, ...), documentées dans les pages [Fonctions de chaînes](../aql/aql-functions-strings.md) et suivantes.
>
> Les helpers de cette page travaillent sur le **texte AQL** : ils produisent des chaînes prêtes à être injectées dans une requête.

## Catégories

Le dossier compte 29 fonctions, organisées en cinq catégories :

| Catégorie | Fonctions | Rôle |
|---|---|---|
| Encodage de valeurs | `aqlValue`, `aqlExpression`, `aqlDocument`, `aqlArray`, `aqlSafeArray` | Transformer une valeur PHP en fragment AQL. |
| Composition de fragments | `aqlAssignments`, `aqlSerialize` | Sérialiser des paires *key/value*. |
| Sous-expressions CUD | `aqlInsertExpression`, `aqlUpdateExpression`, `aqlReplaceExpression`, `aqlUpsertExpression` | Construire le corps des opérations `INSERT` / `UPDATE` / `REPLACE` / `UPSERT`. |
| *Field builders* (`fields/`) | `aqlFields` + 12 `aqlField*` typés | Construire les `RETURN { key: doc.value, ... }`. |
| Introspection et projection | `isAQLExpression`, `isAQLFunction`, `isAQLId`, `isAttributeName`, `assertAttributeName`, `matchesSkin`, `resolveSkinFields` | Détecter, valider et router. |

## Encodage de valeurs

### `aqlValue()` — la fondation

```php
function aqlValue( mixed $value , array $rawValues = [] ) : string
```

Transforme une valeur PHP en expression AQL sûre. C'est la fonction la plus utilisée du dossier — toutes les autres en dépendent directement ou indirectement.

Pour les chaînes, la fonction tente d'abord une détection automatique : si la chaîne ressemble à une **expression AQL** (appel de fonction comme `CONCAT(...)`, référence document `doc.field`, *bind variable* `@var`, *document handle* `users/123`), elle est retournée telle quelle. Sinon, elle est échappée et entourée de *quotes* simples.

```php
use function oihana\arango\db\helpers\aqlValue ;

aqlValue( 'hello'       ) ;        // "'hello'"
aqlValue( 42            ) ;        // '42'
aqlValue( true          ) ;        // 'true'
aqlValue( null          ) ;        // 'null'
aqlValue( [1, 2, 3]     ) ;        // '[1,2,3]'

// Détection automatique
aqlValue( 'CONCAT("a","b")' ) ;    // 'CONCAT("a","b")' (raw)
aqlValue( 'doc.name'        ) ;    // 'doc.name'        (raw)
aqlValue( '@userId'         ) ;    // '@userId'         (raw)
aqlValue( 'users/123'       ) ;    // 'users/123'       (raw)
```

Le second paramètre `$rawValues` permet de forcer le traitement *raw* pour des chaînes qui passeraient sinon par l'échappement :

```php
aqlValue( 'my_variable' )                       ; // "'my_variable'" (quotée)
aqlValue( 'my_variable' , [ 'my_variable' ] )   ; // 'my_variable'   (raw, forcée)
```

Les tableaux associatifs sont délégués à `aqlDocument()`, les objets à `aqlDocument(get_object_vars(...))`, les tableaux indexés sont sérialisés en `[v1,v2,v3]`. Tout type non pris en charge lève `oihana\exceptions\UnsupportedOperationException`.

### `aqlExpression()` — point d'entrée simplifié

```php
function aqlExpression( object|string|array|null $value ) : ?string
```

Variante allégée d'`aqlValue()` qui prend en charge trois cas : `null` retourne `null`, une chaîne est retournée telle quelle (jamais quotée), et un *array* ou un *object* est délégué à `aqlDocument()`. À utiliser quand on sait que la valeur est soit une expression AQL textuelle déjà formée, soit un document à sérialiser — pas un scalaire à échapper.

```php
use function oihana\arango\db\helpers\aqlExpression ;

aqlExpression( 'FOR u IN users RETURN u'   ) ;     // 'FOR u IN users RETURN u'
aqlExpression( [ 'name' => 'John' ]        ) ;     // "{name:'John'}"
aqlExpression( null                        ) ;     // null
```

### `aqlDocument()` — construire un document AQL

```php
function aqlDocument
(
    object|array|string|null $keyValues = []   ,
    array                    $options   = []
) : string
```

Construit une expression de type document inline `{key:value,...}`. Accepte un *array* associatif, un *array* indexé de paires `[key, value]`, un objet, une chaîne (placée telle quelle entre accolades), ou `null` (retourne `'{}'`).

Trois options :

- `AQL::USE_SPACE` *(bool)* — ajoute des espaces autour des accolades et après les virgules (lisibilité des requêtes longues).
- `AQL::RAW_VALUES` *(array)* — liste de clés dont la valeur doit être traitée comme expression AQL brute (pas d'échappement, pas de *quotes*).
- `AQL::RAW_KEYS` *(array)* — liste de clés dont la valeur **entière** doit rester brute (la clé ET la valeur).

```php
use function oihana\arango\db\helpers\aqlDocument ;
use oihana\arango\db\enums\AQL ;

aqlDocument( [ '_from' => 'u._id' , '_to' => 'p._id' ] ) ;
// "{_from:u._id,_to:p._id}"

aqlDocument
(
    [ '_key' => 'CONCAT("u_", i)' , 'name' => 'test' ] ,
    [ AQL::USE_SPACE => true , AQL::RAW_VALUES => [ '_key' ] ]
) ;
// "{ _key: CONCAT(\"u_\", i), name:'test' }"

aqlDocument( [ 'user' => [ 'name' => 'Eka' , 'age' => 47 ] , 'active' => true ] ) ;
// "{user:{name:'Eka',age:47},active:true}"
```

Les clés sont validées : seules celles qui matchent `/^[a-zA-Z_]\w*$/` sont laissées telles quelles, les autres sont quotées et échappées.

### `aqlArray()` et `aqlSafeArray()`

```php
function aqlArray    ( mixed  $value = null                         ) : string
function aqlSafeArray( string $path , ?string $default = '[]'      ) : string
```

`aqlArray()` produit une expression de tableau AQL à partir d'une valeur PHP : un *array* est JSON-encodé, une chaîne est retournée telle quelle (supposée être une référence AQL `doc.items`), un objet est *casté* en *array*, tout autre type retourne `[]`.

`aqlSafeArray()` produit une expression défensive qui garantit qu'un accès à un champ tableau retourne au moins le `$default` (`[]` par défaut) si le champ n'est pas un tableau côté serveur. Utile pour les projections sur des champs optionnels.

```php
use function oihana\arango\db\helpers\aqlArray ;
use function oihana\arango\db\helpers\aqlSafeArray ;

aqlArray( [ 1 , 2 , 3 ]  ) ;        // '[1,2,3]'
aqlArray( 'doc.items'    ) ;        // 'doc.items'
aqlArray( null           ) ;        // '[]'

aqlSafeArray( 'doc.offers' ) ;      // expression défensive sur doc.offers
```

## Composition de fragments

### `aqlAssignments()`

```php
function aqlAssignments
(
    ?array $assignments = []   ,
    string $separator   = ', '
) : string
```

Sérialise un tableau associatif en une liste d'affectations `key = value` séparées. Utilisé pour construire la clause `WITH { ... }` d'un `UPDATE`, ou tout autre endroit où une liste d'assignations textuelles est attendue.

### `aqlSerialize()`

```php
function aqlSerialize( mixed $value , bool $topLevel = true ) : string
```

Sérialiseur générique récursif. Convertit une valeur arbitraire (scalaire, *array*, objet) en fragment AQL en délégant aux helpers spécialisés selon le type rencontré. Le paramètre `$topLevel` contrôle si la valeur racine doit être encapsulée (utile pour la récursion interne).

## Sous-expressions des opérations CUD

Quatre fonctions construisent le **corps** d'une opération de modification AQL. Elles consomment toutes un tableau `$init` aux clés `AQL::*` (collection, document, options, etc.) et retournent la sous-expression textuelle correspondante.

| Fonction | Signature | Produit |
|---|---|---|
| `aqlInsertExpression` | `(array $init = []) : string` | `INSERT { ... } INTO collection [OPTIONS { ... }]` |
| `aqlUpdateExpression` | `(array $init = []) : string` | `UPDATE key WITH { ... } IN collection [OPTIONS { ... }]` |
| `aqlReplaceExpression` | `(array $init = []) : string` | `REPLACE key WITH { ... } IN collection [OPTIONS { ... }]` |
| `aqlUpsertExpression` | `(array $init = []) : string` | `UPSERT { ... } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]` |

Ces fonctions sont consommées par les *traits* CRUD des modèles (`DocumentsInsertTrait`, `DocumentsUpdateTrait`, ...) — en usage direct dans une requête custom, on les appelle rarement.

## *Field builders* — sous-dossier `fields/`

Le sous-dossier [`fields/`](../../../src/oihana/arango/db/helpers/fields/) contient 12 *builders* qui produisent les sous-expressions `key: doc.value` typées d'un `RETURN { ... }`. Chacun prend en charge un type de champ et applique la projection appropriée (cast, accès, transformation).

### Point d'entrée — `aqlFields()`

```php
function aqlFields
(
    ?array              $fields    = null      ,
    string              $docRef    = AQL::DOC  ,
    ?ContainerInterface $container = null
) : string
```

Compose une expression `RETURN { ... }` complète à partir d'un tableau de définitions de champs. Chaque définition est routée vers le *field builder* approprié selon son `Field::FILTER`. Le `$docRef` est l'alias du document dans la requête (par défaut `doc`). Le `$container` est utilisé par certains *builders* qui ont besoin de résoudre une dépendance (par exemple `aqlFieldUrl` pour le *base URL*).

### Catalogue des 12 *field builders*

| *Builder* | Filter associé | Rôle |
|---|---|---|
| `aqlFieldDefault` | `Filter::DEFAULT` | Référence simple `key: doc.keyName`. |
| `aqlFieldBool` | `Filter::BOOL` | Cast booléen `key: TO_BOOL(doc.x)`. |
| `aqlFieldNumber` | `Filter::NUMBER` | Cast numérique `key: TO_NUMBER(doc.x)`. |
| `aqlFieldDateTime` | `Filter::DATETIME` | Cast date ISO 8601. |
| `aqlFieldArray` | `Filter::ARRAY` | Champ tableau défensif. |
| `aqlFieldArrayCount` | `Filter::ARRAY_COUNT` | Nombre d'éléments du tableau. |
| `aqlFieldArrayFirst` | `Filter::ARRAY_FIRST` | Premier élément du tableau. |
| `aqlFieldDocument` | `Filter::DOCUMENT` | Document imbriqué (avec sous-projection). |
| `aqlFieldObject` | `Filter::OBJECT` | Objet ou premier élément d'un tableau. |
| `aqlFieldMap` | `Filter::MAP` | Mapping d'un tableau vers des documents structurés. |
| `aqlFieldTranslate` | `Filter::TRANSLATE` | Champ traduit (sélection de la *locale* courante). |
| `aqlFieldUrl` | `Filter::URL` | URL d'un document avec *placeholders* dynamiques (et routage par type optionnel). |

Exemple minimal du *builder* le plus simple :

```php
use function oihana\arango\db\helpers\fields\aqlFieldDefault ;

aqlFieldDefault( 'name'   , 'doc'          ) ;    // "name: doc.name"
aqlFieldDefault( 'userId' , 'doc' , 'id' ) ;      // "userId: doc.id"
```

En usage normal, on n'appelle pas ces *builders* directement : on déclare un modèle `Documents` avec des `Field::FILTER` typés et `aqlFields()` route automatiquement.

### Options par champ

En plus de `Field::FILTER`, chaque définition de champ accepte des options :

| Option | Effet | Exemple de sortie |
|---|---|---|
| `Field::NAME` | Alias : la clé de sortie diffère de l'attribut source | `slug:doc.title` |
| `Field::ALTERS` | Chaîne de transformation `alt` appliquée à la valeur projetée | `name:LOWER(TRIM(doc.name))` |
| `Field::QUOTED` | Étiquette de sortie entre guillemets (clés à caractères spéciaux) | `` "my-key":doc.`my-key` `` |
| `Field::UNIQUE` | Nom de variable unique pour l'expression AQL | — |
| `Field::REQUIRES` | Sujet(s) de permission : le champ est retiré si l'autorisation est refusée | — |
| `Field::SCOPE` | Source de projection dans une sous-requête d'edge : `Scope::VERTEX` (défaut) ou `Scope::EDGE` (la métadonnée de la relation) | `since:DATE_ISO8601(e.created)` |
| `Field::WHEN` / `Field::ELSE` | Valeur conditionnelle : ne projeter le champ que si une condition tient, sinon repli. Voir [Champs conditionnels](conditional-fields.md). | `price:doc.visibility == 'public' ? doc.price : null` |

> `Field::QUOTED` met des guillemets **uniquement sur l'étiquette** de sortie et accède à l'attribut en **backticks** (`` doc.`my-key` ``) — la forme AQL valide pour un attribut à caractères spéciaux (`doc."my-key"` est invalide et rejeté par ArangoDB). Un `Field::NAME` fournit alors la source : seule l'étiquette est quotée (`"slug":doc.title`).

Exemples travaillés :

```php
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use function oihana\arango\db\helpers\aqlFields ;

// Projection par défaut
aqlFields([ 'name' => [] ]);
// name:doc.name

// Plusieurs champs typés (séparés par ', ')
aqlFields([
    'name'   => [] ,
    'price'  => [ Field::FILTER => Filter::NUMBER ] ,
    'active' => [ Field::FILTER => Filter::BOOL ] ,
]);
// name:doc.name, price:TO_NUMBER(doc.price), active:TO_BOOL(doc.active)

// Référence document personnalisée (sous-requête edge/join)
aqlFields([ 'tags' => [ Field::FILTER => Filter::ARRAY ] ], 'edge');
// tags:IS_ARRAY(edge.tags) ? edge.tags : []

// Alias de clé (Field::NAME)
aqlFields([ 'slug' => [ Field::NAME => 'title' ] ]);
// slug:doc.title

// Transformation de sortie (Field::ALTERS)
aqlFields([ 'name' => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ]);
// name:LOWER(TRIM(doc.name))

// Clé à caractères spéciaux (étiquette quotée, attribut en backticks)
aqlFields([ 'my-key' => [ Field::QUOTED => true ] ]);
// "my-key":doc.`my-key`
```

### Champs URL — `Filter::URL`

Un champ `Filter::URL` construit une URL complète `CONCAT(<chemin>, '/', doc._key)`. Le
chemin (`Field::PATH`) est résolu **au moment de la construction**, côté PHP : les
*placeholders* `{param}` sont remplacés depuis `Arango::ARGS` et l'*URL de base* (résolue
depuis le conteneur) est préfixée — le même chemin s'applique donc à tous les documents.

```php
aqlFields([ 'url' => [ Field::FILTER => Filter::URL , Field::PATH => '/places' ] ], 'doc', $container);
// url:CONCAT('https://base.url/places','/',doc._key)
```

**Routage par type — `Field::PATHS`.** Lorsque la route dépend du type du document,
déclarez une *map* `Field::PATHS` `'<valeur discriminante>' => '<route>'`. Le chemin est
alors choisi **à l'exécution** via la fonction AQL `TRANSLATE()` sur un attribut
discriminant — par défaut `Schema::ADDITIONAL_TYPE`, surchargeable avec `Field::PROPERTY`.
`Field::PATH` devient la route de repli **obligatoire** pour les documents dont le type
n'est pas dans la *map* (elle est émise comme troisième argument de `TRANSLATE()`, de sorte
qu'un type non mappé ne laisse jamais fuiter le discriminant brut dans l'URL).

```php
aqlFields([ 'url' =>
[
    Field::FILTER   => Filter::URL ,
    Field::PATH     => '/thing' ,                              // repli (obligatoire avec PATHS)
    Field::PATHS    => [ 'Place' => '/places' , 'Person' => '/people' ] ,
    Field::PROPERTY => Schema::ADDITIONAL_TYPE ,               // optionnel, c'est la valeur par défaut
]], 'doc');
// url:CONCAT(TRANSLATE(doc.additionalType,{Place:'/places',Person:'/people'},'/thing'),'/',doc._key)
```

> Les *placeholders* et l'*URL de base* sont appliqués à **chaque** branche comme au repli.
> Déclarer `Field::PATHS` **sans** repli `Field::PATH` (ou avec une *map* vide / non associative)
> lève une `UnsupportedOperationException` au build ; l'attribut discriminant est validé par
> `assertAttributeName` (garde anti-injection).

## Introspection AQL

Quatre prédicats permettent de classifier une chaîne :

| Fonction | Signature | Vrai si... |
|---|---|---|
| `isAQLExpression` | `(mixed $value) : bool` | La chaîne ressemble à une expression AQL (fonction, référence doc, bind, *handle*). |
| `isAQLFunction` | `(string $expression) : bool` | La chaîne est un appel de fonction AQL valide et reconnu (`COUNT(doc)`, `DATE_NOW()`, ...). |
| `isAQLId` | `(mixed $value) : bool` | La chaîne respecte le format *document handle* `collection/key`. |
| `isAttributeName` | `(mixed $value) : bool` | La chaîne est un nom d'attribut sûr — un ou plusieurs segments identifiant séparés par des points (`value`, `_key`, `breeding.alternateName`). |

Les trois premiers prédicats sont consommés en interne par `aqlValue()` pour décider d'échapper ou non une chaîne. Ils sont publics pour les cas où l'on a besoin de la même heuristique côté validation custom.

### Garde anti-injection — `isAttributeName` / `assertAttributeName`

Une **valeur** non fiable est toujours placée derrière un *bind* (voir [Bind variables](binds.md)), donc jamais injectable. Mais un **nom d'attribut** (clé) issu d'une entrée utilisateur et concaténé dans un accesseur `doc.<nom>` ne peut **pas** être un *bind* : c'est un identifiant, pas une valeur. C'est le rôle de cette paire (le pendant, pour les attributs, de `isBindVariable` / `assertBindVariable`) :

| Fonction | Signature | Rôle |
|---|---|---|
| `isAttributeName` | `(mixed $value) : bool` | Prédicat : `true` si la chaîne est un nom (ou chemin pointé) d'attribut sûr. |
| `assertAttributeName` | `(mixed $value) : void` | Lève `ValidationException` si le nom n'est pas sûr. |

Le motif accepté est `^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$` : tout caractère capable de s'échapper d'un chemin d'attribut (espace, `(`, `||`, `"`, `;`, `-`, ...) est rejeté.

```php
use function oihana\arango\db\helpers\assertAttributeName;

assertAttributeName( 'breeding.alternateName' ); // ok (chemin imbriqué)
assertAttributeName( 'a || 1==1' );              // throws ValidationException
```

Utilisé par les facettes complexes (`Facet::ARRAY_COMPLEX`, `Facet::EDGE_COMPLEX`) pour valider les **noms de sous-champs** fournis dans `?facets=` avant de les concaténer dans la requête : un sous-champ malveillant fait échouer la facette (ignorée + *warning* loggué), aucun fragment n'atteint l'AQL. Voir [Facettes](facets.md).

## Helpers de projection par *skin*

Deux fonctions liées au système de projection par *skin* (couvert en détail dans [Projection des edges et joins](../edges-joins-projection.md)) :

| Fonction | Signature | Rôle |
|---|---|---|
| `matchesSkin` | `(mixed $skins, ?string $currentSkin) : bool` | Évalue un marqueur `Field::SKINS` contre le *skin* de la requête. |
| `resolveSkinFields` | `(array $definition, ?string $skin) : mixed` | Sélectionne la projection à utiliser pour une définition d'*edge* ou de *join*, en fonction du *skin* courant (`AQL::SKIN_FIELDS` puis `AQL::FIELDS`). |

Ces deux fonctions ne sont presque jamais appelées directement par le code applicatif — elles sont consommées en interne par `FieldsTrait::filterFieldsBySkin` et `buildVariables`. Documentées ici par cohérence du catalogue.

## Voir aussi

- [Bind variables `db/binds/`](binds.md) — placer les valeurs réelles produites par `aqlValue()` derrière des *placeholders* sûrs.
- [Construire une requête AQL pas à pas](../aql/aql-building-queries.md) — assembler ces helpers avec les opérations AQL.
- [Projection des edges et joins](../edges-joins-projection.md) — `matchesSkin` et `resolveSkinFields` en contexte.
- [Documentation officielle AQL — fundamentals](https://docs.arangodb.com/stable/aql/fundamentals/).
