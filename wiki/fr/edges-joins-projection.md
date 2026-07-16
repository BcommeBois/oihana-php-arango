# Projection des edges et joins AQL

Cette page décrit la projection des **relations** : suivre une arête (edge), résoudre une référence stockée (join), traverser une hiérarchie, envelopper le résultat sous une clé. Les mécanismes transverses de la projection — les skins (`Field::SKINS`, `AQL::SKIN_FIELDS`), les permissions (`AQL::REQUIRES`), les transformations (`Field::ALTERS`) — sont décrits dans [La projection des champs](projection.md) et s'appliquent ici à l'identique.

## Sommaire

1. [Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge](#projection-composée--aqlfields--aqledges-sur-la-définition-dedge)
2. [Traversée hiérarchique — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#traversée-hiérarchique--aqlmax_depth--aqlmin_depth)
3. [Projeter les propriétés de l'edge — `Field::SCOPE`](#projeter-les-propriétés-de-ledge--fieldscope)
4. [Envelopper la référence sous une clé — `Filter::WRAP`](#envelopper-la-référence-sous-une-clé--filterwrap)
5. [Projeter un *join* — `Filter::JOIN` / `Filter::JOINS`](#projeter-un-join--filterjoin--filterjoins)
6. [Jointure polymorphe — collection cible selon un champ discriminant](#jointure-polymorphe--collection-cible-selon-un-champ-discriminant)
7. [Couper un cycle INBOUND avec `AQL::SKIN`](#couper-un-cycle-inbound-avec-aqlskin)

## Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge

Quand une edge pointe vers un document complexe, on déclare sa projection en composant `AQL::FIELDS` et `AQL::EDGES` directement sur la définition d'edge dans `AQL::EDGES`. Le pattern est illustré par `employeeEdge.php` :

```php
// Exemple côté projet hôte (`Acme\functions\edges\employeeEdge`).
function employeeEdge(
    ?string $employeePath     = Paths::PEOPLE ,
    ?string $workLocationPath = Paths::LOCATIONS ,
) :array
{
    return
    [
        AQL::MODEL  => EdgesDefinition::CUSTOMER_HAS_EMPLOYEE ,
        AQL::SORT   => Prop::POSITION ,
        AQL::FIELDS => person
        ([
            Prop::ID            => Filter::DEFAULT ,
            Prop::ACTIVE        => Filter::DEFAULT ,
            Prop::ADDRESS       => Filter::DEFAULT ,
            Prop::FAMILY_NAME   => Filter::DEFAULT ,
            Prop::GIVEN_NAME    => Filter::DEFAULT ,
            Prop::WORK_LOCATION => Filter::EDGE ,    // sous-edge déclarée ci-dessous
        ] , $employeePath ) ,
        AQL::EDGES =>
        [
            Prop::WORK_LOCATION => workLocationEdge( $workLocationPath ) ,
        ] ,
    ] ;
}
```

Et côté DI consommateur :

```php
// customers.php
AQL::EDGES =>
[
    Prop::EMPLOYEE => employeeEdge() ,
    Prop::LOCATION => locationEdge() ,
]
```

Points importants :

- `AQL::FIELDS` sur la définition d'edge **est lu** par `buildEdgeVariable`. C'est la projection effective utilisée pour hydrater le document cible.
- `AQL::EDGES` sur la définition d'edge déclare les sous-edges référencées par les `Filter::EDGE` ou `Filter::EDGES` dans la projection.
- `Field::FIELDS` posé **inline au niveau du champ parent** est ignoré pour `Filter::EDGES` (il n'est respecté que pour `Filter::DOCUMENT` et `Filter::MAP`). C'est un piège classique : déclarer la projection au bon niveau (sur la définition d'edge, pas sur le champ parent).

## Traversée hiérarchique — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`

Par défaut, une projection `Filter::EDGES` suit la relation **sur un seul niveau** — les enfants (ou les parents) directs. Pour une relation **auto-référente** — un concept lié à d'autres concepts de la même collection, c'est-à-dire une hiérarchie (thésaurus, arbre de catégories, organigramme) — on peut suivre la relation sur **plusieurs niveaux en une seule traversée** en déclarant une profondeur sur la définition d'edge :

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Traversal ;

AQL::FIELDS =>
[
    Prop::DESCENDANTS => Filter::EDGES , // le champ projeté
],

AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,     // le modèle d'edge (auto-référent)
        AQL::DIRECTION => Traversal::OUTBOUND ,  // OUTBOUND = descendre vers les enfants
        AQL::MAX_DEPTH => 5 ,                    // suivre jusqu'à 5 niveaux
    ],
],
```

La sous-requête générée devient une traversée **bornée** :

```aql
LET descendants = ( FOR vertex, edge IN 1..5 OUTBOUND doc concept_links
    OPTIONS { "order": "bfs", "uniqueVertices": "global" }
    SORT edge.created DESC
    RETURN { … } )
```

### Sens — descendre ou remonter

La profondeur s'applique au sens déclaré dans `AQL::DIRECTION` :

- `Traversal::OUTBOUND` — descendre la hiérarchie (un nœud → ses descendants).
- `Traversal::INBOUND` — remonter la hiérarchie (un nœud → ses ancêtres, la chaîne jusqu'à la racine).

### Règles et valeurs par défaut

- **Aucune profondeur déclarée → inchangé.** Sans `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH`, la traversée reste à la profondeur 1 et l'AQL généré est **strictement identique** à avant — totalement rétro-compatible.
- **`AQL::MAX_DEPTH` seul** fixe la borne basse à `1` (`1..N`), la descente/remontée complète naturelle.
- **`AQL::MIN_DEPTH` seul est refusé.** ArangoDB exige une plage bornée, et une traversée non bornée sur une arête auto-référente risquerait une boucle infinie : une projection bornée **doit** déclarer `AQL::MAX_DEPTH`, sinon `buildEdgeVariable` lève une `UnexpectedValueException`.
- Le résultat est une **liste à plat** de tous les sommets rencontrés sur la plage de profondeur (pas un arbre imbriqué). Pour le retransformer en `children[]` imbriqué, on reconstruit l'arbre à partir de la liste à plat (cf. l'entrée de ROADMAP sur la reconstruction de hiérarchie).

> **Homogène uniquement.** Une profondeur suppose le **même** type à chaque niveau (une arête auto-référente). Pour une chaîne hétérogène où chaque niveau est d'un type différent (`Type1 → Type2 → Type3`), n'utilisez **pas** de profondeur — déclarez plutôt un niveau d'edge imbriqué par type (chacun avec son `AQL::MODEL` / `AQL::FIELDS`), comme montré dans *Projection composée* ci-dessus.

### Métadonnées de reconstruction — `AQL::WITH_PATH`

La traversée en profondeur renvoie une **liste à plat**. Pour la retransformer en arbre imbriqué, il faut connaître, pour chaque nœud, **qui est son parent**. Deux situations :

- **Le document stocke déjà son parent** (ex. un champ `broader` / `parentId`). Rien à faire — projetez ce champ et reconstruisez à partir de lui.
- **Le lien parent vit uniquement dans les arêtes** (le document ne le stocke pas). Activez `AQL::WITH_PATH => true` sur la définition d'edge : la traversée expose alors la variable `path` et injecte deux clés calculées dans chaque élément projeté :
  - `_parent` (`AQL::_PARENT`) — le `_key` du parent immédiat (le nœud d'un cran plus proche du sommet de départ), soit `path.vertices[-2]._key`.
  - `_depth` (`AQL::_DEPTH`) — la profondeur de traversée, soit `LENGTH(path.edges)`.

```php
AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,
        AQL::WITH_PATH => true , // injecte _parent / _depth
    ],
],
```

```aql
LET descendants = ( FOR vertex, edge, path IN 1..5 OUTBOUND doc concept_links OPTIONS { … }
    RETURN { _key: vertex._key, name: vertex.name,
             _parent: path.vertices[-2]._key, _depth: LENGTH(path.edges) } )
```

À noter :

- **Désactivé par défaut → inchangé.** Sans `AQL::WITH_PATH`, aucune variable `path` n'est émise et l'AQL est identique.
- **Projection du sommet entier.** Quand l'edge ne déclare pas de `AQL::FIELDS` (l'élément est le sommet nu), les métadonnées sont greffées via `MERGE(vertex, { _parent, _depth })`.
- **Projection scalaire.** Une projection `Arango::PROPERTY` renvoie un scalaire : aucun objet ne peut porter les métadonnées, donc `AQL::WITH_PATH` y est **ignoré** (et aucune variable `path` n'est émise).
- Un nœud à la profondeur 1 a un `_parent` égal à la clé du **sommet de départ** — la racine à partir de laquelle la liste à plat se reconstruit en arbre `children[]`.

### Reconstruire l'arbre — `buildTree()` / `buildTreeAlter()`

La liste à plat est transformée en arbre imbriqué `children[]` par `buildTree()` — un helper pur en O(n) (aucune requête supplémentaire). Il regroupe les nœuds par parent et descend depuis la racine :

```php
use function oihana\arango\models\helpers\buildTree ;

$tree = buildTree( $flat , rootKey: 'animals' ) ; // source du parent = '_parent' par défaut
```

`buildTree()` est **protégé contre les cycles** (un nœud déjà présent sur la branche courante n'est pas re-descendu) et prend en paramètres la source du parent, la clé des enfants et le champ d'identité — il fonctionne donc aussi bien depuis le `_parent` de `AQL::WITH_PATH` que depuis un champ parent stocké :

```php
$tree = buildTree( $flat , parentSource: 'broader' , rootKey: 'animals' ) ; // cas parent stocké
```

Pour que l'arbre soit livré **automatiquement** dans la réponse, câblez `buildTreeAlter()` en `Alter::MAP` sur le champ hiérarchique. L'altération s'exécute après la requête, lit la racine depuis le `_key` du document englobant et remplace la liste à plat par l'arbre imbriqué :

```php
use oihana\arango\models\enums\Alter ;
use function oihana\arango\models\helpers\buildTreeAlter ;

AQL::FIELDS =>
[
    Prop::DESCENDANTS =>
    [
        Field::FILTER => Filter::EDGES ,
        Field::ALTERS => [ [ Alter::MAP , buildTreeAlter() ] ] , // plat → children[]
    ],
],
AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,       // Lot A — descendre jusqu'à 5 niveaux
        AQL::WITH_PATH => true ,     // Lot B — injecte le _parent utilisé par buildTree
    ],
],
```

Le consommateur reçoit alors, sur chaque document, un champ `descendants` déjà imbriqué en `children[]` — une seule traversée plus un remodelage en mémoire, à n'importe quelle profondeur.

> **Un seul parent par nœud.** `buildTree()` attend que chaque nœud référence **un** parent. Avec `AQL::WITH_PATH`, c'est garanti par l'unicité globale des sommets de la traversée. Une polyhiérarchie où un concept a plusieurs parents (un `broader` sous forme de tableau) est hors du périmètre du remodelage en arbre — la liste à plat (avec `?filter=` / `quant`) reste la bonne surface pour ce cas.

## Projeter les propriétés de l'edge — `Field::SCOPE`

Par défaut, les champs déclarés dans le `AQL::FIELDS` d'une définition d'edge sont projetés depuis le **vecteur cible** du traversal (l'autre bout de la relation). Mais un edge n'est pas qu'un connecteur : il porte souvent sa propre métadonnée (`created`, `weight`, `role`, `order`, …). Le marqueur `Field::SCOPE` permet de remonter ces propriétés **dans le même objet**, à côté des champs du vecteur.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use oihana\arango\enums\Scope ;

AQL::EDGES =>
[
    Prop::FRIENDS =>
    [
        AQL::MODEL  => EdgesDefinition::PERSON_HAS_FRIEND ,
        AQL::FIELDS =>
        [
            Prop::NAME => Filter::DEFAULT ,                                  // depuis le vecteur cible
            'since'    => [ Field::FILTER => Filter::DATETIME ,
                            Field::NAME  => 'created' ,
                            Field::SCOPE => Scope::EDGE ] ,                  // depuis l'edge
            'weight'   => [ Field::FILTER => Filter::NUMBER ,
                            Field::SCOPE => Scope::EDGE ] ,                  // depuis l'edge
        ] ,
    ] ,
]
```

AQL généré (le `RETURN` interne lit `v` **et** `e`) :

```aql
LET friends = (
  FOR v, e IN OUTBOUND doc person_has_friend
  SORT e.created DESC
  RETURN { name: v.name, since: ... e.created ..., weight: TO_NUMBER(e.weight) }
)
```

Règles et points importants :

- **Valeur du scope.** `Scope::VERTEX` (défaut) lit depuis le vecteur, `Scope::EDGE` lit depuis l'edge. Les constantes valent exactement `AQL::VERTEX` / `AQL::EDGE`, donc `Field::SCOPE => AQL::EDGE` est strictement équivalent et évite un `use` supplémentaire si `AQL` est déjà importé.
- **Absence = vecteur.** Un champ sans `Field::SCOPE` se comporte comme avant — la fonctionnalité est 100 % rétro-compatible.
- **Collision de noms.** Les deux sources peuvent porter le même attribut (`name` sur le vecteur ET sur l'edge). Comme la **clé du champ = le label de sortie**, il suffit de donner un label distinct au champ edge et d'aliaser sa source avec `Field::NAME` : `'edgeName' => [ Field::NAME => 'name' , Field::SCOPE => Scope::EDGE ]`.
- **Ordre.** La projection conserve l'ordre de déclaration des champs dans `AQL::FIELDS` — vecteur et edge peuvent être entrelacés librement.
- **Garde-fou — hors traversal.** `Field::SCOPE => edge` n'a de sens qu'à l'intérieur d'une sous-requête d'edge. Posé à la racine, sur un *join* ou dans un sous-document imbriqué (où l'edge n'existe plus), il **lève une exception** (`UnsupportedOperationException`) plutôt que de retomber silencieusement sur le vecteur.
- **Garde-fou — filtres structurels.** `Field::SCOPE => edge` sur un filtre structurel (`Filter::EDGE`, `Filter::EDGES`, `Filter::JOIN`, `Filter::JOINS`, `Filter::EDGES_COUNT`, …) n'aurait aucun effet (ces filtres sont pilotés par une variable précalculée, pas par le document de référence) : il **lève une exception** au lieu d'être ignoré.

## Envelopper la référence sous une clé — `Filter::WRAP`

`Field::SCOPE` remonte une **métadonnée scalaire** de l'edge à côté des champs du vecteur (projection à plat). Son pendant symétrique, `Filter::WRAP`, fait l'inverse pour un **objet** : il **enveloppe la référence courante entière sous une clé nommée**, au lieu d'aplatir ses champs à la racine.

Le cas typique : une traversée d'edge retourne par défaut le vecteur cible *à plat*. Quand le modèle de sortie attend l'entité liée **rangée dans une sous-clé** (par exemple `subject`), à côté de la métadonnée d'edge (`role`), `Filter::WRAP` produit cette forme imbriquée — impossible à obtenir avec la projection à plat.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use oihana\arango\enums\Scope ;

AQL::EDGES =>
[
    'memberships' =>
    [
        AQL::MODEL  => EdgesDefinition::PERSON_HAS_TEAM ,
        AQL::FIELDS =>
        [
            'role'    => [ Field::SCOPE => Scope::EDGE ] ,                   // scalaire, depuis l'edge
            'subject' =>                                                     // objet, enveloppe le vecteur
            [
                Field::FILTER => Filter::WRAP ,
                Field::FIELDS =>
                [
                    'id'   => Filter::DEFAULT ,
                    'name' => Filter::DEFAULT ,
                ] ,
            ] ,
        ] ,
    ] ,
]
```

AQL généré (le vecteur est rangé sous `subject`, l'edge reste à plat) :

```aql
LET memberships = (
  FOR v, e IN OUTBOUND doc person_has_team
  RETURN { role: e.role, subject: { id: v.id, name: v.name } }
)
```

Règles et points importants :

- **Liste de champs requise par défaut.** `Field::FIELDS` projette les sous-champs **contre la référence elle-même** (`v.id`), et non contre un sous-attribut (`v.subject.id`) — c'est la différence clé avec `Filter::DOCUMENT`, qui plonge dans `ref.clé`. Sans `Field::FIELDS`, la projection **lève une exception** (`UnsupportedOperationException`) : envelopper l'objet entier doit être délibéré.
- **Objet entier — opt-in `Field::RAW`.** Pour embarquer la référence telle quelle, sans liste de champs, déclarer `Field::RAW => true` : la sortie devient `subject: v` (tous les attributs du vecteur, sans projection). C'est le seul moyen d'omettre `Field::FIELDS`.
- **Vecteur par défaut, edge possible.** Comme tout champ, `Field::SCOPE => Scope::EDGE` bascule la référence enveloppée vers l'edge — on enveloppe alors **l'edge entier** sous la clé (utile pour exposer le lien lui-même comme objet).
- **Différence avec `Filter::DOCUMENT`.** `Filter::DOCUMENT` imbrique un **sous-attribut existant** (`address: { city: v.address.city }`). `Filter::WRAP` enveloppe **la référence elle-même** sous une clé neuve (`subject: { … v … }`).
- **Compagnon de `Field::SCOPE`.** `Field::SCOPE` remonte des **scalaires** d'edge à plat ; `Filter::WRAP` range un **objet** (vecteur ou edge) sous une clé. Les deux se combinent librement dans le même `AQL::FIELDS`.

### Porter les relations du vecteur enveloppé — `Field::EDGES`

Un vecteur enveloppé peut aussi porter **ses propres relations**, imbriquées **sous la même clé**. Le cas typique : une liste de liens projetée sous la forme `[{ subject: <vecteur> }]`, où le `subject` est lui-même lié à une **3ᵉ entité** par un autre edge (souvent traversé en `INBOUND`). On veut cette entité **rangée dans le `subject`** (`subject.worksFor`), **en une seule requête** — ni aplatie au niveau de l'entrée, ni via un second aller-retour.

La déclaration reprend **exactement la grammaire du niveau racine** : le **marqueur de cardinalité** (`Filter::EDGE` unique / `Filter::EDGES` liste / `Filter::EDGES_COUNT` comptage) dans `Field::FIELDS`, et la **définition de la sous-traversée** dans `Field::EDGES`, sous la même clé. La sous-traversée part **du vecteur enveloppé** (et non du document racine).

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Traversal ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;

// account --[account_has_identity]--> person   (le lien projeté)
// person  <--[org_has_member]-- organization   (l'organisation de la personne, INBOUND)
AQL::EDGES =>
[
    'identities' =>
    [
        AQL::MODEL  => EdgesDefinition::ACCOUNT_HAS_IDENTITY ,
        AQL::FIELDS =>
        [
            'subject' =>
            [
                Field::FILTER => Filter::WRAP ,
                Field::FIELDS =>
                [
                    'id'       => Filter::DEFAULT ,
                    'name'     => Filter::DEFAULT ,
                    'worksFor' => [ Field::FILTER => Filter::EDGE ] ,           // ← marqueur, comme au niveau racine
                ] ,
                Field::EDGES =>                                                // ← définition de la sous-traversée
                [
                    'worksFor' =>
                    [
                        AQL::MODEL     => EdgesDefinition::ORG_HAS_MEMBER ,
                        AQL::DIRECTION => Traversal::INBOUND ,
                        AQL::FIELDS    => [ 'id' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
                    ] ,
                ] ,
            ] ,
        ] ,
    ] ,
]
```

AQL généré (la sous-traversée part de `v`, son `LET` est émis dans le `FOR v`, le résultat est imbriqué dans `subject`) :

```aql
LET identities = (
  FOR v, e IN OUTBOUND doc account_has_identity
    LET worksFor = ( FOR v2, e2 IN INBOUND v org_has_member RETURN { id: v2.id, name: v2.name } )
    RETURN {
      subject: {
        id: v.id, name: v.name,
        worksFor: ( IS_OBJECT(worksFor) ? worksFor : IS_ARRAY(worksFor) ? FIRST(worksFor) : null )
      }
    }
)
```

Règles et points importants :

- **Tout fonctionne comme au niveau racine.** `Filter::EDGE` (objet unique), `Filter::EDGES` (liste) et `Filter::EDGES_COUNT` (comptage) s'utilisent à l'identique ; le gating par permission (`Field::REQUIRES`), les tris et les sous-projections s'appliquent verbatim.
- **Les deux directions.** `AQL::DIRECTION => Traversal::INBOUND` (ou `OUTBOUND`, défaut) — l'entité liée est souvent atteinte en `INBOUND`.
- **Profondeur naturelle.** La sous-traversée est un edge ordinaire : elle porte elle-même ses propres `AQL::EDGES` / `AQL::JOINS`, donc l'entité liée peut projeter plus loin (`subject.worksFor.locatedIn`). Chaque niveau ajoute une sous-requête `FOR` : c'est une question de **performance**, pas de limite dure — garder l'imbrication peu profonde (2–3 niveaux).
- **`Field::RAW` exclut `Field::EDGES`.** Une référence brute (`subject: v`) n'a pas d'objet projeté où greffer une relation — les combiner **lève une exception**.
- **Marqueur et définition vont de pair.** Comme au niveau racine, les deux sont nécessaires : une définition dans `Field::EDGES` sans marqueur dans `Field::FIELDS` est simplement **inutilisée** (rien n'est projeté) ; à l'inverse, un marqueur **sans** définition projette une **référence `LET` fantôme** → erreur AQL à l'exécution. Toujours déclarer les deux.
- **Jointures aussi.** La même mécanique vaut pour les **joins** : un marqueur `Filter::JOIN` / `Filter::JOINS` dans `Field::FIELDS` et une définition dans un `Field::JOINS` compagnon — le join résout alors une référence stockée **sur le vecteur enveloppé** (`vertex.role`). `Field::EDGES` et `Field::JOINS` se combinent librement sous une même clé.
- **Rétro-compatible.** Un `Filter::WRAP` sans `Field::EDGES` ni `Field::JOINS` se comporte exactement comme avant.

## Projeter un *join* — `Filter::JOIN` / `Filter::JOINS`

Là où un *edge* traverse une collection d'arêtes, un **join** résout une **référence stockée dans le document lui-même** vers les documents d'une autre collection. Le **type du champ** choisit la cardinalité, exactement comme `Filter::EDGE` (unique) vs `Filter::EDGES` (multiple) :

- **`Filter::JOIN`** — le champ contient **un** identifiant → projette **le** document joint.
- **`Filter::JOINS`** — le champ contient un **tableau d'identifiants** → projette **la liste** des documents joints.

La projection se déclare en deux temps : le **type** du champ dans `AQL::FIELDS`, et la **définition** du join (collection cible, projection, tri) dans `AQL::JOINS`, sous la même clé.

```php
AQL::FIELDS =>
[
    Prop::_KEY => Filter::DEFAULT ,
    'tracks'   => Filter::JOINS ,        // tableau d'ids → documents joints
],
AQL::JOINS =>
[
    'tracks' =>
    [
        AQL::MODEL   => Models::TRACK ,                                            // modèle Documents cible (DI)
        AQL::FIELDS  => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] , // projection des docs joints
        Arango::SORT => 'name' ,                                                   // tri DANS la jointure
    ],
],
```

`GET /playlists/{id}` renvoie alors `tracks` non plus comme un tableau d'ids, mais comme la **liste des documents** correspondants. L'AQL généré (simplifié) :

```aql
LET tracks = (
    FOR doc_join IN @@track
        FILTER doc_join._key IN ( IS_ARRAY( doc.tracks ) ? doc.tracks : [] )
        SORT doc_join.name ASC
        RETURN { _key: doc_join._key, name: doc_join.name }
)
```

> **Le tri d'un tableau joint se fait DANS la jointure** (`Arango::SORT` sur la définition du join), pas via le `?sort=` externe — qui, lui, trie les **documents parents**, jamais le contenu d'un champ joint. C'est la bonne séparation.

Options utiles sur la définition de join : `Arango::KEY` (attribut de jointure, défaut `_key`), `Arango::PROPERTY` (pointer une propriété imbriquée du parent comme clé), `Arango::CONDITIONS` (filtres supplémentaires), `AQL::FIELDS` / `AQL::EDGES` / `AQL::JOINS` imbriqués, `AQL::SKIN` / `AQL::SKIN_FIELDS` (la projection jointe varie avec `?skin=`), `AQL::REQUIRES` ([gating par permission](projection.md#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)).

> Combinaison naturelle avec les [champs-tableaux embarqués](db/arrays.md) : un champ `tracks` (tableau d'ids muté élément par élément via `ArrayPropertyController`) peut **en même temps** être projeté en documents joints triés dans le `GET` via `Filter::JOINS` — aucune duplication.

## Jointure polymorphe — collection cible selon un champ discriminant

Un join ordinaire vise **une** collection figée (`AQL::MODEL`). Une **jointure polymorphe** choisit sa collection cible **à l'exécution**, d'après la valeur d'un champ du document parent lui-même. Le cas typique : un `PricingConditionSelector` qui porte un `areaScope` (le *type* de zone) et un `areaServed` (la *clé*), et doit résoudre la fiche dans `warehouses` si le scope est `#Warehouse`, dans `subsidiaries` si le scope est `#Company`.

```json
"selector": {
    "areaScope":  "https://schema.oihana.xyz/PricingAreaScope#Warehouse",
    "areaServed": "w1"
}
```

La définition remplace `AQL::MODEL` par trois clés :

- **`Arango::DISCRIMINATOR`** — le champ du parent qui décide (chemin scalaire, ex. `selector.areaScope`).
- **`Arango::MAP`** — la table `type => définition de join`, une branche par valeur ; chaque branche est **une définition de join classique** (avec son `AQL::MODEL`, sa projection, son tri…).
- **`Arango::FALLBACK`** — (optionnel) la branche utilisée quand la valeur ne correspond à **aucun** type déclaré ; `null` = aucune.

Le champ reste déclaré `Filter::JOIN` (fiche unique) ou `Filter::JOINS` (liste) dans `AQL::FIELDS` — **aucun nouveau marqueur** : c'est la présence de `Arango::MAP` + `Arango::DISCRIMINATOR` dans la définition qui bascule en mode polymorphe.

```php
AQL::FIELDS =>
[
    'area' => Filter::JOIN , // fiche unique (JOINS pour une liste)
],
AQL::JOINS =>
[
    'area' =>
    [
        Arango::DISCRIMINATOR => 'selector.areaScope' ,   // le champ du parent qui décide
        Arango::PROPERTY      => 'selector.areaServed' ,  // la clé du parent (partagée par les branches)
        Arango::MAP           =>
        [
            'https://schema.oihana.xyz/PricingAreaScope#Warehouse' =>
            [
                AQL::MODEL  => Models::WAREHOUSE ,
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
            'https://schema.oihana.xyz/PricingAreaScope#Company' =>
            [
                AQL::MODEL  => Models::SUBSIDIARY ,
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
        ] ,
        Arango::FALLBACK => null , // type inconnu → null (JOIN) / [] (JOINS)
    ],
],
```

AQL interdit une collection calculée dans un `FOR … IN …`, la jointure est compilée comme un **`APPEND` de branches statiques gardées** : une sous-requête de join par branche, chacune gardée par une égalité sur le discriminateur, de sorte qu'une seule branche renvoie des lignes. L'AQL généré (simplifié) :

```aql
LET area = APPEND(
    ( FOR doc_join IN @@warehouse
        FILTER doc_join._key == doc.selector.areaServed
           && doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Warehouse"
        RETURN { _key: doc_join._key, name: doc_join.name } ) ,
    ( FOR doc_join IN @@subsidiary
        FILTER doc_join._key == doc.selector.areaServed
           && doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Company"
        RETURN { _key: doc_join._key, name: doc_join.name } )
)
```

> **Le `LET` contient un tableau, comme n'importe quel join.** Une seule branche est non vide (les gardes sont exclusives) ; la projection déplie ensuite ce tableau **exactement comme un join ordinaire** — `FIRST()` pour `Filter::JOIN`, le tableau entier pour `Filter::JOINS`. Rien à changer côté projection.

> **Chaque branche est verrouillée séparément.** Un `Field::REQUIRES` / `AQL::REQUIRES` posé sur une branche la fait **disparaître de l'`APPEND`** si la permission est refusée — sa collection n'est jamais interrogée, donc ni une valeur ni un simple bit d'existence du type caché ne fuite (fail-closed). Ce verrou **se compose** (ET logique) avec les gardes de champ / de définition qui protègent le join entier.

> **Le repli ne récupère jamais un type refusé.** La branche `Arango::FALLBACK` est gardée par `NOT IN [ …tous les types déclarés… ]` — y compris les types dont la branche a été refusée. Un document d'un type refusé route donc vers **rien**, jamais vers le repli : pas d'oracle. Quand toutes les branches sont écartées, le `LET` vaut `[]` (projection → `null` / `[]`), jamais une clause cassée.

Options utiles : la clé du parent (`Arango::PROPERTY`, défaut le nom du champ) et l'attribut de jointure (`Arango::KEY`, défaut `_key`) déclarés au niveau du haut sont **partagés** comme défauts par les branches ; une branche peut surcharger sa propre clé. Chaque branche accepte aussi tout le vocabulaire d'un join classique (`Arango::CONDITIONS`, `Arango::SORT`, sous-`AQL::EDGES` / `AQL::JOINS`, `AQL::SKIN`).

## Couper un cycle INBOUND avec `AQL::SKIN`

Les edges INBOUND vers un document qui pointe en retour vers la source créent un cycle d'hydration potentiellement infini. Exemple : sur un `Policy`, on veut exposer en INBOUND la liste des `Service` qui le référencent. Mais un `Service` a des `Policy` en OUTBOUND, et chaque `Policy` reproject ses `Service`, et ainsi de suite.

La parade est `AQL::SKIN => Skin::MAIN` sur la définition d'edge. Le mode `Skin::MAIN` filtre la projection cible pour ne garder que les champs sans marqueur `Field::SKINS` — donc les sous-edges (toutes derrière `Skin::FULL` ou `Skin::DEFAULT`) sont absents et le cycle s'arrête.

```php
// policies.php — exposition reverse des services
AQL::EDGES =>
[
    Prop::SERVICES_COUNT => Prop::SERVICES ,
    Prop::SERVICES       =>
    [
        AQL::MODEL     => EdgesDefinition::SERVICE_HAS_POLICIES ,
        AQL::DIRECTION => Traversal::INBOUND ,
        AQL::SKIN      => Skin::MAIN ,             // coupe le cycle
    ] ,
]
```

Sans `AQL::SKIN => Skin::MAIN`, Xdebug coupe la requête avec une erreur 500 « infinite loop, aborted your script with a stack depth of '512' frames » sur **toutes les routes** (le conteneur DI compile les modèles `Documents` au démarrage de chaque requête Slim). Le symptôme est trompeur : ce n'est pas la route qui boucle, c'est la définition.

