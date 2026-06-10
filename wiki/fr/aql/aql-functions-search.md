# Fonctions ArangoSearch `db/functions/search/`

Le sous-dossier [`src/oihana/arango/db/functions/search/`](../../../src/oihana/arango/db/functions/search/) regroupe les **11 helpers** correspondant aux *fonctions ArangoSearch* natives d'AQL — contexte d'expression de recherche, filtrage accéléré par index, et scoring de pertinence.

> **Qu'est-ce qu'ArangoSearch ?** Le moteur de recherche intégré d'ArangoDB. Un **Analyzer** transforme le texte en jetons normalisés (`"Quick Foxes"` → `["quick", "fox"]`), une **View** indexe une ou plusieurs collections champ par champ à travers ces Analyzers, et l'**opération `SEARCH`** interroge la View via son index inversé — contrairement à `FILTER`, qui post-traite. Les **scorers** (`BM25()`, `TFIDF()`) classent ensuite les résultats par pertinence. Voir [les clients ArangoSearch](../clients/arangosearch.md) pour créer Views et Analyzers, et [`aqlSearch()`](aql-operations.md) pour l'opération elle-même.

Ces expressions ne sont valides qu'**à l'intérieur d'une opération `SEARCH`** sur une View (ou d'un `FILTER` adossé à un index inversé) — la plupart lèvent une erreur évaluées ailleurs.

## Synthèse

| Catégorie | Fonctions |
|---|---|
| Contexte | `analyzer`, `boost` |
| Filtrage | `phrase`, `levenshteinMatch`, `ngramMatch`, `minhashMatch`, `minMatch`, `exists`, `inRange` |
| Scoring | `bm25`, `tfidf` |

Les fonctions `STARTS_WITH`, `TOKENS` et `LIKE` fonctionnent aussi dans les expressions `SEARCH` mais restent avec les [fonctions strings](aql-functions-strings.md) — noter que [`startsWith()`](aql-functions-strings.md) supporte la forme ArangoSearch à tableau de préfixes (`startsWith('doc.text', ['lor','ips'], 1)`). La fonction de highlighting `OFFSET_INFO` n'est exposée que comme constante de `SearchFunction` (helper différé).

## Référence

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `analyzer` | `(string $expr, string $analyzer)` | `ANALYZER(<expr>, "<analyzer>")` |
| `boost` | `(string $expr, float\|int $boost)` | `BOOST(<expr>, <boost>)` |
| `phrase` | `(string $path, string\|array $phrase, ?string $analyzer = null)` | `PHRASE(<path>, "<phrase>"[, "<analyzer>"])` |
| `levenshteinMatch` | `(string $path, string $target, int $distance, ?bool $transpositions = null, ?int $maxTerms = null, ?string $prefix = null)` | `LEVENSHTEIN_MATCH(<path>, "<target>", <distance>[, …])` |
| `ngramMatch` | `(string $path, string $target, string $analyzer, ?float $threshold = null)` | `NGRAM_MATCH(<path>, "<target>"[, <threshold>], "<analyzer>")` |
| `minhashMatch` | `(string $path, string $target, string $analyzer, ?float $threshold = null)` | `MINHASH_MATCH(<path>, "<target>"[, <threshold>], "<analyzer>")` |
| `minMatch` | `(array $expressions, int $minMatchCount)` | `MIN_MATCH(<expr1>, …, <exprN>, <count>)` |
| `exists` | `(string $path, ?string $type = null, ?string $analyzer = null)` | `EXISTS(<path>[, "<type>"[, "<analyzer>"]])` |
| `inRange` | `(string $path, mixed $low, mixed $high, bool $includeLow, bool $includeHigh)` | `IN_RANGE(<path>, <low>, <high>, <bool>, <bool>)` |
| `bm25` | `(string $doc, ?float $k = null, ?float $b = null)` | `BM25(<doc>[, <k>[, <b>]])` |
| `tfidf` | `(string $doc, ?bool $normalize = null)` | `TFIDF(<doc>[, <normalize>])` |

## Conventions

