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
7. Les [compteurs de facettes `?facetCounts=`](#compteurs-de-facettes-facetcounts) (ventilations à côté de la liste).

## Facettes vs filtres vs recherche

Les facettes sont l'un des trois leviers de filtrage d'un modèle, aux côtés de [`?filter=`](filter.md) et [`?search=`](search/README.md). Le **tableau comparatif complet** (cible, syntaxe, déclaration, forces, socle commun, « quand utiliser quoi ») vit dans la page-pont [**Recherche & filtrage**](search-and-filtering.md).

En bref : `?facets=` brille pour la **multi-sélection compacte** et les **existentiels/agrégats sur relations** (edge/join) que les filtres n'expriment pas ; il **réutilise le même vocabulaire `op` et le même moteur `alt`** que les filtres. Les trois se combinent dans la même requête (chacun produit une portion du `FILTER`, jointes par `&&`).

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

### `Facet::EDGE_AGGREGATE` / `Facet::JOIN_AGGREGATE` — agrégat sur les documents liés

Au lieu de tester la simple **existence** d'un document lié (`EDGE`/`JOIN` ⇒ `LENGTH(…) > 0`), ces facettes **agrègent un champ numérique** sur **tous** les documents liés et comparent le résultat à un seuil :

```
AGG(FOR doc_x IN <source> [FILTER <jointure>] RETURN doc_x.<champ>) <op> @seuil
```

- `EDGE_AGGREGATE` remonte les sommets liés par traversée **`INBOUND doc <edge>`** (pas de FILTER) ;
- `JOIN_AGGREGATE` itère une collection avec **`FILTER doc_x.<KEY> == doc.<PROPERTY>`** (ou `IN` si `AQL::ARRAY`).

La valeur de requête est l'objet **`{agg, field, op, val}`** ; chaque clé est facultative côté URL et retombe sur la définition :

| Clé | Rôle | Défaut |
|---|---|---|
| `agg` | l'agrégateur : `avg`, `sum`, `min`, `max`, `count` | `Facet::AGG`, sinon `count` |
| `field` | le champ numérique agrégé du document lié (ignoré par `count`) — **doit appartenir à la liste blanche `AQL::FIELDS`** (voir *Permission du champ agrégé*) | `AQL::FIELDS` (1er élément) |
| `op` | le comparateur du seuil (`ge`, `gt`, `le`, `lt`, `eq`, `ne`) | `Facet::OP`, sinon `ge` |
| `val` | le seuil (numérique) — **requis** (sinon la facette est ignorée) | — |

Une valeur **scalaire** est lue comme le seuil directement (`?facets={"comments":5}` ⇒ défauts `count`/`ge`).

#### Exemple 1 — `JOIN_AGGREGATE` (jointure par clé)

Des **articles** et leurs **commentaires** ; un commentaire pointe son article par `articleId`. On veut *« les articles dont la note moyenne des commentaires est ≥ 4 »*.

```php
'comments' => [
    Facet::TYPE     => Facet::JOIN_AGGREGATE ,
    AQL::COLLECTION => 'comments' ,  // collection jointe
    AQL::KEY        => 'articleId' , // champ côté joint     (défaut _key)
    Facet::PROPERTY => '_key' ,      // champ côté principal (défaut = la clé de facette)
    Facet::AGG      => 'avg' ,       // agrégateur par défaut
    AQL::FIELDS     => 'score' ,     // champ agrégé par défaut
    Facet::OP       => 'ge' ,        // comparateur par défaut
] ,
```
```
?facets={"comments":{"agg":"avg","field":"score","op":"ge","val":4}}
// (LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0
//  && AVERAGE(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.score) >= @comments_0)

?facets={"comments":{"val":4}}                       // identique (défauts de la config : avg / score / ge)
?facets={"comments":{"agg":"count","val":3}}         // au moins 3 commentaires
?facets={"comments":{"agg":"sum","field":"score","val":10}}    // somme des notes ≥ 10
?facets={"comments":{"agg":"min","field":"score","val":3}}     // pire note ≥ 3
?facets={"comments":{"agg":"count","op":"lt","val":2}}         // peu commentés (moins de 2, mais au moins 1)
```
`AQL::ARRAY => true` fait passer la jointure à `doc_x.<KEY> IN doc.<PROPERTY>` (le doc principal porte un tableau de clés).

#### Exemple 2 — `EDGE_AGGREGATE` (graphe par arête)

Des **organisations** et leurs **bilans** annuels reliés par une arête `balance_edges` (le bilan pointe l'org). On veut *« les organisations dont le CA moyen des bilans reliés ≥ 1 000 000 »*.

```php
'balanceSheets' => [
    Facet::TYPE => Facet::EDGE_AGGREGATE ,
    AQL::EDGE   => 'balance_edges' , // collection d'arêtes (INBOUND doc)
    Facet::AGG  => 'avg' ,
    AQL::FIELDS => 'revenue' ,
    Facet::OP   => 'ge' ,
] ,
```
```
?facets={"balanceSheets":{"agg":"avg","field":"revenue","op":"ge","val":1000000}}
// (LENGTH(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) > 0
//  && AVERAGE(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) >= @balanceSheets_0)

?facets={"balanceSheets":{"agg":"sum","field":"revenue","val":5000000}}  // CA cumulé ≥ 5 M
?facets={"balanceSheets":{"agg":"count","op":"ge","val":3}}              // au moins 3 bilans déposés
?facets={"balanceSheets":{"agg":"max","field":"revenue","val":2000000}} // meilleure année ≥ 2 M
```

> **`count` généralise l'existentiel.** `{"agg":"count","op":"gt","val":0}` reproduit exactement le `LENGTH(…) > 0` des facettes `EDGE`/`JOIN`.

> ⚠️ **Ensembles liés vides.** Une facette aggregate ne matche **que** les documents ayant **au moins un** document lié (d'où la garde `LENGTH(…) > 0 && …` dans l'AQL). C'est volontaire : en AQL `AVERAGE([])`/`MIN([])`/`MAX([])` valent `null` (et `SUM([])`/`COUNT([])` valent `0`), et comme `null` se trie **sous** tout nombre, un seuil `lt`/`le` ferait sinon remonter par accident les documents **sans aucun** lié.
>
> Exemple : avec trois orgs `o1` (bilans 1,2 M / 0,9 M), `o2` (bilan 0,2 M) et `o3` (aucun bilan), la requête `?facets={"balanceSheets":{"agg":"min","field":"revenue","op":"lt","val":500000}}` ne renvoie **que `o2`** — `o3` est exclu par la garde, alors que sans elle `MIN([]) = null < 500000` l'aurait fait remonter.

> **Pas de négation `-` ni d'`alt`** sur les facettes aggregate : l'`op` porte déjà le sens (`ne`/`lt`/…), et le champ comme le seuil sont numériques. Un `field` venant de l'URL est validé (`assertAttributeName`) **et doit appartenir à la liste blanche `AQL::FIELDS`** (voir ci-dessous) ; un `agg` inconnu rend la facette sans effet (ignorée + logguée).

#### Permission du champ agrégé (liste blanche `AQL::FIELDS` + `AQL::MODEL`)

Le champ agrégé peut venir de l'**URL** (`{"field":"…"}`). Laissé libre, il serait un **oracle** : `{"agg":"max","field":"salary",…}` + une dichotomie sur `val` reconstruit une **borne** d'un champ pourtant caché. Deux verrous le ferment.

**Verrou 1 — liste blanche, fermée par défaut.** L'URL ne peut choisir qu'un champ **déclaré** dans `AQL::FIELDS` : une **chaîne** = le seul champ autorisé, une **liste** = l'ensemble autorisé (son 1ᵉʳ élément est le défaut). Un champ hors liste — ou n'importe quel champ si **rien** n'est déclaré — **neutralise la facette** (`false`).

La situation. Une facette qui déclare un seul champ, et une requête qui en réclame un autre :

```php
'balanceSheets' => [ Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ,
```
```
?facets={"balanceSheets":{"agg":"max","field":"revenue","val":X}}   // ✅ dans la liste → agrège revenue
?facets={"balanceSheets":{"agg":"max","field":"salary","val":X}}    // ❌ hors liste → facette neutralisée (false)
```
Pour autoriser plusieurs champs : `AQL::FIELDS => [ 'revenue' , 'ebitda' ]`.

**Verrou 2 — verrou de lecture du champ cible (opt-in `AQL::MODEL`).** Si la facette déclare son **modèle cible**, le champ agrégé hérite du `Field::REQUIRES` de ce modèle, **par utilisateur** :

```php
'balanceSheets' => [
    Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'balance_edges' ,
    AQL::FIELDS => [ 'revenue' , 'ebitda' ] , AQL::MODEL => Models::BALANCE ,
] ,
```

Si `revenue` porte `Field::REQUIRES => 'finance:read'` dans le modèle `BALANCE`, la facette est **neutralisée** pour un utilisateur sans ce droit (et agrège normalement pour un utilisateur autorisé). Sans `AQL::MODEL`, ce verrou est **sauté** — seule la liste blanche s'applique.

> ⚠️ **Migration.** Une facette aggregate qui laissait l'URL choisir le `field` **sans** déclarer `AQL::FIELDS` doit désormais le déclarer (même bascule *fermée par défaut* que `?sort=` / `?groupBy=`). Une facette qui déclarait déjà `AQL::FIELDS` et interroge ce même champ est inchangée.

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

La facette `FIELD` accepte aussi l'opérateur **`between`** (plage inclusive), avec les clés `min`/`max` au lieu de `val` ; une borne omise abandonne son côté (comparaison unilatérale) :

```
?facets={"price":{"op":"between","min":100,"max":200}}
// (doc.price >= @price_min && doc.price <= @price_max)
```

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

## Permission (`REQUIRES`)

Comme les filtres, une facette sur un champ **caché à la lecture** (`Field::REQUIRES`) fuit : utilisée comme filtre elle laisse ce champ restreindre le jeu ; via `?facetCounts=`, elle renvoie ses **valeurs distinctes et leurs comptes en clair** (oracle direct).

La permission se résout par **héritage** du champ homonyme de `$fields`, **ou** par un `Field::REQUIRES` posé directement sur la définition de facette (elle est déjà un tableau) :

```php
public array $fields = [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
public array $facets = [ 'salary' => [ Facet::TYPE => Facet::FIELD ] ] ; // hérite de $fields
// ou explicite : 'salary' => [ Facet::TYPE => Facet::FIELD , Field::REQUIRES => 'hr:read' ]
```

| Surface | Refusée → |
|---|---|
| `?facets=` (facette-filtre) | **neutralisée en `false`** (comme un filtre — jamais élargie) |
| `?facetCounts=` (distribution) | **dimension écartée** (retire une sortie, n'assouplit rien) |

> **Fail-open** identique aux filtres (aucun `REQUIRES` ou aucun *authorizer* → facette normale). **Champ agrégé** : le champ d'une facette aggregate est verrouillé par **liste blanche** (`AQL::FIELDS`) et, en option, par le `Field::REQUIRES` du **modèle cible** (`AQL::MODEL`) — voir *Permission du champ agrégé*. **Facettes de relation** (EDGE/JOIN) : verrouillées explicitement par un `Field::REQUIRES` posé **sur la facette** (pas d'héritage automatique depuis une relation homonyme). Voir [La projection des champs](../projection.md) et [Tri](sort.md#permission-de-tri).

## Compteurs de facettes `?facetCounts=`

Les facettes ci-dessus **filtrent** la liste. Pour afficher, à côté de la liste, le **nombre de documents par valeur** de chaque facette (la barre latérale « Catégorie : Cuisine (42), Voyage (17) »), on demande des **compteurs**. Un compteur ne **restreint jamais** la liste — il dénombre sur ce que la liste affiche déjà, donc il n'entre jamais en conflit avec `?filter=` / `?facets=` (il en *hérite* les filtres) :

```
GET /articles?facetCounts=category,keywords
```

- Les dimensions sont des **clés de `Arango::FACETS`** déjà déclarées (les facettes filtrables deviennent les facettes comptées) ; une clé inconnue est ignorée.
- v1 supporte les types `Facet::FIELD` (champ scalaire) et `Facet::IN` (appartenance à un tableau, dépliée), plus les **sous-champs de tableaux d'objets via `[*]`** (ex. `offers[*].priceCurrency`, voir plus bas) ; les autres types sont ignorés.
- Les comptes sont **conjonctifs** : calculés sur l'ensemble **déjà filtré** (mêmes `?filter` / `?facets` / `?search` que la liste). Avec une [recherche View](search/overview.md) active, chaque sous-requête de comptage itère la View avec le **même `SEARCH`** que la liste, donc les buckets reflètent exactement l'ensemble affiché.

Les buckets sont renvoyés sous la clé `facets` de l'enveloppe de succès standard,
à côté de `total`, **sans modifier** la liste de documents :

```json
{
  "status": "success",
  "url": "https://api.example.org/articles?facetCounts=category,keywords",
  "count": 50,
  "total": 120,
  "facets": {
    "category": [ {"value":"Cuisine","count":42}, {"value":"Voyage","count":17} ],
    "keywords": [ {"value":"bio","count":31}, {"value":"local","count":12} ]
  },
  "result": [ /* …documents filtrés… */ ]
}
```

AQL généré (une sous-requête `LET` par dimension, cf. [`aqlCollect`](../aql/aql-operations.md#aqlcollect)) :
```aql
LET category = (FOR doc IN @@articles FILTER <mêmes filtres> COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN { value, count })
LET keywords = (FOR doc IN @@articles FILTER <mêmes filtres> FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN { value, count })
RETURN { category, keywords }
```

### Compter un sous-champ d'un tableau d'objets (`[*]`)

Le côté comptage atteint les **mêmes chemins** que les côtés [filtre](filter.md) et
[recherche](search/overview.md) acceptent déjà. Un `Facet::PROPERTY` portant le
marqueur d'expansion `[*]` (ex. `offers[*].priceCurrency`) compte un **sous-champ
d'un tableau d'objets embarqué** : le tableau est déplié et le sous-champ projeté,
de sorte que **chaque élément compte pour son propre bucket**. C'est de la pure
parité de notation — cela n'ajoute aucun pouvoir de restriction et ne **couple
pas** les compteurs de facettes à `?filter=`.

Soit un produit avec un tableau `offers` embarqué :

```json
{ "_key": "prod-1", "category": "outillage",
  "offers": [ { "priceCurrency": "EUR" }, { "priceCurrency": "USD" } ] }
```

On déclare la facette comptable, pointant sur le sous-champ du tableau :

```php
Arango::FACETS => [
    'currency' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].priceCurrency' ] ,
]
```
```
GET /products?filter={"category":"outillage"}&facetCounts=currency
```

La sous-requête de comptage déplie le tableau et projette le sous-champ (et hérite
toujours du filtre de la liste) :

```aql
LET currency = (FOR doc IN @@products FILTER doc.category == @0
                FOR item IN doc.offers
                COLLECT value = item.priceCurrency WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
```
```json
"facets": { "currency": [ {"value":"EUR","count":120}, {"value":"USD","count":45} ] }
```

- Le marqueur `[*]` est le signal : il **prime** sur le type `FIELD` / `IN` déclaré.
- **Chaque `[*]` est une boucle `FOR`**, donc les tableaux d'objets imbriqués sont comptés par feuille ; le chemin entre deux marqueurs descend à l'intérieur de l'élément (`a[*].b.c[*].d`).
- Le conteneur et le sous-champ sont gardés par [`assertAttributeName`](helpers.md#garde-anti-injection--isattributename--assertattributename) : un chemin dangereux fait échouer la facette, sans jamais atteindre l'AQL.
- `offers[*]` sans sous-champ compte l'élément lui-même (comme une facette `IN` simple).

Les tableaux imbriqués se déplient d'un cran par marqueur — ex. `offers[*].prices[*].currency` :

```aql
LET currency = (FOR doc IN @@products FILTER <mêmes filtres>
                FOR item  IN doc.offers
                FOR item2 IN item.prices
                COLLECT value = item2.currency WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
```

> C'est le bon outil quand on veut **plusieurs ventilations indépendantes** dans une réponse. Pour transformer la liste elle-même en **une** agrégation, voir le [Regroupement `?groupBy=` / `?group=`](grouping.md).

### Compter des documents distincts par bucket (`Facet::DISTINCT`)

La situation. Une facette qui **déplie un tableau** — que ce soit un sous-champ
`[*]` (`offers[*].sellerId`) ou une facette d'appartenance `Facet::IN`
(`keywords`) — compte par défaut les **éléments** du tableau, pas les documents.
Si le **même** vendeur apparaît dans 3 offres du **même** produit, ce produit est
compté **3 fois** dans le bucket.

C'est cohérent tant qu'on veut « combien d'éléments matchent ». Mais une barre
latérale d'UI attend en général « combien de **documents** matchent » — le même
nombre que renverrait le filtre d'existence équivalent
`?filter={"key":"offers[*].sellerId","val":"X"}`, qui compte des **documents**
(`LENGTH(...) > 0`). Le compteur par éléments affiche alors un nombre **gonflé**
qui ne correspond pas au nombre de résultats du filtre.

Soit un produit dont le même `sellerId` se répète sur plusieurs offres :

```json
{ "_key": "prod-1",
  "offers": [ { "sellerId": "acme" }, { "sellerId": "acme" }, { "sellerId": "globex" } ] }
```

- Compte **par éléments** (défaut) : `acme` → 2, `globex` → 1.
- Compte **par documents** : `acme` → 1, `globex` → 1 (le produit apparaît une
  seule fois par bucket, comme avec `?filter=`).

Pour basculer sur le comptage par documents, on pose l'option **opt-in**
`Facet::DISTINCT => true` sur la déclaration de la facette :

```php
Arango::FACETS => [
    'seller' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].sellerId' , Facet::DISTINCT => true ] ,
]
```

Seule l'**agrégation** change : le `WITH COUNT INTO count` devient un
`AGGREGATE count = COUNT_DISTINCT( doc._key )`. Le dépliage, le tri et la
projection `{ value, count }` restent identiques :

```aql
LET seller = (FOR doc IN @@products FILTER <mêmes filtres>
              FOR item IN doc.offers
              COLLECT value = item.sellerId AGGREGATE count = COUNT_DISTINCT( doc._key )
              SORT count DESC RETURN { value, count })
```

- **Opt-in, rétro-compatible** : sans le flag, le comportement (compte par
  éléments) est **inchangé**.
- S'applique à **toutes les facettes qui déplient** : les sous-champs `[*]`
  (mono‑ et multi‑hops) **et** la famille `Facet::IN` / `Facet::LIST` /
  `Facet::LIST_FIELD` / `Facet::LIST_FIELD_SORTED`.
- Le « distinct » porte **toujours** sur la clé du **document racine**
  (`doc._key`), quelle que soit la profondeur des `[*]` (`a[*].b[*].c` compte
  quand même des documents racine distincts).
- **Sans effet** sur une facette scalaire `Facet::FIELD` : elle émet déjà une
  ligne par document, donc le flag est ignoré (le `WITH COUNT` est conservé).
- Ne touche ni à `?facetsOnly=` ni au `total` exact — ils proviennent déjà d'un
  `count()` dédié.

### Les comptes sans les documents (`?facetsOnly=`)

Une barre latérale de recherche à facettes n'a souvent besoin **que des comptes**
— les documents sont chargés par un appel paginé séparé. Ajoutez
`?facetsOnly=true` pour **sauter entièrement la requête documents** : le tableau
`result` revient vide, tandis que les buckets `facets` et un **`total` exact**
sont quand même calculés.

```
GET /products?facetCounts=category&facetsOnly=true
```

```json
{
  "status": "success",
  "count": 0,
  "total": 120,
  "facets": {
    "category": [ {"value":"tools","count":80}, {"value":"garden","count":40} ]
  },
  "result": []
}
```

- **Pourquoi pas `?limit=0` ?** `limit=0` veut dire **aucune limite** (tout
  renvoyer) — ce n'est *pas* « zéro résultat ». `?facetsOnly=` est le signal dédié,
  sans ambiguïté.
- Le `total` est **exact** dans tous les cas (facettes scalaires *et* tableaux) :
  il provient d'une requête `count()` dédiée qui hérite des **mêmes** `?filter=` /
  `?facets=` / `?search=` que les comptes, jamais de la somme des buckets
  (potentiellement multi-valués).
- Accepte toute forme booléenne : `true`, `1`, `yes`, `on`.
- Employé **seul** (sans `?facetCounts=`), il renvoie quand même le `total` exact
  avec un `result` vide et sans `facets` — une sonde « combien y en a-t-il ? » peu
  coûteuse.

## Voir aussi

- [Filtres HTTP `?filter=`](filter.md) — comparateurs, transformations `alt`, conditions composées.
- [Regroupement HTTP `?groupBy=` / `?group=`](grouping.md) — transformer la liste en agrégation.
- [Helpers AQL `db/helpers/`](helpers.md) — `isAttributeName` / `assertAttributeName`, introspection AQL.
- [Bind variables `db/binds/`](binds.md) — placeholders sûrs.
- [Modèles `Documents` et `Edges`](../models.md) — déclaration `Arango::FACETS`.
