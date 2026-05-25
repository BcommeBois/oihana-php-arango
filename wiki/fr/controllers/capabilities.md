# Capabilities

## Qu'est-ce qu'une *capability* ?

Une *capability* (« capacité ») est une **permission fine** qui s'applique à la **valeur d'un paramètre** ou d'un **champ**, et non au verbe HTTP. Là où une permission Casbin classique répond à la question *« cet utilisateur peut-il faire `GET /products` ? »*, une *capability* répond à des questions plus pointues :

- *« cet utilisateur peut-il demander `?skin=offers.full` sur `/products` ? »* ;
- *« cet utilisateur peut-il filtrer sur `?filter=offers.priceBuying:>=100` ? »* ;
- *« cet utilisateur peut-il envoyer le champ `manualPriceOverride` dans le body d'un `PATCH /offers/{id}` ? »* ;
- *« cet utilisateur peut-il déclencher l'action transverse `?bench=true` ? »*.

Dans tous les cas, le verbe HTTP est **déjà autorisé** par Casbin — le client a bien le droit d'appeler la route. La *capability* gate **finement** ce qu'il peut envoyer **à l'intérieur** de la requête.

Côté serveur, chaque *capability* est rattachée à une permission Casbin (typiquement `products:skin.offers.full`). Si l'utilisateur n'a pas cette permission, le framework applique la politique configurée : refuser la requête en 403, ignorer silencieusement la valeur, replier sur une valeur par défaut, etc.

## Pourquoi un système séparé de Casbin

Casbin est excellent pour gater les **endpoints** : `GET`, `POST`, `PATCH`, `DELETE` par ressource. Mais Casbin ne sait pas répondre à *« cet utilisateur peut-il passer cette valeur dans ce paramètre ? »* — il ne voit que le verbe et le chemin de la route.

Sans système de *capabilities*, on a deux solutions médiocres :

