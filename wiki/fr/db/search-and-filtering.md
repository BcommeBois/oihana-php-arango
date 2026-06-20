# Recherche & filtrage

Trois paramètres d'URL permettent de **restreindre** la liste renvoyée par un modèle `Documents` : `?search=`, `?filter=` et `?facets=`. Cette page est le point d'entrée : elle explique **quand utiliser lequel**, ce qu'ils ont **en commun**, et comment ils se **combinent**. Chacun a ensuite sa page dédiée.

| Paramètre | Pour quoi | Page |
|---|---|---|
| [`?search=`](search.md) | Recherche « plein texte » simple : un terme `LIKE` sur plusieurs champs déclarés | [search.md](search.md) |
| [`?filter=`](filter.md) | Interrogation **précise** d'un champ : comparateurs riches, ET/OU/NON | [filter.md](filter.md) |
| [`?facets=`](facets.md) | **Facettes** déclarées : multi-sélection compacte, existence/agrégat sur relations | [facets.md](facets.md) |

## Le modèle mental

> **`?search`** = « contient ce mot quelque part » (large, flou).
> **`?filter`** = « ce champ vaut exactement / est ≥ / matche … » (précis, logique booléenne).
> **`?facets`** = « coche ces cases déclarées » (multi-sélection d'UI + relations edge/join que les filtres n'expriment pas).

## Comment ils se combinent

Les trois alimentent **le même `FILTER`** d'une requête `list()`, sur le document courant `doc`, joints par `&&` :

```aql
FOR doc IN articles
    FILTER (LIKE(doc.name,@search_0,true) || …)   // ?search  → un groupe OR
        && doc.price >= @f0                        // ?filter
        && (doc.withStatus =~ @c0)                 // ?facets
    SORT  …                                         // ?sort        (voir models.md)
    LIMIT …                                         // pagination   (voir models.md)
    RETURN { … }                                    // projection   (Field::*, voir edges-joins-projection.md)
```

On peut donc envoyer les trois dans la même requête — ils se cumulent (logique ET entre eux). `?search` forme son propre groupe `OR` interne, puis est ANDé au reste.

> **Au-delà du filtrage.** Une requête de liste comprend aussi le **tri** (`?sort`), la **pagination** (`?limit`/`?offset`) et la **projection** de sortie (skins, `Field::*`). Ils ne *filtrent* pas et ne sont pas couverts ici — voir [models.md](../models.md) et [Projection des edges et joins](../edges-joins-projection.md).

## Tableau comparatif

