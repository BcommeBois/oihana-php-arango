# Projection des edges et joins AQL

## Sommaire

1. [Vue d'ensemble](#vue-densemble)
2. [Le marqueur `Field::SKINS` au niveau document](#le-marqueur-fieldskins-au-niveau-document)
3. [Projection composée — `AQL::FIELDS` + `AQL::EDGES` sur la définition d'edge](#projection-composée--aqlfields--aqledges-sur-la-définition-dedge)
4. [Couper un cycle INBOUND avec `AQL::SKIN`](#couper-un-cycle-inbound-avec-aqlskin)
5. [Projection variable selon le skin de la requête — `Field::SKINS` sur les sous-champs](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs)
6. [Projection alternative selon le skin — `AQL::SKIN_FIELDS`](#projection-alternative-selon-le-skin--aqlskin_fields)
7. [Quel mécanisme choisir ?](#quel-mécanisme-choisir-)
8. [Restreindre la projection à une permission — `AQL::REQUIRES`](#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)
9. [Référence interne — la fonction `matchesSkin`](#référence-interne--la-fonction-matchesskin)

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
