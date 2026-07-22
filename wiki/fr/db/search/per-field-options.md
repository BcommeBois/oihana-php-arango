# Options de recherche par champ

Au-delà du boost, chaque entrée de `Search::FIELDS` (du bloc `AQL::VIEW`, voir [Vue d'ensemble](overview.md)) accepte des options déclarées **par champ** (forme tableau `champ => [ … ]`). Toutes suivent la même convention : **clé absente = hérite du niveau View, valeur explicite = surcharge** (un `0` / `false` explicite désactive donc l'option pour ce champ).

## Vue d'ensemble

| Option par champ | Rôle | Exemple |
|---|---|---|
| [`Search::FUZZY`](#tolérance-aux-fautes-par-champ) | tolérer les fautes (texte) / rester exact (codes) | `?search=scirie` trouve « Scierie… » mais pas un code voisin |
| [`Search::ANALYZER`](#analyzer-par-champ) | un Analyzer par champ (français, anglais, …) | `?search=workshops` matche via `text_en` (racine `workshop`) |
| [`Search::ANALYZER` (liste)](#plusieurs-analyzers-par-champ-autocomplétion) | plusieurs Analyzers sur **un** champ (ex. `text` + `ngram`) | `?search=ate` retrouve « Atelier » (autocomplétion) |
| [`Search::NGRAM`](#autocomplétion-précise-ngram_match) | autocomplétion **précise** (NGRAM_MATCH + seuil de similarité) | `?search=ate` trouve « Atelier » sans le bruit |
| [`Search::LANG`](#recherche-localisée-lang) | recherche localisée pilotée par `?lang=` | `?search=menuiserie&lang=fr` cible le côté français |
| [`Search::PHRASE`](#bonus-dexpression-exacte-par-champ) | bonus d'expression exacte là où c'est utile | `?search=cuir vintage` remonte l'expression adjacente |
| [`Search::REQUIRES`](#permissions-de-recherche) | limiter un champ aux requêtes autorisées | un champ `secret` n'est cherché qu'avec la permission |

Ces options se composent (un même champ peut déclarer boost + analyzer + langue + fuzzy + phrase). Chaque section ci-dessous en détaille une, avec un exemple concret de bout en bout.

## Tolérance aux fautes par champ

`Search::FUZZY` peut être déclaré **par champ** dans une entrée tableau de `Search::FIELDS`, en miroir exact de `Search::BOOST`. Une même View peut alors tolérer les fautes sur les champs texte tout en restant **exacte** sur les codes ou identifiants (où une tolérance ramènerait le mauvais enregistrement) :

```php
Search::FIELDS =>
[
    'name' => [ Search::BOOST => 3 , Search::FUZZY => 1 ] , // texte : tolérant
    'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // code : exact
    'slogan' => 2 ,                                          // forme courte conservée (boost 2)
] ,
Search::FUZZY => 1 , // défaut au niveau de la View
```

`Search::FUZZY` est la **distance d'édition maximale** (Damerau‑Levenshtein), pas un booléen : valide de `0` à `4` — `1` tolère une faute, `2` deux, etc. ; `0` = exact.

Règle de résolution : un champ qui déclare `Search::FUZZY` l'emporte (un **`0` explicite désactive** la tolérance pour ce champ) ; un champ sans clé `FUZZY` hérite du `Search::FUZZY` de la View ; sans valeur globale, la tolérance est désactivée. Comportement **100 % rétro-compatible** : une déclaration sans fuzzy par champ produit exactement l'AQL d'avant.

**Exemple concret.** Avec les champs ci-dessus et une faute de frappe sur la requête :

```
GET /places?search=scirie
```

| Champ | Tolérance | « scirie » (faute pour « scierie ») |
|---|---|---|
| `name` (`FUZZY => 1`) | 1 faute tolérée | ✅ trouve « Scierie de la Loire » |
| `code` (`FUZZY => 0`) | exact | ❌ aucun rapprochement |

L'AQL généré pour `name` ajoute la branche tolérante à côté du match par jetons :

```aql
   BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)        // tolère 1 faute d'édition
```

Le champ `code` n'émet **que** `doc.code IN TOKENS(...)` (pas de `LEVENSHTEIN_MATCH`) : une recherche `REF-00` ne ramène jamais le code `REF-001`.

## Analyzer par champ

De la même façon, `Search::ANALYZER` peut être déclaré **par champ**. Une même View peut alors indexer (et interroger) un champ français avec `text_fr` et un champ anglais avec `text_en` :

```php
Search::FIELDS =>
[
    'name'    => 3 ,                                  // Analyzer de la View
    'summary' => [ Search::ANALYZER => 'text_en' ] ,  // surcharge par champ
] ,
Search::ANALYZER => 'text_fr' , // défaut au niveau de la View
```

Règle de résolution : un champ qui déclare `Search::ANALYZER` l'emporte ; sinon il hérite du `Search::ANALYZER` de la View (lui-même `identity` par défaut). L'Analyzer étant **figé à l'indexation**, une surcharge par champ se répercute des deux côtés : le link de la View indexe le champ avec son Analyzer, et la requête regroupe les expressions par Analyzer — un `ANALYZER(…, "<analyzer>")` par groupe, le tout en `OR`. Avec un seul Analyzer la sortie est strictement celle d'avant.

> **Champ « code » en token exact.** Déclarer `Search::ANALYZER => 'identity'` sur un champ — pour le matcher en token exact plutôt que linguistiquement — est pleinement supporté et **ne provoque aucun drift**. `identity` étant l'Analyzer par défaut du link, le serveur stocke un tel champ sans le répéter ; la déclaration omet donc elle aussi cette mention redondante, si bien que `$model->viewDiff()` reste `IN_SYNC`.
>
> ```php
> Search::FIELDS =>
> [
>     'name' => 3 ,                                  // text_fr (défaut de la View)
>     'code' => [ Search::ANALYZER => 'identity' ] , // token exact, sans drift
> ] ,
> ```

**Exemple concret.** `name` est indexé en français, `summary` en anglais :

```
GET /places?search=workshops
```

Le pluriel anglais « workshops » est ramené à sa racine « workshop » par l'Analyzer `text_en` et retrouve la fiche dont le `summary` est « woodworking workshop » — ce que `text_fr` ne saurait pas faire. L'AQL généré produit **un `ANALYZER()` par Analyzer**, OR-és :

```aql
   ANALYZER(BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3), "text_fr")
|| ANALYZER(doc.summary IN TOKENS(@search_0, "text_en"), "text_en")
```

> **Drift** — changer l'Analyzer d'un champ modifie le link de la View. Comme tout changement de déclaration, il ne met pas à jour une View déjà créée : resynchronisez avec `$model->viewSync()` ou `arangodb views --sync`. Un analyzer (modèle ou par champ) inconnu du serveur est signalé par `$model->viewDiff()` (statut `INVALID`).

## Plusieurs Analyzers par champ (autocomplétion)

`Search::ANALYZER` par champ accepte aussi une **liste** : le champ est alors indexé **et** interrogé sous **chaque** Analyzer. On indexe ainsi le même champ de plusieurs façons à la fois — typiquement un `text` pour la recherche par mots entiers **et** un [`ngram`](../analyzers.md#ngramanalyzer--lautocomplétion--les-sous-chaînes) pour l'**autocomplétion** :

```php
Search::FIELDS =>
[
    'name' => [ Search::ANALYZER => [ 'text_fr' , 'autocomplete' ] ] , // deux recettes
] ,
```

> ⚠️ L'analyzer `autocomplete` (un `ngram`) n'est **pas** un built-in : il doit être déclaré dans le registre `analyzers` et créé **avant** la View (voir [Analyzers](../analyzers.md)).

Le link indexe le champ avec **toute** la liste, et la requête émet **une branche `ANALYZER(…)` par Analyzer**, OR-ées :

```aql
   ANALYZER(doc.name IN TOKENS(@search_0, "text_fr"), "text_fr")
|| ANALYZER(doc.name IN TOKENS(@search_0, "autocomplete"), "autocomplete")
```

Taper `ate` retrouve « **Ate**lier » par la branche `ngram` (que `text_fr` seul ne matcherait pas), tandis que les mots entiers passent par la branche `text_fr`. Les autres options du champ (`BOOST`, `FUZZY`, `PHRASE`) s'appliquent à **chaque** branche.

> La recherche par fragments est **lâche** par nature : un mot entier passé par la branche `ngram` peut aussi ramener des fiches qui partagent seulement des fragments. C'est le rôle du `score` (`BM25`) de faire remonter les meilleures d'abord ; combine avec `BOOST` si besoin. **Précision** : aujourd'hui, montre le **top‑N** via `?limit` — les meilleures correspondances sont en tête. Pour un contrôle plus fin, interroge l'analyzer ngram par **seuil de similarité** (`NGRAM_MATCH`) plutôt qu'« au moins un fragment commun » : voir [Autocomplétion précise](#autocomplétion-précise-ngram_match). Le niveau **View** (`Search::ANALYZER` global) reste, lui, une seule valeur (le défaut hérité).

## Autocomplétion précise (`NGRAM_MATCH`)

L'approche `text` + `ngram` ci-dessus interroge l'analyzer ngram par `IN TOKENS` — « ≥ 1 fragment commun », donc **lâche** : un mot entier peut ramener des fiches qui ne partagent qu'un fragment (le `score` BM25 les classe derrière, mais elles restent dans l'ensemble). `Search::NGRAM` interroge l'analyzer ngram par **seuil de similarité** (`NGRAM_MATCH`) : il faut qu'une **fraction** des fragments corresponde, ce qui exclut le bruit **dans le `SEARCH`** lui-même.

```php
Search::FIELDS =>
[
    'name' =>
    [
        Search::ANALYZER => 'text_fr' ,                                          // mots entiers (IN TOKENS, BM25)
        Search::NGRAM    => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] , // précis (NGRAM_MATCH)
    ] ,
] ,
// sucre : Search::NGRAM => 'autocomplete'  (seuil = défaut serveur 0.7)
```

- `Search::NGRAM` est **disjoint** de `Search::ANALYZER` : les recettes `text` vont dans `ANALYZER` (interrogées par `IN TOKENS`), la recette `ngram` ici (interrogée par `NGRAM_MATCH`). L'analyzer ngram est tout de même **indexé** sur le champ.
- `Search::THRESHOLD` : un flottant `0.0–1.0` (fraction des n-grams requis ; plus haut = plus strict). Absent → **défaut serveur `0.7`**. Hors borne → `ValidationException`.
- Le `BOOST` du champ s'applique à la branche ; `FUZZY` / `PHRASE` ne s'y appliquent pas.

AQL généré :

```aql
   ANALYZER(doc.name IN TOKENS(@search_0, "text_fr"), "text_fr")
|| ANALYZER(NGRAM_MATCH(doc.name, @search_0, 0.6, "autocomplete"), "autocomplete")
```

**Le gain.** Avec un seuil, taper `ate` retrouve « Atelier » (similarité 1.0) **et** le mot entier « atelier » n'amène plus une fiche « ferronnerie » qui ne partage que des bouts (similarité sous le seuil) — la précision que l'approche `IN TOKENS` n'a pas.

> ⚠️ `NGRAM_MATCH` veut un analyzer `ngram` déclaré avec **`min == max`** (une seule taille de fragment, ex. trigramme) et **`preserveOriginal: false`** — voir [Analyzers](../analyzers.md). Une requête plus courte que `min` ne produit aucun n-gram (donc aucun match) : c'est le compromis de la précision.

## Recherche localisée (`?lang=`)

Pour un attribut i18n stocké en objet `{ "fr": …, "en": … }`, indexez chaque sous-champ localisé (chemin pointé) avec son Analyzer **et** son marqueur de locale `Search::LANG` :

```php
Search::FIELDS =>
[
    'name'     => 3 ,                                                       // non localisé : toujours cherché
    'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
    'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
] ,
```

Quand la requête porte une langue active (le paramètre [`?lang=`](README.md), déjà utilisé pour la projection `TRANSLATE()` au `RETURN`), la recherche s'y aligne : seuls les champs dont `Search::LANG` correspond — **plus** les champs non localisés (sans `LANG`) — participent au `SEARCH`. Sans `?lang=`, tous les champs sont cherchés.

- `?lang=fr` → cherche `name` + `intro.fr` (le côté anglais est écarté) ;
- `?lang=en` → cherche `name` + `intro.en` ;
- **garde-fou** : si la langue active ne correspond à **aucun** champ (ex. `?lang=de`), le filtre est ignoré et tous les champs sont cherchés — jamais de `SEARCH` vide.

Le `Search::LANG` (recherche) et le `?lang` de projection (`TRANSLATE` au `RETURN`) sont indépendants mais cohérents : la même langue active narrowe la recherche et localise la sortie. Rétro-compatible : sans aucun `Search::LANG`, `?lang=` n'a aucun effet sur la recherche.

**Exemple concret.** Sur l'attribut i18n `intro` ci-dessus, le mot français « menuiserie » ne vit que dans `intro.fr` :

```
GET /places?search=menuiserie&lang=fr
```

`?lang=fr` ne cherche que `name` (non localisé) et `intro.fr` — la fiche française est trouvée :

```aql
ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3) || doc.intro.fr IN TOKENS(@search_0,"text_fr"), "text_fr")
```

La **même** requête en `?lang=en` cherche `name` + `intro.en` (le côté français est écarté) — « menuiserie » ne ramène alors plus rien :

```aql
ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3),"text_fr") || ANALYZER(doc.intro.en IN TOKENS(@search_0,"text_en"),"text_en")
```

## Bonus d'expression exacte par champ

`Search::PHRASE` peut aussi être déclaré **par champ**. Le bonus `PHRASE()` (qui classe une expression exacte devant un match dispersé) s'active alors là où il a du sens — le titre — et reste désactivé ailleurs — un code, un identifiant :

```php
Search::FIELDS =>
[
    'name'        => [ Search::BOOST => 3 , Search::PHRASE => true  ] , // bonus expression exacte
    'description' => [ Search::PHRASE => false ] ,                       // pas de bonus phrase
] ,
Search::PHRASE => true , // défaut au niveau de la View
```

Règle de résolution : un champ qui déclare `Search::PHRASE` l'emporte (un **`false` explicite désactive** le bonus pour ce champ) ; un champ sans clé `PHRASE` hérite du `Search::PHRASE` de la View ; sans valeur globale, le bonus est désactivé. Le bonus pèse `boost × 2` (il compose avec le boost par champ) et `PHRASE()` exige que l'Analyzer du champ expose les features `position` et `frequency`. Rétro-compatible : sans `PHRASE` par champ, la sortie est strictement celle d'avant.

**Exemple concret.** Avec le champ `name` ci-dessus (`Search::PHRASE => true`) et la requête :

```
GET /places?search=cuir vintage
```

deux fiches contiennent les **deux** mots et matchent donc toutes les deux (match par jetons) :

| `name` | Mots présents | Adjacents et dans l'ordre ? | Bonus `PHRASE()` |
|---|---|---|---|
| « Fauteuil **cuir vintage** » | cuir, vintage | ✅ oui | ✅ `boost × 2` → **remonte en tête** |
| « Sac en cuir, style vintage » | cuir, vintage | ❌ dispersés | ❌ aucun bonus |

L'AQL généré pour ce champ ajoute, à côté du match par jetons, la branche d'expression exacte :

```aql
   BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)   // match par jetons (les 2 fiches)
OR BOOST(PHRASE(doc.name, @search_0), 6)                // bonus expression exacte (fiche 1 seulement)
```

Résultat : « Fauteuil cuir vintage » passe **devant** « Sac en cuir, style vintage » au classement `BM25`. Le champ `code` (`Search::PHRASE => false`) ne reçoit jamais cette branche : un identifiant comme `REF-2024` ne doit pas être « rapproché » d'une saisie approximative.

## Combiner les mots d'un terme (`Search::OPERATOR`)

Un peu de vocabulaire d'abord. Une saisie `?search=` se découpe en **termes** (séparés par des virgules) et, à l'intérieur d'un terme, en **mots** (séparés par des espaces). `Search::OPERATOR` décide comment les **mots d'un même terme** se combinent **à l'intérieur d'un champ** :

- `Logic::OR` (défaut) : le terme entier est cherché d'un bloc (`doc.name IN TOKENS("fourcade marc", …)`). Or la sémantique de `IN` est « au moins un jeton » — chercher « fourcade marc » ramène donc **tous les « marc »** ;
- `Logic::AND` : le terme est éclaté et **chaque mot doit se retrouver dans le même champ** (`doc.name IN TOKENS("fourcade", …) && doc.name IN TOKENS("marc", …)`). Seul « Fourcade Marc » ressort.

L'opérateur ne resserre que les mots **dans** un champ. Les deux autres « OU » de la recherche ne bougent pas : le OU entre termes séparés par une virgule (`marc,marco` = l'un ou l'autre) et le OU entre champs (une fiche n'a jamais à matcher deux champs différents à la fois).

```php
Search::FIELDS =>
[
    'name' => [ Search::OPERATOR => Logic::AND ] , // les deux mots dans le nom
    'code' => 1 ,                                  // laissé souple (défaut de la View)
] ,
Search::OPERATOR => Logic::OR , // défaut au niveau de la View
```

Règle de résolution : un champ qui déclare `Search::OPERATOR` l'emporte ; un champ sans clé hérite de l'opérateur de la View ; sans valeur globale, c'est `OR`. **Rétro-compatible** : sans `OPERATOR` (ni View ni champ), la sortie AQL est strictement celle d'avant.

Un code exact (identifiant, code postal) n'est **pas** gêné par un `AND` global : une saisie d'un seul mot est identique en `AND` et en `OR`, et une saisie de deux mots neutralise simplement sa branche (un code ne contient jamais les deux mots) — exactement le comportement voulu.

**Exemple concret.** Annuaire de personnes, champ `name` déclaré en `Search::OPERATOR => Logic::AND`, requête :

```
GET /people?search=fourcade marc
```

| `name` | fourcade présent ? | marc présent ? | `AND` retient ? |
|---|---|---|---|
| « Fourcade Marc » | ✅ | ✅ | ✅ oui |
| « Jean-Marc Dupont » | ❌ | ✅ | ❌ non (« fourcade » manque) |
| « Marc Durand » | ❌ | ✅ | ❌ non |

L'AQL généré pour le champ conjugue les deux mots (chacun sur son propre bind) :

```aql
ANALYZER((doc.name IN TOKENS(@search_0_0, "text_fr") && doc.name IN TOKENS(@search_0_1, "text_fr")), "text_fr")
```

Le **bonus d'expression exacte** (`Search::PHRASE`) reste posé sur le terme entier : il classe « Fourcade Marc » (adjacents, dans l'ordre) devant un match dispersé sans jamais élargir l'ensemble retenu. La **tolérance aux fautes** (`Search::FUZZY`) s'applique, elle, mot par mot.

### Découpage des mots (`Search::SEPARATORS`)

Un terme est découpé en mots sur l'**espace** — mais un nom composé écrit **sans espace**, « Jean-Marc », resterait un seul mot et se re-diluerait en `OU` de ses jetons via `IN TOKENS` (« Jean-Marc » ramènerait tous les « jean » *ou* « marc », et toutes les villes en « Saint-**Jean** »). Pour l'éviter, le découpage se fait **aussi sur le trait d'union par défaut** : « Jean-Marc » se comporte comme « Jean Marc ».

`Search::SEPARATORS` déclare l'ensemble des caractères qui découpent **en plus de l'espace** (toujours séparateur). Deux écritures au choix — une **chaîne de caractères** ou une **liste de caractères** (même ensemble) :

```php
Search::OPERATOR   => Logic::AND ,
Search::SEPARATORS => "-./" ,                // chaîne
// ou
Search::SEPARATORS => [ "-" , "." , "/" ] ,  // liste
```

Défaut (clé absente) = le **trait d'union**. Une valeur **vide** (`""` ou `[]`) découpe sur l'espace **seul** — utile pour garder un code à trait d'union (`REF-2024`) entier. Les caractères sont échappés (n'importe quelle ponctuation est sûre). ⚠️ Élargir aux **élisions** (l'apostrophe de « d'Artagnan ») créerait un mot d'une lettre qui vide le `AND` — le défaut trait d'union reste sûr. N'agit qu'en mode `AND`.

## Permissions de recherche

`Search::REQUIRES` déclare le(s) **sujet(s) de permission** requis pour chercher — une chaîne ou une liste (sémantique OR) — en miroir exact de [`Field::REQUIRES`](../../projection.md) côté projection. La décision est déléguée à l'**autorizer** de la requête (le closure `Arango::AUTHORIZER`, injecté par le contrôleur et consulté par `isAuthorized()`). Il se déclare à **deux niveaux** :

- sur le **bloc `AQL::VIEW`** → garde **toute** la recherche (tous les champs) ;
- dans une **entrée de `Search::FIELDS`** → garde **ce seul** champ.

```php
AQL::VIEW =>
[
    Search::NAME     => 'placesView' ,
    Search::REQUIRES => 'app:search' ,                              // contrôle global : pas de recherche sans ce sujet
    Search::FIELDS   =>
    [
        'name'   => 3 ,                                             // public (soumis au seul contrôle global)
        'salary' => [ Search::REQUIRES => 'hr.salary:search' ] ,    // + 1 sujet requis
        'ssn'    => [ Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] , // + OR : admin OU audit
    ] ,
] ,
```

Les deux niveaux se combinent en **AND** : un champ est cherché si **(le contrôle de la View est absent ou accordé) ET (le contrôle du champ est absent ou accordé)**. Dans une même liste, les sujets se combinent en **OR**. ⚠️ C'est la **seule** facette **additive** : contrairement au boost / fuzzy / analyzer / langue / phrase (où le champ *surcharge* la View), les `REQUIRES` s'**accumulent** (le plus restrictif gagne) — par sécurité.

**Troisième niveau — l'héritage de la projection.** En plus de son `Search::REQUIRES` propre, un champ cherchable hérite **automatiquement** le `Field::REQUIRES` déclaré sur la **projection** (`$fields`), au **sous-champ exact** (`contactPoints[*].email` descend jusqu'à `email`) — exactement comme `?filter=`, `?facets=`, `?sort=`, `?bounds=` et `?groupBy=`. Autrement dit : **un champ masqué à la lecture est déjà non-cherchable**, sans avoir à re-déclarer `Search::REQUIRES`. Les trois contrôles se composent en **AND** (le plus restrictif gagne). Un champ **sans** `Field::REQUIRES`, ou **absent** de la projection, reste cherchable — le cas assumé « chercher sur une donnée qu'on n'affiche pas ».

**Exemple concret.** Le mot « confidentiel » ne vit que dans un champ `secret` gardé par `Search::REQUIRES => 'places:secret'` :

```
GET /places?search=confidentiel
```

| Requête | `secret` cherché ? | Résultat |
|---|---|---|
| autorizer accorde `places:secret` | ✅ oui | la fiche remonte |
| autorizer refuse | ❌ non (champ retiré) | aucun résultat |

Points clés :

- **Héritage de la projection** — un champ masqué par `Field::REQUIRES` sur `$fields` est retiré de la recherche **même sans `Search::REQUIRES`** (symétrie avec les cinq autres surfaces d'interrogation ; ferme l'oracle de recherche sur un champ non lisible).
- **Contrôle global** — si le `Search::REQUIRES` de la View est refusé, **toute** la recherche renvoie `false` (zéro résultat), quels que soient les champs déclarés.
- **Aucune fuite par défaut** — si les permissions retirent **tous** les champs cherchés, le `SEARCH` émis est `false` : zéro résultat. Il ne retombe **jamais** sur « cherche tout » ni sur le balayage `LIKE` (ce qui contournerait le contrôle).
- **Fail-open sans autorizer** — si aucun `Arango::AUTHORIZER` n'est injecté, la couche d'autorisation est considérée désactivée et les champs gardés restent cherchables (comportement identique à la projection). En production, le contrôleur injecte toujours l'autorizer.
- **`count()` et `facetCounts()`** appliquent le même filtrage (ils réutilisent la même expression `SEARCH`).
- Rétro-compatible : sans `REQUIRES` sur aucun champ, l'AQL est inchangé.

## Voir aussi

- [Vue d'ensemble](overview.md) — déclarer la View, URLs, pertinence, provisioning.
- [Champs de tableaux d'objets](array-fields.md) — `contactPoints[*].email`.
- [Analyzers](../analyzers.md) — catalogue des Analyzers et création d'un Analyzer maison.
