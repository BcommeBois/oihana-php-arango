# Champs conditionnels — `Field::WHEN`

Une projection scalaire peut être **gardée par une condition** : la valeur du champ n'est
calculée que si la condition tient, sinon elle retombe sur une branche `else`. C'est
l'équivalent AQL du `CASE WHEN … THEN … ELSE …` SQL, rendu par un ternaire :

```aql
price: doc.visibility == 'public' ? doc.price : null
```

- La **clé est toujours présente** — seule la *valeur* bascule. `Field::WHEN` ne retire
  jamais la clé (cela demanderait un `MERGE`, volontairement hors périmètre ; une valeur
  absente s'exprime par `null`).
- La condition est **résolue à l'exécution** à partir des attributs du document (décision
  par ligne), contrairement à `Field::SKINS` / `Field::REQUIRES` qui décident de
  l'*inclusion* en amont (par requête / par permission). Les trois sont orthogonaux et se
  cumulent.
- Les valeurs de condition sont **inlinées** (non bindées) : un `WHEN` est de la
  configuration déclarée par le développeur, jamais une entrée utilisateur — voir
  [Sécurité](#sécurité).

> S'applique à la **projection scalaire par défaut** et aux deux projections qui fabriquent
> une valeur sans garde propre : la reconstruction d'un sous-document
> (`Filter::DOCUMENT`, voir [Garder un sous-document](#garder-un-sous-document--fieldnullable))
> et celle d'un lien
> (`Filter::URL`, voir [Le lien, seulement s'il y a une clé](#le-lien-seulement-sil-y-a-une-clé--fieldwhen-sur-un-filterurl)).
> Un `Field::WHEN` sur un autre filtre typé/structurel (`EDGES`, `JOINS`, `MAP`, …) lève une
> `UnsupportedOperationException` : ces filtres ont leur propre forme et leur propre garde,
> il n'y a rien à ajouter.

## Démarrage rapide

```php
use oihana\arango\enums\Field ;

$fields =
[
    // montrer le vrai prix au public, le prix de base sinon
    'price' =>
    [
        Field::WHEN => [ 'visibility' , 'public' ] ,
        Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
    ],
];
// price: doc.visibility == 'public' ? doc.price : doc.basePrice
```

L'attribut de condition est **indépendant** du champ projeté — ici la valeur est `price`
mais le test lit `visibility`.

## La condition

Une condition est une **feuille** (une comparaison) ou un **groupe** (des feuilles
combinées par de la logique).

### Formes de feuille

| Déclaré | Sens | AQL |
|---|---|---|
| `'active'` (chaîne) | véracité | `TO_BOOL(doc.active)` |
| `[ 'visibility', 'public' ]` | égalité | `doc.visibility == 'public'` |
| `[ 'stock', 'gt', 0 ]` | comparateur explicite | `doc.stock > 0` |
| `[ FilterParam::KEY => 'status', FilterParam::OP => 'eq', FilterParam::VAL => 'public' ]` | forme associative | `doc.status == 'public'` |

La forme associative reprend le vocabulaire de feuille du DSL `?filter=` (`FilterParam`
`key` / `op` / `val` / `alt`) — une condition écrite pour un filtre se lit pareil ici.

**Comparateurs supportés** (infixes uniquement) : `eq`, `ne`, `ge`, `gt`, `le`, `lt`, `in`,
`nin`, `like`, `nlike`, `match`, `nmatch`. Les opérateurs en forme de fonction (`contains`,
`sw`, `ew`, `regex`, …) sont **rejetés** — utilisez le `?filter=` plat pour ceux-ci.

**Comparer deux attributs** — une valeur qui ressemble à une référence de document est
conservée brute :

```php
Field::WHEN => [ 'price', 'gt', 'doc.minPrice' ]
// doc.price > doc.minPrice
```

### `alt` sur les opérandes

Une feuille peut porter une chaîne `alt` qui enrobe l'attribut comparé (gauche) et/ou la
valeur (droite) — même vocabulaire miroir `"lower"` / `{ key, val }` / `{ key, val:true }`
que les filtres plats :

```php
Field::WHEN =>
[
    FilterParam::KEY => 'status' ,
    FilterParam::VAL => 'PUBLIC' ,
    FilterParam::ALT => [ 'key' => 'lower' , 'val' => true ] , // miroir des deux côtés
]
// LOWER(doc.status) == LOWER('PUBLIC')
```

> Ne pas confondre les deux portées d'`alt` : un `alt` **dans une feuille** enrobe les
> *opérandes de condition* ; `Field::ALTERS` sur le champ enrobe la *valeur projetée*
> (voir plus bas).

### Groupes — AND / OR / NOT

Les groupes reprennent la grammaire récursive de `?filter=` :

| Déclaré | AQL |
|---|---|
| `[ [ 'visibility', 'public' ], [ 'stock', 'gt', 0 ] ]` (AND implicite) | `(doc.visibility == 'public' && doc.stock > 0)` |
| `[ 'and', c1, c2 ]` | `(c1 && c2)` |
| `[ 'or', [ 'role', 'admin' ], [ 'owner', 'eq', true ] ]` | `(doc.role == 'admin' \|\| doc.owner == true)` |
| `[ 'not', [ 'anonymized', true ] ]` | `!(doc.anonymized == true)` |
| `[ 'and', [ 'or', c1, c2 ], [ 'active', true ] ]` (imbriqué) | `((c1 \|\| c2) && doc.active == true)` |

Désambiguïsation : une liste commençant par `and` / `or` / `not` est un **groupe** ; une
liste dont tous les éléments sont des tableaux est un **AND implicite** ; une liste de
scalaires est une **feuille unique**.

## La branche `else`

Sans `Field::ELSE`, le repli est `null`. Deux formes sinon :

| Déclaré | else AQL | Sens |
|---|---|---|
| `Field::ELSE => 0` | `0` | littéral (inliné ; `null` / `0` / `'inconnu'` …) |
| `Field::ELSE => [ Field::PROPERTY => 'basePrice' ]` | `doc.basePrice` | un autre attribut du document |

### Un littéral ambigu — `betweenQuotes()`

Un littéral chaîne est quoté automatiquement… **sauf s'il ressemble déjà à de l'AQL**. C'est
volontaire : c'est ce qui permet d'écrire `[ 'price' , 'gt' , 'doc.minPrice' ]` et de comparer
deux attributs plutôt qu'un attribut à du texte. Mais quelques littéraux métier tombent dans
le même filet — `N/A` a la forme d'un *document handle* (`collection/clé`), `en/US` aussi.

Pour lever l'ambiguïté, dis-le explicitement avec `betweenQuotes()` :

```php
use function oihana\core\strings\betweenQuotes ;

Field::ELSE => betweenQuotes( 'N/A' ) ,   // →  : 'N/A'
Field::ELSE => 'N/A' ,                    // →  : N/A   ⛔
```

Sans elle, ArangoDB lit `N/A` comme du code et **refuse la requête entière** — y compris pour
les lignes qui ne prennent pas la branche `else`, la résolution des noms se faisant au plan et
non à l'exécution :

```
RETURN { p: true  ? 1 : N/A }    → ERREUR 1203 : collection or view not found: A
RETURN { p: false ? 1 : N/A }    → ERREUR 1203 : collection or view not found: A
RETURN { p: false ? 1 : 'N/A' }  → OK  [ { "p": "N/A" } ]
```

L'échec est donc franc et immédiat, jamais une valeur fausse qui passerait inaperçue. La règle
tient en une phrase : **un littéral qui contient un `/` ou un `.` se déclare avec
`betweenQuotes()`**. Les autres (`'inconnu'`, `'Non renseigné'`, `0`, `null`) n'en ont pas besoin.

## Combinaison avec les autres options

`Field::WHEN` se compose avec les autres options par champ :

```php
'slug' =>
[
    Field::NAME   => 'title' ,                  // source de la valeur ≠ clé de sortie
    Field::WHEN   => [ 'published', 'eq', true ] ,
    Field::ALTERS => [ 'trim', 'lower' ] ,      // enrobe la valeur du THEN
]
// slug: doc.published == true ? LOWER(TRIM(doc.title)) : null
```

- `Field::ALTERS` décore la branche **then** (`cond ? ALTERS(valeur) : else`).
- `Field::NAME` aliase la source projetée, indépendamment de l'attribut de condition.
- `Field::REQUIRES` (gating de permission) et `Field::SKINS` (variantes nommées)
  s'appliquent toujours — ils décident si le champ est présent du tout, avant que la
  condition soit évaluée.

## Garder un sous-document — `Field::NULLABLE`

**La situation.** Un `Filter::DOCUMENT` ne lit pas un sous-document, il le **reconstruit**
attribut par attribut. C'est ce qui permet, par exemple, de recalculer une `url` à la
lecture au lieu de la stocker :

```php
'thing' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::FIELDS =>
    [
        '_key' => [] ,
        'name' => [] ,
        'url'  => [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
    ] ,
]
```

Cette reconstruction n'était **jamais gardée**. Quand l'attribut source est absent, chaque
ligne lit un attribut d'un objet qui n'existe pas — ce qui, en AQL, vaut `null` sans lever
d'erreur — et l'objet est tout de même émis. Une case vide revient donc **habillée** :

| Le document en base | Ce qui sortait |
|---|---|
| `{ "_key": "u1", "name": "Alice", "thing": { "_key": "t9" } }` | `{ "thing": { "_key": "t9", "name": null, "url": "https://base/things/t9" } }` |
| `{ "_key": "u2", "name": "Bob" }` — pas de `thing` | `{ "thing": { "_key": null, "name": null, "url": "https://base/things/" } }` |

La deuxième ligne est le problème : `url` vaut `"https://base/things/"`, une adresse qui ne
mène nulle part, et côté consommateur `if (x.thing)` est **vrai** alors qu'il n'y a rien.
Il fallait savoir écrire `x.thing?._key`, ce que rien n'annonçait.

**Le remède.** Une ligne, qui déclare l'intention « pas de source, pas d'objet » :

```php
'thing' =>
[
    Field::FILTER   => Filter::DOCUMENT ,
    Field::NULLABLE => true ,            // ← la seule ligne ajoutée
    Field::FIELDS   => [ … idem … ] ,
]
```

```aql
thing:IS_OBJECT(doc.thing) ? {_key:doc.thing._key, name:doc.thing.name, url:CONCAT('https://base/things','/',doc.thing._key)} : null
```

| Le document en base | Ce qui sort |
|---|---|
| `{ …, "thing": { "_key": "t9" } }` | `{ "thing": { "_key": "t9", "name": null, "url": "https://base/things/t9" } }` — inchangé |
| `{ … }` — pas de `thing` | `{ "thing": null }` |

L'objet entre les accolades n'a pas bougé d'un caractère : il est seulement passé derrière
une garde.

> **Pourquoi `IS_OBJECT()` et non `!= null`.** Un attribut qui *existe* mais n'est pas un
> objet — une chaîne, un nombre — reconstruit exactement le même objet de `null`. Le test
> porte donc sur le **type**, comme partout ailleurs dans la librairie (`Filter::ARRAY`
> teste `IS_ARRAY`, `Filter::EDGE` teste `IS_OBJECT`).

### La condition libre — `Field::WHEN` sur un `Filter::DOCUMENT`

`Field::NULLABLE` n'est qu'une condition écrite d'avance. Quand la garde doit être autre
chose que « la source existe », elle s'écrit avec la grammaire de condition décrite plus
haut :

```php
'contact' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::WHEN   => [ 'visibility' , 'public' ] ,
    Field::FIELDS => [ 'email' => [] , 'telephone' => [] ] ,
]
// contact: doc.visibility == 'public' ? {email:doc.contact.email, telephone:doc.contact.telephone} : null
```

La branche inverse se choisit comme sur un scalaire (`Field::ELSE`, défaut `null`), et les
deux gardes se composent en `&&` — inutile de réécrire l'existence à la main :

```php
    Field::NULLABLE => true ,
    Field::WHEN     => [ 'visibility' , 'public' ] ,
// contact: (IS_OBJECT(doc.contact) && doc.visibility == 'public') ? { … } : null
```

**La condition se lit sur le document parent**, jamais sur le sous-document reconstruit :
`doc.visibility`, pas `doc.contact.visibility`. Ce n'est pas un détail d'implémentation —
c'est ce qui fait que le contrôle d'autorisation décrit en [Sécurité](#sécurité)
s'applique tel quel : il garde les attributs lus par une condition contre la projection du
**niveau courant**. Si `visibility` porte un `Field::REQUIRES` refusé, le champ `contact`
disparaît entièrement, au lieu de devenir un oracle sur `visibility`.

### Imbrication

Chaque niveau porte sa propre garde ; elles ne se gênent pas, le ternaire externe
n'évaluant jamais l'objet interne quand il est faux :

```php
'thing' =>
[
    Field::FILTER   => Filter::DOCUMENT ,
    Field::NULLABLE => true ,
    Field::FIELDS   =>
    [
        'name'  => [] ,
        'owner' => [ Field::FILTER => Filter::DOCUMENT , Field::NULLABLE => true , Field::FIELDS => [ 'name' => [] ] ] ,
    ] ,
]
// thing: IS_OBJECT(doc.thing) ? {name:doc.thing.name, owner:IS_OBJECT(doc.thing.owner) ? {name:doc.thing.owner.name} : null} : null
```

### Où le marqueur s'applique — et où il lève

`Filter::DOCUMENT` est le **seul** filtre qui reconstruit un **objet** sans garde propre.
Les autres se gardent déjà eux-mêmes, chacun avec le test qui convient à sa forme — poser
`Field::NULLABLE` sur l'un d'eux serait un no-op silencieux, c'est donc une erreur de
définition qui lève une `UnsupportedOperationException` :

| Filtre | Reconstruit | Source absente | `Field::NULLABLE` |
|---|---|---|---|
| `Filter::DOCUMENT` | un objet | un objet de `null` | ✅ **c'est ici** |
| `Filter::MAP` | un tableau | `[]` — `IS_ARRAY()` déjà posé | ⛔ lève |
| `Filter::WRAP` | un objet | sans objet : projette la référence courante, qui existe par construction | ⛔ lève |
| `Filter::EDGE` / `Filter::JOIN` | un objet | `null` — `IS_OBJECT()` déjà posé | ⛔ lève |
| `Filter::URL` | un lien | une adresse tronquée | ⛔ lève — sa garde est `Field::WHEN`, [section suivante](#le-lien-seulement-sil-y-a-une-clé--fieldwhen-sur-un-filterurl) |

La dernière ligne mérite une seconde lecture. Un `Filter::URL` fabrique lui aussi sans
garde, exactement comme un `Filter::DOCUMENT` — mais à partir d'une **clé scalaire**, pas
d'un objet : `IS_OBJECT()` n'y serait jamais vrai et le champ ne sortirait tout simplement
jamais. `Field::NULLABLE` garde son sens unique ; l'URL se garde avec une condition.

> **Une réserve, à connaître.** Les `Field::EDGES` / `Field::JOINS` déclarés **sous** un
> sous-document gardé continuent d'émettre leur `LET` en amont, même quand la garde rend
> `null`. La requête reste juste, elle n'est pas plus rapide. C'est structurel : un `LET`
> ne se conditionne pas en AQL.

**Rétrocompatibilité.** Sans le marqueur, l'AQL émis est **identique au caractère près** à
celui d'avant : pas de `IS_OBJECT`, pas de ternaire, pas un espace de plus. Toutes les
projections existantes sont inchangées, et un test le fige.

## Le lien, seulement s'il y a une clé — `Field::WHEN` sur un `Filter::URL`

**La situation.** Un `Filter::URL` ne lit pas davantage une adresse stockée : il en
**reconstruit** une, en concaténant une route et la clé du document.

```php
'url' => [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
// url:CONCAT('https://base/things','/',doc._key)
```

Or AQL **ignore** les arguments nuls d'un `CONCAT()`. Un document sans clé ne revient donc
pas sans URL : il revient avec une adresse qui ne mène nulle part, et rien dans la réponse
ne le dit.

Ce n'est pas un cas d'école. Une projection en sous-document reconstruit des **copies
figées** : certaines proviennent d'un enregistrement et en portent la clé, leur lien est
donc légitimement refabriqué à la lecture ; d'autres sont des valeurs saisies à la main,
sans aucun enregistrement derrière elles, donc **sans la moindre clé**. Les deux cohabitent
dans le même champ, distinguées par un discriminant qu'elles portent. Mesuré sur un vrai
serveur, côte à côte :

| La copie stockée | Ce qui sortait |
|---|---|
| `{ "_key": "t9", "additionalType": "Place", "name": "Widget" }` | `{ "name": "Widget", "url": "/things/t9" }` |
| `{ "additionalType": "Text", "name": "Saisi à la main" }` — pas de clé | `{ "name": "Saisi à la main", "url": "/things/" }` |
| `{ "_key": "", "additionalType": "Place", … }` — une clé vide | `{ "name": "Clé vide", "url": "/things/" }` |

**Le remède.** La même grammaire de condition, posée sur l'URL elle-même — l'objet autour
n'est pas gardé, seul le lien s'abstient :

```php
'url' =>
[
    Field::FILTER => Filter::URL ,
    Field::PATH   => '/things' ,
    Field::WHEN   => [ '_key' ] ,        // ← la seule ligne ajoutée
]
```

```aql
url:TO_BOOL(doc._key) ? CONCAT('https://base/things','/',doc._key) : null
```

| La copie stockée | Ce qui sort |
|---|---|
| `{ "_key": "t9", … }` | `{ "name": "Widget", "url": "/things/t9" }` — inchangé |
| pas de clé | `{ "name": "Saisi à la main", "url": null }` |
| une clé vide | `{ "name": "Clé vide", "url": null }` |

> **Pourquoi une condition à un seul élément.** `[ '_key' ]` est une feuille de **véracité**
> (`TO_BOOL()`), pas une égalité. C'est ce qui couvre la clé vide autant que la clé absente
> — les deux produisent exactement le même lien tronqué, et un test `!= null` n'aurait
> attrapé que la seconde.

### Lire le discriminant de la copie

La condition est compilée contre **la référence que la projection lit elle-même**. Pour une
URL projetée dans un sous-document, cette référence *est* le sous-document — un discriminant
porté par la copie décide donc, sans un mot sur le parent :

```php
'thing' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::FIELDS =>
    [
        'name' => [] ,
        'url'  => [ Field::FILTER => Filter::URL , Field::PATH => '/things' , Field::WHEN => [ 'additionalType' , 'Place' ] ] ,
    ] ,
]
// thing:{name:doc.thing.name, url:doc.thing.additionalType == 'Place' ? CONCAT('/things','/',doc.thing._key) : null}
```

C'est aussi ce qui fait que le verrou d'autorisation s'applique mot pour mot, comme sur un
`Filter::DOCUMENT` : les attributs lus par une condition sont vérifiés contre la projection
du niveau **courant**, si bien qu'une URL dont la condition lit un champ refusé disparaît
entièrement au lieu de devenir un oracle sur lui.

> **Une limite, mesurée plutôt que supposée.** Un discriminant dit ce qu'une copie *est*,
> pas si elle est adressable. Une copie qui se déclare `Place` et qui n'a pourtant aucune
> clé utilisable revient avec le lien tronqué — garder sur le type est une autre question
> que garder sur la clé. Quand les deux comptent, `Field::WHEN` accepte un groupe `and`.

**Rétrocompatibilité.** Sans le marqueur, l'AQL émis est inchangé **au caractère près**, sur
la route simple comme sur la route à discriminant (`Field::PATHS`) — pas de ternaire, pas de
test. Deux tests le figent.

## Filtrer les éléments d'un tableau projeté — `Field::WHERE`

`Field::WHEN` décide **la valeur** d'un champ scalaire. `Field::WHERE` décide **quels
éléments** d'un tableau projeté (`Filter::MAP`) sont retournés — un `FILTER` posé dans la
boucle imbriquée, **entre** le `FOR` et le `RETURN` :

```aql
addresses: ( FOR item IN doc.addresses
             FILTER item.region IN @allowedRegions
             RETURN { street: item.street, city: item.city } )
```

Ne pas les confondre :

| Marqueur | Décide | Posé sur | Compilé contre |
|---|---|---|---|
| `Field::WHEN` | la *valeur* d'un champ (ternaire) | projection scalaire par défaut, `Filter::DOCUMENT` ou `Filter::URL` | la référence du niveau projeté — `doc` pour un sous-document, le sous-document lui-même pour une URL imbriquée dedans |
| `Field::NULLABLE` | si l'objet reconstruit est émis (`IS_OBJECT` de la source) | un `Filter::DOCUMENT` | `doc` (le **parent**) |
| `Field::WHERE` | *quels éléments* d'un tableau sont projetés (`FILTER`) | un `Filter::MAP` | l'élément (`item`) |
| `AQL::WHERE` | *quels sommets* une relation projette (`FILTER`) | une **définition** d'edge | le sommet traversé (`vertex`) |

`Field::WHERE` réutilise **exactement** la grammaire de condition de `Field::WHEN` (feuilles,
groupes `AND` / `OR` / `NOT`, `alt`) — compilée contre **l'élément du tableau** (`item`), pas
contre `doc`.

> **La même question, un cran plus loin : `AQL::WHERE`.** Ce que `Field::WHERE` fait pour un
> tableau **embarqué** dans le document, `AQL::WHERE` le fait pour une **relation** (`Filter::EDGE`
> / `EDGES` / `EDGES_COUNT`) : il restreint les sommets traversés, avec cette même grammaire, ce
> même support de `aqlBindRef()` et ce même contrat *fail-closed*. La différence est le siège —
> `Field::WHERE` se déclare sur une **entrée de projection**, `AQL::WHERE` sur une **définition de
> relation**, où il vaut pour tous les points d'entrée à la fois. Le couple reprend celui de
> `Field::REQUIRES` (entrée) et `AQL::REQUIRES` (définition). Détail dans
> [Projection des edges et joins](../edges-joins-projection.md#restreindre-les-sommets-projetés--aqlwhere).

### Comparer à une valeur connue seulement à l'exécution — `aqlBindRef()`

**La situation.** Chaque `user` porte un tableau `addresses[]`, chaque adresse a une
`region`. On veut qu'un appelant ne voie que les adresses de **ses** régions autorisées — et
cette liste n'est connue **qu'à l'exécution**, pas à l'écriture du modèle.

Une condition `WHEN` **inline** ses valeurs : de la configuration figée. Ici la valeur — la
liste des régions — n'existe qu'à la requête. `aqlBindRef('nom')` déclare « cette valeur est
une **variable liée** `@nom`, fournie ailleurs » : le nom est **validé** (règles de bind
ArangoDB), **aucune valeur n'est inlinée**, seul le jeton `@nom` est émis.

**1. Le modèle** (statique) :

```php
use function oihana\arango\db\binds\aqlBindRef ;

'addresses' =>
[
    Field::FILTER => Filter::MAP ,
    Field::WHERE  => [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] ,
    Field::FIELDS => [ 'street' => Filter::DEFAULT , 'city' => Filter::DEFAULT ] ,
]
```

**2. L'appelant fournit les valeurs** (par requête, via le mécanisme existant `AQL::BINDS`) :

```php
$init[ AQL::BINDS ] = [ 'allowedRegions' => [ 'eu-west' , 'eu-north' ] ] ;
```

**3. L'AQL produit** — le jeton `@allowedRegions`, jamais la liste inlinée ; sa valeur voyage
dans la carte `bindVars` **unique** de la requête (fusionnée par `AQL::BINDS`). La projection
n'a qu'à **nommer** le créneau ; l'hôte le **remplit**.

### Le bind peut aussi être à gauche

Un bind **booléen** peut occuper la position d'attribut — un interrupteur fourni à la
requête. `[ aqlBindRef('unrestricted') ]` compile en `@unrestricted` (jeton nu, ni `doc.`, ni
`TO_BOOL`). Utile pour « voit tout, **sauf si** restreint » :

```php
Field::WHERE =>
[ 'or' ,
    [ aqlBindRef( 'unrestricted' ) ] ,                    // → @unrestricted
    [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] , // → item.region IN @allowedRegions
]
// FILTER (@unrestricted || item.region IN @allowedRegions)
```

### Fermé par défaut (*fail-closed*)

Contrairement à `Field::REQUIRES` (ouvert en l'absence d'authorizer), `Field::WHERE`
**ferme** :

- bind lié à un tableau **vide** → `IN []` → **aucun élément** (comportement voulu) ;
- bind **absent** de la carte finale → la requête AQL **échoue** (erreur ArangoDB) → aucune
  donnée. Un bind manquant n'est **jamais** réinterprété en « pas de filtre » (ce serait
  *fail-open*).

Les éléments hors périmètre ne sont **jamais lus** en base : filtre, tri et facette ne
peuvent donc rien en inférer. Le câblage applicatif (résoudre la liste, injecter les binds)
se fait **hors** de la librairie, dans le projet consommateur.

### Champ skinné : le bind orphelin est élagué automatiquement

La situation. Le champ qui porte le `Field::WHERE` est **projeté conditionnellement** : selon
le skin actif (ou un `?fields` explicite), il peut **ne pas** être rendu. Or l'appelant, lui,
a déjà fourni la valeur du bind dans `AQL::BINDS` — il ne peut pas raisonnablement savoir *à
l'avance* si le champ survivra. Résultat : la requête finale ne contient **aucune** référence
`@monBind`, alors que le bind est déclaré. ArangoDB rejette :

```
bind parameter 'monBind' was not declared in the query
```

La responsabilité revient donc à la couche qui exécute la requête. Juste avant l'exécution
(`prepareAndExecute()`, le point de passage **unique** de toutes les requêtes — `get()`,
`list()`, `count()`, `exist()`, edges…), la librairie **retire les binds réellement inutilisés
par le texte de la requête**. Le bind orphelin disparaît, la requête passe.

Ce tri est **borné et sûr** :

- il ne touche **que** les binds déclarés « optionnels » — c'est-à-dire les noms d'`aqlBindRef`
  découverts dans les déclarations du modèle : les projections (`$fields` / `$skinFields`) **et
  les registres de relations** (`$edges` / `$joins`). Un bind qui n'est pas un `aqlBindRef`
  déclaré n'est **jamais** retiré ;
- un bind optionnel n'est retiré **que** s'il est absent du texte ; s'il est référencé, il est
  gardé (le nom est comparé au **jeton complet**, donc `@offers` ne matche pas dans
  `@offersScope`) ;
- il ne fait que **retirer du surplus** : un bind référencé-mais-absent des valeurs échoue
  toujours comme avant. On ne perd donc que la protection d'ArangoDB contre les binds « en
  trop » — sans intérêt pour des requêtes construites par la librairie.

Rien à câbler côté hôte : la source de vérité est le `aqlBindRef` que tu as déjà écrit dans le
champ. Le paramètre `prepareAndExecute( …, $optionalBinds )` (4ᵉ position) reste disponible
pour **forcer** la liste, ou pour **désactiver** le tri en passant `[]`.

Les **registres de relations** comptent autant que les projections. Une définition d'arête ou
de jointure est un arbre de déclarations à part entière : elle peut porter un bind, soit dans
sa propre sous-projection (`AQL::FIELDS`), soit dans un prédicat de définition. Et une relation
est projetée conditionnellement elle aussi — un skin peut l'écarter en entier — donc son bind
se retrouve orphelin exactement de la même façon. C'est pourquoi la découverte lit les quatre
sources, et pas seulement les deux arbres de projection.

## Sécurité

La condition d'un `Field::WHEN` est compilée **inline** ; celle d'un `Field::WHERE` peut en
plus **référencer un bind**. Les deux sont sûres par construction :

- Les **noms d'attributs** (opérandes de condition et `else` valué par attribut) sont
  validés par `assertAttributeName()` — tout caractère capable de s'échapper d'un accesseur
  `doc.<attr>` est rejeté par une `ValidationException`.
- Les **valeurs littérales** sont déclarées par le développeur dans la définition du champ
  (jamais une entrée de requête — celles-ci passent par des binds dans `?filter=`), inlinées
  et échappées par `aqlValue()`.
- Une **référence de bind** (`aqlBindRef('nom')`) n'inline rien : le **nom** est validé par
  `assertBindVariable()`, et seul le jeton `@nom` est émis. La **valeur** est fournie à la
  requête via `AQL::BINDS` — donc jamais concaténée dans le texte AQL, quel que soit son
  contenu.
- Le **contrôle d'autorisation** ne garde pas seulement le champ *porteur* de la condition
  (déjà couvert par son propre `Field::REQUIRES`) : les champs **lus** par la condition
  (`Field::WHEN` / `Field::WHERE`, et la branche `else` valuée par attribut) le sont aussi.
  Si l'un est masqué à la lecture (`Field::REQUIRES` refusé pour cet utilisateur), **tout le
  champ conditionnel est retiré** de la projection — sinon la présence/absence de sa valeur
  (ou la branche `else`) trahirait le champ masqué (oracle d'inférence). *Fail-open* : un
  champ lu **sans** `Field::REQUIRES`, absent de la projection, ou sans authorizer branché,
  laisse le champ conditionnel intact.

## AQL généré — référence

```
price : (TO_BOOL(doc.active) && LOWER(doc.status) == 'public') ? LOWER(TRIM(doc.price)) : doc.basePrice
        └─────────────── condition ───────────────┘            └──── then (+ALTERS) ───┘   └── else ──┘
```

Voir aussi : [Helpers AQL](helpers.md) · [La projection des champs](../projection.md).
