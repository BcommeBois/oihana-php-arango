# Adaptateur Casbin RBAC

Le dossier [`src/oihana/arango/casbin/`](../../src/oihana/arango/casbin/) contient une seule classe : `ArangoCasbinAdapter`. Elle adapte le moteur [Casbin](https://casbin.org/) (RBAC / ABAC) à une collection ArangoDB en passant par un modèle [`Documents`](models.md) standard du framework.

## Pourquoi un adaptateur dédié

Casbin gère la **logique** d'autorisation (modèles RBAC / ABAC, *enforcer*, *matcher* policies). Il ne sait rien du stockage : il consomme un *adapter* pour persister, lire, mettre à jour les *policies*. L'adaptateur officiel par défaut est sur fichier (CSV) ; pour une application multi-tenant, multi-utilisateur, ou qui modifie ses policies dynamiquement, il faut un *adapter* base de données.

`ArangoCasbinAdapter` joue ce rôle :

- **Persistance** des *policies* dans une collection ArangoDB (généralement `rbac`).
- **Lecture** au démarrage de l'*enforcer* ou à la demande (mode filtré).
- **Cohérence** avec le reste du framework — `bind variables`, gestion du `_key`, intégration DI.
- **Compatibilité Casbin** complète : implémente les quatre interfaces standard.

## Interfaces Casbin implémentées

L'adaptateur implémente **toutes les interfaces de persistance** de Casbin :

| Interface | Apport |
|---|---|
| `Casbin\Persist\Adapter` | API de base : `loadPolicy`, `savePolicy`, `addPolicy`, `removePolicy`, `removeFilteredPolicy`. |
| `Casbin\Persist\BatchAdapter` | `addPolicies`, `removePolicies` en lot (insertion / suppression bulk). |
| `Casbin\Persist\FilteredAdapter` | `loadFilteredPolicy` — charge un sous-ensemble de *policies* via filtre (utile en multi-tenant). |
| `Casbin\Persist\UpdatableAdapter` | `updatePolicy`, `updateFilteredPolicies` — mises à jour atomiques. |

Concrètement, n'importe quel `Enforcer` Casbin standard peut s'adosser à cet adaptateur sans adaptation.

## Composition

L'adaptateur ne contient pas sa propre couche d'accès données. Il **consomme un modèle `Documents`** configuré sur la collection RBAC. Cela garantit :

- Une **seule source de vérité** pour les conventions d'accès ArangoDB (bind, validation, hooks).
- Une **cohérence** entre les *policies* écrites par Casbin et les requêtes lectures faites par le reste de l'application (un *dump* manuel d'une *policy* utilise les mêmes outils).
- Une **portabilité** : si on change la collection cible ou les champs, on modifie la définition du modèle, pas l'adaptateur.

```php
public function __construct
(
    Documents|DocumentsModel $model  ,
    ?LoggerInterface         $logger = null
)
```

## Structure d'une *policy* Casbin en ArangoDB

Casbin utilise une structure clé/valeur avec sept emplacements possibles par *policy* :

```php
public const array KEYS = [ 'ptype' , 'v0' , 'v1' , 'v2' , 'v3' , 'v4' , 'v5' ] ;
```

| Clé | Rôle |
|---|---|
| `ptype` | Type de policy (`p` pour permission, `g` pour groupe, etc.). |
| `v0` | Premier élément (sujet — utilisateur ou rôle). |
| `v1` | Deuxième élément (objet — ressource). |
| `v2` | Troisième élément (action — verbe HTTP, opération métier). |
| `v3` à `v5` | Éléments optionnels — domaine multi-tenant, contexte, conditions. |

Un document Casbin en collection `rbac` ressemble à :

```json
{
    "_key": "p:alice:users:read",
    "ptype": "p",
    "v0":    "alice",
    "v1":    "users",
    "v2":    "read"
}
```

L'adaptateur fait le mapping entre cette structure et l'API Casbin. Le format de `_key` est défini par convention — typiquement `<ptype>:<v0>:<v1>:<v2>`.

## Définition DI

