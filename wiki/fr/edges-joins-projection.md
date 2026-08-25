# Projection des edges et joins AQL

Cette page décrit la projection des **relations** : suivre une arête (edge), résoudre une référence stockée (join), traverser une hiérarchie, envelopper le résultat sous une clé. Les mécanismes transverses de la projection — les skins (`Field::SKINS`, `AQL::SKIN_FIELDS`), les permissions (`AQL::REQUIRES`), les transformations (`Field::ALTERS`) — sont décrits dans [La projection des champs](projection.md) et s'appliquent ici à l'identique.

> **Traversées programmatiques.** Le contrôle d'autorisation vaut aussi pour les traversées explicites `getVertices()` / `getOutboundVertices()` / `getInboundVertices()` / `getAnyVertices()` : la projection du **modèle cible** hérite ses `Field::REQUIRES` / `AQL::REQUIRES` — l'*authorizer* de la requête est propagé jusqu'à la projection de la cible, donc un champ ou une relation cachés à la lecture le restent à travers l'arête (pas d'oracle de champ via un edge). *Fail-open* sans *authorizer*.

## Sommaire

1. [Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge](#projection-composée--aqlfields--aqledges-sur-la-définition-dedge)
2. [Traversée hiérarchique — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#traversée-hiérarchique--aqlmax_depth--aqlmin_depth)
3. [Projeter les propriétés de l'edge — `Field::SCOPE`](#projeter-les-propriétés-de-ledge--fieldscope)
4. [Envelopper la référence sous une clé — `Filter::WRAP`](#envelopper-la-référence-sous-une-clé--filterwrap)
5. [Projeter un *join* — `Filter::JOIN` / `Filter::JOINS`](#projeter-un-join--filterjoin--filterjoins)
6. [Jointure polymorphe — collection cible selon un champ discriminant](#jointure-polymorphe--collection-cible-selon-un-champ-discriminant)
7. [Edge polymorphe — arête cible selon un champ discriminant](#edge-polymorphe--arête-cible-selon-un-champ-discriminant)
8. [Restreindre les sommets projetés — `AQL::WHERE` / `AQL::PRUNE`](#restreindre-les-sommets-projetés--aqlwhere)
9. [Le compteur dit toujours la même chose que la liste — `Filter::EDGES_COUNT`](#le-compteur-dit-toujours-la-même-chose-que-la-liste--filteredges_count)
10. [Ancrer une relation ailleurs — `Arango::SOURCE`](#ancrer-une-relation-ailleurs--arangosource)
11. [Couper un cycle INBOUND avec `AQL::SKIN`](#couper-un-cycle-inbound-avec-aqlskin)

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
- Cette projection ad-hoc **hérite les `Field::REQUIRES` du modèle cible** : un champ déclaré ici mais masqué à la lecture sur le modèle cible reste masqué (retiré de la sous-requête si l'*authorizer* le refuse), sans qu'on ait à redéclarer sa permission sur la définition. Le contrôle porte sur l'attribut **source** (`Field::NAME` si aliasé), jamais sur la clé de sortie. *Fail-open* : un champ sans `Field::REQUIRES` sur le modèle cible, ou sans *authorizer*, reste projeté.
- `AQL::EDGES` sur la définition d'edge déclare les sous-edges référencées par les `Filter::EDGE` ou `Filter::EDGES` dans la projection.
- `Field::FIELDS` posé **inline au niveau du champ parent** est ignoré pour `Filter::EDGES` (il n'est respecté que pour `Filter::DOCUMENT` et `Filter::MAP`). C'est un piège classique : déclarer la projection au bon niveau (sur la définition d'edge, pas sur le champ parent).

### Lever le contrôle du modèle cible pour cette relation — `Field::SELF_REQUIRES`

L'héritage ci-dessus est **absolu**, et c'est voulu : une relation ne peut pas décider toute seule qu'une autre permission suffit à voir un champ que le modèle cible masque. C'est exactement ce qui empêche un champ masqué de fuir à travers une relation. Mais parfois la relation *est* un contexte légitimement plus permissif — on ne lit le `salary` d'un collègue qu'avec `people:admin`, alors qu'on devrait toujours voir **son propre** salaire quand la relation suivie est « ma fiche employé à moi ».

`Field::SELF_REQUIRES` ouvre cette porte, et seulement celle-là. C'est une **alternative en OU** au `Field::REQUIRES` du modèle cible, honorée **uniquement** quand une relation re-projette le champ via son propre `AQL::FIELDS`. Une lecture de premier niveau du modèle ne passe jamais par ce chemin : le contrôle propre du modèle reste donc intact partout ailleurs.

La situation. Sur le modèle `people`, `salary` est masqué derrière `people:admin`. Une relation le re-projette et ajoute une alternative propre au possesseur :

```php
// modèle people — le contrôle de lecture auquel tout le monde est mesuré
'salary' => [ Field::REQUIRES => 'people:admin' ] ,

// sur l'AQL::FIELDS d'une relation — même champ, plus une alternative propre à la relation
'salary' =>
[
    Field::SELF_REQUIRES => 'people:self' , // une chaîne, ou une liste de sujets (OU)
] ,
```

| Appelant | `people:admin` | `people:self` | `salary` via la relation |
| --- | --- | --- | --- |
| un admin RH | accordé | — | **conservé** (le contrôle cible passe déjà) |
| le possesseur de la fiche | refusé | accordé | **conservé** (l'alternative *self* passe) |
| n'importe qui d'autre | refusé | refusé | retiré |

Les règles, une par une :

- **OU, jamais ET.** Le champ survit si le contrôle cible passe **ou** si au moins un sujet de `Field::SELF_REQUIRES` est accordé. Une liste est un OU logique, exactement comme `Field::REQUIRES`.
- **Ce helper uniquement.** `Field::SELF_REQUIRES` est lu par `authorizeTargetFields()` — le re-contrôle de relation — et par rien d'autre. Une lecture directe du modèle (`list`, `get`, une projection racine) ne le consulte jamais : il ne peut donc jamais élargir une lecture de premier niveau.
- **Une valeur vide ou malformée est ignorée.** `Field::SELF_REQUIRES => []` (ou toute valeur sans sujet chaîne) n'est **pas** un override — le champ reste retiré. Il ne peut pas emprunter le *fail-open* « aucun sujet → autorisé » d'un contrôle normal pour ré-exposer silencieusement un champ masqué.
- **Fail-open inchangé.** Sans *authorizer* câblé, le champ est conservé de toute façon — comme partout ailleurs.

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

La même clé est lue à l'identique par **toutes** les surfaces d'arêtes — la
projection, le comptage, la dimension `?group=`, les filtres hiérarchiques et le
promeneur de relations imbriquées :

- **Le défaut est `Traversal::OUTBOUND`** : une arête part du document qui la déclare.
- ⚠ **Un mot-clé inconnu est refusé**, jamais remplacé en silence par le défaut. Il était
  auparavant replié : un `AQL::DIRECTION => 'OUTBOUD'` mal orthographié compilait en
  `OUTBOUND` sans un mot — et sur une relation réellement atteinte en `INBOUND`, ce
  silence est une **projection vide en `200`**, indiscernable de « cette relation n'a
  aucun sommet ». Une *déclaration* fautive est côté serveur, donc elle lève ; une clé
  inconnue venant de la *requête* continue d'être jetée en silence, la frontière tracée
  partout ailleurs.
- **`Traversal::ANY` exige les deux extrémités sur la même collection.** `ANY` parcourt
  l'arête dans les deux sens : sur une relation **auto-référentielle** (un thésaurus,
  `user_follows`) c'est exactement le bon sens, et la projection est inchangée. Sur une
  relation dont les deux extrémités sont des collections **différentes**, il est
  **refusé** : une projection atteint un seul modèle de sommet, et les sommets de l'autre
  côté revenaient projetés avec les champs du côté proche — et filtrés par le
  `Field::REQUIRES` du côté proche. Déclarer `INBOUND` ou `OUTBOUND`, ou deux relations.
  > Cette restriction ne concerne qu'une relation **projetée**, qui a un modèle à
  > résoudre. Une **facette** liée déclare une *collection* d'arêtes, pas un modèle :
  > `ANY` y reste sans restriction — voir [Sens de traversée](db/facets.md#traversal-direction-aqldirection).

### Règles et valeurs par défaut

- **Aucune profondeur déclarée → inchangé.** Sans `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH`, la traversée reste à la profondeur 1 et l'AQL généré est **strictement identique** à avant — totalement rétro-compatible.
- **`AQL::MAX_DEPTH` seul** fixe la borne basse à `1` (`1..N`), la descente/remontée complète naturelle.
- **`AQL::MIN_DEPTH` seul est refusé.** ArangoDB exige une plage bornée, et une traversée non bornée sur une arête auto-référente risquerait une boucle infinie : une projection bornée **doit** déclarer `AQL::MAX_DEPTH`, sinon `buildEdgeVariable` lève une `UnexpectedValueException`.
- Le résultat est une **liste à plat** de tous les sommets rencontrés sur la plage de profondeur (pas un arbre imbriqué). Pour le retransformer en `children[]` imbriqué, on reconstruit l'arbre à partir de la liste à plat (cf. l'entrée de ROADMAP sur la reconstruction de hiérarchie).

> ⚠️ **Restreindre une hiérarchie demande DEUX clés, pas une.** Sur une traversée à profondeur, un simple filtre cache le sommet visé mais **pas sa descendance** : la marche continue à travers lui. Il faut `AQL::WHERE` *et* `AQL::PRUNE` — voir [Restreindre les sommets projetés](#restreindre-les-sommets-projetés--aqlwhere).

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

> **Entrée filtrée → des trous ; entrée élaguée → un arbre propre.** `buildTree()` suppose que la liste plate est *connexe* en descendant depuis la racine. Un [`?filter=`](controllers/README.md) sur la traversée peut casser ça : un sommet dont l'ancêtre a été filtré devient un **orphelin** — son `_parent` pointe vers un nœud retiré. Le remodelage dépend alors du mode de racine :
> - avec `buildTreeAlter()` (un `rootKey` explicite), l'orphelin — et son propre sous-arbre — est **abandonné** : la branche est tronquée au sommet filtré ;
> - avec des racines inférées (`rootKey: null`), l'orphelin **remonte au contraire en racine**, détaché de son vrai ancêtre.
>
> `?prune=` n'a pas cet effet : il coupe toute la branche sous un sommet non-matchant, donc aucun survivant n'est jamais laissé orphelin et l'arbre reconstruit reste connexe. **Règle : pour filtrer *et* reconstruire un arbre, préfère `?prune=` à `?filter=`** — ou accepte en connaissance de cause la troncature / la remontée ci-dessus (une liste à plat, elle, reste toujours une surface correcte pour `?filter=`).

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

### Restreindre les documents joints — `Arango::CONDITIONS`

**Le problème, en clair.** Une jointure ramène des documents. Si ton application en masque une partie — désactivés, hors périmètre — la liste principale les cache, mais la **fiche du parent** continue de les nommer dans son champ joint. On ne peut pas les énumérer, mais on les lit par ricochet.

C'est l'équivalent, pour les jointures, de ce que [`AQL::WHERE`](#restreindre-les-sommets-projetés--aqlwhere) fait pour les arêtes.

**La déclaration.** `Arango::CONDITIONS` porte des prédicats supplémentaires, ajoutés à l'appariement de clé. Deux formes : un simple tableau de prédicats AQL, ou — celle qui sert — une **fonction** qui les rend.

```php
'hasPart' =>
[
    AQL::MODEL          => 'Products' ,
    Arango::CONDITIONS  => fn( string $part ) :array => [ $part . '.active == true' ] ,
] ,
```

```aql
LET hasPart = (FOR doc_join_18 IN products
                 FILTER doc_join_18._key IN doc.hasPart && doc_join_18.active == true
                 RETURN doc_join_18)
```

> **Pourquoi une fonction et pas une chaîne ?** Le nom de la variable de boucle est **généré** (`doc_join_18…`), tu ne peux donc pas l'écrire en dur. La fonction le reçoit.

**Les trois arguments.** Ils sont **toujours** passés :

| # | Argument | À quoi il sert |
|---|---|---|
| 1 | `$join` | le nom de la boucle, pour préfixer tes attributs |
| 2 | `$parent` | le document englobant, pour comparer avec le parent |
| 3 | `$init` | le contexte de la requête |

⚠️ **Tu ne déclares que ce dont tu as besoin.** PHP jette les arguments en trop passés à une fonction que tu as écrite, donc `fn( $join )` et `fn( $join , $parent )` marchent tels quels — rien à migrer. Ce qui plante, c'est l'inverse : déclarer un paramètre qu'on ne te passe pas.

Du contexte, seules **trois clés sont contractuelles** : `Arango::AUTHORIZER`, `AQL::SKIN` et `Arango::BINDS`. Le reste est interne, ne t'appuie pas dessus.

**Rendre `[]` n'émet aucun prédicat**, et c'est le point important : c'est ce qui permet à un périmètre de rester inerte hors requête HTTP.

```php
Arango::CONDITIONS => function( string $part , string $parent , array $init ) :array
{
    if ( !isset( $init[ Arango::AUTHORIZER ] ) ) { return [] ; }  // CLI, moissonnage, tests
    return [ $part . '.productType NOT IN @inactiveTypes' ] ;
} ,
```

Sans autorisateur, la requête est celle d'avant **au bit près**, et aucune valeur n'est à fournir. Tes commandes en ligne de commande ne bougent pas d'une ligne.

**Une méthode d'objet ou un objet invocable** font aussi l'affaire, si le périmètre a besoin d'un service :

```php
Arango::CONDITIONS => [ $scopeProvider , 'productConditions' ] ,
```

> ⚠️ **Le piège de la valeur fournie à la requête.** Si ton prédicat référence `@maValeur` **en texte**, l'élagage automatique des valeurs inutilisées ne peut pas le voir — il cherche des objets `aqlBindRef()`, et un `@` dans une chaîne est indistinguable d'un `@` littéral. Donc si un skin peut écarter cette jointure, la requête entière sera refusée (*bind parameter … was not declared*). Deux réponses : préférer un prédicat **littéral** quand c'est possible, ou nommer la valeur explicitement en 4ᵉ argument de `prepareAndExecute( $query , $binds , $options , [ 'maValeur' ] )`.

> Un retour qui n'est pas un tableau lève une exception — jamais un filtre silencieusement absent.

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

## Edge polymorphe — arête cible selon un champ discriminant

Le pendant, côté **edges**, de la [jointure polymorphe](#jointure-polymorphe--collection-cible-selon-un-champ-discriminant). Là où un edge ordinaire traverse **une** collection d'arêtes figée (`AQL::MODEL`), un **edge polymorphe** choisit **à l'exécution** l'arête (et donc le sommet cible) à traverser, d'après la valeur d'un champ du **vecteur de départ** (le document source). Exemple : un nœud porte un champ `kind`, et l'on veut suivre `warehouse_edges` si `kind == "warehouse"`, `company_edges` si `kind == "company"`.

La définition remplace `AQL::MODEL` par les mêmes trois clés que la jointure polymorphe :

- **`Arango::DISCRIMINATOR`** — le champ du **vecteur de départ** qui décide (chemin scalaire, ex. `kind`).
- **`Arango::MAP`** — la table `type => définition d'edge`, une branche par valeur ; chaque branche est **une définition d'edge classique** (son `AQL::MODEL`, sa `AQL::DIRECTION`, sa projection, sa profondeur…).
- **`Arango::FALLBACK`** — (optionnel) la branche pour une valeur ne correspondant à **aucun** type déclaré ; `null` = aucune.

Le champ reste déclaré `Filter::EDGE` (unique) ou `Filter::EDGES` (liste) dans `AQL::FIELDS` — **aucun nouveau marqueur** : c'est la présence de `Arango::MAP` + `Arango::DISCRIMINATOR` qui bascule en mode polymorphe (détection partagée `isPolymorphic`, commune aux joins et aux edges).

```php
AQL::FIELDS =>
[
    'area' => Filter::EDGE ,                      // sommet unique (EDGES pour une liste)
],
AQL::EDGES =>
[
    'area' =>
    [
        Arango::DISCRIMINATOR => 'kind' ,          // le champ du vecteur de départ qui décide
        Arango::MAP           =>
        [
            'warehouse' =>
            [
                AQL::MODEL  => Edges::WAREHOUSE ,   // arête source → warehouses
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
            'company' =>
            [
                AQL::MODEL  => Edges::COMPANY ,     // arête source → subsidiaries
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
        ] ,
        Arango::FALLBACK => null ,                  // type inconnu → null (EDGE) / [] (EDGES)
    ],
],
```

AQL interdisant une collection calculée dans un `FOR … IN <sens> … <collection>`, l'edge est compilé comme un **`APPEND` de traversées statiques gardées** : une traversée par branche, gardée par une égalité sur le discriminateur, de sorte qu'une seule branche renvoie des lignes. L'AQL généré (simplifié) :

```aql
LET area = APPEND(
    ( FOR vertex, edge IN OUTBOUND doc warehouse_edges
        FILTER doc.kind == "warehouse"
        RETURN { _key: vertex._key, name: vertex.name } ) ,
    ( FOR vertex, edge IN OUTBOUND doc company_edges
        FILTER doc.kind == "company"
        RETURN { _key: vertex._key, name: vertex.name } )
)
```

> **Le `LET` contient un tableau, comme n'importe quel edge.** Une seule branche est non vide ; la projection déplie ensuite ce tableau **exactement comme un edge ordinaire** — `FIRST()` pour `Filter::EDGE`, le tableau entier pour `Filter::EDGES`. Rien à changer côté projection.

> **Chaque branche est une définition d'edge complète** : elle peut déclarer son propre `AQL::DIRECTION` (OUTBOUND/INBOUND), sa profondeur (`AQL::MAX_DEPTH`), etc. Projections homogènes recommandées entre branches.

> **Sécurité — identique à la jointure polymorphe.** Une branche refusée par permission (`Field::REQUIRES` / `AQL::REQUIRES`) est **retirée de l'`APPEND`** (fail-closed : sa collection n'est jamais traversée). Le repli `Arango::FALLBACK` est gardé par `NOT IN [ …tous les types déclarés… ]`, y compris les types refusés — un document d'un type refusé route vers **rien**, jamais vers le repli (pas d'oracle). Toutes branches écartées ⇒ `LET` vaut `[]`. La logique anti-oracle est **partagée** avec la jointure (un seul assembleur `buildPolymorphicRelationVariable`).

> ⚠️ **`Filter::EDGES_COUNT` polymorphe : non supporté (v1).** Le comptage passe par `LENGTH(traversal)`, incompatible avec le patron `APPEND` de branches. Une entrée de type count reste comptée sur la collection d'arête figée du modèle (comportement classique).

## Restreindre les sommets projetés — `AQL::WHERE`

**Le problème, en clair.** Un consommateur masque certains documents d'une collection — désactivés, hors périmètre, peu importe. La liste principale les cache correctement. Mais la fiche du parent, elle, continue de les nommer dans son tableau d'enfants : on ne peut pas les énumérer, mais on les lit **par ricochet**, à travers la relation d'un document servi.

C'est un annuaire dont on aurait retiré des pages, mais dont l'index en fin de volume citerait toujours les noms retirés.

**Ce que `AQL::WHERE` ajoute.** Un prédicat déclaré sur la **définition de la relation**, compilé contre le **sommet traversé**, et donc appliqué partout où cette définition est utilisée — la liste, la fiche, la sous-route, sans avoir à câbler chaque point d'entrée.

**La situation.** Un thésaurus expose la descendance de chaque concept ; le consommateur cache certains termes et calcule cette liste par requête.

```php
AQL::FIELDS =>
[
    'narrower' => Filter::EDGES ,
],
AQL::EDGES =>
[
    'narrower' =>
    [
        AQL::MODEL     => TermNarrower::class ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
    ] ,
],
```

L'AQL émis :

```aql
LET narrower_e1 = ( FOR vertex_1, edge_1 IN OUTBOUND doc term_narrower
                      OPTIONS { … }
                      FILTER vertex_1.id NOT IN @hiddenTerms
                      RETURN vertex_1 )
```

Le `FILTER` porte sur `vertex_1`, le sommet **d'arrivée** — pas sur `doc`. La valeur de `@hiddenTerms` est fournie à l'appel via `Arango::BINDS`.

### La grammaire est celle que tu connais déjà

`AQL::WHERE` compile avec **le même compilateur** que `Field::WHERE` et `Field::WHEN` (voir [Champs conditionnels](db/conditional-fields.md)). Tout ce que l'un accepte, l'autre l'accepte :

```php
AQL::WHERE => 'active' ,                                        // TO_BOOL(vertex.active)
AQL::WHERE => [ 'status' , 'active' ] ,                         // vertex.status == 'active'
AQL::WHERE => [ 'or' , [ 'status' , 'active' ] , [ 'not' , [ 'hidden' , true ] ] ] ,
AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ,       // vertex.id NOT IN @hidden
```

> **Deux clés de même valeur, deux sièges.** `Field::WHERE` se déclare sur une **entrée de projection** et filtre les éléments d'un tableau embarqué (`Filter::MAP`) ; `AQL::WHERE` se déclare sur une **définition de relation** et filtre les sommets traversés. Le couple reprend exactement celui de `Field::REQUIRES` (entrée) et `AQL::REQUIRES` (définition) — une seule grammaire, deux endroits où la poser.

### Ce que la clé garantit

| Situation | Comportement |
|---|---|
| Clé absente | **Aucun `FILTER` émis** — AQL identique au bit près |
| Bind lié à `[]` | `IN []` ⇒ aucun sommet retenu |
| Bind non fourni à l'appel | La **requête échoue** — jamais « pas de filtre » |
| Descripteur malformé | Exception à la construction — jamais un filtre silencieusement absent |
| Nom d'attribut dangereux | `ValidationException` (`assertAttributeName`) |

Le *fail-closed* est natif : un oubli de câblage se voit, il ne s'ouvre pas.

### Portée

| Forme | Pris en charge |
|---|---|
| `Filter::EDGES` (cardinalité N) | ✅ |
| `Filter::EDGE` (cardinalité 1) | ✅ — même définition, même prédicat |
| `Filter::EDGES_COUNT` | ✅ — **le compte filtre aussi** |
| Relations imbriquées (`AQL::EDGES` dans une définition) | ✅ à toute profondeur, chaque niveau son prédicat |
| Edge polymorphe | ✅ **par branche** (`Arango::MAP` et `Arango::FALLBACK`) |
| Jointures (`Filter::JOIN` / `JOINS`) | ⛔ — voir `AQL::CONDITIONS` sur la définition de jointure |

> **Le compte filtre, et ce n'est pas négociable.** Si le compte ignorait le prédicat, l'interface afficherait « 5 » à côté d'une liste de 3. La divergence *est* le bug, pas le filtrage : `Filter::EDGES_COUNT` lit la même déclaration et émet son `FILTER` dans la boucle comptée.

> **Sur un edge polymorphe, la clé se déclare branche par branche** — et c'est le bon découpage : chaque branche traverse une **autre collection**, donc l'ensemble masqué n'est pas le même. Le prédicat **s'ajoute** au garde de discriminant, il ne le remplace pas : `FILTER doc.kind == "warehouse" && vertex_1.id NOT IN @hidden`. Perdre le garde ferait rendre des lignes à toutes les branches de l'`APPEND` à la fois.

### Sur une hiérarchie, filtrer ne suffit pas — `AQL::PRUNE`

**La situation.** Une relation peut remonter plusieurs niveaux d'un coup (`AQL::MAX_DEPTH`) : la descendance d'une catégorie, et pas seulement ses enfants directs. Prenons cet arbre, où `b` est masqué :

```
a ─┬─ b ── c
   └─ e ── f
```

`AQL::WHERE` filtre la **sortie** de la traversée, il n'arrête pas la marche. Le serveur descend donc *à travers* `b` — qu'il jette ensuite — et ramène quand même `c`. **Mesuré sur une vraie base** :

| Déclaration | Résultat |
|---|---|
| `AQL::WHERE` seul | `c`, `e`, `f` — **`c` fuit** |
| `AQL::WHERE` + `AQL::PRUNE => true` | `e`, `f` — branche coupée, la sœur intacte |

On avait retiré le panneau indicateur au bord de la route, mais la route restait ouverte.

**La déclaration.** `AQL::PRUNE => true` reprend le prédicat de `AQL::WHERE` et le **nie** — « cache-le, *et* sa descendance » :

```php
'descendants' =>
[
    AQL::MODEL     => 'TermNarrowerEdge' ,
    AQL::MAX_DEPTH => 5 ,
    AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
    AQL::PRUNE     => true ,
] ,
```

```aql
(FOR vertex_1, edge_1 IN 1..5 OUTBOUND doc term_narrower
   PRUNE !(vertex_1.id NOT IN @hiddenTerms)
   OPTIONS { order: "bfs", uniqueVertices: "global" }
   FILTER vertex_1.id NOT IN @hiddenTerms
   SORT edge_1.created DESC RETURN vertex_1)
```

> **Les deux clauses vont ENSEMBLE, l'une ne remplace pas l'autre.** `PRUNE` arrête la marche *après* avoir visité le sommet : celui sur lequel on s'arrête est donc **toujours rendu**, c'est le `FILTER` qui l'enlève. Émettre `PRUNE` seul cacherait la descendance mais garderait le sommet masqué.

**Une condition propre**, quand « arrêter de descendre » n'est pas « cacher » :

```php
AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,   // ce qu'on cache
AQL::PRUNE => [ 'archived' , true ] ,                            // où l'on s'arrête
```

`AQL::PRUNE` se déclare aussi **seul** : la marche s'arrête, rien n'est caché — le sommet d'arrêt reste dans le résultat, sa descendance non.

**Les cas de bord :**

| Cas | Comportement |
|---|---|
| Clé absente | Aucun `PRUNE` émis, requête identique au bit près |
| `true` sans `AQL::WHERE` | **Exception** — il n'y a rien à nier, c'est une erreur de câblage. Rester silencieux laisserait la descendance masquée dans le résultat, exactement ce que la clé sert à éviter. |
| Condition malformée | Exception à la construction, comme pour `AQL::WHERE` |
| Relation à profondeur 1 | Accepté, sans effet — une définition peut gagner de la profondeur plus tard |
| `Filter::EDGES_COUNT` | **Pas concerné** : le compte est toujours une traversée de profondeur 1 (il n'émet pas la plage déclarée), et élaguer une marche d'un seul niveau ne change rien. ⚠️ Si le compte honore un jour la profondeur, il devra honorer `AQL::PRUNE` en même temps. |

> **Bénéfice en prime : l'arbre reconstruit redevient cohérent.** Avec `AQL::WITH_PATH`, `c` arrivait en annonçant « mon parent est `b` » — un parent absent du résultat, que `buildTree()` ne savait pas où accrocher. Si `c` ne vient plus, le problème disparaît.

> Testé **sur vraie base** (`EdgePruneScopeIntegrationTest`), et pas seulement sur l'AQL rendu : la garantie dépend de la façon dont le serveur parcourt le graphe, notamment avec les options que la lib émet toujours (`order: bfs`, `uniqueVertices: global`).

### `AQL::WHERE` et les permissions ne répondent pas à la même question

C'est la distinction à garder en tête :

- `AQL::REQUIRES` / `Field::REQUIRES` décident **SI** la relation est projetée. Refusé ⇒ la relation disparaît entièrement, clé absente du JSON et `LET` absent de l'AQL.
- `AQL::WHERE` décide **QUELS** sommets elle rend. La relation est là, son contenu est restreint.

Les deux se composent sans se marcher dessus : refusé, il n'y a plus de traversée à restreindre ; accordé, la traversée est là et elle est restreinte.

### Câblage côté hôte

Rien à câbler dans la lib. Deux choses à faire dans le projet consommateur :

1. **Résoudre la liste** (souvent un résolveur mis en cache — voir `DocumentFieldSetResolver`).
2. **Injecter le bind** dans `Arango::BINDS` à l'appel du modèle.

> **Le bind orphelin est élagué tout seul.** Une relation est projetée conditionnellement : un skin peut l'écarter, et le bind déclaré ne serait alors référencé nulle part — ArangoDB rejetterait la requête entière. La couche d'exécution retire ce surplus automatiquement, en dérivant la liste des binds élaguables des déclarations du modèle, **registres de relations compris**. Détail dans [Champs conditionnels](db/conditional-fields.md#champ-skinné--le-bind-orphelin-est-élagué-automatiquement).

## Le compteur dit toujours la même chose que la liste — `Filter::EDGES_COUNT`

À côté d'une liste de relations, on affiche souvent un simple **nombre** : « cette catégorie a N sous-catégories ». C'est un compteur (`Filter::EDGES_COUNT`), et son intérêt est de ne pas charger la liste pour connaître sa taille.

### Comment on le déclare

Le compteur et la liste parlent du **même lien**, donc ils partagent la **même définition**. Le registre a un raccourci pour le dire : une valeur de type chaîne renvoie vers une autre entrée.

```php
AQL::FIELDS =>
[
    'id'               => [] ,
    'descendants'      => [ Field::FILTER => Filter::EDGES       ] ,  // la liste
    'descendantsCount' => [ Field::FILTER => Filter::EDGES_COUNT ] ,  // le nombre
] ,

AQL::EDGES =>
[
    'descendants' =>
    [
        AQL::MODEL     => 'NarrowerEdge' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,
    ] ,
    'descendantsCount' => 'descendants' ,   // ← raccourci : LA MÊME définition
] ,
```

> Rien n'oblige à passer par le raccourci — on peut redéclarer une définition complète sous `descendantsCount`. Mais alors les deux entrées sont indépendantes : c'est à toi de les garder cohérentes, la lib ne peut pas deviner que tu voulais qu'elles parlent du même lien.

### La règle, en une phrase

**Le compteur compte exactement les lignes que la liste renvoie.** Tout ce que la définition dit sur *quels* sommets sont parcourus — la profondeur, le périmètre (`AQL::WHERE`), l'arrêt (`AQL::PRUNE`), l'unicité des sommets — est lu de la même façon des deux côtés.

Ce n'est pas une évidence gratuite : ça ne l'était pas. Trois déclarations donnaient un nombre que les lignes contredisaient, et **aucune des trois ne se voit en lisant l'AQL** — elles dépendent de la façon dont le serveur parcourt le graphe. D'où des tests sur vraie base.

### Les trois cas, mesurés

Prenons cet arbre, avec deux subtilités volontaires : `d` est atteignable par **deux chemins**, et le lien `a → c` existe **en double**.

```
a ─┬─ b ── d
   ├─ c ── d
   └─ c          ← le même lien, créé deux fois
```

| Déclaration | La liste renvoie | Le compteur disait | Il dit |
|---|---|---|---|
| Aucune profondeur, mais un lien en double | `[b, c]` → **2** | **3** — `c` compté deux fois | **2** ✅ |
| `AQL::MAX_DEPTH => 5` | `[b, c, d]` → **3** | **2** — les enfants directs seulement | **3** ✅ |
| `MIN_DEPTH => 2` + `MAX_DEPTH => 5` | `[d]` → **1** | **2** — la borne basse ignorée | **1** ✅ |

Les trois causes, une par une :

1. **La profondeur n'était pas lue.** Le compteur n'émettait ni `AQL::MIN_DEPTH` ni `AQL::MAX_DEPTH`, donc il restait à un seul niveau pendant que la liste descendait sur cinq. Sur l'arbre ci-dessus : `2` contre une liste de `3`.
2. **Les options de parcours n'étaient pas les mêmes.** La liste a toujours parcouru avec `uniqueVertices: global` — chaque sommet visité **une seule fois**, quel que soit le nombre de chemins qui y mènent. Le compteur n'émettait **aucune** option, donc le défaut d'ArangoDB s'appliquait : un sommet par chemin. Un lien en double, ou un losange, faisait sur-compter. C'est le seul cas qui **touchait déjà les relations à un niveau**.
3. **L'arrêt de parcours n'était pas lu** — conséquence du point 1. Dès que le compteur descend en profondeur, il doit s'arrêter là où la liste s'arrête, sinon il compte la descendance d'un sommet que la liste a coupé.

> ⚠️ **Les deux premières causes se compensaient partiellement, et c'est ce qui rendait le bug difficile à voir.** Sur une relation à profondeur, oublier la plage fait *sous*-compter et oublier les options fait *sur*-compter. Corriger la profondeur seule aurait échangé un `2` faux contre un `6` faux (mesuré). Il fallait les deux.

### Le AQL produit

```aql
LET descendantsCount = (LENGTH(FOR descendantsCount_v IN 1..5 OUTBOUND doc term_narrower
                                 PRUNE !(descendantsCount_v.id NOT IN @hidden)
                                 OPTIONS { order: "bfs", uniqueVertices: "global" }
                                 FILTER descendantsCount_v.id NOT IN @hidden
                                 RETURN descendantsCount_v))
```

C'est mot pour mot la traversée de la liste, sans la projection ni le tri — qui ne changent pas *combien* de lignes il y a.

### Ce qui n'a pas changé

| | |
|---|---|
| Une définition **sans** profondeur, périmètre ni arrêt | Compte toujours les voisins directs |
| La variable de boucle interne | Toujours dérivée du nom du `LET` (`<nom>_v`), jamais `vertex` — sinon collision quand le compteur est projeté à travers une traversée |
| Les permissions | Inchangées : `Field::REQUIRES` sur l'entrée du compteur, ou `AQL::REQUIRES` sur la définition partagée, décident **si** le compteur est émis |
| Le compteur polymorphe | Toujours non supporté (voir plus haut) |

> **Une seule chose bouge dans l'AQL d'une définition existante** : les options de parcours apparaissent maintenant dans le compteur. Concrètement, un sommet atteignable plusieurs fois n'est plus compté plusieurs fois. Si un écran affichait un nombre gonflé par un lien en double, il affichera désormais le bon.

> Les trois cas sont vérifiés **sur vraie base** (`EdgeCountAgreesWithListIntegrationTest`) : la liste et le compteur sont construits par les vrais constructeurs, exécutés dans la même requête, et comparés l'un à l'autre.

### Sous le capot : trois portes communes

Pour que la divergence ne puisse pas revenir, les deux constructeurs lisent la définition à travers les **mêmes** fonctions plutôt que chacun à sa façon :

| Porte | Ce qu'elle lit |
|---|---|
| `resolveEdgeDepthRange()` | La plage `MIN_DEPTH` / `MAX_DEPTH`, **et sa règle de refus** (`MIN_DEPTH` seul est interdit) |
| `resolveEdgeVertexScope()` | `AQL::WHERE` et `AQL::PRUNE`, compilés contre la variable de sommet qu'on lui passe |
| `edgeTraversalOptions()` | Les options de parcours |

Ce n'est pas de la cosmétique : la règle de refus, par exemple, vaut maintenant des deux côtés — le compteur et la liste ne peuvent plus être en désaccord sur ce qui constitue une déclaration valide.

## Ancrer une relation ailleurs — `Arango::SOURCE`

Par défaut, une relation lit son point d'ancrage **d'après son nom de sortie** : une jointure nommée `provider` va chercher sa clé étrangère dans `doc.provider`, un edge nommé `supplier` part de `doc` lui-même. Le libellé de sortie et l'endroit où vit vraiment la donnée sont soudés l'un à l'autre.

`Arango::SOURCE` **les dessoude** : il déclare, sous forme de **chemin absolu depuis `doc`**, *où* la relation lit son ancre — indépendamment du nom du champ de sortie. C'est **optionnel** : absent, l'AQL est identique au bit près.

La *nature* de l'ancre diffère selon le type de relation — les deux mécanismes ne s'accrochent pas à la même chose :

- **Jointure** — l'ancre est la **valeur de clé étrangère** comparée à `doc_join._key`. `SOURCE` déplace le match : `FILTER doc_join._key == doc.<source>`.
- **Edge** — l'ancre est le **sommet de départ** du traversal. `SOURCE` déplace le point de départ : `FOR … IN OUTBOUND doc.<source> …`.

### Sur une jointure

**La situation.** Un document `offer` range l'identifiant de son fournisseur dans un sous-objet `selector`, mais on veut l'exposer à plat sous le champ `provider`.

```php
AQL::FIELDS =>
[
    'provider' => Filter::JOIN ,
],
AQL::JOINS =>
[
    'provider' =>
    [
        AQL::MODEL     => Models::PROVIDER ,
        Arango::SOURCE => 'selector.providerId' ,   // chemin absolu, découplé de « provider »
        AQL::FIELDS    => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
    ],
],
```

AQL généré (simplifié) :

```aql
LET provider = (
    FOR doc_join IN @@provider
        FILTER doc_join._key == doc.selector.providerId
        RETURN { _key: doc_join._key, name: doc_join.name }
)
```

Sans `SOURCE`, la jointure viserait `doc.provider` — qui n'existe pas → jointure vide.

### Sur un edge

**La situation.** On veut suivre les arêtes `supplied_by` non pas depuis le document courant, mais depuis le sommet dont l'identifiant est rangé dans `doc.selector.providerId`.

```php
AQL::EDGES =>
[
    'supplier' =>
    [
        AQL::MODEL     => EdgesDefinition::SUPPLIED_BY ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        Arango::SOURCE => 'selector.providerId' ,   // le sommet de départ du traversal
    ],
],
```

AQL généré (simplifié) :

```aql
LET supplier = (
    FOR vertex, edge IN OUTBOUND doc.selector.providerId supplied_by
        RETURN vertex
)
```

> ⚠️ **Sur un edge, `doc.<source>` doit contenir un `_id` complet** (`"providers/123"`), pas un simple `_key` : ArangoDB démarre un traversal depuis un `_id` de sommet. Sur une jointure, `doc.<source>` porte la clé comparée à `doc_join._key`. Même idée unifiante — *où lire l'ancre sur le parent* — mais nature d'ancre différente.

> **`SOURCE` se compose avec `PROPERTY`.** `SOURCE` fixe la racine, `PROPERTY` reste un suffixe relatif : `Arango::SOURCE => 'selector.provider'` + `Arango::PROPERTY => 'id'` → `doc.selector.provider.id`. Le patron historique `substitutesSegment` (`PROPERTY` seul, sans `SOURCE`) ne change pas.

> **Sur une relation polymorphe, seule l'ancre bouge.** Pour un edge polymorphe, `SOURCE` déplace le départ du traversal (`OUTBOUND doc.<source>`) tandis que le **discriminateur reste résolu sur le document parent** (`doc.<discriminator>` choisit toujours la collection d'arête) — les deux références sont volontairement distinctes.

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

