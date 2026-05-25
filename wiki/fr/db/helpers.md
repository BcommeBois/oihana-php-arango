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
| Introspection et projection | `isAQLExpression`, `isAQLFunction`, `isAQLId`, `matchesSkin`, `resolveSkinFields` | Détecter et router. |

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
| `aqlFieldUrl` | `Filter::URL` | URL d'un document avec *placeholders* dynamiques. |

Exemple minimal du *builder* le plus simple :

```php
use function oihana\arango\db\helpers\fields\aqlFieldDefault ;

aqlFieldDefault( 'name'   , 'doc'          ) ;    // "name: doc.name"
aqlFieldDefault( 'userId' , 'doc' , 'id' ) ;      // "userId: doc.id"
```

En usage normal, on n'appelle pas ces *builders* directement : on déclare un modèle `Documents` avec des `Field::FILTER` typés et `aqlFields()` route automatiquement.

## Introspection AQL

Trois prédicats permettent de classifier une chaîne :

| Fonction | Signature | Vrai si... |
|---|---|---|
| `isAQLExpression` | `(mixed $value) : bool` | La chaîne ressemble à une expression AQL (fonction, référence doc, bind, *handle*). |
| `isAQLFunction` | `(string $expression) : bool` | La chaîne est un appel de fonction AQL valide et reconnu (`COUNT(doc)`, `DATE_NOW()`, ...). |
| `isAQLId` | `(mixed $value) : bool` | La chaîne respecte le format *document handle* `collection/key`. |

Ces prédicats sont consommés en interne par `aqlValue()` pour décider d'échapper ou non une chaîne. Ils sont publics pour les cas où l'on a besoin de la même heuristique côté validation custom.

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
