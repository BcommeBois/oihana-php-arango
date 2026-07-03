# Projection des edges et joins AQL

## Sommaire

1. [Vue d'ensemble](#vue-densemble)
2. [Le marqueur `Field::SKINS` au niveau document](#le-marqueur-fieldskins-au-niveau-document)
3. [Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge](#projection-composée--aqlfields--aqledges-sur-la-définition-dedge)
4. [Traversée hiérarchique — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#traversée-hiérarchique--aqlmax_depth--aqlmin_depth)
5. [Projeter les propriétés de l'edge — `Field::SCOPE`](#projeter-les-propriétés-de-ledge--fieldscope)
6. [Envelopper la référence sous une clé — `Filter::WRAP`](#envelopper-la-référence-sous-une-clé--filterwrap)
7. [Projeter un *join* — `Filter::JOIN` / `Filter::JOINS`](#projeter-un-join--filterjoin--filterjoins)
8. [Couper un cycle INBOUND avec `AQL::SKIN`](#couper-un-cycle-inbound-avec-aqlskin)
9. [Projection variable selon le skin de la requête — `Field::SKINS` sur les sous-champs](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs)
10. [Projection alternative selon le skin — `AQL::SKIN_FIELDS`](#projection-alternative-selon-le-skin--aqlskin_fields)
11. [Quel mécanisme choisir ?](#quel-mécanisme-choisir-)
12. [Restreindre la projection à une permission — `AQL::REQUIRES`](#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)
13. [Transformer la valeur projetée — `Field::ALTERS`](#transformer-la-valeur-projetée--fieldalters)
14. [Référence interne — la fonction `matchesSkin`](#référence-interne--la-fonction-matchesskin)

## Vue d'ensemble

La couche de projection AQL décide, pour chaque requête HTTP, quels champs et quelles relations (edges, joins) inclure dans la réponse. La décision repose sur trois éléments :

- le **skin de la requête** : passé via `?skin=full`, `?skin=default`, ou injecté par le contrôleur via `SKIN_METHODS` (par défaut `default` pour une liste, `full` pour un GET unique) ;
- les **marqueurs `Field::SKINS`** sur les champs : déclarent les skins qui activent ce champ ;
- la **définition d'edge ou de join** dans `AQL::EDGES` / `AQL::JOINS` : déclare la projection des relations associées.

Le flux interne est résumé ainsi :

```
controller → model->get/list( SKIN ) → returnFields( $init )
   → prepareQueryFields( fields , skin )
      → filterFieldsBySkin( fields , skin )   ← matchesSkin sur Field::SKINS
   → buildVariables( fields , edges , joins )
      → buildEdgeVariable( definition )       ← projection des edges
      → buildJoinVariable( definition )       ← projection des joins
```

Le développeur n'écrit jamais d'appels à `matchesSkin` ou aux builders directement. Il décrit ses intentions via `Field::SKINS`, `AQL::FIELDS`, `AQL::EDGES`, `AQL::SKIN`, `AQL::SKIN_FIELDS` dans les définitions du conteneur.

## Le marqueur `Field::SKINS` au niveau document

Sur un champ d'un modèle `Documents`, `Field::SKINS` déclare la liste des skins qui activent le champ.

```php
AQL::FIELDS =>
[
    Prop::_KEY        => Filter::DEFAULT ,
    Prop::EMAIL       => Filter::DEFAULT ,
    Prop::ROLES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
    Prop::ROLES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::DEFAULT , Skin::FULL ] ] ,
    Prop::PERMISSIONS => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL ] ] ,
]
```

Avec cette configuration :

- `GET /users` (skin par défaut `default`) renvoie `_key`, `email`, `rolesCount` et `roles[]`.
- `GET /users/{id}` (skin par défaut `full`) renvoie `_key`, `email`, `roles[]` et `permissions[]` (le count n'apparaît plus).

Un champ sans `Field::SKINS` est toujours visible.

Le marqueur accepte trois formes :

```php
Field::SKINS => [ Skin::FULL , Skin::DEFAULT ]   // tableau de skins
Field::SKINS => 'main,full'                       // chaîne séparée par virgules
Field::SKINS => null                              // équivalent à pas de marqueur
```

Les skins sont des chaînes de caractères opaques. Tout skin défini dans `Acme\enums\Skin` (qui étend le trait `oihana\controllers\enums\traits\SkinTrait`) peut être utilisé librement, y compris les skins métier comme `Skin::IMAGE`, `Skin::OFFERS`, `Skin::EMPLOYEE`.

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

Options utiles sur la définition de join : `Arango::KEY` (attribut de jointure, défaut `_key`), `Arango::PROPERTY` (pointer une propriété imbriquée du parent comme clé), `Arango::CONDITIONS` (filtres supplémentaires), `AQL::FIELDS` / `AQL::EDGES` / `AQL::JOINS` imbriqués, `AQL::SKIN` / `AQL::SKIN_FIELDS` (la projection jointe varie avec `?skin=`), `AQL::REQUIRES` ([gating par permission](#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)).

> Combinaison naturelle avec les [champs-tableaux embarqués](db/arrays.md) : un champ `tracks` (tableau d'ids muté élément par élément via `ArrayPropertyController`) peut **en même temps** être projeté en documents joints triés dans le `GET` via `Filter::JOINS` — aucune duplication.

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

## Projection variable selon le skin de la requête — `Field::SKINS` sur les sous-champs

Quand la projection d'une edge varie peu entre skins, le moyen le plus léger est de poser des `Field::SKINS` sur les sous-champs de la projection. Le skin de la requête est propagé automatiquement au target via `$init` (héritage du skin parent) ou peut être pinné explicitement via `AQL::SKIN`.

Exemple : sur `/users`, on veut des rôles plats en liste et des rôles riches sur la fiche unique. Sans dupliquer la définition :

```php
// users.php
Prop::ROLES =>
[
    AQL::MODEL  => EdgesDefinition::USER_HAS_ROLES ,
    AQL::FIELDS =>
    [
        // Champs plats — visibles dans tous les skins (pas de marqueur)
        Prop::_KEY                        => Filter::DEFAULT ,
        Prop::NAME                        => Filter::DEFAULT ,
        Prop::IDENTIFIER                  => Filter::DEFAULT ,

        // Comptes visibles seulement en liste, relations hydratées seulement sur la fiche
        Prop::PERMISSIONS_COUNT           => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::PERMISSIONS                 => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::APPLICATION_TEMPLATES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
    ] ,
    AQL::EDGES =>
    [
        Prop::PERMISSIONS_COUNT           => Prop::PERMISSIONS ,
        Prop::PERMISSIONS                 => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => Prop::APPLICATION_TEMPLATES ,
        Prop::APPLICATION_TEMPLATES       => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_APPLICATION_TEMPLATES ] ,
    ] ,
]
```

Résultats :

- `GET /users` (skin `default`) : chaque rôle expose ses champs plats, plus `permissionsCount` ;
- `GET /users/{id}?skin=full` ou `GET /me` : chaque rôle expose en plus `permissions[]` hydratés.

La même définition couvre les deux cas. Pour les sous-endpoints dédiés (`/users/{id}/roles`, `/users/{id}/permissions/effective`) qui ont leur propre DI, la projection est indépendante et reste riche.

### `Field::SKINS` en profondeur — sous-champs imbriqués (`Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`)

Le marqueur `Field::SKINS` est honoré à **tous les niveaux d'imbrication** d'une projection : sur les sous-champs d'un `Filter::MAP`, d'un `Filter::DOCUMENT` ou d'un `Filter::WRAP` — y compris un MAP dans un MAP. Le skin de la requête est propagé aux `Field::FIELDS` imbriqués, avec les mêmes règles qu'au premier niveau :

- un sous-champ **sans** marqueur est visible dans tous les skins ;
- sans skin demandé, tout passe ;
- un sous-champ retiré disparaît **complètement** : sa clé n'apparaît pas dans la réponse et, s'il porte un marqueur de relation (avec son entrée `Field::EDGES` / `Field::JOINS` au même niveau), le `LET` correspondant n'est pas émis.

Exemple : un produit stocke une grille de prix `offers[]`, dont chaque entrée contient un sous-tableau `offers[]` (un prix par type de client). Chaque prix porte une décomposition sensible `priceSpecification` qu'on ne veut exposer que dans des skins dédiés — en une seule déclaration du champ :

```php
'offers' =>
[
    Field::FILTER => Filter::MAP ,
    Field::FIELDS =>
    [
        'offers' =>
        [
            Field::FILTER => Filter::MAP ,
            Field::FIELDS =>
            [
                'price'              => Filter::DEFAULT ,
                'priceSpecification' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'offers.full' , 'full' ] ] ,
            ] ,
        ] ,
    ] ,
]
```

Résultats :

- `GET /products/{id}` (skin `default`) : la grille sort avec `price`, sans `priceSpecification` ;
- `GET /products/{id}?skin=full` : la décomposition `priceSpecification` apparaît à chaque prix.

**Parent vidé = parent retiré.** Si le skin retire **tous** les sous-champs déclarés d'un parent MAP / DOCUMENT / WRAP, le parent lui-même disparaît de la projection (clé absente) — jamais de repli sur le sous-document brut, jamais d'objet vide, jamais d'erreur. C'est la sémantique naturelle des skins : un champ hors skin n'apparaît pas.

**Cohabitation avec `Field::REQUIRES`.** Les deux marqueurs se cumulent sur un même sous-champ : `Field::SKINS` décide de la **vue** (le skin demandé), `Field::REQUIRES` de la **sécurité** (la permission). Le sous-champ n'apparaît que si le skin matche **et** que la permission est accordée.

## Projection alternative selon le skin — `AQL::SKIN_FIELDS`

Quand la projection diffère largement entre skins, et que poser des `Field::SKINS` partout devient illisible, on peut déclarer plusieurs projections distinctes via `AQL::SKIN_FIELDS` : une table `skin => projection`, où chaque projection est un tableau de champs de la **même forme que `AQL::FIELDS`**. Au moment de construire la sous-requête, le framework choisit le bucket correspondant au skin de la requête.

```php
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL       => EdgesDefinition::USER_HAS_ROLES ,
        AQL::SKIN_FIELDS =>
        [
            // Version plate (skin `default`, la liste) : champs scalaires seulement
            Skin::DEFAULT =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,

            // Version riche (skin `full`, la fiche) : mêmes champs + relation hydratée
            Skin::FULL =>
            [
                Prop::_KEY        => Filter::DEFAULT ,
                Prop::NAME        => Filter::DEFAULT ,
                Prop::PERMISSIONS => Filter::EDGES ,
            ] ,

            // Optionnel : bucket fallback pour tout autre skin
            '*' =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,
        ] ,
        AQL::EDGES =>
        [
            Prop::PERMISSIONS => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        ] ,
    ] ,
]
```

Chaque bucket est une projection **complète et autonome** : le bucket choisi remplace entièrement les autres, il n'y a **pas de fusion** entre buckets (d'où la répétition de `_key`/`name` — voir la factorisation ci-dessous). Le marqueur `Filter::EDGES` du bucket `full` s'appuie sur l'entrée `AQL::EDGES` de la définition, exactement comme dans une projection `AQL::FIELDS` classique ; le bucket `default` ne portant pas le marqueur, la sous-traversée n'est pas émise pour ce skin.

Ordre de résolution interne :

1. `AQL::SKIN_FIELDS[$skin]` — projection dédiée au skin courant ;
2. `AQL::SKIN_FIELDS['*']` — entrée fallback de la table ;
3. `AQL::FIELDS` — ancienne projection unique (rétro-compatibilité) ;
4. `null` — aucune projection déclarée.

Si `AQL::SKIN_FIELDS` est absent ou n'est pas un tableau, la résolution retombe directement sur `AQL::FIELDS`, ce qui garantit la rétro-compatibilité avec les définitions antérieures.

`AQL::SKIN_FIELDS` est aussi reconnu par `buildJoinVariable`, le mécanisme est strictement le même pour les joins.

### Factoriser les buckets avec une fonction de projection

Les buckets partagent souvent une base commune ; l'écrire dans chaque bucket est fastidieux et source de dérive (un champ ajouté dans un bucket et oublié dans l'autre). Le pattern usuel est une **fonction de projection** côté projet hôte : un simple helper qui retourne la base et y fusionne des extras.

```php
/**
 * Projection de base d'un rôle ; $extra ajoute (ou remplace) des champs par bucket.
 */
function role( array $extra = [] ) :array
{
    return
    [
        Prop::_KEY => Filter::DEFAULT ,
        Prop::NAME => Filter::DEFAULT ,
        ...$extra ,
    ] ;
}
```

La table de l'exemple précédent devient compacte :

```php
AQL::SKIN_FIELDS =>
[
    Skin::DEFAULT => role() ,                                       // version plate : la base seule
    Skin::FULL    => role([ Prop::PERMISSIONS => Filter::EDGES ]) , // base + relation hydratée
    '*'           => role() ,                                       // optionnel : fallback
] ,
```

Ce helper appartient au **projet hôte** — il n'existe pas dans la lib, c'est une convention de configuration, pas une API. Il vaut le coup dès que plusieurs buckets (ou plusieurs définitions d'edges/joins visant le même modèle) partagent la même base de champs.

### Portée de `AQL::SKIN_FIELDS`

Deux limites à connaître :

- `AQL::SKIN_FIELDS` est une **clé de définition d'edge ou de join** — c'est le seul endroit où elle est lue (elle y est ré-évaluée à chaque niveau d'imbrication des relations, le skin de la requête étant propagé). Posée sur un champ du modèle (racine `AQL::FIELDS`) ou sur un sous-champ d'un `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`, elle est **ignorée en silence**. Pour faire varier un champ ou un sous-champ avec le skin, le mécanisme est [`Field::SKINS`](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs), honoré à toute profondeur.
- Un skin pinné via `AQL::SKIN` ne vaut que pour la définition qui le porte : ses sous-relations (`AQL::EDGES` / `AQL::JOINS` imbriqués) retombent sur le skin de la requête, sauf pin explicite sur leur propre définition.

## Quel mécanisme choisir ?

| Besoin | Solution recommandée |
|---|---|
| Une seule projection, peu importe le skin | `AQL::FIELDS` seul |
| Quelques sous-champs varient entre skins (count caché en full, edge caché en default…) | `Field::SKINS` posé sur les sous-champs de `AQL::FIELDS` |
| Un sous-champ **imbriqué** (grille de prix, sous-objet d'un MAP/DOCUMENT/WRAP) ne doit sortir que dans certains skins | `Field::SKINS` posé sur le sous-champ imbriqué — honoré à toute profondeur |
| La projection diffère largement entre skins (champs ajoutés, joins changés…) | `AQL::SKIN_FIELDS` avec une entrée par skin |
| Edge INBOUND vers un document qui peut référencer en retour la source | `AQL::SKIN => Skin::MAIN` sur la définition d'edge pour couper le cycle |
| Restreindre la projection d'un edge ou d'un join à une permission utilisateur | `AQL::REQUIRES` sur la définition + injection du callable via `InjectAuthorizerTrait` |

Les mécanismes se cumulent. Une définition peut combiner `AQL::SKIN_FIELDS` pour la projection principale, des `Field::SKINS` sur les sous-champs des projections individuelles, et un `AQL::SKIN` pour pinner le skin du target. La résolution est indépendante à chaque niveau.

## Restreindre la projection d'un edge ou d'un join à une permission — `AQL::REQUIRES`

Une relation peut être soumise à permission à **deux niveaux**, qui se cumulent :

- `Field::REQUIRES` sur **le champ** d'une projection : verrouille *cette projection-là* de la relation ;
- `AQL::REQUIRES` sur **la définition** de l'edge ou du join : verrouille la relation *partout où la définition est utilisée* — posé une fois, appliqué partout.

Dans les deux cas, une relation refusée est silencieusement omise : aucun `LET` généré, la clé n'apparaît pas dans la réponse (ni `null`, ni tableau vide), aucune erreur. Le mécanisme reste agnostique du système d'autorisation : la décision est déléguée à un callable injecté dans `$init[Arango::AUTHORIZER]` (voir le câblage plus bas).

### Le décor des exemples

Une API d'entreprise. Une collection `users` (les fiches des employés), une collection `roles` (les rôles applicatifs), reliées par des arêtes `user_has_roles`. Deux personnes appellent **la même route** `GET /users/123` :

- **Alice**, administratrice : elle possède la permission `users.roles:list` ;
- **Bob**, employé : il ne la possède pas.

Objectif : Alice voit les rôles de la fiche, Bob voit la même fiche **sans** les rôles — sans erreur, sans champ vide, sans écrire deux routes.

### Verrouiller la relation sur sa définition

**La situation.** Pour cacher les rôles à Bob avec le seul verrou de champ, il faudrait poser `Field::REQUIRES` sur le champ `roles` de **chaque projection** qui le mentionne. Si trois modèles ou trois écrans projettent cette relation, il faut penser au verrou trois fois — en oublier un = fuite. Le verrou de définition se pose **une seule fois, sur la définition de la relation elle-même** : peu importe qui la projette, où et comment, elle est protégée.

```php
Models::USERS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'users' ,
    AQL::FIELDS =>
    [
        Prop::_KEY  => Filter::DEFAULT ,
        Prop::NAME  => Filter::DEFAULT ,
        Prop::ROLES => [ Field::FILTER => Filter::EDGES ] ,   // on projette la relation, sans verrou ici
    ] ,
    AQL::EDGES =>
    [
        Prop::ROLES =>
        [
            AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
            AQL::REQUIRES => 'users.roles:list' ,             // ← LE verrou, posé une fois pour toutes
        ] ,
    ] ,
])
```

**Ce que chacun reçoit** sur `GET /users/123` :

```jsonc
// Alice (permission accordée)                 // Bob (permission refusée)
{                                              {
  "_key" : "123" ,                               "_key" : "123" ,
  "name" : "Jeanne Martin" ,                     "name" : "Jeanne Martin"
  "roles": [ { "name": "manager" } ]             // pas de clé "roles" du tout
}                                              }
```

Pour Bob, la requête envoyée à ArangoDB ne contient même plus la traversée des rôles : on ne calcule pas ce qu'on ne montrera pas.

### La route « document entier »

**La situation.** Certaines routes ne définissent aucune liste de champs : le framework renvoie alors le document complet, enrichi de toutes les relations déclarées dans le modèle. Le verrou de définition s'applique aussi sur ce chemin : Bob reçoit le document complet, **moins** les relations auxquelles il n'a pas droit. Rien à déclarer en plus — c'est la même déclaration que l'exemple précédent. (Une entrée **alias** du registre — `'members' => 'roles'` — suit l'autorisation de sa cible : si `roles` est refusé, `members` disparaît aussi.)

### Deux verrous qui se cumulent

**La situation.** La direction RH demande : « les rôles ne sont visibles que des managers (`users.roles:list`), et dans l'écran RH complet, il faut **en plus** être habilité RH (`rh:read`) ». Deux exigences de niveaux différents : une sur la relation elle-même, une sur un écran précis. Chacune se déclare à son niveau, et **les deux doivent être satisfaites** :

```php
AQL::FIELDS =>
[
    Prop::ROLES =>
    [
        Field::FILTER   => Filter::EDGES ,
        Field::REQUIRES => 'rh:read' ,            // verrou de CETTE projection (l'écran RH)
    ] ,
] ,
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
        AQL::REQUIRES => 'users.roles:list' ,     // verrou de la relation, partout
    ] ,
]
```

Un manager non habilité RH ne voit pas les rôles dans l'écran RH ; un habilité RH qui n'est pas manager non plus. À l'inverse, **dans un même verrou**, une liste de permissions se lit comme un OU : `AQL::REQUIRES => [ 'users.roles:list' , 'users.roles:admin' ]` = « l'une des deux suffit ».

### Une relation enfouie dans un sous-tableau

**La situation.** Une fiche produit contient un tableau `offers` (une entrée par offre de prix). Chaque offre est reliée à ses vendeurs par une arête. Le public consulte le catalogue et voit les prix ; seuls les gestionnaires du catalogue (`offers.sellers:list`) voient **qui vend**. La relation est ici enfouie dans un sous-tableau — le verrou fonctionne exactement pareil :

```php
'offers' =>
[
    Field::FILTER => Filter::MAP ,                    // on parcourt le tableau d'offres
    Field::FIELDS =>
    [
        'price'   => Filter::DEFAULT ,
        'sellers' => [ Field::FILTER => Filter::EDGES ] ,
    ] ,
    Field::EDGES =>
    [
        'sellers' => [ AQL::MODEL => OfferHasSellers::class , AQL::REQUIRES => 'offers.sellers:list' ] ,
    ] ,
]
```

Le public reçoit `offers: [ { "price": 100 }, … ]` ; le gestionnaire reçoit en plus `"sellers": [...]` dans chaque offre. Même chose si la relation est enfouie dans un sous-objet (`Filter::DOCUMENT`), un objet enveloppé (`Filter::WRAP`), ou au bout d'une cascade (la relation d'une relation) : le verrou est vérifié **à chaque étage**.

### Les formes acceptées

`AQL::REQUIRES` (comme `Field::REQUIRES`) accepte deux formes :

- **Une chaîne** — un seul sujet de permission requis.
- **Un tableau de chaînes** — sémantique OU : la projection est autorisée dès qu'**au moins un** des sujets est accordé.

Quand la clé est absente, aucun contrôle n'est appliqué — comportement par défaut, aucun risque sur les définitions existantes.

### Les limites du mécanisme

**Limite 1 — Si votre code fabrique l'AQL à la main, c'est à lui de vérifier.** Dans l'usage normal (les modèles, `list()`, `get()`, les contrôleurs), la vérification est automatique. Mais la bibliothèque expose aussi les fonctions de bas niveau qui fabriquent un morceau de requête isolé — `buildEdgeVariable()` par exemple. Appelées **directement** avec une définition verrouillée, elles fabriquent le morceau sans poser de question : à ce niveau-là, l'appelant est supposé savoir ce qu'il fait. Tant qu'un projet passe par les modèles, cette limite ne le concerne pas.

**Limite 2 — La recherche a ses propres verrous, séparés et intacts.** La recherche plein-texte (`?search=`, les Views — `Search::REQUIRES` sur les specs) et la recherche fédérée multi-collections ont chacune leur propre système de permission. Un `AQL::REQUIRES` posé sur une définition d'arête ne protège pas un résultat de recherche : chaque couche a son verrou.

**Limite 3 — Le compteur de tableau stocké n'a pas de définition à verrouiller.** `Filter::JOINS_COUNT` ne suit aucune relation — il compte les éléments d'un tableau **déjà stocké dans le document** (ex. `doc.memberIds`). Pas de définition derrière, donc pas d'endroit où poser `AQL::REQUIRES` : pour le cacher, poser `Field::REQUIRES` sur le champ lui-même.

**Limite 4 — Sans contrôleur d'accès injecté, tout est ouvert.** Si une route n'injecte aucun authorizer (script d'administration, traitement interne, test), aucun verrou ne bloque : tout sort. C'est le contrat existant (voir « Comportement quand l'authorizer est absent » ci-dessous) — la protection n'existe que là où le contrôleur fournit le callable.

### Câblage côté contrôleur — pattern recommandé

`oihana/php-arango` ne connaît rien du système d'autorisation utilisé (Casbin, OPA, contrôle maison…). Le contrôleur fournit un callable `Closure(string $subject): bool` que le framework appellera pour chaque sujet déclaré.

`DocumentsController` expose deux hooks de cycle de vie issus du trait [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php) — `beforeModelCall( ?Request , array &$init )` et `afterModelCall( ?Request , array &$init , mixed &$result )` — qui sont automatiquement invoqués autour de chaque opération CRUD principale (`list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete`). Le pattern recommandé est d'override `beforeModelCall` une seule fois pour activer le contrôle d'accès sur tous les verbes HTTP du contrôleur :

```php
use oihana\api\controllers\traits\CapabilityAuthorizerTrait;
use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController extends DocumentsController
{
    use CapabilityAuthorizerTrait ;

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;

        if ( ( $authorizer = $this->buildAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
```

Le trait `CapabilityAuthorizerTrait` — fait partie de la facade `CapabilityGuardTrait` — fabrique un `Closure(string): bool` request-scoped basé sur le `CapabilityEnforcer` Casbin et le `userId` Zitadel courant. Il applique automatiquement `safeSubject` sur l'identifiant utilisateur (voir [tips auth-code](https://github.com/BcommeBois/oihana-php-auth/blob/main/wiki/fr/tips.md)). Quand l'enforcer est indisponible ou que la requête ne porte pas d'utilisateur authentifié, `buildAuthorizer` retourne `null` — l'`if` saute et le framework retombe sur son comportement par défaut (fail open, voir section suivante).

Avantage : l'override est **une seule ligne par contrôleur**, pas par verbe HTTP. Le câblage couvre `list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete` automatiquement.

### Variante — pattern request-agnostique avec `InjectAuthorizerTrait`

Quand le callable est connu à la construction du contrôleur (test unitaire, callable issu directement du conteneur DI sans dépendre du request, mode batch CLI…), un trait alternatif [`InjectAuthorizerTrait`](../../src/oihana/arango/controllers/traits/inject/InjectAuthorizerTrait.php) (côté `oihana/php-arango`, agnostique de Casbin) permet de stocker un callable stable au constructeur et de le poser dans chaque `$init` :

```php
use oihana\arango\controllers\traits\inject\InjectAuthorizerTrait;

final class BatchController extends DocumentsController
{
    use InjectAuthorizerTrait ;

    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;
        $this->initializeArangoAuthorizer( $init , fn() : bool => true ) ;
    }

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        $this->injectAuthorizer( $init ) ;
    }
}
```

`initializeArangoAuthorizer` accepte tout format de callable PHP standard (Closure, invokable, `[obj, 'method']`, `'Class::method'`, fonction qualifiée — la résolution passe par `oihana\core\callables\resolveCallable`). Pour les cas Casbin + request-scoped en production, préférer le pattern `CapabilityAuthorizerTrait` ci-dessus.

### Comportement quand l'authorizer est absent

Si `$init[Arango::AUTHORIZER]` n'est pas posé (le contrôleur n'override pas `beforeModelCall`, ou aucun enforcer n'est enregistré pour ce contrôleur), la fonction de contrôle interne `isAuthorized` retourne `true` par défaut — la projection est **autorisée** (fail open). Cette logique évite de casser une route quand on ajoute `AQL::REQUIRES` sur une définition partagée tant que tous les contrôleurs concernés n'ont pas été câblés.

Pour soumettre une projection à permission de manière stricte, le middleware `Authorized` sur la route HTTP (Casbin niveau permission HTTP) doit toujours être l'enveloppe principale — `AQL::REQUIRES` est une **deuxième couche** de contrôle d'accès à l'intérieur de la projection AQL, pas un remplacement.

### Fonctions internes — `isAuthorized` et `authorizeRelationFields`

`isAuthorized($definition, $init)` est le juge unique des deux niveaux de verrou : `buildVariables` l'appelle sur l'entrée de champ **et** sur la définition au moment de décider d'émettre chaque `LET` ; `aqlFields` l'appelle sur chaque champ au moment de la projection ; `buildEdgesVariables`/`buildJoinVariables` l'appellent sur chaque définition de la route « document entier ». Sa signature et son comportement :

```php
function isAuthorized( array $definition , array $init = [] ) : bool
```

- Pas de `AQL::REQUIRES` → `true` (no-op).
- Pas de callable sous `Arango::AUTHORIZER`, ou valeur non callable → `true` (fail open).
- Une chaîne ou un tableau → `true` dès qu'**au moins un** sujet est accordé par le callable. Seul `true` strict compte comme un grant (un truthy `1`, `'yes'` etc. n'autorise pas la projection).

La fonction se trouve dans `oihana\arango\models\helpers\isAuthorized`.

Sa compagne `authorizeRelationFields($fields, $edges, $joins, $init)` (même namespace) assure la **symétrie** du verrou de définition : une relation est émise par deux chemins parallèles — la sous-requête `LET` d'un côté, la clé projetée dans le `RETURN` de l'autre. Quand une définition est refusée, cette fonction retire le champ correspondant de la projection, pour que le `RETURN` ne référence jamais une variable qui n'a pas été émise. Elle est appliquée automatiquement partout où une projection rencontre ses registres d'edges/joins — vous n'avez jamais à l'appeler vous-même.

## Transformer la valeur projetée — `Field::ALTERS`

`Field::ALTERS` applique une **chaîne de transformations AQL** à la valeur d'un champ **au moment du `RETURN`**, exactement comme les transformations [`alt`](db/filter.md#transformations-alt) des filtres — mais côté **sortie**. C'est le pendant en projection : ce que `alt` fait pour comparer (`LOWER(doc.x) == LOWER(@v)`), `ALTERS` le fait pour renvoyer (`name: LOWER(doc.name)`).

La chaîne réutilise le même vocabulaire que `alt` (le registre `FilterFunction`) :

- une **fonction simple** : `'lower'` → `LOWER(doc.x)` ;
- une **chaîne de fonctions** : `['trim','lower']` → `LOWER(TRIM(doc.x))` (appliquée de gauche à droite, la dernière englobe) ;
- une **fonction avec paramètres** : `['substring', 0, 3]` → `SUBSTRING(doc.x, 0, 3)` ;
- une **chaîne mixte** : on peut panacher fonctions simples et fonctions-avec-paramètres dans la même liste — `['trim', ['substring',0,3], 'lower']` → `LOWER(SUBSTRING(TRIM(doc.x), 0, 3))`.

### Déclaration

```php
Arango::FIELDS =>
[
    // name renvoyé normalisé : sans espaces superflus et en minuscules
    'name'  => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ,

    // un alias de sortie (slug) calculé à partir d'un autre champ (title)
    'slug'  => [ Field::NAME => 'title' , Field::ALTERS => 'lower' ] ,

    // un code tronqué aux 3 premiers caractères
    'code'  => [ Field::NAME => 'reference' , Field::ALTERS => [ 'substring' , 0 , 3 ] ] ,
] ,
```

Génère la projection :

```aql
RETURN {
    name : LOWER(TRIM(doc.name)) ,
    slug : LOWER(doc.title) ,
    code : SUBSTRING(doc.reference, 0, 3)
}
```

### Exemples concrets

| Intention | Déclaration | AQL projeté |
|---|---|---|
| Email normalisé en minuscules | `'email' => [ Field::ALTERS => 'lower' ]` | `email: LOWER(doc.email)` |
| Titre détouré (espaces) | `'title' => [ Field::ALTERS => 'trim' ]` | `title: TRIM(doc.title)` |
| Slug minuscule depuis `title` | `'slug' => [ Field::NAME => 'title', Field::ALTERS => 'lower' ]` | `slug: LOWER(doc.title)` |
| Nom propre nettoyé | `'name' => [ Field::ALTERS => ['trim','lower'] ]` | `name: LOWER(TRIM(doc.name))` |
| Initiales (3 car.) | `'code' => [ Field::ALTERS => ['substring',0,3] ]` | `code: SUBSTRING(doc.code,0,3)` |

Sur la donnée `{ name: "  Jean DUPONT  ", title: "Hello World" }`, la projection ci-dessus renvoie `{ name: "jean dupont", slug: "hello world" }`.

### Portée et règles

- **Opt-in par champ** : un champ sans `Field::ALTERS` est projeté à l'identique (aucun changement de comportement existant).
- **Projection scalaire par défaut uniquement** (`clé: doc.clé`). Sur un champ portant un **`Field::FILTER` typé** (`BOOL`, `DATETIME`, `NUMBER`…) ou **structurel** (`EDGE`, `JOIN`, `MAP`, `DOCUMENT`…), `Field::ALTERS` est **ignoré** : une chaîne scalaire (`LOWER`, `TRIM`…) n'a pas de sens sur un sous-objet ou une conversion de type. Utilisez l'un **ou** l'autre.
- **`Field::NAME`** choisit l'attribut source ; la clé de sortie reste celle de la définition (utile pour exposer un champ transformé sous un autre nom, type `slug`).
- Aucun risque d'injection : les noms de fonctions sont sur **liste blanche** (`FilterFunction`) — une fonction inconnue est sans effet.

## Référence interne — la fonction `matchesSkin`

`matchesSkin($skins, $currentSkin)` est utilisée en interne par `FieldsTrait::filterFieldsBySkin` pour évaluer les marqueurs `Field::SKINS`. Elle ne fait pas partie de l'API publique du framework de projection — vous n'avez pas à l'appeler directement.

Sa signature et son comportement, pour information :

```php
function matchesSkin( mixed $skins , ?string $currentSkin ) :bool
```

- `null` ou `$currentSkin` à `null` : retourne toujours `true` (pas de filtre).
- Tableau : `in_array($currentSkin, $skins, true)`.
- Chaîne : équivalent à un tableau séparé par virgules, avec espaces tolérés.
- Toute autre forme : retourne `true` par défaut (robustesse face à une définition mal formée).

La fonction se trouve dans `oihana\arango\db\helpers\matchesSkin`.
