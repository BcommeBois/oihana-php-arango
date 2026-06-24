# Analyzers

Un **Analyzer** est la *recette de préparation du texte* d'ArangoSearch. Avant
de ranger un texte dans un index de recherche — et avant de comparer ce que tu
tapes —, le moteur passe le texte dans cette recette : il le découpe, le met en
minuscules, enlève les accents, réduit les mots à leur racine, etc. Comme la
**même** recette s'applique à l'indexation *et* à la requête, les deux côtés se
rencontrent toujours sur le même terrain.

```
"Les Scieries de l'Évre !"   --text_fr-->   [ scieri , evre ]
```

> **Analogie.** `text_fr` est un bibliothécaire qui range par thème et comprend
> les synonymes : tu lui demandes « scierie », il retrouve « Scieries ».
> `identity` est un casier avec une étiquette exacte : tu retrouves la boîte
> **seulement** si tu donnes l'étiquette au mot près. Tu choisis la recette
> selon ce que contient le champ — du texte, ou un code.

> **Un Analyzer est figé à l'indexation.** Le surcharger à la requête seule ne
> sert à rien (tu chercherais des jetons racinisés à l'anglaise dans un index
> racinisé à la française). Ce qu'on change, c'est le *champ* cherché, et le bon
> Analyzer suit le champ — voir [Analyzer par champ](search/per-field-options.md#analyzer-par-champ).

## Sommaire

- [Les analyzers intégrés](#les-analyzers-intégrés)
- [Les six types qu'on peut fabriquer](#les-six-types-quon-peut-fabriquer)
- [Les features — ce qu'elles débloquent](#les-features--ce-quelles-débloquent)
- [Créer un analyzer proprement](#créer-un-analyzer-proprement)
- [Cycle de vie — la commande `arango:analyzers`](#cycle-de-vie--la-commande-arangoanalyzers)
- [Brancher l'analyzer sur un modèle / une View](#brancher-lanalyzer-sur-un-modèle--une-view)
- [Limites actuelles](#limites-actuelles)
- [Voir aussi](#voir-aussi)

## Les analyzers intégrés

ArangoDB fournit des analyzers **toujours présents** — rien à créer, tu les
référence par leur nom. Ils sont catalogués dans l'enum
[`BuiltinAnalyzer`](../../../src/oihana/arango/clients/analyzer/enums/BuiltinAnalyzer.php)
pour éviter les chaînes magiques :

| Nom | Recette | Pour quoi |
|---|---|---|
| `identity` | aucune transformation, le texte tel quel | codes, références, identifiants — correspondance **exacte** |
| `text_de`, `text_en`, `text_es`, `text_fi`, `text_fr`, `text_it`, `text_nl`, `text_no`, `text_pt`, `text_ru`, `text_sv`, `text_zh` | tokenisation + minuscules + accents repliés + racinisation, par langue | noms, descriptions, contenu rédigé dans cette langue |

```php
use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer ;

Search::ANALYZER => BuiltinAnalyzer::TEXT_FR ,   // 'text_fr'
```

`identity` est l'analyzer **par défaut** : un champ qui ne précise rien est
indexé tel quel. C'est exactement ce qu'il faut pour un champ `code`.

## Les six types qu'on peut fabriquer

Quand les intégrés ne suffisent pas (réglage fin des accents, stopwords maison,
recherche par préfixe ou par sous-chaîne…), tu fabriques ton propre analyzer. La
lib expose six classes « recette » — des value objects `readonly` qui implémentent
[`AnalyzerOptions`](../../../src/oihana/arango/clients/analyzer/AnalyzerOptions.php).
C'est **l'ensemble complet** de ce qui est fabricable aujourd'hui (voir
[Limites actuelles](#limites-actuelles)).

| Classe | Ce qu'elle fait | Paramètres |
|---|---|---|
| [`IdentityAnalyzer`](../../../src/oihana/arango/clients/analyzer/IdentityAnalyzer.php) | tel quel, aucune transformation | (aucun) |
| [`NormAnalyzer`](../../../src/oihana/arango/clients/analyzer/NormAnalyzer.php) | minuscules / majuscules + accents — **sans** découper en mots | `locale`, `case`, `accent` |
| [`StemAnalyzer`](../../../src/oihana/arango/clients/analyzer/StemAnalyzer.php) | racinisation (un mot → sa racine) ; entrée mono-mot | `locale` |
| [`TextAnalyzer`](../../../src/oihana/arango/clients/analyzer/TextAnalyzer.php) | la bête de somme : tokenise + minuscules + accents + racinisation + stopwords + n-grams de préfixe | `locale`, `case`, `accent`, `stemming`, `stopwords`, `stopwordsPath`, `edgeNgram` |
| [`NgramAnalyzer`](../../../src/oihana/arango/clients/analyzer/NgramAnalyzer.php) | découpe en sous-chaînes (n-grams) de `min` à `max` caractères — la brique de l'**autocomplétion** / recherche par sous-chaîne | `min`, `max`, `preserveOriginal`, `startMarker`, `endMarker`, `streamType` |
| [`PipelineAnalyzer`](../../../src/oihana/arango/clients/analyzer/PipelineAnalyzer.php) | enchaîne **dans l'ordre** une suite de sous-analyzers, chacun recevant la sortie du précédent — la façon typée de composer (ex. `norm` → `ngram`) | `pipeline` (la liste ordonnée des sous-analyzers) |

Le `locale` est un tag BCP 47 / ICU (`'fr'`, `'en'`, `'fr.utf-8'`). Le `case`
prend ses valeurs dans l'enum [`CaseFolding`](../../../src/oihana/arango/clients/analyzer/enums/CaseFolding.php)
(`lower` / `upper` / `none`). Un paramètre `null` (omis) laisse le serveur
appliquer son défaut.

### `IdentityAnalyzer` — le casier exact

```php
use oihana\arango\clients\analyzer\IdentityAnalyzer ;

$db->createAnalyzer( 'identity_raw' , new IdentityAnalyzer() ) ;
```

```
"REF-2024"   -->   [ REF-2024 ]      // un seul jeton, intact
```

> En pratique tu n'as presque jamais besoin de le créer : l'`identity` intégré
> fait déjà ça, et c'est le défaut.

### `NormAnalyzer` — normaliser sans découper

```php
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\enums\CaseFolding ;

$db->createAnalyzer( 'norm_fr' , new NormAnalyzer( locale: 'fr' , case: CaseFolding::LOWER , accent: false ) ) ;
```

```
"Évre"   -->   [ evre ]      // minuscules + accent replié, NON tokenisé
```

Idéal pour un tri ou un regroupement insensible à la casse/aux accents, ou pour
matcher une étiquette courte sans la découper en mots.

### `StemAnalyzer` — réduire à la racine

```php
use oihana\arango\clients\analyzer\StemAnalyzer ;

$db->createAnalyzer( 'stem_en' , new StemAnalyzer( locale: 'en' ) ) ;
```

```
"running"   -->   [ run ]      // entrée déjà tokenisée (un seul mot)
```

`StemAnalyzer` attend un seul mot en entrée — pour raciniser une phrase
entière, c'est `TextAnalyzer` (qui tokenise *puis* racinise) qu'il faut.

### `TextAnalyzer` — la recherche linguistique complète

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\analyzer\enums\CaseFolding ;

$db->createAnalyzer
(
    'text_fr_custom' ,
    new TextAnalyzer
    (
        locale    : 'fr.utf-8' ,
        case      : CaseFolding::LOWER ,
        accent    : false ,                 // replier les accents
        stemming  : true ,
        stopwords : [ 'le' , 'la' , 'les' , 'de' ] ,
        edgeNgram : [ 'min' => 2 , 'max' => 5 , 'preserveOriginal' => true ] ,
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

```
"Les Scieries de l'Évre"   -->   [ sc , sci , scie , scier , scieri , ev , evr , evre ]
```

Les mots vides (`les`, `de`, `l'`) sont retirés, le reste est mis en minuscules,
désaccentué, racinisé, puis l'option `edgeNgram` émet les **préfixes** de chaque
jeton (de 2 à 5 lettres) — c'est ce qui permet la recherche « au fur et à
mesure de la frappe » (`scie` retrouve `scieri`). `preserveOriginal` garde aussi
le jeton entier.

### `NgramAnalyzer` — l'autocomplétion / les sous-chaînes

```php
use oihana\arango\clients\analyzer\NgramAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer
(
    'autocomplete' ,
    new NgramAnalyzer
    (
        min              : 2 ,        // longueur mini d'un fragment
        max              : 5 ,        // longueur maxi
        preserveOriginal : true ,     // garde aussi le mot entier
        streamType       : 'utf8' ,   // 'utf8' (par caractère) ou 'binary' (par octet, défaut)
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
) ;
```

```
"atelier"   -->   [ at , ate , atel , ateli , te , tel , teli , telie , ... , atelier ]
```

Contrairement à l'option `edgeNgram` du `TextAnalyzer` — qui n'émet que les
**préfixes** — l'analyzer `ngram` émet les sous-chaînes **à toutes les
positions**. Taper `tel` retrouve donc `a`**`tel`**`ier`, pas seulement ce qui
commence par `tel`. C'est l'outil de l'autocomplétion et de la recherche
partielle. On le **combine** en général avec un `text` sur le **même** champ
(plusieurs analyzers par champ), pour servir à la fois la recherche par mots
entiers et l'autocomplétion.

> `ngram` ne met pas en minuscules ni ne replie les accents : pour une
> autocomplétion insensible à la casse, enchaîne un `norm` **avant** lui avec un
> [`PipelineAnalyzer`](#pipelineanalyzer--autocomplétion-insensible-à-la-casse-et-aux-accents)
> (ou indexe des données déjà normalisées). Les marqueurs `startMarker` /
> `endMarker` permettent de distinguer un fragment de début/fin de mot.

Deux façons d'interroger un champ indexé en `ngram` :

- **par `IN TOKENS`** (`Search::ANALYZER`) — « partage ≥ 1 fragment » : simple mais **lâche**. La config ci-dessus (`min: 2, max: 5, preserveOriginal: true`) lui convient.
- **par `NGRAM_MATCH`** (`Search::NGRAM` + seuil de similarité) — **précis** : exige une fraction des fragments. Pour cet usage, déclare plutôt l'analyzer avec **`min == max`** (une seule taille, ex. trigramme) et **`preserveOriginal: false`**, et active les features `position` + `frequency`. Voir [Autocomplétion précise](search/per-field-options.md#autocomplétion-précise-ngram_match).

### `PipelineAnalyzer` — autocomplétion insensible à la casse et aux accents

Un analyzer `ngram` seul ne normalise **ni la casse ni les accents**. Quand les
données sont stockées en majuscules (des noms de villes comme `"L'ABSIE"`,
`"ANGLET"`), les n-grams sont en majuscules tandis qu'un utilisateur qui tape
`l'ab` produit des n-grams en minuscules — les deux ne se croisent jamais et
l'autocomplétion ne renvoie **rien**. ArangoDB n'offre aucun bouton « normalise
d'abord » sur `ngram` ; la solution propre est un **pipeline** qui exécute un
`norm` (minuscules + repli des accents) **avant** le `ngram`, pour que les
valeurs indexées et la requête soient repliées à la même forme avant le découpage.
L'ordre compte : `norm` d'abord, `ngram` ensuite.

```php
use oihana\arango\clients\analyzer\NgramAnalyzer ;
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\PipelineAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\analyzer\enums\CaseFolding ;

$db->createAnalyzer
(
    'autocomplete' ,
    new PipelineAnalyzer
    ([
        new NormAnalyzer ( locale: 'fr' , case: CaseFolding::LOWER , accent: false ) , // 1. replie casse + accents
        new NgramAnalyzer( min: 3 , max: 5 , preserveOriginal: true ) ,                 // 2. puis découpe
    ]) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
) ;
```

```
"L'ABSIE"   -->   norm -->   "l'absie"   -->   ngram -->   [ l'a , l'ab , 'ab , ... , l'absie ]
```

Désormais une requête tapée `l'ab` est repliée de la même façon et retrouve le
document. Le constructeur prend une **liste ordonnée** d'autres value objects
analyzers et valide qu'elle est non vide et que chaque membre est un
`AnalyzerOptions`.

> **Le piège du faux-drift — ne déclare pas les défauts des sous-analyzers.**
> Quand le serveur relit un pipeline, il remplit **toutes** les propriétés par
> défaut de chaque sous-analyzer (un `norm` déclaré avec seulement `{ locale }`
> est relu `{ locale, case, accent }` ; un `ngram` renvoie aussi ses
> `startMarker` / `endMarker` / `streamType`). `analyzerDiff()` en tient compte :
> la chaîne du pipeline est comparée **déclaration d'abord** — membre par membre,
> dans l'ordre, en ne vérifiant que les propriétés que tu as déclarées et en
> ignorant les défauts serveur — donc un pipeline déclaré sans ces défauts est
> rapporté `IN_SYNC`, pas en drift permanent. L'**ordre** de la chaîne fait
> partie de la comparaison : inverser `norm` et `ngram` *est* un vrai drift. (Un
> `RawAnalyzer( 'pipeline', … )` dumpé du serveur, avec tous ses défauts, fait un
> aller-retour tout aussi propre.)

## Les features — ce qu'elles débloquent

Les **features** sont choisies par analyzer à la création. Elles décident des
métadonnées conservées dans l'index, donc des opérateurs et scorers `SEARCH`
disponibles ensuite. Elles sont cataloguées dans
[`AnalyzerFeature`](../../../src/oihana/arango/clients/analyzer/enums/AnalyzerFeature.php) :

| Feature | Sans elle, tu n'as pas… |
|---|---|
| `FREQUENCY` | le scoring `BM25()` / `TFIDF()` (pertinence) |
| `NORM` | la normalisation par longueur de `BM25()` (les champs courts ne sont plus avantagés à tort) |
| `POSITION` | `PHRASE()` — le matching d'expression exacte |
| `OFFSET` | la mise en évidence de snippets (implique `POSITION`) |

> Chaque feature coûte de l'espace disque et du CPU à l'écriture — n'active que
> ce dont tes requêtes ont besoin. Pour la recherche View classée par pertinence
> (`BM25`, bonus phrase), le trio utile est `FREQUENCY` + `POSITION` + `NORM`.

## Créer un analyzer proprement

Un analyzer custom **n'est pas auto-créé** par les modèles. Il doit exister sur
le serveur **avant** qu'une View ne le référence — sinon `viewDiff()` renvoie un
rapport `INVALID` (« analyzer not found ») et la View n'est jamais créée.

Deux façons de le créer :

- **Ad-hoc / bootstrap** — `createAnalyzer()` (raccourci de
  `$db->analyzer($name)->create($options, $features)`), pratique dans un script
  de mise en place ou un test :

  ```php
  $analyzer = $db->analyzer( 'text_fr_custom' ) ;   // factory, aucun appel HTTP

  $analyzer->exists() ;                  // bool
  $analyzer->get() ;                     // description brute : type, properties, features
  $analyzer->drop( force: true ) ;       // force: true pour supprimer même utilisé par une View
  ```

  `$db->analyzers()` renvoie un handle `Analyzer` par analyzer ;
  `$db->listAnalyzers()` renvoie les descriptions brutes. Les deux incluent les
  intégrés (`identity`, `text_en`, …).

- **Migration versionnée** *(recommandé en déploiement)* — créer l'analyzer
  dans une migration (`arango:migrate`), pour qu'il soit posé de façon
  reproductible avant les Views qui en dépendent. Voir
  [Outillage de migration](../commands/arangodb.md).

> **Registre déclaratif.** Tu peux regrouper tes analyzers custom dans une liste
> d'`AnalyzerDefinition` sous la clé `ArangoCommandParam::ANALYZERS` — au niveau
> de la base, comme `collectionIndexes` pour les index (un seul `AnalyzerDefinition`
> est toléré à la place d'une liste). C'est la source unique que la commande
> `arango:analyzers` (et `doctor`) lit pour les diagnostiquer et les provisionner.

> **Diff / sync programmatique.** La façade `ArangoDB` expose
> `analyzerDiff( AnalyzerDefinition )` (compare le déclaré au serveur →
> `MISSING` / `IN_SYNC` / `DRIFTED` / `INVALID`) et `analyzerSync()` (crée les
> **manquants**, et **signale** seulement les driftés — un analyzer étant
> immuable, sa correction reste une opération consciente). `analyzerDependentViews()`
> liste les Views qui référencent un analyzer (ce qu'un drop + recreate
> impacterait). Ce sont les briques de la commande `arango:analyzers`.
>
> Pour réparer un drift sur place, `analyzerSync( $def, force: true )` exécute la
> cascade : drop + recreate de l'analyzer **puis** reconstruction de l'index
> inversé de chaque View dépendante (retrait + ré-ajout du link — la seule
> manière dont le serveur reconstruit réellement). ⚠️ Non transactionnel :
> entre le drop et le recreate l'analyzer n'existe plus brièvement. Le chemin
> sans casse reste une migration « nouveau nom » (voir ci-dessous).

> **Déploiement / dump.** Les analyzers vivent dans la collection **système**
> `_analyzers`. `arangodump` ne la sauve qu'avec `--include-system-collections`
> — les intégrés (`text_fr`, …) sont toujours là, mais tes analyzers **custom**
> doivent être recréés (migration) ou inclus explicitement dans le dump.

## Cycle de vie — la commande `arango:analyzers`

Une fois tes analyzers dans le [registre déclaratif](#créer-un-analyzer-proprement),
la commande [`arango:analyzers`](../commands/arangodb.md#analyzers--gestion-des-analyzers-custom)
les diagnostique et les provisionne comme les Views et les index — `--diff`
(rapport), `--sync` (crée les manquants, signale les driftés), `--sync --force`
(répare un drift sur place, en cascade sur les Views dépendantes). Deux modes de
plus comptent pour la contrainte d'immuabilité :

- **Réparer via une migration (`--fix`)** — un analyzer étant immuable, réparer
  un drift = drop + recreate même nom (**chemin B**) plus reconstruction de
  chaque View dépendante. `--fix` écrit ça sous forme de **migration de
  réparation** prête à relire (une par analyzer drifté) et ne touche aucune
  base ; on relit, puis on lance `migrate`. C'est la forme différée et
  versionnée de `--sync --force`. Il existe aussi un **chemin A** sans casse —
  créer l'analyzer sous un **nouveau nom**, pointer le `Search::ANALYZER` du
  modèle dessus, `viewSync()`, puis dropper l'ancien — qui reste une
  modification manuelle documentée (il édite le modèle), pas générée.
- **Élaguer les orphelins (`--prune`)** — supprime les analyzers custom
  **orphelins** (sur le serveur, déclarés par personne), après confirmation. Un
  orphelin encore utilisé par une View n'est supprimé qu'avec `--force` (il
  laisse la View pendante).
  > ⚠️ **Base partagée.** Un analyzer orphelin peut appartenir à une **autre
  > application** partageant la même base — les analyzers sont au niveau base,
  > pas au niveau modèle. `--prune` est opt-in pour exactement cette raison :
  > relis la liste avant de confirmer. Les built-in (`identity`, `text_*`) et les
  > analyzers déclarés ne sont jamais élagués.

## Brancher l'analyzer sur un modèle / une View

Côté modèle `Documents`, tu ne manipules jamais l'analyzer directement : tu
déclares son **nom** dans le bloc `AQL::VIEW`, au niveau de la View ou par champ.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;
use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer ;

AQL::VIEW =>
[
    Search::NAME     => 'placesView' ,
    Search::ANALYZER => BuiltinAnalyzer::TEXT_FR ,   // défaut de la View
    Search::FIELDS   =>
    [
        'name' => 3 ,                                          // hérite de text_fr
        'code' => [ Search::ANALYZER => 'identity' ] ,         // token exact (sans drift)
    ] ,
] ,
```

Règle de résolution : un champ qui déclare `Search::ANALYZER` l'emporte ; sinon
il hérite de l'analyzer de la View (lui-même `identity` par défaut). Les détails
(résolution, AQL généré, recherche localisée `?lang=`) sont dans
[Recherche View — Analyzer par champ](search/per-field-options.md#analyzer-par-champ).

## Limites actuelles

La lib expose les six types ci-dessus (`identity`, `norm`, `stem`, `text`,
`ngram`, `pipeline`). Les autres types ArangoDB — `aql`, `geo_json` /
`geo_point` / `geo_s2`, `segmentation`, `collation`, `minhash`, `delimiter` /
`multi_delimiter`, `stopwords`, `classification`, `nearest_neighbors` — ne sont
**pas encore** exposés par une classe dédiée (prévu plus tard). En attendant, un tel
analyzer se crée hors-lib (API HTTP `/_api/analyzer` directe ou `arangosh`),
puis se référence par son nom dans une View comme n'importe quel autre.

## Voir aussi

- [Recherche View (ArangoSearch)](search/overview.md) — déclarer une View et la recherche classée par pertinence ; Analyzer par champ, `?lang=`.
- [Comprendre ArangoSearch](../getting-started/arangosearch.md) — l'introduction aux concepts (Analyzers, Views, `SEARCH`, scoring).
- [Client ArangoSearch](../clients/arangosearch.md) — l'API bas-niveau du client (Views, links, cycle de vie).
- [Fonctions ArangoSearch AQL](../aql/aql-functions-search.md) — `BM25()`, `PHRASE()`, `TOKENS()`, etc.