1. **Tout autoriser à tout le monde** — le contrôleur fait son meilleur effort, et tant pis si un utilisateur bas de hiérarchie peut récupérer des prix d'achat normalement réservés au superadmin via `?skin=offers.full`.
2. **Démultiplier les endpoints** — `/products/public`, `/products/admin-with-prices`, `/products/internal` — déjà vu, déjà rejeté dans [Skins](skins.md#pourquoi-un-système-de-skins).

Le système de *capabilities* du framework résout le problème en **gardant Casbin pour les verbes** et en ajoutant une couche de gating sur les valeurs de paramètre et de champ. Le tout est déclaratif (un seul bloc dans la définition DI du contrôleur) et orthogonal à la chaîne `payload → rules → modèle`.

## De quoi parle cette page

Cette page documente :

- La **clé `Arango::CAPABILITIES`** dans la définition DI du contrôleur, son format et ses sous-clés (`Capability::OBJECT`, `Capability::VALUES`, `Capability::KEYS`, `Capability::FALLBACK`, `Capability::POLICY`, `Capability::REQUIRE`, `Capability::DENY`).
- Les **6 + 1 traits** qui implémentent l'application des *capabilities* : `CapabilityGuardTrait` (façade) + `CapabilityContextTrait` (état partagé) + `CapabilityParamTrait`, `CapabilityFilterKeysTrait`, `CapabilityBinaryTrait`, `CapabilityFieldsTrait`, `CapabilityAuthorizerTrait`.
- Le pattern d'**authorizer** : un `Closure(string $subject): bool` injecté par requête qui consulte le `CapabilityEnforcer` Casbin.
- Le **gating au niveau du champ** côté modèle via `AQL::REQUIRES` (et son interaction avec `Arango::AUTHORIZER`) — couvert en détail dans [Projection des edges et joins](../edges-joins-projection.md).

## Position dans le pipeline

```
HTTP request
  → middleware Authorized (Casbin)               gate verbe + chemin
  → PrepareSkin                                  fixe $init[ Arango::SKIN ]
  → enforceParam(?skin=)                         valide la VALEUR du skin (cette page)
  → enforceFilterKeys(?filter=...)               valide les CLÉS du filtre (cette page)
  → enforceFields($payload)                      valide les CHAMPS du body (cette page)
  → hasCapability(?bench=true)                   gate une action transverse (cette page)
  → preparePayload + validator                   cf. payloads.md + rules.md
  → buildAuthorizer($request) → $init[ AUTHORIZER ]
  → beforeModelCall($request, &$init)
  → model->...($init)                            modèle filtre AQL::REQUIRES si présent
  → response
```

Les hooks `enforceParam`, `enforceFilterKeys`, `enforceFields`, `hasCapability` sont les **points d'application** des *capabilities*. Ils sont appelés par le contrôleur (typiquement dans `beforeModelCall` ou directement dans le verbe HTTP) selon le besoin.

## Configuration côté contrôleur — `Arango::CAPABILITIES`

Un seul bloc dans la définition DI :

```php
Arango::CAPABILITIES =>
[
    Capability::OBJECT     => '/products' ,                    // sujet Casbin commun

    ControllerParam::SKIN  =>                                  // capability sur ?skin=
    [
        Capability::POLICY   => CapabilityPolicy::SILENT_DOWNGRADE ,
        Capability::FALLBACK => Skin::OFFERS                   ,
        Capability::VALUES   =>
        [
            Skin::OFFERS_FULL => 'products:skin.offers.full' ,                    // REQUIRE (forme courte)
            Skin::SPECIAL     => [ Capability::DENY => 'products:skin.special' ], // DENY (forme longue)
        ] ,
    ] ,
] ,
```

Lecture :

- `Capability::OBJECT => '/products'` — sujet Casbin commun à tout le bloc. Les permissions ci-dessous sont préfixées par `products:` par convention.
- `ControllerParam::SKIN` — désigne le paramètre URL `?skin=`. Le pattern fonctionne pareil pour `?filter=`, `?bench=`, `?search=`, etc.
- `Capability::POLICY` — politique appliquée quand la *capability* est refusée. Valeurs possibles : `SILENT_DOWNGRADE` (remplace par `FALLBACK`), `REJECT_403` (renvoie 403), `IGNORE` (laisse passer), `THROW` (exception).
- `Capability::FALLBACK` — valeur de remplacement quand la politique est `SILENT_DOWNGRADE`.
- `Capability::VALUES` — map `valeur acceptée → permission Casbin requise`. Chaque entrée peut être :
  - une **chaîne** = forme courte `REQUIRE` (la permission est requise pour autoriser la valeur),
  - un **tableau** `[ Capability::DENY => 'permission' ]` = forme longue `DENY` (la permission interdit la valeur).

`Capability::KEYS` est l'équivalent pour les paramètres de type *map* comme `?filter=`. Chaque **clé** du filtre est validée contre une permission :

```php
ControllerParam::FILTER =>
[
    Capability::POLICY  => CapabilityPolicy::REJECT_403 ,
    Capability::KEYS    =>
    [
        'offers.priceBuying'  => 'products:filter.offers.priceBuying' ,
        'offers.discountRate' => 'products:filter.offers.discountRate' ,
    ] ,
] ,
```

## Catalogue des sous-clés `Capability::*`

| Constante | Type | Rôle |
|---|---|---|
| `Capability::OBJECT` | `string` | Sujet Casbin commun (typiquement la ressource — `/products`, `/users`). |
| `Capability::POLICY` | `CapabilityPolicy::*` | Politique appliquée en cas de refus. |
| `Capability::FALLBACK` | `mixed` | Valeur de remplacement quand la politique est `SILENT_DOWNGRADE`. |
| `Capability::VALUES` | `array<string,string\|array>` | Mapping valeur → permission pour les paramètres énumérés (`?skin=`). |
| `Capability::KEYS` | `array<string,string\|array>` | Mapping clé → permission pour les paramètres *map* (`?filter=`). |
| `Capability::REQUIRE` | `string` | Permission requise (forme longue d'une entrée `VALUES`/`KEYS`). |
| `Capability::DENY` | `string` | Permission qui **interdit** une valeur ou une clé (négation de `REQUIRE`). |
| `Capability::FALLBACKS` | `array<string,string>` | Pour `KEYS` : map clé refusée → clé de substitution (au lieu de drop, on remappe). |

## Catalogue des politiques `CapabilityPolicy::*`

| Politique | Comportement quand la permission manque |
|---|---|
| `SILENT_DOWNGRADE` | Remplace la valeur par `FALLBACK` (ou drop si pas de fallback). Aucune erreur retournée — pratique pour ne pas casser une UI quand un user perd une permission. |
| `REJECT_403` | Réponse `403 Forbidden` immédiate. À utiliser quand le client doit savoir que la valeur est interdite (par exemple `?bench=true` sur un endpoint non-admin). |
| `IGNORE` | Laisse passer sans gating. Équivalent de ne pas configurer la *capability* — exposé pour les *features flags* runtime. |
| `THROW` | Lance une exception serveur. Pour les *bugs* applicatifs : si on arrive là, c'est qu'il y a un trou dans la déclaration. |

## Les 7 traits Capability

L'enforcement est implémenté par sept traits exposés par [`oihana/php-auth`](https://github.com/BcommeBois/oihana-php-auth/tree/main/src/oihana/auth/controllers/traits). Selon le besoin, on consomme un seul trait spécialisé ou la façade `CapabilityGuardTrait` qui bundle tout.

| Trait | Rôle | Quand l'utiliser |
|---|---|---|
| `CapabilityGuardTrait` | **Façade** qui agrège les six traits spécialisés (sauf `CapabilityAuthorizerTrait`). | Contrôleur qui consomme plusieurs types de *capabilities* — c'est le cas standard. |
| `CapabilityContextTrait` | État partagé : `$capabilities`, *kill-switch*, *enforcer* injecté. **Obligatoire** dès qu'un autre trait Capability est utilisé. | Toujours via `CapabilityGuardTrait` — pas besoin d'y toucher directement. |
| `CapabilityParamTrait` | `enforceParam( $request , $paramName )` pour les paramètres à **valeur énumérée** (`?skin=`). | Quand on a une liste fermée de valeurs autorisées et qu'on veut gater chacune individuellement. |
| `CapabilityFilterKeysTrait` | `enforceFilterKeys( $request )` pour les paramètres de type *map* (`?filter=`). Gate les **clés** du filtre. | Sur les ressources avec des champs filtrables sensibles (prix, données privées). |
| `CapabilityFieldsTrait` | `enforceFields( $payload )` pour gater les **champs du body** sur `PATCH` / `POST` / `PUT`. | Quand un champ ne doit être modifiable que par certains utilisateurs (par exemple `level` sur `/roles`). |
| `CapabilityBinaryTrait` | `hasCapability( $request , $paramName )` pour les paramètres **binaires** (`?bench=true`) ou les **actions transverses**. | Pour les *features* qu'on ne mappe pas sur une valeur mais sur la présence d'un paramètre. |
| `CapabilityAuthorizerTrait` | `buildAuthorizer( $request )` produit un `Closure(string $subject): bool` **request-scoped**. | Pour fournir l'*authorizer* à la couche modèle via `Arango::AUTHORIZER` (gating de `AQL::REQUIRES` sur les edges/joins). |

Pattern d'usage standard dans un contrôleur custom :

```php
use oihana\arango\controllers\DocumentsController ;
use oihana\controllers\traits\CapabilityGuardTrait ;
use Psr\Http\Message\ServerRequestInterface as Request ;

final class ProductsController extends DocumentsController
{
    use CapabilityGuardTrait ;

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;

        // Gate la valeur de ?skin= contre les permissions Casbin
        $this->enforceParam( $request , ControllerParam::SKIN , $init ) ;

        // Gate les clés de ?filter=
        $this->enforceFilterKeys( $request , $init ) ;

        // Injecte l'authorizer pour la couche modèle (AQL::REQUIRES)
        if ( ( $authorizer = $this->buildAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
```

## L'*authorizer* — vers le modèle

Une *capability* peut aussi vivre **au niveau du modèle**, sur un *edge* ou un *join* : c'est `AQL::REQUIRES`, documenté en détail dans [Projection des edges et joins](../edges-joins-projection.md#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires).

Le contrôleur ne sait rien du système d'autorisation utilisé en interne — il **injecte un *callable*** que le modèle consulte au besoin :

```php
$init[ Arango::AUTHORIZER ] = fn( string $subject ) : bool
                            => $enforcer->enforce( $userId , $subject , 'view' ) ;
```

`CapabilityAuthorizerTrait::buildAuthorizer( $request )` fabrique automatiquement ce *callable* request-scoped basé sur le `CapabilityEnforcer` Casbin. Le modèle filtre alors ses *edges* et *joins* annotés `AQL::REQUIRES` en consultant ce *callable* — sans avoir à comprendre Casbin lui-même.

C'est le contrat de **séparation des responsabilités** entre `oihana/php-arango` (qui ne sait rien d'auth) et la couche contrôleur du projet hôte (qui implémente Casbin). Quand un jour `oihana/php-arango` est extrait en lib autonome, l'*authorizer* reste injectable depuis l'extérieur — voir le chantier [découplage `oihana/php-arango` ↔ `oihana/api`](../../../../docs/fr/arango/dependencies.md#couplages-locaux-au-projet-hôte).

## Exemple complet — `/products?skin=offers.full`

Cas réel sur la ressource `products`. Trois *skins* exposés au catalogue produits :

- `Skin::DEFAULT` — liste produit (gratuit pour tous les utilisateurs authentifiés).
- `Skin::OFFERS` — fiche avec les offres tarifaires de vente (gratuit pour les vendeurs).
- `Skin::OFFERS_FULL` — fiche avec **les offres + les prix d'achat** (restreint au superadmin et aux acheteurs).

Définition du contrôleur :

```php
use Acme\enums\Skin ;
use oihana\arango\enums\Arango ;
use oihana\auth\enums\Capability ;
use oihana\auth\enums\CapabilityPolicy ;
use oihana\controllers\enums\ControllerParam ;

Controllers::PRODUCTS => fn( Container $c ) => new ProductsController( $c ,
[
    Arango::MODEL        => Models::PRODUCTS                          ,
    Arango::SKINS        => [ Skin::DEFAULT , Skin::OFFERS , Skin::OFFERS_FULL ] ,
    Arango::SKIN_DEFAULT => Skin::DEFAULT                              ,
    Arango::SKIN_METHODS =>
    [
        HttpMethod::list => Skin::DEFAULT ,
        HttpMethod::get  => Skin::OFFERS  ,
    ] ,

    Arango::CAPABILITIES =>
    [
        Capability::OBJECT => '/products' ,

        ControllerParam::SKIN =>
        [
            Capability::POLICY   => CapabilityPolicy::SILENT_DOWNGRADE ,
            Capability::FALLBACK => Skin::OFFERS ,
            Capability::VALUES   =>
            [
                Skin::OFFERS_FULL => 'products:skin.offers.full' ,
                // Skin::DEFAULT et Skin::OFFERS sont libres
            ] ,
        ] ,
    ] ,
]) ,
```

Comportement runtime :

- `GET /products?skin=default` (vendeur lambda) → OK, projection liste.
- `GET /products/{id}?skin=offers` (vendeur) → OK, projection vente.
- `GET /products/{id}?skin=offers.full` (vendeur **sans** permission `products:skin.offers.full`) → la politique `SILENT_DOWNGRADE` remplace silencieusement par `Skin::OFFERS`. Le client reçoit la fiche vente sans les prix d'achat — il ne voit même pas qu'on l'a refusé.
- `GET /products/{id}?skin=offers.full` (acheteur **avec** la permission) → OK, projection complète incluant les prix d'achat.

Le client n'a jamais besoin de demander une URL différente selon son rôle. Le serveur projette automatiquement ce que l'utilisateur a le droit de voir.

## Voir aussi

- [Skins](skins.md) — système de projection complémentaire (les *capabilities* gatent les **valeurs** des *skins*).
- [Projection des edges et joins — `AQL::REQUIRES`](../edges-joins-projection.md#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires) — *capability* au niveau modèle (edge/join).
- [Filtres HTTP `?filter=`](../filter.md) — paramètre couvert par `CapabilityFilterKeysTrait`.
- [Adaptateur Casbin RBAC](../casbin.md) — système d'autorisation sous-jacent.
- [Dépendances — Couplages locaux](../dependencies.md#couplages-locaux-au-projet-hôte) — le contrat d'injection d'*authorizer* qui garde `oihana/php-arango` indépendant.
