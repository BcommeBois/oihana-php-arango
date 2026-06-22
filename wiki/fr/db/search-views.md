# Recherche View (ArangoSearch) — `?search=` classé par pertinence

Déclarez une **View ArangoSearch** sur un modèle `Documents` (le bloc `AQL::VIEW`) et le paramètre [`?search=`](search.md) bascule, automatiquement et **sans aucun changement d'URL**, du simple balayage `LIKE` vers une recherche **accélérée par index et classée par pertinence** : matching linguistique (tokenisation, racinisation, accents), boosts par champ, bonus d'expression exacte, tolérance aux fautes, et un score `BM25` qui classe les meilleurs résultats d'abord.

> ArangoSearch est nouveau pour vous (Analyzers, Views, scoring) ? Commencez par lire notre page dédiée [Comprendre ArangoSearch](../getting-started/arangosearch.md).

## Déclaration du modèle

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;

$places = new Documents( $container ,
[
    AQL::COLLECTION => 'places' ,
    AQL::VIEW =>
    [
        Search::NAME     => 'placesView' ,   // le nom de la View (requis)
        Search::ANALYZER => 'text_fr' ,      // Analyzer des champs cherchés
        Search::FIELDS   =>
        [
            'name'        => 3 ,             // champ => boost (le nom pèse 3×)
            'description' => 1 ,
        ] ,
        Search::PHRASE   => true ,           // bonus d'expression exacte (boost ×2)
        Search::FUZZY    => 1 ,              // tolérance Levenshtein (0 = off)
    ] ,
]) ;
```

| Clé | Type | Rôle |
|---|---|---|
| `Search::NAME` | `string` | **Requis** — le nom de la View. Sans lui le bloc est inerte et `?search=` reste le balayage `LIKE`. |
| `Search::ANALYZER` | `string` | Analyzer utilisé pour indexer **et** interroger les champs (défaut `identity` — déclarez un Analyzer texte pour la recherche linguistique). Surchargeable par champ — voir ci-dessous. |
| `Search::FIELDS` | `array` | Map `champ => boost` (ou `champ => [ Search::BOOST => n, Search::FUZZY => d ]` pour des options par champ). Chemins pointés supportés, ainsi que les **sous-champs de tableaux d'objets** via `[*]` ([voir ci-dessous](#champs-de-tableaux-dobjets-contactpointsemail)). Fallback sur `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Ajoute un bonus d'expression exacte : un match `PHRASE()` pèse `boost × 2`. |
| `Search::FUZZY` | `int` | Tolérance aux fautes globale : `LEVENSHTEIN_MATCH` avec cette distance d'édition maximale (valeur valide `0`–`4`, `0` = off). Surchargeable par champ — voir ci-dessous. |

### Options par champ — vue d'ensemble