| Critère | `?search=` | `?filter=` | `?facets=` |
|---|---|---|---|
| **But** | « contient » multi-champs | interrogation fine d'un champ | facettes d'UI + relations |
| **Cible** | plusieurs champs déclarés (`searchable`) | un champ scalaire `doc.x` (+ chemins `a.b`, expansion `champ[*].sub`, `match`) | FIELD, IN (tableau), EDGE/JOIN, complexes, AGGREGATE |
| **Syntaxe** | `?search=marc,marco` (CSV de termes) | explicite `{ "key","op","val","alt" }` | compacte par clé `{ "<facette>": <valeur> }` |
| **Opérateur** | toujours `LIKE %terme%` (insensible à la casse) | tout `FilterComparator`/`FilterArrayComparator` | les mêmes (réutilisés) |
| **Combinaison interne** | OR sur (chaque terme × chaque champ) | ET/OU/NON imbriquables (`["and"/"or"/"not", …]`) | aucune — chaque facette est indépendante, toutes ANDées |
| **Déclaration modèle** | liste `searchable` | `AQL::FILTERS` → un `FilterType` par champ | `Arango::FACETS` → un `Facet::TYPE` par facette |
| **Whitelist** | champs `searchable` fixés | champs `FILTERS` typés | **clé non déclarée = ignorée** |
| **Relations (edge/join)** | non | **oui**, via un [chemin de relation](filter.md#filtrer-à-travers-les-relations-edges--joins--documents-imbriqués) (existence « a au moins un lié qui matche ») | **first-class** (existence, agrégats, facettes d'UI) |
| **Spécifiques** | — | dates (`now`/`tz`), `between`, `AT LEAST (n)`, alters `pluck`/`coalesce` | `between` (FIELD), `*_AGGREGATE`, alias `LIST*`, `Facet::PROPERTY` |

## Le socle commun

Au-delà des différences de surface, les trois reposent sur **les mêmes briques** — c'est ce qui rend l'API cohérente :

- **Même cible AQL.** Chacun produit un fragment du `FILTER` de `buildListQuery`, évalué sur `doc`, combiné par `&&`.
- **Même vocabulaire `op`** (pour filtres & facettes) : [`FilterComparator`](filter.md#opérateurs) (`eq/ne/gt/ge/lt/le/like/nlike/match/nmatch`) et `FilterArrayComparator` (`any.in/all.in/none.in…`). Aucun code maison ; un `op` inconnu retombe sur le défaut du type.
- **Même moteur `alt`** (filtres & facettes) : les helpers `alterExpression()` / `resolveAltSides()` enveloppent le champ comparé (`key`) et/ou la valeur (`val`, `val:true` = miroir) avec des fonctions AQL (`lower`, `trim`, `abs`, `dateDay`…). Le pendant *sortie* est [`Field::ALTERS`](../edges-joins-projection.md#transformer-la-valeur-projetée--fieldalters).
- **Mêmes binds.** Seules les **valeurs** sont liées (`@bind`) ; les valeurs utilisateur ne touchent jamais le texte AQL directement.
- **Même contrat de sécurité.** Seuls les `@bind` sont user-controlled ; l'`op` est whitelisté (`getAlias` → défaut) ; les clés/sous-champs venant de l'URL sont validés (`assertAttributeName`) ou whitelistés par déclaration.
- **Même tolérance aux erreurs.** Un fragment mal formé (valeur invalide, sous-champ dangereux, facette non déclarée…) est **ignoré et journalisé** (`warning`) — il ne casse jamais la requête entière.

## Quand utiliser quoi

- **`?search`** — une **barre de recherche** unique « tape un mot » qui balaie quelques champs textuels (`name`, `firstName`, `email`…). Simple, large, sans configuration par requête.
- **`?filter`** — une **recherche avancée** : logique booléenne (ET/OU/NON), plages de dates avec fuseau, comparateurs fins sur un champ précis — **y compris à travers une relation** (`location.name`, `employee[*].salary` : « a au moins un lié qui matche »).
- **`?facets`** — des **facettes d'UI** (cases à cocher multi-valeurs) et les **agrégats** sur relations (« moyenne des reliés ≥ … », comptages) ; pour une simple condition booléenne sur un document lié, préfère `?filter`.

### La même intention, exprimée de plusieurs façons

```
# « contient "marc" dans un champ textuel »          → ?search
?search=marc
# → (LIKE(doc.name,@s,true) || LIKE(doc.firstName,@s,true) || …)

# « le nom vaut exactement "marc" »                   → ?filter
?filter={"key":"name","op":"eq","val":"marc"}
# → doc.name == @v

# « mot-clé cuisine OU jardin » (multi-sélection)     → ?facets
?facets={"keywords":"cuisine,jardin"}
# → TO_ARRAY([@k0,@k1]) ANY IN doc.keywords

# « lié à l'org 1234 » (facette d'UI, multi-valeurs)  → ?facets
?facets={"location":1234}
# → LENGTH(FOR v IN INBOUND doc orgs_places FILTER v._key==@v RETURN 1) > 0

# « dont l'org liée s'appelle "Acme" » (relation)     → ?filter (chemin de relation)
?filter={"key":"location.name","val":"Acme"}
# → LENGTH(FOR v IN INBOUND doc orgs_places FILTER v.name==@v LIMIT 1 RETURN 1) > 0
```

> `?filter=` **sait aussi traverser les relations** via un chemin (`location.name`, `employee[*].salary`) — voir [Filtrer à travers les relations](filter.md#filtrer-à-travers-les-relations-edges--joins--documents-imbriqués). Réserve `?facets=` aux **facettes d'UI** (cases à cocher multi-valeurs sur une relation) et aux **agrégats** ; emploie `?filter=` pour une condition booléenne fine sur le document lié.

## Tri par distance (`?near=`)

À part des trois leviers ci-dessus, qui **restreignent**, `?near=` **ordonne** : il classe la liste du plus proche au plus loin d'un point géographique. Il ne filtre pas — combine-le avec un [filtre `geo`](filter.md#opérateur-distance-géolocalisation) pour borner un rayon.

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
# → SORT DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC
```

`?near=` fournit le **point d'ancrage** (attribut Schema.org `GeoCoordinates`, alias `lat`/`lng`/`lon` acceptés) et expose une **clé de tri synthétique `distance`** que `?sort=` pilote — `?sort=` reste l'**unique autorité de tri** :

| Requête | Tri |
|---|---|
| `?near=…` seul | `distance` ASC (défaut, plus proche d'abord) |
| `?near=…&sort=-distance` | plus loin d'abord |
| `?near=…&sort=distance,name` | distance puis nom (tu choisis la priorité) |
| `?near=…&sort=name` | nom seul — distance **non** ajoutée (le `?sort` explicite décide) |
| `?sort=distance` sans `?near=` | ignoré (pas d'ancrage) |

Combinaison typique — les 10 lieux **les plus proches**, musées, dans 5 km :

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
&filter=[{"key":"type","val":"museum"},{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"max":5000}]
&limit=10
```

`DISTANCE` opère sur deux scalaires → tri **index-accéléré** par un [`GeoIndex`](../clients/indexes.md) à deux champs. Les coordonnées ne sont bindées que si un critère `distance` est réellement émis (jamais de bind inutilisé). Voir les [fonctions géospatiales](../aql/aql-functions-geo.md).

## Voir aussi

- [Recherche `?search=`](search.md) — la recherche multi-champs `LIKE`.
- [Recherche View (ArangoSearch)](search-views.md) — le `?search=` classé par pertinence (View déclarée au modèle).
- [Filtres `?filter=`](filter.md) — comparateurs, `alt`, ET/OU/NON, dates, `between`, `AT LEAST`, `distance`.
- [Facettes `?facets=`](facets.md) — catalogue des types (FIELD/IN/EDGE/JOIN/complexes/agrégats).
- [Filtrage interne `AQL::CONDITIONS`](filter-internal.md) — conditions serveur-only (jamais exposées à l'URL).
- [ArangoSearch (vues)](../clients/arangosearch.md) — full-text avancé (analyseurs, scoring) ; à distinguer du `?search` simple.
- [Modèles `Documents`](../models.md) — tri, pagination et cycle de vie de la requête de liste.
