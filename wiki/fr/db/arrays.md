# Champs-tableaux embarqués `AQL::ARRAYS`

> Gérer un **tableau stocké à l'intérieur d'un document** (ajouter, retirer, déplacer, réordonner, éditer, tester) côté serveur, de façon atomique, en une seule requête AQL `UPDATE`.

Le trait [`DocumentsArrayTrait`](../../../src/oihana/arango/models/traits/DocumentsArrayTrait.php) — composé par [`Documents`](../models.md) — expose un petit jeu de méthodes pour muter un champ-liste embarqué (par ex. `tracks`, `tags`, `hasPart`…) sans rapatrier le tableau côté PHP. Le comportement de chaque champ (ordre, unicité, compteur de longueur optionnel) se déclare **une seule fois** sur le modèle, via l'option `AQL::ARRAYS`.

Cette page documente :

1. [Quand l'utiliser](#quand-lutiliser) (vs *edges*).
2. La [déclaration `AQL::ARRAYS`](#déclaration-aqlarrays) et les [modes d'ordre](#modes-dordre-arraymode).
3. Le [ciblage par clé d'élément](#cibler-un-élément-par-sa-clé-arangoitem_key), pour les tableaux d'**objets**.
4. La [numérotation des éléments](#numéroter-les-éléments-arangoposition_key), pour un glisser-déposer.
5. Les [sept méthodes](#les-méthodes) et leurs clés `$init`.
6. Les [signaux](#signaux) et la [propagation aux parents](#propager-une-modification-aux-documents-parents).
7. La [migration](#migration-depuis-listitemtrait--multifieldtrait) depuis les anciens traits.

## Quand l'utiliser

Ce pattern convient aux **petites listes ordonnées embarquées** dans un document : références ordonnées (`hasPart`, `itemListElement`), étiquettes (`tags`), etc. — quand des *edges* seraient trop lourds et que l'ordre compte.

Pour des relations nombreuses, traversables ou partagées, préférez les [*edges*](../edges-joins-projection.md).

## Déclaration `AQL::ARRAYS`

Chaque champ-tableau est déclaré à la construction du modèle, à côté de `AQL::FILTERS`, `AQL::EDGES`, etc. :

```php
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

$playlists = new Documents( $container,
[
    AQL::COLLECTION => 'Playlist',

    AQL::ARRAYS =>
    [
        'tracks'   => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ], // ordonné + compteur tenu
        'tags'     => ArrayMode::SET ,        // unique, ordre d'insertion (raccourci)
        'genres'   => ArrayMode::SORTED_SET , // unique + trié par valeur
        'chapters' => [ ArrayMode::LIST , Arango::ITEM_KEY => 'id' ], // tableau d'objets, ciblés par leur `id`
    ],
]);
```

Chaque entrée est :

- soit un **raccourci** : `'tags' => ArrayMode::SET` ;
- soit une **forme riche** : `'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ]`.

Un champ **non déclaré** est traité par défaut comme `ArrayMode::LIST`, sans compteur.

### Le compteur (`Arango::COUNTER`)

Si un champ déclare un `COUNTER`, l'attribut nommé est **recalculé automatiquement** (`LENGTH(...)`) à chaque mutation. Pratique pour trier/filtrer sur la taille de la liste sans dérouler le tableau (par ex. `numberOfTracks`).

### Valeur par défaut à la création

Les champs-tableaux déclarés sont **initialisés à `[]` à la création** du document (et leur `counter` à `0`) : `insert()` — et la branche INSERT d'`upsert()` — pose ces défauts pour tout champ déclaré dans `AQL::ARRAYS` que le payload ne fournit pas. Ainsi un document neuf est toujours prêt pour `arrayInsert`/`arrayContains` sans cas particulier « champ absent ». Les valeurs fournies explicitement ne sont jamais écrasées.

## Modes d'ordre (`ArrayMode`)

Le mode pilote **l'unicité ET le tri** en un seul réglage — vous ne passez donc jamais de flag `unique`/`sorted` à l'appel :

| Mode | Doublons | Ordre | `arrayMove` | AQL d'insertion |
|---|---|---|---|---|
| `ArrayMode::LIST` | autorisés | insertion | ✅ | `APPEND(doc.f, @value)` |
| `ArrayMode::SET` | non | insertion | ✅ | `APPEND(doc.f, @value, true)` |
| `ArrayMode::SORTED_SET` | non | par valeur | ❌ (lève une exception) | `SORTED_UNIQUE(APPEND(doc.f, @value, true))` |

> Sur un champ `SORTED_SET`, tout ce qui touche à l'ordre manuel n'a aucun sens (le tri par valeur écrase toute position) et lève une `UnsupportedOperationException` : [`arrayMove()`](#arraymove), [`arrayReorder()`](#arrayreorder), et la [numérotation](#numéroter-les-éléments-arangoposition_key).

## Cibler un élément par sa clé (`Arango::ITEM_KEY`)

Par défaut, toutes les opérations désignent un élément par **égalité de valeur** : `REMOVE_VALUE(doc.tracks, @value)` retire l'élément dont la valeur est, octet pour octet, celle qu'on a envoyée. Pour des chaînes (`'jazz'`, `'track-A'`), c'est parfait. Pour des **objets**, c'est un piège.

**Le décor.** La playlist `playlist-42` porte des chapitres, et chaque chapitre est un objet :

```json
{
  "_key"     : "playlist-42",
  "chapters" : [ { "id": "c1", "title": "Intro", "rating": 3 } ]
}
```

**La situation.** Le client veut retirer le chapitre `c1`. Sans clé d'élément, il n'a qu'un moyen de le désigner : renvoyer l'objet entier.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => [ 'id' => 'c1' , 'title' => 'Intro' , 'rating' => 3 ], // sa copie, complète
]);
```

Deux choses tournent mal, et **silencieusement** :

- **La copie vieillit.** Dès qu'une écriture a touché le chapitre — une note qui change, un attribut ajouté par une migration — la copie que détient le client ne vaut plus celle de la base. L'égalité échoue, l'opération ne matche rien. Et elle ne proteste pas : elle renvoie un document inchangé, pas une erreur.
- **L'objet ne tient pas dans une URL.** `DELETE /playlists/42/chapters/{value}` attend un segment, pas un JSON. Il faut passer par le corps de la requête, ce qui ferme les routes REST naturelles.

**La déclaration.** Un champ peut nommer l'attribut qui identifie chacun de ses éléments :

```php
AQL::ARRAYS =>
[
    'chapters' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfChapters' , Arango::ITEM_KEY => 'id' ],
    'tags'     => ArrayMode::SET , // aucune clé déclarée → ciblage par valeur, comme avant
]
```

À partir de là, `Arango::VALUE` n'est plus l'élément : c'est **sa clé**.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => 'c1', // deux caractères, qui tiennent dans une URL
]);
```

> Ne pas confondre avec `Arango::KEY`, qui identifie le **document** (`_key` par défaut). `Arango::ITEM_KEY` identifie un **élément à l'intérieur** d'un de ses champs-tableaux.

### Ce que ça change dans l'AQL générée

Les deux colonnes, côte à côte :

| Opération | Sans clé (par valeur) | Avec `ITEM_KEY => 'id'` |
|---|---|---|
| [`arrayContains`](#arraycontains) | `POSITION(doc.f, @value)` | `doc.f[? FILTER CURRENT.id == @value]` |
| [`arrayRemove`](#arrayremove) | `REMOVE_VALUE(doc.f, @value)` | `doc.f[* FILTER CURRENT.id != @value]` |
| [`arrayMove`](#arraymove) | `REMOVE_VALUE` puis recomposition | `FIRST(…)` puis recomposition **gardée** |
| [`arrayUpdate`](#arrayupdate) | *(refusé — voir plus bas)* | `doc.f[* RETURN CURRENT.id == @value ? MERGE(CURRENT, @patch) : CURRENT]` |
| [`arrayInsert`](#arrayinsert) | `APPEND(doc.f, @value)` | *identique* — une insertion porte l'élément entier, il n'y a rien à retrouver |
| [`arrayPurgeRef`](#arraypurgeref) | `REMOVE_VALUE(doc.f, @value)` | *identique* — voir les [limites](#les-limites) |

**Rétrocompatibilité.** Un champ qui ne déclare aucun `ITEM_KEY` garde exactement son comportement : l'AQL générée est identique, à l'octet près.

### Une clé qui ne correspond à rien

Les deux opérations qui visent un élément **existant** — `arrayMove` et `arrayUpdate` — sont **gardées** : une clé inconnue réécrit le tableau **tel quel**. `arrayMove` n'insère pas un `null` à la position demandée, `arrayUpdate` ne fusionne rien. Le tableau retourné (`RETURN NEW`) suffit alors à constater le raté — c'est ainsi que le [contrôleur HTTP](../controllers/README.md#arraypropertycontroller) répond `404` sans une seule requête de plus.

### Override ponctuel

La clé peut aussi se passer à l'appel, via `Arango::ITEM_KEY` dans `$init`. Elle l'emporte alors sur la configuration du champ — pratique pour un champ non déclaré, ou pour un appel qui sait mieux :

```php
$playlists->arrayRemove([
    Arango::OWNER    => 'playlist-42',
    Arango::FIELD    => 'members',
    Arango::VALUE    => 7,
    Arango::ITEM_KEY => 'memberId', // ce champ ne déclare rien, l'appel tranche
]);
```

### Les limites

Une par une :

- **`arrayPurgeRef` reste par valeur.** La purge à l'échelle de la collection ignore l'`ITEM_KEY` et compare structurellement. C'est cohérent avec son usage (purger une **référence** partagée, en général un scalaire), mais c'est un trou connu : il n'existe pas encore de purge par clé.
- **La comparaison est typée.** `CURRENT.id == @value` est stricte en AQL : une clé numérique `1` demandée depuis une URL — donc `"1"`, une chaîne — ne matche rien. Déclarez des clés textuelles, ou convertissez avant l'appel.
- **`arrayContains` prend une clé, pas une liste.** Seul `arrayRemove` accepte plusieurs clés d'un coup (`CURRENT.id NOT IN @value`).
- **Le nom de la clé est interpolé verbatim** dans l'AQL — les helpers d'expansion de tableau n'échappent rien. Il est donc validé par `assertAttributeName()` **quelle que soit son origine** (configuration ou override d'appel) : un nom qui n'est pas un identifiant d'attribut sûr lève une `ValidationException` avant d'atteindre la moindre requête.

## Numéroter les éléments (`Arango::POSITION_KEY`)

Imaginez un classeur à anneaux. Il y a deux façons d'y ranger les feuilles.

- **L'ordre physique** : vous sortez une feuille, vous la remettez trois crans plus haut. Rien d'autre à toucher.
- **Le numéro écrit en haut de chaque feuille** : dès que vous en bougez une, il faut **regommer et réécrire le numéro de toutes les autres**.

Un tableau embarqué connaît déjà le premier : ArangoDB conserve l'ordre des éléments, et [`arrayMove`](#arraymove) suffit à le changer. Le second — un attribut `position` porté par chaque élément — demande une renumérotation complète à chaque écriture. C'est ce que déclare `Arango::POSITION_KEY`.

> **Avez-vous vraiment besoin de cet attribut ?** L'ordre du tableau vous revient tel quel : le rang n'est nécessaire que si vos éléments sont consommés **détachés** de leur document parent, si un schéma existant l'impose, ou si un client trie dessus. Dans les autres cas, `arrayMove` seul fait le travail, sans rien à regommer.

**Le décor.** Une facture porte ses lignes dans un tableau embarqué, chaque ligne portant son rang :

```json
{
  "_key"  : "invoice-42",
  "lines" : [
    { "id": "l1", "label": "Alpha", "position": 0 },
    { "id": "l2", "label": "Beta" , "position": 1 },
    { "id": "l3", "label": "Gamma", "position": 2 }
  ]
}
```

**La situation.** L'utilisateur fait glisser `l3` en tête. L'ordre du tableau change tout seul — mais les trois `position` deviennent fausses **en même temps**, pas seulement celle de la ligne déplacée.

**La déclaration.** Le champ nomme l'attribut qui porte le rang, à côté du mode et de la clé d'élément :

```php
AQL::ARRAYS =>
[
    'lines'  => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfLines' , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => 'position' ],
    'tracks' => [ ArrayMode::LIST ], // rien de déclaré → aucun élément n'est jamais renuméroté
]
```

À partir de là, **toute** écriture sur `lines` renumérote le tableau entier, à partir des index :

```aql
LET __arr = …            -- ce que l'opération a produit (ajout, retrait, déplacement, édition…)
LET __pos = LENGTH(__arr) == 0 ? []
          : (FOR __i IN 0 .. LENGTH(__arr) - 1 RETURN MERGE(NTH(__arr, __i), { position: __i }))
UPDATE doc WITH { lines: __pos, numberOfLines: LENGTH(__pos), modified: … }
```

La numérotation est **à base 0**, comme `Arango::POSITION` et comme `SLICE`/`NTH` en AQL.

### Ce qui est renuméroté

**Tout**, y compris la purge à l'échelle de la collection :

| Opération | Effet sur les rangs |
|---|---|
| [`arrayInsert`](#arrayinsert) | l'élément ajouté prend son rang, les autres sont confirmés |
| [`arrayRemove`](#arrayremove) | le trou se referme (`0,1,3` devient `0,1,2`) |
| [`arrayMove`](#arraymove) / [`arrayReorder`](#arrayreorder) | tout le nouvel ordre est réécrit |
| [`arrayUpdate`](#arrayupdate) | le patch s'applique, puis les rangs sont réécrits **par-dessus** |
| [`arrayPurgeRef`](#arraypurgeref) | renumérote aussi — retirer une référence ne laisse pas de trou |

**Rétrocompatibilité.** Un champ qui ne déclare aucun `POSITION_KEY` garde exactement son AQL, à l'octet près.

### La garde du tableau vide

Le ternaire `LENGTH(__arr) == 0 ? []` n'est pas cosmétique. Sur un tableau vide, `0 .. LENGTH(__arr) - 1` devient `0 .. -1` — et AQL lit cet intervalle **à l'envers**, comme une descente : il produit `[0, -1]`. Sans la garde, vider le tableau y écrirait donc **deux éléments fantômes `null`**, au lieu de le laisser vide.

### L'ordre des opérations

La renumérotation est le **dernier** `LET`, appliqué sur le tableau que l'opération vient de produire. Deux conséquences, et elles sont voulues :

- **L'invariant du champ passe avant.** Sur un `ArrayMode::SET`, `UNIQUE()` s'applique d'abord : si les rangs étaient écrits avant, deux éléments identiques deviendraient distincts (`position: 0` et `position: 1`) et l'unicité ne fusionnerait plus rien.
- **Un patch ne choisit jamais son rang.** `arrayUpdate` fusionne d'abord, la renumérotation réécrit ensuite — un corps de requête qui porte `"position": 99` est donc sans effet sur le rang. Le serveur reste seul maître de la numérotation.

### Les limites

Une par une :

- **Interdit sur un `SORTED_SET`.** Écrire le rang **dans** l'élément nourrit le tri qui décide de ce rang : l'ordre ne se stabiliserait jamais. La combinaison lève une `UnsupportedOperationException`.
- **Le nom doit être plat.** Une clé d'élément pointée (`meta.id`) est acceptée, parce qu'elle n'est jamais que **lue** — AQL descend d'un niveau. Une clé de position pointée est **refusée** : elle est **réécrite**, et dans un objet AQL la clé est une chaîne, pas un chemin. `MERGE(CURRENT, { "meta.position": 3 })` créerait un attribut dont le *nom* contient un point, à côté du vrai `meta` resté périmé — silencieusement. Un `MERGE` imbriqué (`MERGE(CURRENT, { meta: MERGE(CURRENT.meta, { position: 3 }) })`) le permettrait un jour ; en attendant, une clé pointée lève une `ValidationException`.
- **La base est 0, en dur.** Pas d'option pour numéroter à partir de 1.
- **Le nom est interpolé verbatim** dans l'AQL, donc validé par `assertAttributeName()` quelle que soit son origine — même règle que la clé d'élément.

## Les méthodes

| Méthode | Rôle | Retour |
|---|---|---|
| [`arrayInsert`](#arrayinsert) | ajoute une ou plusieurs valeurs | `?object` (doc modifié) |
| [`arrayRemove`](#arrayremove) | retire une ou plusieurs valeurs | `?object` |
| [`arrayMove`](#arraymove) | déplace une valeur à une position | `?object` |
| [`arrayReorder`](#arrayreorder) | applique **tout un ordre** depuis une liste de clés | `?object` |
| [`arrayUpdate`](#arrayupdate) | fusionne un patch dans un élément, **sur place** | `?object` |
| [`arrayContains`](#arraycontains) | teste la présence d'une valeur | `bool` |
| [`arrayPurgeRef`](#arraypurgeref) | retire une valeur dans **tous** les documents qui la contiennent | `object[]` ou `int` |

### Clés `$init` communes

| Clé | Défaut | Description |
|---|---|---|
| `Arango::OWNER` | — | La valeur qui identifie le document à modifier. |
| `Arango::KEY` | `_key` | L'attribut de localisation du document (ex. `Prop::ID`, `'name'`). |
| `Arango::PREFIX` | `doc` | L'alias AQL du document. |
| `Arango::FIELD` | — | Le champ-tableau visé. |
| `Arango::VALUE` | — | L'élément (ou les éléments) concerné(s) — ou **sa clé**, si le champ déclare un `ITEM_KEY`. |
| `Arango::ITEM_KEY` | *(config du champ)* | Override ponctuel de l'attribut qui identifie un élément. Voir [ciblage par clé](#cibler-un-élément-par-sa-clé-arangoitem_key). |
| `Arango::POSITION_KEY` | *(config du champ)* | Override ponctuel de l'attribut qui porte le rang. Voir [numérotation](#numéroter-les-éléments-arangoposition_key). |
| `Arango::TOUCH` | `true` | Met `modified` à `DATE_ISO8601(DATE_NOW())` ; `false` pour ne pas y toucher. |
| `Arango::DEBUG` | `false` | Journalise la requête AQL compilée. |

> **Convention `OWNER`/`VALUE`** : ici `OWNER` localise le document et `VALUE` est l'élément du tableau. (Ailleurs dans la lib, `VALUE` localise le document ; `OWNER` lève l'ambiguïté pour les opérations sur tableaux.)

### `arrayInsert`

Ajoute une ou plusieurs valeurs. `VALUE` accepte un scalaire ou un tableau (ses éléments sont ajoutés, jamais imbriqués). Clés additionnelles : `Arango::SIDE` (`Side::LEFT` pour préfixer, `Side::RIGHT` par défaut pour suffixer), `Arango::MODE` (override ponctuel du mode).

```php
use oihana\arango\models\enums\Side;

$playlists->arrayInsert([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tracks',
    Arango::VALUE => [ 'track-A' , 'track-B' ],
]);
```
```aql
FOR doc IN @@collection FILTER doc._key == @key
  LET __arr = APPEND(doc.tracks, @value)
  UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) }
  IN @@collection RETURN NEW
```

- `tags` (SET) → `APPEND(doc.tags, @value, true)` (unique appliqué automatiquement).
- `genres` (SORTED_SET) → `SORTED_UNIQUE(APPEND(doc.genres, @value, true))`.
- `Side::LEFT` → opérandes inversés : `APPEND(@value, doc.tracks)`.

### `arrayRemove`

Retire une ou plusieurs valeurs. Scalaire → `REMOVE_VALUE` ; tableau → `REMOVE_VALUES`.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tracks',
    Arango::VALUE => 'track-A',
]);
// → LET __arr = REMOVE_VALUE(doc.tracks, @value)
```

Sur un champ à clé d'élément, `VALUE` porte la ou les **clés** à retirer :

```aql
LET __arr = doc.chapters[* FILTER CURRENT.id != @value]      -- une clé
LET __arr = doc.chapters[* FILTER CURRENT.id NOT IN @value]  -- une liste de clés
```

### `arrayMove`

Déplace une valeur existante à une position (index à base 0, clé `Arango::POSITION`). Non supporté sur un champ `SORTED_SET`.

```php
$playlists->arrayMove([
    Arango::OWNER    => 'playlist-42',
    Arango::FIELD    => 'tracks',
    Arango::VALUE    => 'track-A',
    Arango::POSITION => 2,
]);
```
```aql
LET __rm  = REMOVE_VALUE(doc.tracks, @value)
LET __arr = APPEND( PUSH( SLICE(__rm, 0, 2), @value, true ), SLICE(__rm, 2) )
```

Sur un champ à clé d'élément, l'élément doit d'abord être **retrouvé** avant d'être réinséré — et toute la recomposition est gardée sur cette recherche :

```aql
LET __el  = FIRST(doc.chapters[* FILTER CURRENT.id == @value])
LET __rm  = doc.chapters[* FILTER CURRENT.id != @value]
LET __arr = __el == null ? doc.chapters
          : APPEND( PUSH( SLICE(__rm, 0, 2), __el, true ), SLICE(__rm, 2) )
```

Une clé inconnue laisse donc `__el` à `null`, et le tableau est réécrit **inchangé** — jamais un `null` fantôme à la position demandée.

### `arrayReorder`

Applique **tout un ordre d'un coup**, depuis la liste des clés d'éléments — là où `arrayMove` en déplace un seul. C'est la forme qui convient quand le client connaît déjà l'ordre final : elle est idempotente, elle tient en un aller-retour, et elle ne laisse aucune ambiguïté sur l'intention.

**La situation.** L'utilisateur a fini de réarranger les lignes de `invoice-42` ; l'interface envoie l'ordre obtenu.

```php
$invoices->arrayReorder([
    Arango::OWNER => 'invoice-42',
    Arango::FIELD => 'lines',
    Arango::VALUE => [ 'l3' , 'l1' , 'l2' ], // les clés, dans l'ordre voulu
]);
```
```aql
LET __ord = (FOR __k IN @value
             LET __el = FIRST(doc.lines[* FILTER CURRENT.id == __k])
             FILTER __el != null
             RETURN __el)
LET __arr = APPEND(__ord, doc.lines[* FILTER CURRENT.id NOT IN @value])
```

**Une liste partielle réordonne, elle ne supprime pas.** Les éléments que la liste ne nomme pas sont **conservés** et rappendus derrière, dans leur ordre relatif. Envoyer `[ 'l3' ]` remonte `l3` en tête et laisse `l1`, `l2` à la suite — un bug d'interface qui n'enverrait qu'un sous-ensemble ne peut donc pas effacer des lignes.

Les autres cas limites vont dans le même sens :

| Envoyé | Résultat |
|---|---|
| une clé inconnue | ignorée (`FILTER __el != null`), le reste s'applique |
| une liste vide | rien n'est nommé, donc tout est « reste » : le tableau est intact |
| une clé en double | écrasée avant la requête, première occurrence gagnante |

> **Pourquoi dédoublonner en PHP plutôt qu'avec `UNIQUE()`.** Résoudre deux fois la même clé pousserait deux fois son élément, alors que le `NOT IN` ne le retire qu'une fois des restants : le tableau gagnerait un clone. Et l'`UNIQUE()` d'AQL ne garantit **aucun ordre** de sortie — or l'ordre est précisément le sujet de cette opération.

**Une clé d'élément est obligatoire** (comme pour [`arrayUpdate`](#arrayupdate)) : sans attribut identifiant les éléments, il n'y a rien à ordonner. Et l'opération est **refusée sur un `SORTED_SET`** (comme [`arrayMove`](#arraymove)) : le tri par valeur écraserait l'ordre demandé. Les deux cas lèvent une `UnsupportedOperationException`.

Enfin, `arrayReorder` **ne réapplique pas** l'invariant du champ : c'est une permutation d'éléments existants, elle ne peut pas introduire un doublon qui n'était pas déjà là.

### `arrayContains`

Teste la présence d'une valeur dans le tableau d'un document. Retourne un `bool`.

```php
$playlists->arrayContains([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tags',
    Arango::VALUE => 'jazz',
]); // bool
```
```aql
RETURN LENGTH(FOR doc IN @@collection FILTER doc._key == @key && POSITION(doc.tags, @value) RETURN 1) > 0
```

Sur un champ à clé d'élément, le test de présence devient l'opérateur booléen d'expansion — un objet est ainsi retrouvé depuis sa seule clé :

```aql
… FILTER doc._key == @key && doc.chapters[? FILTER CURRENT.id == @value] …
```

### `arrayUpdate`

Fusionne un **patch partiel** dans l'élément qui porte la clé donnée — une édition **sur place**, là où les autres méthodes ne font qu'ajouter, retirer ou réordonner des éléments entiers. Le patch se passe par la clé `Arango::PATCH`.

**La situation.** Le chapitre `c1` de `playlist-42` doit passer à la note 5, et gagner une annotation. Rien d'autre ne doit bouger.

```php
$playlists->arrayUpdate([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => 'c1',                            // la clé de l'élément
    Arango::PATCH => [ 'rating' => 5 , 'note' => 'live' ], // ce qui change
]);
```
```aql
LET __arr = doc.chapters[* RETURN CURRENT.id == @value ? MERGE(CURRENT, @patch) : CURRENT]
UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: … }
```

Tous les éléments sont reprojetés, seul celui qui porte la clé est fusionné. La fusion est **partielle** : `rating` est écrasé, `note` est ajouté, `title` survit intact — et l'ordre du tableau comme les voisins sont préservés.

**Une clé d'élément est obligatoire.** Sur un champ ciblé par valeur, `arrayUpdate` lève une `UnsupportedOperationException` au lieu de servir une opération à moitié :

> Désigner l'élément par sa valeur exigerait d'en détenir une copie octet pour octet — que **le patch qu'on est en train d'appliquer invalide**. Le deuxième appel identique ne matcherait plus rien. Mieux vaut refuser que d'émettre une opération qui ne marche qu'une fois. (Un `Arango::ITEM_KEY` passé à l'appel suffit à débloquer un champ non déclaré.)

**L'invariant du champ est réappliqué.** Un patch peut rendre deux éléments identiques : `ArrayMode::SET` enveloppe donc le résultat dans `UNIQUE()`, `ArrayMode::SORTED_SET` dans `SORTED_UNIQUE()` — les deux acceptent des objets. Contrairement à `arrayMove`, il n'y a **pas** de garde `SORTED_SET` : une édition sur place n'entre pas en conflit avec l'ordre de tri.

Enfin, une clé inconnue réécrit le tableau inchangé (rien n'est fusionné), ce qui laisse au [contrôleur HTTP](../controllers/README.md#arraypropertycontroller) de quoi répondre `404`.

#### Effacer un attribut — `Arango::ERASE_NULL`

🔑 **Un `null` ne vide rien, sauf si on le demande.** `MERGE()` le conserve : un patch qui dit `{ "note": null }` réécrit l'attribut **à `null`** au lieu de l'ôter. Un élément reconstruit sur place ne peut donc jamais perdre un attribut qu'il portait — ce qui devient un piège dès qu'on recalcule un élément entier plutôt que d'en retoucher un bout.

**La métaphore.** `MERGE()` sait écrire au tableau, il ne sait pas effacer. `Arango::ERASE_NULL` est le chiffon qu'on lui tend.

**La situation.** Le chapitre `c1` perd son annotation : on ne veut pas `"note": null` dans le document, on veut que la clé disparaisse.

```php
$playlists->arrayUpdate([
    Arango::OWNER      => 'playlist-42',
    Arango::FIELD      => 'chapters',
    Arango::VALUE      => 'c1',
    Arango::PATCH      => [ 'rating' => 5 , 'note' => null ], // 5 est écrit, note est ôtée
    Arango::ERASE_NULL => true,
]);
```
```aql
LET __arr = doc.chapters[* RETURN CURRENT.id == @value ? UNSET(MERGE(CURRENT, @patch), "note") : CURRENT]
```

Deux limites, dites d'avance :

- **Le premier niveau seulement**, comme `UNSET()` lui-même : un `null` niché dans un sous-objet du patch (`{ "price": { "value": null } }`) reste une valeur que la fusion écrit. Un élément perd un attribut entier à la fois, jamais la moitié d'un.
- **Les noms partent en littéraux** dans l'AQL — `UNSET()` prend des chaînes, pas des liaisons — donc chacun passe par la même garde que tout autre nom d'attribut émis ici. Un nom douteux lève une `ValidationException` plutôt que d'atteindre le serveur.

Le drapeau est **explicite** : sans lui, un élément conserve tous ses attributs, exactement comme avant.

⚠️ **Trois `null` cohabitent dans cette bibliothèque, et ils ne parlent pas du même endroit.** À ne pas confondre :

| | Où | Ce qu'il décide |
| :-- | :-- | :-- |
| `Arango::ERASE_NULL` | l'appel à `arrayUpdate` | si un `null` du patch **ôte** l'attribut d'un **élément** |
| `Arango::KEEP_NULL` | une définition de champ de payload | si un `null` envoyé par le client **survit** à la passe de compression |
| `Arango::OPTIONS` → `keepNull` | l'`UPDATE` d'ArangoDB | ce que le serveur fait d'un attribut nul **au niveau du document** |

### `arrayPurgeRef`

Retire une valeur dans **tous** les documents de la collection qui la contiennent — typiquement pour purger une référence devenue obsolète (un élément supprimé du catalogue).

```php
// Le morceau "track-A" est supprimé : on l'ôte de toutes les playlists.
$playlists->arrayPurgeRef([
    Arango::FIELD => 'tracks',
    Arango::VALUE => 'track-A',
]);
```
```aql
FOR doc IN @@collection FILTER POSITION(doc.tracks, @value)
  LET __arr = REMOVE_VALUE(doc.tracks, @value)
  UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) }
  IN @@collection RETURN NEW
```

Le retour est **au choix** :

- par défaut → `object[]` (les documents modifiés) ;
- avec `Arango::COUNT => true` → un `int` (le nombre de documents affectés, sans matérialiser les documents).

## Signaux

Les méthodes d'écriture sur un document (`arrayInsert`/`arrayRemove`/`arrayMove`/`arrayReorder`/`arrayUpdate`) émettent les signaux `beforeUpdate` / `afterUpdate` du trait [`HasUpdateSignals`](../models.md#cycle-de-vie-et-hooks), exactement comme les autres méthodes d'écriture du modèle. `arrayContains` est une lecture : aucun signal. `arrayPurgeRef` n'en émet pas non plus — c'est une opération à l'échelle de la collection, qui ne rentre pas dans le contrat « un document mis à jour ».

## Propager une modification aux documents parents

Quand un enfant change, on peut vouloir « toucher » les documents parents qui le référencent (par ex. invalider un cache). **La librairie ne balaie pas la base pour ça** : elle émet un signal, et c'est à votre application d'y réagir.

```php
// Quand un "track" est modifié, on rafraîchit les playlists qui le contiennent.
$tracks->afterUpdate->connect( function( AfterUpdate $event ) use ( $playlists )
{
    $playlists->arrayPurgeRef([ /* … */ ]); // ou un updateDate ciblé sur les parents concernés
});
```

Avantage : la propagation reste **explicite, testable et sous votre contrôle**, plutôt qu'un balayage AQL massif caché dans le modèle.

## Migration depuis `ListItemTrait` / `MultiFieldTrait`

`DocumentsArrayTrait` remplace les anciens traits `ListItemTrait` et `MultiFieldTrait` (supprimés). Correspondance :

| Ancien | Nouveau |
|---|---|
| `insertListItems` / `insertInMultiField` | `arrayInsert` |
| `deleteListItem` / `deleteListItemAll` / `deleteInMultiField` | `arrayRemove` |
| `updateInMultiField` | `arrayMove` |
| `existsInMultiField` | `arrayContains` |
| `deleteReverseInMultiField` | `arrayPurgeRef` |
| `updateDateParentMultiField` | *(supprimé — voir [propagation aux parents](#propager-une-modification-aux-documents-parents))* |

Le compteur (ancien `num`) et le côté d'insertion (`left`/`right`) sont désormais déclarés via `Arango::COUNTER` et l'enum `Side`.

## Voir aussi

- [Modèles `Documents` et `Edges`](../models.md) — la couche haut-niveau et son catalogue de clés `AQL::*`.
- [Projection des edges et joins](../edges-joins-projection.md) — l'alternative *edges* pour les relations.
- [Référence des enums](../enums.md) — `AQL`, `Arango`, `ArrayMode`, `Side`.