```php
use DI\Container ;
use Casbin\Enforcer ;
use oihana\arango\casbin\ArangoCasbinAdapter ;
use Psr\Log\LoggerInterface ;

return
[
    // Modèle Documents sur la collection rbac
    Models::RBAC => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION => 'rbac'             ,
        AQL::DATABASE   => Databases::ARANGO  ,
        AQL::FIELDS     =>
        [
            Prop::_KEY => Filter::DEFAULT ,
            'ptype'    => Filter::DEFAULT ,
            'v0'       => Filter::DEFAULT ,
            'v1'       => Filter::DEFAULT ,
            'v2'       => Filter::DEFAULT ,
            'v3'       => Filter::DEFAULT ,
            'v4'       => Filter::DEFAULT ,
            'v5'       => Filter::DEFAULT ,
        ] ,
    ]) ,

    // Adaptateur Casbin branché sur le modèle
    Services::CASBIN_ADAPTER => fn( Container $c ) => new ArangoCasbinAdapter
    (
        $c->get( Models::RBAC )                ,
        $c->get( LoggerInterface::class )
    ) ,

    // Enforcer Casbin standard qui consomme l'adaptateur
    Services::CASBIN_ENFORCER => fn( Container $c ) => new Enforcer
    (
        '/path/to/rbac_model.conf'             ,
        $c->get( Services::CASBIN_ADAPTER )
    ) ,
] ;
```

L'`Enforcer` Casbin est ensuite consommé partout dans l'application via :

```php
$enforcer = $container->get( Services::CASBIN_ENFORCER ) ;

if ( $enforcer->enforce( $userKey , 'users' , 'read' ) )
{
    // autorisé
}
```

## Synchronisation des *policies* depuis les *edges*

Convention typique : les *policies* Casbin sont **dérivées** d'un état canonique stocké en *edges* (relations entre `users` ↔ `roles`, `roles` ↔ `permissions`, etc.). Une commande dédiée matérialise les *edges* en *policies* :

```bash
php bin/console.php auth:sync:policies
```

Le flot :

1. Lire les *edges* canoniques (`user_has_roles`, `role_has_permissions`, `policy_has_permissions`).
2. Calculer l'ensemble des tuples Casbin équivalents.
3. Wiper la collection `rbac`.
4. Insérer les nouveaux tuples en lot via `BatchAdapter::addPolicies()`.

Avantage : un seul *source of truth* (les *edges*), une vue dénormalisée (les *policies* Casbin) régénérable à la demande.

## Pièges connus

### `savePolicy()` réinitialise tout

`Adapter::savePolicy()` est conçu pour **écrire la totalité** des *policies* en un coup. Si on l'appelle accidentellement (par exemple, en branchant `Enforcer::savePolicy()` à la fin d'une opération), on perd toutes les *policies* en base et on n'écrit que celles présentes en mémoire dans l'*enforcer*.

Règle d'or : **ne jamais appeler `savePolicy()` côté application**. Utiliser `addPolicy()` / `removePolicy()` / `updatePolicy()` qui sont incrémentaux.

### `loadFilteredPolicy()` et état `filtered`

Quand on appelle `loadFilteredPolicy()`, l'attribut interne `$filtered` passe à `true`. À partir de ce moment, l'*enforcer* sait qu'il n'a qu'une vue partielle de la base et **refuse** `savePolicy()` (ce qui éviterait l'écrasement décrit ci-dessus).

Pour repasser en mode complet : appeler `loadPolicy()` (sans filtre).

### Conflits sur le `_key`

Le `_key` d'une *policy* doit être unique par convention `<ptype>:<v0>:<v1>:<v2>`. Tenter d'ajouter deux *policies* identiques lève `Error409` (conflit ArangoDB). L'adaptateur ne dédoublonne pas en interne — c'est à la couche métier (typiquement la commande `auth:sync:policies`) de garantir l'unicité.

### `safeSubject` pour les identifiants

Tout sujet (`v0`) passé à l'*enforcer* (lecture ou écriture) doit passer par `casbinSafeSubject()` pour éviter la coercion silencieuse de Casbin sur certains caractères. Voir [Tips d'authentification](https://github.com/BcommeBois/oihana-php-auth/blob/main/wiki/fr/tips.md) du package `oihana/php-auth`.

## Voir aussi

- [Modèles `Documents` et `Edges`](models.md) — la couche métier consommée par l'adaptateur.
- [Projection des edges et joins](edges-joins-projection.md) — `AQL::REQUIRES` exploité par le pattern d'autorisation Casbin.
- [Contrôleurs Slim — `InjectAuthorizerTrait`](controllers/README.md#trait-injectauthorizertrait) — injection d'un *callable* `Closure(string $subject): bool` basé sur l'*enforcer*.
- [Documentation officielle Casbin](https://casbin.org/docs/overview).
- [Documentation officielle Casbin PHP](https://github.com/php-casbin/php-casbin).
