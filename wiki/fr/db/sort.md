# Tri (`?sort=` et `?near=`)

Une requête de liste ne fait pas que **restreindre** (voir [Recherche & filtrage](search-and-filtering.md)) : elle **ordonne**. Cette page couvre les deux leviers de tri exposés à l'URL — `?sort=` (tri par champ) et `?near=` (tri par distance géographique) — et surtout le **garde-fou** qui décide *sur quoi* un client a le droit de trier.

## Le principe : fail-closed

**L'analogie.** Comme pour la projection (`fields`) et les filtres, le tri a un videur à l'entrée. Le modèle tient la **liste des invités** — les champs déclarés triables dans `AQL::SORTABLE`. Une clé de tri qui n'est pas sur la liste n'entre pas : elle est **silencieusement ignorée**. Et un modèle qui ne tient **aucune** liste ne laisse trier sur **rien** — jamais « tout par défaut ».

> C'est le point à retenir : `AQL::SORTABLE` absent (`null`) signifie **rien n'est triable**, pas « tout est triable ». Le client ne choisit jamais un nom de champ que le modèle n'a pas explicitement ouvert.

## La grammaire `?sort=`

`?sort=` est une **liste de clés séparées par des virgules** ; un `-` en tête inverse une clé en descendant.

```
?sort=name,-created   →   SORT doc.name ASC, doc.created DESC
```

