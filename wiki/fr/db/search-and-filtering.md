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
| **Relations (edge/join)** | non | seulement en feuille d'un chemin | **first-class** (existence, agrégats) |
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
- **`?filter`** — une **recherche avancée** : logique booléenne (ET/OU/NON), plages de dates avec fuseau, comparateurs fins sur un champ précis.
- **`?facets`** — des **facettes d'UI** (cases à cocher multi-valeurs) et tout ce qui touche aux **relations** (« lié à… », « a au moins un… », « moyenne des reliés ≥ … »).

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

# « lié à l'org 1234 » (relation)                     → ?facets uniquement
?facets={"location":1234}
# → LENGTH(FOR v IN INBOUND doc orgs_places FILTER v._key==@v RETURN 1) > 0
```

## Voir aussi

- [Recherche `?search=`](search.md) — la recherche multi-champs `LIKE`.
- [Filtres `?filter=`](filter.md) — comparateurs, `alt`, ET/OU/NON, dates, `between`, `AT LEAST`.
- [Facettes `?facets=`](facets.md) — catalogue des types (FIELD/IN/EDGE/JOIN/complexes/agrégats).
- [Filtrage interne `AQL::CONDITIONS`](filter-internal.md) — conditions serveur-only (jamais exposées à l'URL).
- [ArangoSearch (vues)](../clients/arangosearch.md) — full-text avancé (analyseurs, scoring) ; à distinguer du `?search` simple.
- [Modèles `Documents`](../models.md) — tri, pagination et cycle de vie de la requête de liste.