- **Auto-quoting** — les arguments qui doivent être des littéraux string AQL (noms d'Analyzers, cibles de recherche, types d'`exists`, le contenu de `phrase`) sont émis via `json_encode` : les strings sont quotées, les guillemets doubles et les caractères non-ASCII deviennent des échappements JSON valides. Les chemins d'attributs, expressions de recherche et la variable `doc` restent **bruts** ; les booléens PHP deviennent `true`/`false`.
- **Forme tableau de `phrase()`** — passer un tableau pour refléter la syntaxe tableau AQL officielle à l'identique : jetons string, entiers `skipTokens` (jokers), et tableaux associatifs comme object-tokens (`['STARTS_WITH' => ['ips']]` → `{"STARTS_WITH":["ips"]}`).
- **Ordre des arguments** — en AQL, `NGRAM_MATCH`/`MINHASH_MATCH` placent leur `threshold` *optionnel* avant l'`analyzer` *obligatoire* ; PHP l'interdit, donc les helpers prennent l'analyzer en troisième, le threshold en dernier, et réordonnent l'AQL émis.
- **Comblement des défauts** — les arguments AQL sont positionnels : quand un argument optionnel tardif est fourni, les helpers comblent les précédents omis avec les défauts officiels du serveur : `levenshteinMatch(…, prefix: 'qui')` émet `transpositions = true` et `maxTerms = 64` ; `bm25('doc', b: 0.5)` émet `k = 1.2`.
- **Raccourci `exists()`** — `exists('doc.text', analyzer: 'text_en')` remplit tout seul le littéral de type `"analyzer"`. Avec les Views `arangosearch`, `EXISTS()` ne matche que si le link déclare `storeValues: "id"`.

## Exemples

Une recherche classée par pertinence — la phrase pèse 3× plus dans le nom qu'un match approché dans la description :

```php
use function oihana\arango\db\functions\search\analyzer ;
use function oihana\arango\db\functions\search\bm25 ;
use function oihana\arango\db\functions\search\boost ;
use function oihana\arango\db\functions\search\levenshteinMatch ;
use function oihana\arango\db\functions\search\phrase ;

$search = analyzer
(
    boost( phrase( 'doc.name' , 'scierie' ) , 3 )
    . ' OR ' .
    levenshteinMatch( 'doc.description' , 'scierie' , 1 ) ,
    'text_fr'
) ;

$aql = 'FOR doc IN placesView SEARCH ' . $search
     . ' SORT ' . bm25( 'doc' ) . ' DESC LIMIT 20 RETURN doc' ;
// FOR doc IN placesView
//   SEARCH ANALYZER(BOOST(PHRASE(doc.name,"scierie"),3) OR LEVENSHTEIN_MATCH(doc.description,"scierie",1), "text_fr")
//   SORT BM25(doc) DESC LIMIT 20 RETURN doc
```

Une phrase de proximité (« quick … fox » avec un jeton joker entre les deux) et un object-token :

```php
use function oihana\arango\db\functions\search\phrase ;

phrase( 'doc.text' , [ 'quick' , 1 , 'fox' ] , 'text_en' ) ;
// 'PHRASE(doc.text,["quick",1,"fox"],"text_en")'

phrase( 'doc.text' , [ 'lorem' , [ 'STARTS_WITH' => [ 'ips' ] ] ] , 'text_en' ) ;
// 'PHRASE(doc.text,["lorem",{"STARTS_WITH":["ips"]}],"text_en")'
```

Tests d'existence et d'intervalle, combinés (« a un texte, et 3 ≤ value ≤ 5 ») :

```php
use function oihana\arango\db\functions\search\exists ;
use function oihana\arango\db\functions\search\inRange ;

exists( 'doc.text' ) . ' AND ' . inRange( 'doc.value' , 3 , 5 , true , true ) ;
// 'EXISTS(doc.text) AND IN_RANGE(doc.value,3,5,true,true)'
```

> `ngramMatch()` et `minhashMatch()` exigent des Analyzers custom `ngram` / `minhash` — voir [Analyzers](../clients/arangosearch.md) pour les créer.

## Voir aussi

- [Fonctions strings `db/functions/strings/`](aql-functions-strings.md) — `startsWith` (forme tableau), `tokens`, `like`.
- [Opérations AQL](aql-operations.md) — l'opération `aqlSearch()`.
- [Clients ArangoSearch](../clients/arangosearch.md) — Views, links et Analyzers.
- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Documentation AQL officielle — fonctions ArangoSearch](https://docs.arangodb.com/stable/aql/functions/arangosearch/).