Au-delà du boost, chaque entrée de `Search::FIELDS` accepte des options déclarées **par champ** (forme tableau `champ => [ … ]`). Toutes suivent la même convention : **clé absente = hérite du niveau View, valeur explicite = surcharge** (un `0` / `false` explicite désactive donc l'option pour ce champ).

| Option par champ | Rôle | Exemple |
|---|---|---|
| [`Search::FUZZY`](#tolérance-aux-fautes-par-champ) | tolérer les fautes (texte) / rester exact (codes) | `?search=scirie` trouve « Scierie… » mais pas un code voisin |
| [`Search::ANALYZER`](#analyzer-par-champ) | un Analyzer par champ (français, anglais, …) | `?search=workshops` matche via `text_en` (racine `workshop`) |
| [`Search::LANG`](#recherche-localisée-lang) | recherche localisée pilotée par `?lang=` | `?search=menuiserie&lang=fr` cible le côté français |
| [`Search::PHRASE`](#bonus-dexpression-exacte-par-champ) | bonus d'expression exacte là où c'est utile | `?search=cuir vintage` remonte l'expression adjacente |
| [`Search::REQUIRES`](#permissions-de-recherche) | limiter un champ aux requêtes autorisées | un champ `secret` n'est cherché qu'avec la permission |

Ces options se composent (un même champ peut déclarer boost + analyzer + langue + fuzzy + phrase). Chaque section ci-dessous en détaille une, avec un exemple concret de bout en bout.

### Tolérance aux fautes par champ

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

### Analyzer par champ

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

### Recherche localisée (`?lang=`)

Pour un attribut i18n stocké en objet `{ "fr": …, "en": … }`, indexez chaque sous-champ localisé (chemin pointé) avec son Analyzer **et** son marqueur de locale `Search::LANG` :

```php
Search::FIELDS =>
[
    'name'     => 3 ,                                                       // non localisé : toujours cherché
    'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
    'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
] ,
```

Quand la requête porte une langue active (le paramètre [`?lang=`](search.md), déjà utilisé pour la projection `TRANSLATE()` au `RETURN`), la recherche s'y aligne : seuls les champs dont `Search::LANG` correspond — **plus** les champs non localisés (sans `LANG`) — participent au `SEARCH`. Sans `?lang=`, tous les champs sont cherchés.

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

### Bonus d'expression exacte par champ

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

### Permissions de recherche

`Search::REQUIRES` déclare le(s) **sujet(s) de permission** requis pour chercher — une chaîne ou une liste (sémantique OR) — en miroir exact de [`Field::REQUIRES`](../edges-joins-projection.md) côté projection. La décision est déléguée à l'**autorizer** de la requête (le closure `Arango::AUTHORIZER`, injecté par le contrôleur et consulté par `isAuthorized()`). Il se déclare à **deux niveaux** :

- sur le **bloc `AQL::VIEW`** → garde **toute** la recherche (tous les champs) ;
- dans une **entrée de `Search::FIELDS`** → garde **ce seul** champ.

```php
AQL::VIEW =>
[
    Search::NAME     => 'placesView' ,
    Search::REQUIRES => 'app:search' ,                              // gate global : pas de recherche sans ce sujet
    Search::FIELDS   =>
    [
        'name'   => 3 ,                                             // public (soumis au seul gate global)
        'salary' => [ Search::REQUIRES => 'hr.salary:search' ] ,    // + 1 sujet requis
        'ssn'    => [ Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] , // + OR : admin OU audit
    ] ,
] ,
```

Les deux niveaux se combinent en **AND** : un champ est cherché si **(le gate de la View est absent ou accordé) ET (le gate du champ est absent ou accordé)**. Dans une même liste, les sujets se combinent en **OR**. ⚠️ C'est la **seule** facette **additive** : contrairement au boost / fuzzy / analyzer / langue / phrase (où le champ *surcharge* la View), les `REQUIRES` s'**accumulent** (le plus restrictif gagne) — par sécurité.

**Exemple concret.** Le mot « confidentiel » ne vit que dans un champ `secret` gardé par `Search::REQUIRES => 'places:secret'` :

```
GET /places?search=confidentiel
```

| Requête | `secret` cherché ? | Résultat |
|---|---|---|
| autorizer accorde `places:secret` | ✅ oui | la fiche remonte |
| autorizer refuse | ❌ non (champ retiré) | aucun résultat |

Points clés :

- **Gate global** — si le `Search::REQUIRES` de la View est refusé, **toute** la recherche renvoie `false` (zéro résultat), quels que soient les champs déclarés.
- **Aucune fuite par défaut** — si les permissions retirent **tous** les champs cherchés, le `SEARCH` émis est `false` : zéro résultat. Il ne retombe **jamais** sur « cherche tout » ni sur le balayage `LIKE` (ce qui contournerait le contrôle).
- **Fail-open sans autorizer** — si aucun `Arango::AUTHORIZER` n'est injecté, la couche d'autorisation est considérée désactivée et les champs gardés restent cherchables (comportement identique à la projection). En production, le contrôleur injecte toujours l'autorizer.
- **`count()` et `facetCounts()`** appliquent le même filtrage (ils réutilisent la même expression `SEARCH`).
- Rétro-compatible : sans `REQUIRES` sur aucun champ, l'AQL est inchangé.

### Champs de tableaux d'objets (`contactPoints[*].email`)

Un document porte souvent un **tableau d'objets** — une liste de moyens de contact, d'étiquettes, de membres… :

```json
{
  "name": "Marc",
  "contactPoints":
  [
    { "email": "marc@acme.com",  "type": "work" },
    { "email": "marc@gmail.com", "type": "home" }
  ]
}
```

On veut que `?search=gmail` retrouve ce document parce que **l'un** de ses `contactPoints` contient « gmail ». Déclarez le sous-champ avec le marqueur `[*]` (« pour chaque élément du tableau »), la même notation que côté [`?filter=`](filter.md) :

```php
Search::FIELDS =>
[
    'name'                       => 5 ,
    'contactPoints[*].email'     => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
    'contactPoints[*].telephone' => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
] ,
```

Le `[*]` est une **notation côté développeur** : en interne il est **retiré sur les deux étages**.

**Index créé** (le `[*]` retiré — chemin à plat) : ArangoSearch (édition **Community**) descend tout seul dans le tableau et indexe l'`email` de chaque élément.

```json
{ "fields": { "name": { "analyzers": ["text_fr"] },
              "contactPoints": { "fields": { "email":     { "analyzers": ["text_fr"] },
                                             "telephone": { "analyzers": ["text_fr"] } } } } }
```

**Requête générée** (le `[*]` retiré aussi) — la clause `SEARCH` d'ArangoSearch **refuse** l'expansion `[*]`, et le chemin à plat matche déjà n'importe quel élément du tableau :

```aql
SEARCH ANALYZER(
       doc.name                  IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.email     IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.telephone IN TOKENS(@search_0, "text_fr")
, "text_fr")
```

Les options par champ (`Search::ANALYZER`, `FUZZY`, `PHRASE`, `BOOST`, `LANG`, `REQUIRES`) fonctionnent à l'identique sur un champ `[*]`.

**Plusieurs niveaux.** Tous les `[*]` sont retirés, quelle que soit la profondeur : `employees[*].contactPoints[*].email` indexe `employees` → `contactPoints` → `email` et se cherche via `doc.employees.contactPoints.email IN TOKENS(...)`.

> **Recherche non corrélée — Community, sans Enterprise.** Ceci trouve « un document dont *un* élément contient le mot X ». Cela **ne** permet **pas** d'exiger « le *même* élément a X **et** Y » (par ex. l'email contient `acme.com` **et** le type est `billing` sur le **même** contact) : l'index Community aplatit le tableau et perd la frontière entre éléments. Cette corrélation exigerait les champs `nested` d'ArangoSearch, réservés à l'édition **Enterprise** — hors périmètre ici. Si vous avez besoin d'une condition corrélée, exprimez-la via [`?filter=`](filter.md) (`contactPoints[*]` avec `match`/`quant`), qui re-teste élément par élément. `trackListPositions` n'est **pas** activé (le défaut convient à une recherche non corrélée).

**Le provisioning est automatique** : comme la collection et ses `AQL::INDEXES`, la View est créée paresseusement à l'initialisation du modèle quand elle n'existe pas (champs cherchés liés avec l'Analyzer déclaré). Une View existante n'est **jamais modifiée automatiquement** — après un changement de déclaration, inspectez et resynchronisez explicitement : `$model->viewDiff()` détecte l'écart, `$model->viewSync()` le répare via `updateProperties()` (la View reste interrogeable pendant la ré-indexation), et l'[action `views` de la commande `arangodb`](../commands/arangodb.md#views--gestion-des-views-arangosearch) fait la même chose en CLI (`--diff` / `--sync`), intégrable aux scripts de déploiement :

```bash
# après un changement de déclaration AQL::VIEW : voir l'écart, puis resynchroniser
composer arango:views -- --diff              # lecture seule : liste les Views à créer / driftées
composer arango:views -- --sync              # crée les manquantes + resynchronise toutes les driftées
composer arango:views -- --sync=placesView   # ciblé (plusieurs noms séparés par des virgules)
```

> Forme longue équivalente : `php bin/console.php command:arangodb views --sync`. Le `--sync` privilégie `updateProperties()` (mise à jour douce) plutôt qu'un drop + recreate.

## URLs et comportement

```
GET /places?search=scierie
```

génère (termes bindés — l'entrée utilisateur n'atteint jamais le texte AQL) :

```aql
FOR doc IN placesView
  SEARCH ANALYZER(
       BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
    OR BOOST(PHRASE(doc.name, @search_0), 6)
    OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)
    OR doc.description IN TOKENS(@search_0, "text_fr")
    OR BOOST(PHRASE(doc.description, @search_0), 2)
    OR LEVENSHTEIN_MATCH(doc.description, @search_0, 1)
  , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 0, 50
  RETURN { ... }
```

Le contrat de `?search=` est inchangé : termes séparés par des virgules, **OR** partout — seul le moteur diffère. Et le reste du pipeline ([`?filter=`](filter.md), [`?facets=`](facets.md), `?limit`/`?offset`, skins, projections) continue de fonctionner comme avant : les filtres s'appliquent **après** le `SEARCH`, en `FILTER` de post-traitement.

### Pertinence et `?sort=`

Une recherche active expose la clé de tri synthétique **`score`** (le pendant pertinence de [`distance`](search-and-filtering.md) pour `?near=`) :

| Requête | Ordre |
|---|---|
| `?search=scierie` | `score` DESC (défaut — le plus pertinent d'abord, prime sur `SORT_DEFAULT`) |
| `?search=scierie&sort=-score,name` | pertinence, puis nom |
| `?search=scierie&sort=name` | nom seul — la pertinence n'est **pas** ajoutée (le `?sort` explicite décide) |
| `?sort=score` sans `?search=` | droppé (pas de recherche active) |

### Réponses

L'enveloppe JSON est **identique** à une liste classique (l'enveloppe de succès standard `status` / `url` / `count` / `total` / `result` des contrôleurs) — seuls l'ordre (et la qualité du matching) changent :

```json
{
  "status": "success",
  "url": "https://api.example.org/places?search=bois",
  "count": 2,
  "total": 2,
  "result":
  [
    { "name": "Atelier du bois" , "description": "menuiserie fine" } ,
    { "name": "Scierie de la Loire" , "description": "le bois de chêne et de sapin" }
  ]
}
```

`total`, [`?count`](../models.md) **et** [`?facetCounts=`](facets.md) suivent tous le même `SEARCH` — la liste, les totaux et les buckets de facettes sont toujours d'accord sur l'ensemble matché.

## Recettes

**Barre de recherche avec pertinence** — la déclaration ci-dessus ; rien d'autre. `?search=scierie` renvoie les meilleurs résultats d'abord, tolère une faute (`scierei`), et survit aux accents/pluriels via l'Analyzer.

**Biais préfixe façon autocomplete** — gardez `Search::PHRASE => true` : en tapant des mots entiers, les expressions exactes remontent en tête.

**Sous-champ localisé** — les champs sont des chemins : `Search::FIELDS => [ 'description.fr' => 1 ]` cherche le côté français d'un attribut i18n `{ "fr": …, "en": … }`. Pour aller plus loin, un [Analyzer par champ](#analyzer-par-champ) (français/anglais) et une [sélection pilotée par `?lang=`](#recherche-localisée-lang) sont disponibles.

**Sous-champ d'un tableau d'objets** — `Search::FIELDS => [ 'contactPoints[*].email' => 1 ]` rend cherchable l'`email` de **chaque** élément du tableau `contactPoints` — voir [Champs de tableaux d'objets](#champs-de-tableaux-dobjets-contactpointsemail).

**Imposer un tri classique** — la pertinence n'est que le tri *par défaut* : `?search=bois&sort=name` (ou n'importe quel `?sort=`) reprend entièrement la main, exactement comme avant.

**Revenir en arrière** — retirez le bloc `AQL::VIEW` (ou son `Search::NAME`) : `?search=` retombe instantanément sur le balayage `LIKE` historique sur `AQL::SEARCHABLE`. Aucun changement d'URL, de contrôleur ou de route dans un sens comme dans l'autre.

## Bon à savoir

- **Consistance différée** — un document fraîchement inséré devient cherchable dans la View après ~1 s (`commitIntervalMsec`). Les listes sans `?search=` ne sont pas concernées.
- **Exigences du scoring** — le score `BM25` exige la feature d'Analyzer `frequency` (les Analyzers texte built-in l'ont) ; `PHRASE` exige `position` + `frequency`.
- **La recherche est bindée** — les termes voyagent en variables `@search_N` ; les noms de champs viennent de la déclaration du modèle, jamais de l'URL.
- **Les Analyzers doivent exister d'abord** — une View référence ses Analyzers par leur **nom**, elle ne les crée pas. Les built-in (`text_fr`, `text_en`, `identity`…) sont toujours présents. Un Analyzer **maison** doit être déclaré dans le registre `analyzers` et créé en base (`composer arango:analyzers -- --sync` ou `composer arango:doctor -- --apply`) **avant** la View — sa définition (type, propriétés, features) n'est pas déductible du seul nom. Sinon la View est marquée `INVALID` et la création paresseuse échoue silencieusement (la recherche échouera ensuite au runtime). Diagnostiquez avec `composer arango:views -- --diff` ou `composer arango:doctor`. Voir [Analyzers](analyzers.md).

## Voir aussi

- [Recherche `?search=`](search.md) — le balayage `LIKE` (modèles sans View).
- [Recherche & filtrage](search-and-filtering.md) — vue d'ensemble des leviers.
- [Fonctions ArangoSearch](../aql/aql-functions-search.md) — les helpers `SEARCH` sous-jacents.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — le builder de requête scorée autonome.
- [Clients ArangoSearch](../clients/arangosearch.md) — gestion des Views et Analyzers.