Chaque clé est résolue contre la *whitelist* `AQL::SORTABLE` (clé URL → champ AQL). Une clé hors *whitelist* est **ignorée** (pas d'erreur, elle disparaît simplement de la clause `SORT`).

## Déclarer les champs triables — `AQL::SORTABLE`

La *whitelist* résout chaque clé de `?sort=` vers un champ AQL. Trois notations interchangeables sont acceptées et **peuvent être mélangées dans le même tableau** :

```php
// Raccourci indexé — la clé URL est identique au champ (le cas courant) :
AQL::SORTABLE => [ Prop::_FROM , Prop::_TO , Prop::CREATED , Prop::MODIFIED ]

// Alias indexé — la clé publique diffère du champ AQL (?sort=name → doc.givenName) :
AQL::SORTABLE => [ [ Prop::NAME => Prop::GIVEN_NAME ] , Prop::CREATED ]

// Associatif (historique) — toujours supporté, inchangé :
AQL::SORTABLE => [ Prop::CREATED => Prop::CREATED , Prop::NAME => Prop::GIVEN_NAME ]
```

- Le sens de la paire est toujours `[ cléURL => champAQL ]`, identique dans les trois formes.
- La valeur d'un champ peut être un chemin multi-segments (`[ 'address', 'city' ]` → `address.city`), y compris en forme alias.

La normalisation (`oihana\arango\models\helpers\normalizeSortable()`) replie n'importe laquelle des trois formes vers la map canonique `cléURL => champAQL`, une seule fois à la construction. Elle est **idempotente** et **rétro-compatible** : une map associative existante est renvoyée telle quelle.

## Le tri par défaut passe aussi par la *whitelist*

`AQL::SORT_DEFAULT` fixe le tri appliqué quand aucun `?sort=` n'est fourni. Il s'écrit dans **la même grammaire** que `?sort=` (ex. `'-created'`) et traverse **le même videur**.

> **La situation.** Un modèle veut trier par défaut sur `created`, mais ne déclare pas `SORTABLE`.

```php
AQL::SORT_DEFAULT => Prop::CREATED ,   // et pas de AQL::SORTABLE
```

Comme la *whitelist* est vide (fail-closed), la clé `created` du défaut est ignorée elle aussi : **le modèle ne trie rien**. La règle est unique et sans exception : *tout ce qui est nommé — par le client ou par le défaut — doit être dans `SORTABLE`.*

```php
AQL::SORTABLE     => [ Prop::CREATED , Prop::NAME ] ,
AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,        // '-created' — la clé est whitelistée : OK
```

## Trier à travers une relation

**La situation.** L'auteur d'un article n'est pas un de ses champs : il vit dans
un autre document, au bout d'une arête. `?sort=author` était impossible — le
compilateur de tri ne connaissait qu'une seule forme, `doc.<chemin>`, un chemin
**stocké** et rien d'autre.

C'est possible dès lors que la relation est **projetée**, et la raison vaut d'être
connue : projeter un champ `Filter::EDGE` émet un `LET`, et la requête compilée
place tous les `LET` **avant** le `SORT`. Ordonner sur le document lié revient
donc à *nommer cette variable*, pas à traverser une seconde fois. Une seule
traversée sert à la fois la projection et l'ordre.

Trois déclarations travaillent ensemble :

```php
AQL::FIELDS =>
[
    'title'  => [] ,
    'author' =>
    [
        Field::FILTER => Filter::EDGE ,   // ① relation singulière
        Field::UNIQUE => 'authorRef' ,    // ② épingler la variable du LET
    ] ,
] ,

AQL::EDGES =>
[
    'author' =>
    [
        AQL::MODEL     => $articlesAuthors ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
    ] ,
] ,

AQL::SORTABLE =>
[
    'title' ,
    'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ,  // ③
] ,
```

```
?sort=title,-author
```
```aql
FOR doc IN @@articles
  LET authorRef = ( FOR v IN OUTBOUND doc articles_authors RETURN … )
  SORT doc.title ASC, FIRST(authorRef).name DESC
  RETURN { title: doc.title, author: … }
```

- **`AQL::EDGE` nomme le champ projeté**, pas la collection d'arêtes. C'est ce qui
  permet au tri de retrouver la variable que la projection a déjà créée.
- **`Field::PATH` nomme le champ du document lié**, et accepte un chemin imbriqué
  (`[ 'address', 'city' ]` → `FIRST(authorRef).address.city`).
- **La relation doit être projetée** : la bibliothèque ne fabrique jamais une
  traversée juste pour ordonner. Si on trie dessus, on le renvoie.
- **`Field::UNIQUE` est obligatoire.** Sans lui, la variable du `LET` porte un nom
  aléatoire engendré (`author_e1626906831`), qu'aucune déclaration ne peut
  désigner.

### Ce qui est refusé, et à quel volume

La frontière est celle que toute la bibliothèque trace : une **déclaration** que
seul l'auteur du modèle peut corriger est refusée bruyamment ; une **clé client**
est jetée en silence.

| Situation | Réaction |
|---|---|
| Le champ nommé n'est pas projeté | **refus** (`500`) — aucun `LET` à nommer |
| La relation est plurielle (`Filter::EDGES`) | **refus** (`500`) — lequel des documents liés déciderait ? |
| Pas de `Field::UNIQUE` sur la relation | **refus** (`500`) — la variable est indésignable |
| Pas de `Field::PATH` sur l'entrée | **refus** (`500`) — rien ne dit ce qui ordonne |
| `?sort=` nomme une clé inconnue | **jetée**, en silence — contrat inchangé |
| La permission refuse la clé | **jetée**, en silence — pas d'oracle de tri |

**La permission** fonctionne comme partout ailleurs : un `Field::REQUIRES`
explicite sur l'entrée `SORTABLE` l'emporte, sinon le sujet de la **relation
projetée** est hérité. Ce qu'on ne peut pas lire, on ne peut pas s'en servir pour
ordonner — sinon l'ordre le trahit.

> **Avec `?group=`**, un critère relationnel est inerte : après un `COLLECT` les
> variables du document n'existent plus, et le tri groupé n'accepte que les
> variables que le `COLLECT` émet. La clé est jetée comme n'importe quelle autre
> clé non groupée.

## Permission de tri

Whitelister ne suffit pas toujours. Un champ peut être **caché à la lecture** par une permission (`Field::REQUIRES` dans la projection) : s'il reste triable, l'ordre des résultats trahit sa valeur. C'est l'**oracle de tri** — trier sur `salary` sans le droit de le lire, et deviner qui gagne le plus rien qu'en regardant l'ordre.

Le tri se ferme donc au même endroit que la lecture. La permission se résout de deux façons.

### Façon héritée (le cas courant)

**La situation.** Le champ `salary` est déjà protégé dans la projection ; on veut le rendre triable sans redire la permission.

```php
public array  $fields   = [ Prop::NAME => true , Prop::SALARY => [ Field::REQUIRES => 'hr:read' ] ] ;
public ?array $sortable = [ Prop::NAME , Prop::SALARY ] ;   // juste la liste
```

Quand `?sort=salary` arrive, le tri **hérite la permission du champ visé dans `$fields`, au chemin résolu** — pas seulement à la racine. Un alias vers un chemin profond (`'salary' => 'address.salary'`) hérite le `Field::REQUIRES` du **sous-champ exact** `address.salary`, exactement comme `?groupBy=` et `?bounds=` (via `isPathAuthorized`, qui descend `Field::FIELDS` et strippe `[*]`). *« Ce que tu ne peux pas lire, tu ne peux pas le trier »* — automatiquement, sans déclaration en double, et sans se tromper de champ (jamais l'homonyme de la clé URL).

| Utilisateur **avec** `hr:read` | Utilisateur **sans** `hr:read` |
|---|---|
| `?sort=salary` → `SORT doc.salary ASC` | `?sort=salary` → clé **ignorée**, aucun tri sur ce champ |

### Façon explicite (champ non projeté, ou règle propre au tri)

**La situation.** Un champ triable qui n'existe **pas** dans la projection — il n'y a donc aucune permission à hériter. On l'écrit directement dans l'entrée `SORTABLE`.

```php
AQL::SORTABLE =>
[
    Prop::NAME ,
    'rank' => [ Field::PATH => 'internal.rank' , Field::REQUIRES => 'staff:read' ] ,
] ,
```

L'entrée porte son propre champ (`Field::PATH` → `doc.internal.rank`) et sa propre permission (`Field::REQUIRES`). Une permission écrite ici **prime** sur celle héritée de `$fields`.

> **Règle de résolution.** La permission explicite de l'entrée `SORTABLE` gagne ; sinon on hérite de celle du **champ visé dans `$fields`, au chemin résolu** (profondeur incluse — jamais le champ homonyme de la clé URL) ; sinon aucune permission (le champ trie librement). Aucune permission, ou aucun *authorizer* branché → tri libre (*fail-open* — exactement la sémantique des `fields`).

## Les clés synthétiques `distance` et `score`

Deux clés de tri ne désignent pas un champ mais un **calcul**, et sont résolues **en amont** de la *whitelist* — elles trient donc même sans `SORTABLE` :

- **`distance`** — pilotée par `?near=` (voir ci-dessous). Sans ancrage `?near=`, `?sort=distance` est ignoré.
- **`score`** — la pertinence d'une recherche View (`?search=` sur une View déclarée). Voir [Recherche View](search/overview.md). Une recherche active seule trie par `score` décroissant par défaut (le plus pertinent d'abord).

## Tri par distance (`?near=`)

À part des trois leviers de filtrage, qui **restreignent**, `?near=` **ordonne** : il classe la liste du plus proche au plus loin d'un point géographique. Il ne filtre pas — combine-le avec un [filtre `geo`](filter.md#opérateur-distance-géolocalisation) pour borner un rayon.

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
# → SORT DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC
```

`?near=` fournit le **point d'ancrage** (attribut Schema.org `GeoCoordinates`, alias `lat`/`lng`/`lon` acceptés) et expose la **clé de tri synthétique `distance`** que `?sort=` pilote — `?sort=` reste l'**unique autorité de tri** :

| Requête | Tri |
|---|---|
| `?near=…` seul | `distance` ASC (défaut, plus proche d'abord) |
| `?near=…&sort=-distance` | plus loin d'abord |
| `?near=…&sort=distance,name` | distance puis nom (tu choisis la priorité) |
| `?near=…&sort=name` | nom seul — distance **non** ajoutée (le `?sort` explicite décide) |
| `?sort=distance` sans `?near=` | ignoré (pas d'ancrage) |

### La clé géo est une dimension de tri — donc whitelistée

**La situation.** Le `"key":"geo"` du payload nomme le champ géo depuis lequel la distance est mesurée. C'est un champ que le client désigne : il passe **le même videur** que n'importe quelle clé de tri.

La clé géo doit donc être **déclarée dans `AQL::SORTABLE`**, et peut être **soumise à un contrôle d'autorisation** comme le reste :

```php
// Tri par distance ouvert à tous :
AQL::SORTABLE => [ Prop::NAME , 'geo' ] ,

// Tri par distance réservé (position sensible) :
AQL::SORTABLE => [ Prop::NAME , 'geo' => [ Field::PATH => 'geo' , Field::REQUIRES => 'geo:read' ] ] ,
```

| Clé géo whitelistée (et autorisée) | Clé géo absente de `SORTABLE`, ou refusée |
|---|---|
| `?near={"key":"geo",…}` → tri par distance | clé **ignorée**, aucun tri par distance |

Combinaison typique — les 10 lieux **les plus proches**, musées, dans 5 km :

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
&filter=[{"key":"type","val":"museum"},{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"max":5000}]
&limit=10
```

`DISTANCE` opère sur deux scalaires → tri **index-accéléré** par un [`GeoIndex`](../clients/indexes.md) à deux champs. Les coordonnées ne sont bindées que si un critère `distance` est réellement émis (jamais de bind inutilisé). Voir les [fonctions géospatiales](../aql/aql-functions-geo.md).

## Limites & migration

- **`SORTABLE` absent = rien ne trie.** Un modèle qui comptait sur l'ancien « mode ouvert » (trier sans déclarer `SORTABLE`) doit désormais déclarer ses clés. Sinon `?sort=` et `SORT_DEFAULT` ne produisent plus rien.
- **`SORT_DEFAULT` doit nommer des clés whitelistées.** Le tri par défaut passe par le même videur que le client.
- **La clé géo de `?near=` doit être dans `SORTABLE`.** Un modèle exposant `?near=` déclare son champ géo (`'geo'`, ou une définition `Field::REQUIRES` pour le protéger). Sans ça, le tri par distance s'arrête.
- **Une clé client invalide est ignorée, jamais une exception.** Une clé de tri (ou une clé géo) hors *whitelist*, ou refusée par la permission, est simplement droppée — pas d'injection possible, pas de plantage. Une **déclaration** fautive est l'autre côté de cette frontière : une entrée relationnelle qui ne peut pas être honorée lève, parce que seul l'auteur du modèle peut la corriger.
- ⚠ **`Arango::SORT` accepte aussi un tableau, et cette forme saute les deux portiers.** Une chaîne est ce qu'un client envoie (`?sort=name`), et elle passe par la *whitelist* `SORTABLE` et par la garde de permission. Un **tableau** est recopié tel quel dans la clause `SORT` :

  ```php
  $model->list( [ Arango::SORT => 'salary' ] ) ;              // whitelist + permission
  $model->list( [ Arango::SORT => [ 'doc.salary DESC' ] ] ) ; // ni l'une ni l'autre
  ```

  Aucune URL ne peut produire un tableau — les paramètres de requête sont toujours des chaînes — donc c'est une **facilité côté serveur**, pas une faille exposée. Mais c'est le seul chemin où un critère de tri atteint la requête sans garde : ne jamais construire ce tableau à partir de données de requête, sinon les deux gardes tombent d'un coup. Pour une relation, utiliser plutôt la déclaration ci-dessus.

## Voir aussi

- [Recherche & filtrage](search-and-filtering.md) — les trois leviers qui **restreignent** la liste.
- [La projection des champs](../projection.md) — `Field::REQUIRES`, skins et le système de permission dont le tri hérite.
- [Filtres `?filter=`](filter.md) — dont l'[opérateur `distance`](filter.md#opérateur-distance-géolocalisation) qui **borne** un rayon.
- [Recherche View (ArangoSearch)](search/overview.md) — la clé de tri `score`.
- [Modèles `Documents`](../models.md) — pagination et cycle de vie de la requête de liste.
