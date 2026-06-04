# Facettes HTTP `?facets=`

Le framework expose, à côté du [filtrage `?filter=`](filter.md), un système de **facettes** sur les routes `GET` adossées à un modèle [`Documents`](../models.md). Là où un filtre compare un **champ scalaire du document courant**, une facette répond à des questions **relationnelles ou multi-valeurs** : « les documents liés à tel sommet », « ceux dont le tableau contient telles valeurs », « ceux ayant un document joint qui matche plusieurs champs »…

Le client envoie son intention en JSON dans le paramètre URL `?facets=`, le framework la convertit en fragments `FILTER` AQL avec *bind variables*, et l'exécute sur la collection cible.

Cette page documente :

1. [Facettes vs filtres](#facettes-vs-filtres) — laquelle utiliser.
2. La [syntaxe URL](#syntaxe-url) `?facets=`.
3. La [déclaration côté modèle](#déclaration-côté-modèle) (`Arango::FACETS` + `Facet::TYPE`).
4. Le [catalogue des types de facettes](#catalogue-des-types-de-facettes), avec exemples concrets et AQL généré.
5. Les [opérateurs `op`](#opérateurs-op), la [négation](#négation) et les [comportements par défaut](#comportements-par-défaut).
6. La [sécurité](#sécurité-et-injection-aql) (anti-injection).

## Facettes vs filtres

| | `?filter=` | `?facets=` |
|---|---|---|
| Cible | un **champ scalaire** du document courant (`doc.x`) | un **champ**, un **tableau**, un **edge** ou un **join** |
| Syntaxe | explicite `{key, op, val, alt}` | compacte par clé : `{"<facette>": <valeur>}` |
| Forces | comparateurs riches, transformations `alt`, AND/OR/imbrication | multi-sélection compacte, existentiels sur relations (edge/join), recherche multi-champs |
| Vocabulaire `op` | `FilterComparator` / `FilterArrayComparator` | **les mêmes** (réutilisés) |

Les deux se combinent dans la même requête (chacun produit une portion du `FILTER`, jointes par `&&`).

## Syntaxe URL

Le paramètre `?facets=` est un **objet JSON** dont chaque clé est le **nom d'une facette déclarée** sur le modèle, et la valeur l'intention de filtrage :

```
?facets={"withStatus":"draft","keywords":"cuisine,jardin"}
```

- Le JSON doit être URL-encodé (la plupart des clients HTTP le font).
- Une clé **absente de la déclaration du modèle est silencieusement ignorée** (sécurité : aucune facette non whitelistée n'est exécutable).
- Une facette dont la construction échoue (valeur invalide, sous-champ dangereux…) est **ignorée et journalisée** (`warning`) — elle ne casse jamais la requête entière.

En PHP :

```php
$facets = [ 'withStatus' => 'draft' , 'keywords' => 'cuisine,jardin' ] ;
$url    = '/articles?facets=' . urlencode( json_encode( $facets ) ) ;
```

## Déclaration côté modèle

Chaque facette exposable est déclarée sous la clé **`Arango::FACETS`** (= `'facets'`) à la construction du modèle. Chaque entrée porte au minimum un **`Facet::TYPE`** :

```php
use oihana\arango\enums\Arango ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Facet ;
use oihana\arango\models\enums\filters\FilterComparator ;

$articles = new Documents
([
    Arango::FACETS =>
    [
        'withStatus' => [ Facet::TYPE => Facet::FIELD ] ,
        'keywords'   => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'keywords' ] ,
        'location'   => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] ,
        'author'     => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] ,
    ]
]) ;
```

> La clé d'URL (`"withStatus"`) et la **propriété document** ciblée peuvent différer : voir [`Facet::PROPERTY`](#alias-de-propriété).

Clés de configuration communes :

| Clé | Rôle | Défaut |
|---|---|---|
| `Facet::TYPE` | Le type de facette (obligatoire). | — |
| `Facet::PROPERTY` | La propriété document visée (alias de la clé d'URL). | la clé de facette |
| `Facet::OP` | L'opérateur de comparaison (selon le type). | `eq` (sauf `IN` → `any.in`, `FIELD` → `match`) |
| `AQL::FIELDS` | Le(s) champ(s) recherché(s) (EDGE/JOIN), CSV ou liste. | `_key` |
| `AQL::EDGE` | La collection d'edges (EDGE / EDGE_COMPLEX). | — |
| `AQL::COLLECTION` | La collection jointe (JOIN / JOIN_COMPLEX). | — |
| `AQL::KEY` | Le champ côté collection jointe. | `_key` |
| `AQL::ARRAY` | Jointure sur un **tableau** de clés (`IN`). | `false` |

## Catalogue des types de facettes

Les exemples ci-dessous utilisent des collections concrètes (celles du harnais d'intégration `FacetIntegrationTest`).

### `Facet::FIELD` — comparaison sur un champ scalaire

Filtre sur une propriété simple du document (statut, identifiant, prix…). Valeurs CSV en `OR`, préfixe `-` pour la négation. **Opérateur par défaut : `match` (`=~`, regex)** — pour de l'égalité exacte, préciser `op: eq`.

```php
'withStatus' => [ Facet::TYPE => Facet::FIELD ] ,
'price'      => [ Facet::TYPE => Facet::FIELD , Facet::OP => FilterComparator::GE ] ,
```
```
?facets={"withStatus":"draft"}                    // (doc.withStatus =~ @0)            ⚠ regex : "draft" matche aussi "predraft"
?facets={"withStatus":"draft,review"}             // (doc.withStatus =~ @0 || doc.withStatus =~ @1)
?facets={"withStatus":"-draft"}                    // (doc.withStatus !~ @0)
?facets={"withStatus":{"op":"eq","val":"draft"}}   // (doc.withStatus == @0)            exact
?facets={"price":{"op":"ge","val":100}}            // (doc.price >= @0)                 numérique (le type est préservé)
?facets={"name":{"op":"like","val":"jo%"}}         // (doc.name LIKE @0)
```
Opérateurs : `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `like`, `nlike`, `match` (défaut), `nmatch`.

### `Facet::IN` — appartenance à un tableau *(alias `LIST`, `LIST_FIELD`, `LIST_FIELD_SORTED`)*

Filtre sur une propriété **tableau** du document. **Opérateur par défaut : `any.in`** (le document possède **au moins une** des valeurs). Accepte une CSV, une liste, ou un objet `{op, val}`.

```php
'keywords' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'keywords' ] ,
```
```
?facets={"keywords":"cuisine,jardin"}                        // TO_ARRAY([@0,@1]) ANY IN doc.keywords   (cuisine OU jardin)
?facets={"keywords":["cuisine","jardin"]}                    // forme tableau, même résultat
?facets={"keywords":{"op":"all.in","val":"cuisine,jardin"}}  // ALL IN  : possède les DEUX
?facets={"keywords":{"op":"none.in","val":["cuisine"]}}      // NONE IN : ne possède AUCUNE
```
Opérateurs (de `FilterArrayComparator`) : `any.in` (défaut), `all.in`, `none.in`, `any.nin`, …

> `LIST`, `LIST_FIELD` et `LIST_FIELD_SORTED` sont des **alias historiques** de `IN` (opérateur `any.in`). `LIST_FIELD_SORTED` ajoute un `SORT POSITION(...)` qui ordonne selon l'ordre des valeurs demandées.

### `Facet::EDGE` — existence d'un sommet lié *(simple)*

« Garder les documents liés (ou non liés) à un sommet via une traversée d'edge **INBOUND** ». Match sur un ou plusieurs champs du sommet (`AQL::FIELDS`, OR), opérateur configurable.

```php
'location' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] ,
```
```
?facets={"location":1234}            // LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @0 RETURN doc_location._key) > 0
?facets={"location":"1234,5678"}     // … == @0 || … == @1 …                            (lié à 1234 OU 5678)
?facets={"location":"-1234"}         // LENGTH(…) == 0                                  (NON lié à 1234)
?facets={"location":"1234,-5678"}    // (LENGTH(…>0) && LENGTH(…==0))                   (lié à 1234 ET pas à 5678)
```
**Recherche multi-champs (l'ex-`THESAURUS`)** — chercher un terme dans plusieurs champs du sommet lié avec `like` :
```php
'subjects' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'has_subject' ,
                AQL::FIELDS => '_key,name,alternateName' , Facet::OP => 'like' ] ,
```
```
?facets={"subjects":"art"}  // LENGTH(FOR doc_subjects IN INBOUND doc has_subject
                            //   FILTER (doc_subjects._key LIKE @0 || doc_subjects.name LIKE @0 || doc_subjects.alternateName LIKE @0)
                            //   RETURN doc_subjects._key) > 0
```

### `Facet::EDGE_COMPLEX` — sommet lié matchant plusieurs champs *(complexe)*

Comme `EDGE`, mais la valeur est un **objet** `{champ: condition}` et **tous** les champs doivent matcher **le même sommet** (AND). Chaque champ accepte une valeur, une liste (OR) et la négation `-` (inline `!=`).

```php
'numbers' => [ Facet::TYPE => Facet::EDGE_COMPLEX , AQL::EDGE => 'livestocks_has_numbers' ] ,
```
```
?facets={"numbers":{"value":"459"}}                  // LENGTH(FOR doc_numbers IN INBOUND doc livestocks_has_numbers FILTER doc_numbers.value == @… RETURN doc_numbers._key) > 0
?facets={"numbers":{"value":"459","kind":"ear"}}     // … value == @ && kind == @ …      (même sommet)
?facets={"numbers":{"value":["459","460"]}}          // … (value == @0 || value == @1) …
?facets={"numbers":{"value":"-459","kind":"ear"}}    // … value != @ && kind == @ …      (négation interne au même sommet)
```

### `Facet::JOIN` — existence d'un document joint par clé *(simple)*

Le pendant **key-join** d'`EDGE` (pas d'edge : une jointure par attribut). « Garder les documents ayant au moins un document joint dont le champ matche la valeur ». La jointure est `doc_join.<KEY> == doc.<PROPERTY>`.

```php
'author' => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' ,
              Facet::PROPERTY => 'authorId' , AQL::KEY => '_key' , AQL::FIELDS => 'name' ] ,
```
```
?facets={"author":"alice"}        // LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && doc_author.name == @0 RETURN 1) > 0
?facets={"author":"alice,bob"}    // … && (doc_author.name == @0 || doc_author.name == @1) …
?facets={"author":"-spammer"}     // … == 0                                              (exclut les posts liés à "spammer")
?facets={"author":{"op":"like","val":"al"}}  // … doc_author.name LIKE @0 …
```
- `AQL::KEY` : le champ côté collection jointe (défaut `_key`). `Facet::PROPERTY` : le champ côté document principal (défaut = la clé de facette).
- `AQL::ARRAY => true` : la jointure devient `doc_join.<KEY> IN doc.<PROPERTY>` (le document principal porte un **tableau** de clés).

### `Facet::JOIN_COMPLEX` — document joint matchant plusieurs champs *(complexe)*

Le pendant key-join d'`EDGE_COMPLEX`. Valeur **objet** `{champ: condition}`, champs **ANDés** sur le même document joint.

```php
'comments' => [ Facet::TYPE => Facet::JOIN_COMPLEX , AQL::COLLECTION => 'comments' ,
                AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ,
```
```
?facets={"comments":{"status":"approved"}}              // LENGTH(FOR doc_comments IN comments FILTER doc_comments.postId == doc._key && doc_comments.status == @… RETURN 1) > 0
?facets={"comments":{"status":"approved","score":"5"}}  // … status == @ && score == @ …
?facets={"comments":{"status":["a","b"]}}               // … (status == @0 || status == @1) …
?facets={"comments":{"status":"-spam"}}                 // … status != @ …                 (négation interne)
```
Topologies couvertes par `AQL::KEY` / `Facet::PROPERTY` / `AQL::ARRAY` : 1-1 (le doc porte la clé), 1-n inversé (les docs joints référencent le doc), 1-n par tableau.

### `Facet::ARRAY_COMPLEX` — tableau d'objets embarqués *(complexe)*

« Garder les documents dont une propriété **tableau embarqué** contient au moins un élément matchant les conditions ». Valeur **objet** `{sous-champ: condition}`.

```php
'workshops' => [ Facet::TYPE => Facet::ARRAY_COMPLEX ] ,
```
```
?facets={"workshops":{"breeding.alternateName":"pig"}}            // LENGTH(FOR doc_workshops IN doc.workshops FILTER doc_workshops.breeding.alternateName == @… RETURN 1) > 0
?facets={"workshops":{"breeding.alternateName":["pig","cattle"]}} // … == @0 || == @1 …    (un élément pig OU cattle)
?facets={"workshops":{"breeding.alternateName":["-pig","cattle"]}}// … != @0 && != @1 …    (un élément ni pig ni cattle)
```

## Opérateurs `op`

Les facettes **réutilisent le vocabulaire des filtres** — aucun code maison :

- Scalaire ([`FilterComparator`](filter.md#opérateurs)) : `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `like`, `nlike`, `match`, `nmatch`.
- Tableau ([`FilterArrayComparator`](filter.md)) : `any.in`, `all.in`, `none.in`, `any.nin`, `all.nin`, `none.nin`, …

L'`op` se déclare soit en config (`Facet::OP`), soit par requête dans un objet `{ "op": "…", "val": … }`. Un `op` inconnu retombe sur le défaut du type (jamais d'injection — voir plus bas).

## Transformations `alt`

Comme les [filtres](filter.md#transformations-alt), une facette peut envelopper la comparaison par des fonctions AQL (`lower`, `trim`, `abs`, `dateDay`…). `alt` agit sur le **champ comparé** (gauche) et/ou la **valeur** (droite) :

- `alt:"lower"` / `alt:["trim","lower"]` → **champ seul** (`LOWER(doc.x) == @v`).
- `alt:{ "key":<chaîne>, "val":<chaîne> }` → une chaîne par côté.
- `alt:{ "key":<chaîne>, "val":true }` → `val:true` = **miroir** (même chaîne des deux côtés), pour une comparaison symétrique (ex. égalité insensible à la casse).

### Deux endroits, l'URL l'emporte

`alt` se déclare **soit dans la définition du modèle** (`Facet::ALT`, défaut pour toutes les requêtes), **soit dans la requête URL** (`{op,val,alt}`, au cas par cas). Si les deux sont présents, **l'URL gagne** — exactement comme `op`.

**① Figé dans la définition** — l'email est insensible à la casse pour tout le monde ; le client envoie une valeur brute :
```php
Arango::FACETS => [
    Prop::EMAIL => [
        Facet::TYPE => Facet::FIELD ,
        Facet::OP   => FilterComparator::EQ ,
        Facet::ALT  => [ 'key' => 'lower' , 'val' => true ] , // défaut appliqué à chaque requête
    ] ,
]
```
```
?facets={"email":"JEAN@X.COM"}
// (LOWER(doc.email) == LOWER(@0))
```

**② Fourni par l'URL** — aucun `alt` en définition, le client décide :
```
?facets={"email":{"op":"eq","val":"JEAN@X.COM","alt":{"key":"lower","val":true}}}
// (LOWER(doc.email) == LOWER(@0))
```

**③ L'URL surcharge la définition** — définition `upper`, requête `lower` ⇒ c'est `lower` :
```
?facets={"email":{"val":"jean@x.com","alt":{"key":"lower","val":true}}}
// (LOWER(doc.email) == LOWER(@0))
```

### Sur les facettes liées (EDGE / JOIN)

`alt` enveloppe le **champ du document lié** et la valeur, à l'intérieur du `LENGTH(…)` :
```php
Prop::LOCATION => [
    Facet::TYPE => Facet::EDGE , Facet::EDGE => 'orgs_places' ,
    AQL::FIELDS => 'name' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ,
]
```
```
?facets={"location":"paris"}
// LENGTH(FOR v IN INBOUND doc orgs_places FILTER LOWER(v.name) == LOWER(@0) RETURN …) > 0
```

> ⚠️ **Extracteurs vs normaliseurs** — même règle que les filtres : pour un **extracteur** (`dateYear`, `count`…) la valeur fournie est *déjà* la cible, gardez la forme chaîne `alt:"dateYear"` (champ seul) ; pour un **normaliseur symétrique** (`lower`, `abs`…), utilisez la forme objet ou `val:true`.

### Sur les facettes complexes (`EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX`)

Pour les facettes complexes, `alt` se déclare **uniquement dans la définition** (`Facet::ALT`) et s'applique **globalement à tous les sous-champs** de l'objet `{sous-champ : condition}` :

```php
Prop::NUMBERS => [
    Facet::TYPE => Facet::EDGE_COMPLEX , Facet::EDGE => 'has_numbers' ,
    Facet::ALT  => [ 'key' => 'lower' , 'val' => true ] , // s'applique à CHAQUE sous-champ
]
```
```
?facets={"numbers":{"value":"459","kind":"EAR"}}
// LENGTH(FOR v IN … FILTER LOWER(v.value) == LOWER(@0) && LOWER(v.kind) == LOWER(@1) RETURN …) > 0
```
La clé structurelle de jointure (`doc_x.<KEY> == doc.<PROPERTY>` d'un `JOIN_COMPLEX`) n'est **jamais** enveloppée — seules les conditions de sous-champs le sont.

> **Limite (volontaire, Option A).** Pour les complexes, `alt` est **global** : on ne peut pas (encore) cibler un seul sous-champ, ni le fournir par l'URL au cas par cas. C'est le cas d'usage principal (« cette facette liée est insensible à la casse »). La granularité **par sous-champ** (forme `{sous-champ:{val,alt}}` dans l'URL) est possible techniquement mais **non prévue à ce stade** — elle pourra être ajoutée si un besoin concret apparaît.

### Sur la facette `Facet::IN` (membership tableau)

`Facet::IN` (et ses alias `LIST` / `LIST_FIELD` / `LIST_FIELD_SORTED`) accepte `alt` côté définition **et** URL, comme FIELD/EDGE/JOIN. Particularité : la propriété comparée est un **tableau**, donc le côté champ est **projeté élément par élément** (`doc.tags[* RETURN LOWER(CURRENT)]`) — un simple `LOWER(doc.tags)` renverrait `null`. Le côté valeur enveloppe chaque valeur demandée, et le `SORT POSITION(...)` éventuel reste cohérent :

```
?facets={"tags":{"val":["TECH","News"],"alt":{"key":"lower","val":true}}}
// TO_ARRAY([LOWER(@0),LOWER(@1)]) ANY IN doc.tags[* RETURN LOWER(CURRENT)]
```

Couvert : **`FIELD`, `EDGE`, `JOIN`, `IN`** (+ alias `LIST*`) — champ + valeur, définition **ou** URL — et **`EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX`** (global via la définition). Aucun risque d'injection : les noms de fonctions sont sur liste blanche (une fonction inconnue est sans effet), seules les valeurs sont liées.

## Négation

La sémantique du préfixe `-` **dépend du type**, et c'est volontaire :

| Type | `-valeur` signifie | AQL |
|---|---|---|
| `FIELD` | bascule l'opérateur vers sa forme négative (`match`→`nmatch`, `eq`→`ne`, `like`→`nlike`) ; groupe ANDé | `doc.x !~ @` |
| `IN` | utiliser `op: none.in` au niveau ensemble | `… NONE IN doc.x` |
| `EDGE` / `JOIN` *(simple)* | **exclusion** : le document n'est lié à aucune valeur niée | `LENGTH(…) == 0` |
| `EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX` | **négation interne** : il existe un doc lié dont le champ **≠** valeur | `… != @ …` (dans `LENGTH(…) > 0`) |

> Pour les facettes complexes, la négation est **existentielle interne** (« il existe un élément ≠ X »), pas « exclure les documents contenant X » — c'est la seule sémantique cohérente avec le couplage multi-champs sur le même document lié.

## Comportements par défaut

| Type | `op` défaut | Champ(s) défaut | Forme de valeur |
|---|---|---|---|
| `FIELD` | `match` (`=~`) | la clé (ou `Facet::PROPERTY`) | scalaire / CSV / `{op,val,alt}` |
| `IN` (+ alias) | `any.in` | la clé (ou `Facet::PROPERTY`) | CSV / liste / `{op,val,alt}` |
| `EDGE` | `eq` | `_key` (`AQL::FIELDS`) | scalaire / CSV / `{op,val,alt}` |
| `JOIN` | `eq` | `_key` (`AQL::FIELDS`) | scalaire / CSV / `{op,val,alt}` |
| `EDGE_COMPLEX` | `eq`/`!=` par champ | clés de l'objet | objet `{champ:cond}` *(+ `Facet::ALT` global)* |
| `JOIN_COMPLEX` | `eq`/`!=` par champ | clés de l'objet | objet `{champ:cond}` *(+ `Facet::ALT` global)* |
| `ARRAY_COMPLEX` | `eq`/`!=` par champ | clés de l'objet | objet `{champ:cond}` *(+ `Facet::ALT` global)* |

Plusieurs facettes d'une même requête sont jointes par `&&`.

## Sécurité et injection AQL

Le contrat est strict : **seules les valeurs liées (`@bind`) sont sous contrôle de l'utilisateur**.

- Les **valeurs** passent toujours par un *bind* paramétré (jamais injectables).
- Les **opérateurs** sont whitelistés (`getAlias` → défaut si inconnu).
- Les **clés de facette** sont whitelistées (`Arango::FACETS` du modèle ; clé absente → ignorée).
- Les **noms de sous-champs** des facettes complexes (issus de l'URL et concaténés dans `doc.<champ>`) sont validés par [`assertAttributeName`](helpers.md#garde-anti-injection--isattributename--assertattributename) : un nom dangereux fait échouer la facette (ignorée + `warning`), aucun fragment n'atteint l'AQL.

## Voir aussi

- [Filtres HTTP `?filter=`](filter.md) — comparateurs, transformations `alt`, conditions composées.
- [Helpers AQL `db/helpers/`](helpers.md) — `isAttributeName` / `assertAttributeName`, introspection AQL.
- [Bind variables `db/binds/`](binds.md) — placeholders sûrs.
- [Modèles `Documents` et `Edges`](../models.md) — déclaration `Arango::FACETS`.
