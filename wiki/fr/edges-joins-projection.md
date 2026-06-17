# Projection des edges et joins AQL

## Sommaire

1. [Vue d'ensemble](#vue-densemble)
2. [Le marqueur `Field::SKINS` au niveau document](#le-marqueur-fieldskins-au-niveau-document)
3. [Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge](#projection-composée--aqlfields--aqledges-sur-la-définition-dedge)
4. [Projeter les propriétés de l'edge — `Field::SCOPE`](#projeter-les-propriétés-de-ledge--fieldscope)
5. [Envelopper la référence sous une clé — `Filter::WRAP`](#envelopper-la-référence-sous-une-clé--filterwrap)
6. [Projeter un *join* — `Filter::JOIN` / `Filter::JOINS`](#projeter-un-join--filterjoin--filterjoins)
7. [Couper un cycle INBOUND avec `AQL::SKIN`](#couper-un-cycle-inbound-avec-aqlskin)
8. [Projection variable selon le skin de la requête — `Field::SKINS` sur les sous-champs](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs)
9. [Projection alternative selon le skin — `AQL::SKIN_FIELDS`](#projection-alternative-selon-le-skin--aqlskin_fields)
10. [Quel mécanisme choisir ?](#quel-mécanisme-choisir-)
11. [Restreindre la projection à une permission — `AQL::REQUIRES`](#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)
12. [Transformer la valeur projetée — `Field::ALTERS`](#transformer-la-valeur-projetée--fieldalters)
13. [Référence interne — la fonction `matchesSkin`](#référence-interne--la-fonction-matchesskin)

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
    AQL::FIELDS => role
    ([
        Prop::IDENTIFIER                  => Filter::DEFAULT ,
        Prop::PERMISSIONS_COUNT           => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::PERMISSIONS                 => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::APPLICATION_TEMPLATES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
    ]) ,
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

## Projection alternative selon le skin — `AQL::SKIN_FIELDS`

Quand la projection diffère largement entre skins, et que poser des `Field::SKINS` partout devient illisible, on peut déclarer plusieurs projections distinctes via `AQL::SKIN_FIELDS`.

Forme générale :

```php
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL       => EdgesDefinition::USER_HAS_ROLES ,
        AQL::SKIN_FIELDS =>
        [
            Skin::DEFAULT => role() ,                                       // version plate
            Skin::FULL    => role([ Prop::PERMISSIONS => Filter::EDGES ]) , // version riche
            '*'           => role() ,                                        // optionnel : entrée fallback
        ] ,
        AQL::EDGES =>
        [
            Prop::PERMISSIONS => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        ] ,
    ] ,
]
```

Ordre de résolution interne :

1. `AQL::SKIN_FIELDS[$skin]` — projection dédiée au skin courant ;
2. `AQL::SKIN_FIELDS['*']` — entrée fallback de la table ;
3. `AQL::FIELDS` — ancienne projection unique (rétro-compatibilité) ;
4. `null` — aucune projection déclarée.

Si `AQL::SKIN_FIELDS` est absent ou n'est pas un tableau, la résolution retombe directement sur `AQL::FIELDS`, ce qui garantit la rétro-compatibilité avec les définitions antérieures.

`AQL::SKIN_FIELDS` est aussi reconnu par `buildJoinVariable`, le mécanisme est strictement le même pour les joins.

## Quel mécanisme choisir ?

| Besoin | Solution recommandée |
|---|---|
| Une seule projection, peu importe le skin | `AQL::FIELDS` seul |
| Quelques sous-champs varient entre skins (count caché en full, edge caché en default…) | `Field::SKINS` posé sur les sous-champs de `AQL::FIELDS` |
| La projection diffère largement entre skins (champs ajoutés, joins changés…) | `AQL::SKIN_FIELDS` avec une entrée par skin |
| Edge INBOUND vers un document qui peut référencer en retour la source | `AQL::SKIN => Skin::MAIN` sur la définition d'edge pour couper le cycle |
| Restreindre la projection d'un edge ou d'un join à une permission utilisateur | `AQL::REQUIRES` sur la définition + injection du callable via `InjectAuthorizerTrait` |

Les mécanismes se cumulent. Une définition peut combiner `AQL::SKIN_FIELDS` pour la projection principale, des `Field::SKINS` sur les sous-champs des projections individuelles, et un `AQL::SKIN` pour pinner le skin du target. La résolution est indépendante à chaque niveau.

## Restreindre la projection d'un edge ou d'un join à une permission — `AQL::REQUIRES`

Une définition peut déclarer une permission requise via `AQL::REQUIRES`. Si l'utilisateur courant n'a pas cette permission, l'edge ou le join est silencieusement omis de la projection (aucun `LET` AQL généré, aucune fuite, aucune erreur). Le mécanisme reste agnostique du système d'autorisation : la décision est déléguée à un callable injecté dans `$init[Arango::AUTHORIZER]`.

### Format de la déclaration

```php
Prop::ROLES =>
[
    AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
    AQL::REQUIRES => 'users.roles:list' ,
] ,
```

`AQL::REQUIRES` accepte deux formes :

- **Une chaîne** — un seul sujet de permission requis.
- **Un tableau de chaînes** — sémantique OR : la projection est autorisée dès qu'**au moins un** des sujets est accordé. Pratique quand plusieurs permissions ouvrent l'accès au même edge (par exemple `users.roles:list` ou `users.roles:admin`).

Quand `AQL::REQUIRES` est absent, aucun contrôle n'est appliqué — comportement par défaut, aucun risque sur les définitions existantes.

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

### Fonction interne — `isAuthorized`

`isAuthorized($definition, $init)` est utilisée par `buildVariables` au moment de décider d'inclure ou non chaque edge ou join. Sa signature et son comportement :

```php
function isAuthorized( array $definition , array $init = [] ) : bool
```

- Pas de `AQL::REQUIRES` → `true` (no-op).
- Pas de callable sous `Arango::AUTHORIZER`, ou valeur non callable → `true` (fail open).
- Une chaîne ou un tableau → `true` dès qu'**au moins un** sujet est accordé par le callable. Seul `true` strict compte comme un grant (un truthy `1`, `'yes'` etc. n'autorise pas la projection).

La fonction se trouve dans `oihana\arango\models\helpers\isAuthorized`.

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
