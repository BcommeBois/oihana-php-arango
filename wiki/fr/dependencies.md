# Dépendances

`oihana/php-arango` s'appuie sur un petit ensemble de packages `oihana/php-*` qui couvrent les briques de base (enums, exceptions, réflexion, fichiers) et les couches transverses (système, signaux, commandes). Cette page liste les dépendances exactes observées dans le code, leur rôle, et propose un snippet `composer require` minimal selon les sous-modules effectivement utilisés.

> Toutes les dépendances `oihana/*` sont aujourd'hui versionnées en `dev-main`. La stabilisation viendra par cascade quand l'ensemble du graphe sera tagué. Voir la note finale.

## Vue d'ensemble

Le tableau ci-dessous reflète les *imports* `use oihana\...` réellement présents dans le code de `src/oihana/arango/` au moment de la rédaction. Le nombre d'imports donne une idée de la centralité de chaque package.

| Package | Namespace racine | Imports | Rôle |
|---|---|---|---|
| `oihana/php-enums` | `oihana\enums\` | 147 | Constantes typées et `ConstantsTrait` (`AQL::*`, `Operator::*`, ...) |
| `oihana/php-exceptions` | `oihana\exceptions\` | 132 | `ValidationException` et la famille d'exceptions standard |
| `oihana/php-reflect` | `oihana\reflect\` | 74 | Introspection (`getPublicProperties`, `hasTrait`, `useConstantsTrait`, ...) |
| `oihana/php-system` | `oihana\` (large) | 99 | Controllers de base, models de base, traits transverses, logging |
| `oihana/php-commands` | `oihana\commands\` | 37 | Squelette Symfony Console pour `ArangoCommand` et `DocumentsCommand` |
| `oihana/php-files` | `oihana\files\` + `oihana\options\` | 18 | Lecture de fichiers, options sérialisables |
| `oihana/php-core` | `oihana\core\` | 3 | Helpers de base (`oihana\core\strings\compile`, `resolveCallable`, ...) |
| `oihana/php-signals` | `oihana\signals\` | 2 | Signaux applicatifs (cascade via `afterDelete`) |

> Le package `oihana/php-system` a un *autoload* large (`"oihana\\" => "src/oihana"`) : il fournit en réalité plusieurs namespaces racine — `oihana\controllers\`, `oihana\models\`, `oihana\traits\`, `oihana\logging\`. Les 99 imports recensés se répartissent ainsi : `controllers` (45), `models` (41), `traits` (12), `logging` (1).

## Détail par package

### `oihana/php-enums`

- Namespace : `oihana\enums\`
- Rôle : fournit `ConstantsTrait` (introspection `keys()` / `values()`) et la convention commune des enums du framework. Toutes les classes `AQL`, `Arango`, `Filter`, `Operator`, `Comparator`, `Skin`, etc. sont basées sur ce trait.
- Centralité : **maximale**. Aucun module de `oihana/php-arango` ne fonctionne sans ce package.

### `oihana/php-exceptions`

- Namespace : `oihana\exceptions\`
- Rôle : socle des exceptions transverses (`ValidationException`, `UnauthorizedException`, `NotFoundException`, ...). Les modèles `Documents` lèvent ces exceptions standardisées sur tout échec de validation, de lookup ou de cohérence.
- Centralité : **maximale**. Tous les modules y recourent.

### `oihana/php-reflect`

- Namespace : `oihana\reflect\`
- Rôle : introspection légère. `useConstantsTrait()` détecte si une classe consomme `ConstantsTrait`, `getPublicProperties()` énumère les propriétés publiques d'un schéma, `hasTrait()` valide la composition d'un modèle. Utilisé par la couche modèles pour hydrater et valider sans `ReflectionClass` brut.
- Centralité : **haute** (74 imports), surtout dans `models/` et `db/`.

### `oihana/php-system`

- Namespaces : `oihana\controllers\`, `oihana\models\`, `oihana\traits\`, `oihana\logging\` (autoload large `oihana\`).
- Rôle : base des contrôleurs HTTP (`Controller`, `StatusTrait`, `PrepareSkin`, `ModelCallTrait`, ...), base des modèles transverses (au-dessus de laquelle `Documents` et `Edges` se composent), `LoggerTrait` PSR-3.
- Centralité : **haute** (99 imports). Requis dès qu'on consomme `arango/controllers/`, `arango/models/` ou le contrôleur Slim CRUD.

### `oihana/php-commands`

- Namespace : `oihana\commands\`
- Rôle : squelette Symfony Console (classe `Command` enrichie, traits I/O, formats de sortie). `ArangoCommand` et `DocumentsCommand` en héritent pour exposer le CRUD ArangoDB en CLI.
- Centralité : **moyenne** (37 imports). Requis pour `arango/commands/` uniquement.

### `oihana/php-files`

- Namespaces : `oihana\files\` + `oihana\options\`
- Rôle : lecture de fichiers (`readJson`, `readToml`) et classes `Options` sérialisables qui sous-tendent les `*Options` AQL (`QueryOptions`, `InsertOptions`, ...).
- Centralité : **modérée** (18 imports), concentrés dans `db/options/`.

### `oihana/php-core`

- Namespace : `oihana\core\`
- Rôle : helpers fondamentaux — `oihana\core\strings\compile()` (joindre des fragments en ignorant les vides), `oihana\core\callables\resolveCallable()` (résolution Closure / `[obj, method]` / `'Class::method'`), `oihana\core\arrays\*` (utilitaires tableaux).
- Centralité : **faible en imports** (3) mais **structurel** : les helpers consommés sont au cœur de plusieurs algorithmes (composition de tri, résolution d'*authorizer*).

### `oihana/php-signals`

- Namespace : `oihana\signals\`
- Rôle : bus de signaux applicatifs léger. `oihana/php-arango` l'utilise pour la **cascade automatique des relations** : un `Documents::delete()` émet un signal `afterDelete` que les `EdgesFromTrait` et `EdgesToTrait` interceptent pour purger les arêtes liées.
- Centralité : **faible en imports** (2) mais **critique fonctionnellement** dès qu'on déclare des `AQL::EDGES` sur un modèle.

## Dépendances cross-cutting (non `oihana/*`)

`oihana/php-arango` ne tire aucune dépendance lourde par lui-même, mais certains sous-modules s'intègrent à des frameworks tiers que le projet hôte doit fournir :

| Sous-module | Dépendance externe attendue | Notes |
|---|---|---|
| `arango/controllers/` | `slim/slim` (Slim 4) + un conteneur PSR-11 | Le contrôleur consomme `Psr\Http\Message\ServerRequestInterface` et `Psr\Http\Message\ResponseInterface`. |
| `arango/commands/` | `symfony/console` | `DocumentsCommand` étend `Symfony\Component\Console\Command\Command` via `oihana/php-commands`. |
| `arango/casbin/` | `casbin/casbin` | `ArangoCasbinAdapter` implémente `Casbin\Persist\Adapter`, `BatchAdapter`, `FilteredAdapter`. |
| `arango/client/` | aucune (le code est embarqué) | Fork *legacy* du driver officiel ArangoDB PHP. |

Le conteneur PSR-11 utilisé partout dans les exemples est PHP-DI ; rien dans `oihana/php-arango` n'est cependant couplé à cette implémentation — toute implémentation `Psr\Container\ContainerInterface` convient.

## Intégration avec `oihana/php-auth`

La chaîne *capabilities* (gating par permission Casbin) du sous-dossier `arango/auth/` et `arango/casbin/` repose sur les contrats publics exposés par le package séparé [`oihana/php-auth`](https://github.com/BcommeBois/oihana-php-auth) :

- `oihana\auth\CapabilityEnforcerInterface` — exécute une décision Casbin sur un sujet.
- `oihana\auth\PermissionSubjectResolverInterface` — résout le sujet Casbin courant à partir de la requête / du JWT.
- `oihana\auth\controllers\traits\DocumentsControllerCapabilitiesTrait` — point d'entrée *opt-in* des contrôleurs HTTP pour activer le contrôle d'accès fin.
- `oihana\auth\controllers\traits\PermissionAuthorizerTrait` — fabrique le `Closure(string $subject): bool` request-scoped.

`oihana/php-auth` est déclaré dans `composer.json` comme dépendance directe : aucune action n'est nécessaire côté consommateur pour bénéficier de ces traits.

## Snippet `composer require` minimal

Pour un usage **complet** de `oihana/php-arango` (couche AQL + modèles + contrôleurs Slim + commandes CLI + Casbin) :

```bash
composer require \
    oihana/php-enums:dev-main      \
    oihana/php-exceptions:dev-main \
    oihana/php-reflect:dev-main    \
    oihana/php-system:dev-main     \
    oihana/php-commands:dev-main   \
    oihana/php-files:dev-main      \
    oihana/php-core:dev-main       \
    oihana/php-signals:dev-main
```

Pour un usage **minimal** (couche AQL `db/` seule, sans modèles ni contrôleurs) :

```bash
composer require \
    oihana/php-enums:dev-main      \
    oihana/php-exceptions:dev-main \
    oihana/php-reflect:dev-main    \
    oihana/php-core:dev-main       \
    oihana/php-files:dev-main
```

Pour les sous-modules optionnels :

```bash
# Contrôleurs Slim
composer require slim/slim:^4.0 php-di/php-di:^7.0

# Commandes Symfony Console
composer require symfony/console:^6.0

# Adaptateur Casbin RBAC
composer require casbin/casbin:^3.0
```

> `oihana/php-arango` lui-même n'est pas encore publié en tant que package autonome. Tant que l'extraction n'a pas eu lieu, le code vit sous `src/oihana/arango/` dans une application hôte. Le snippet `composer require oihana/php-arango:dev-main` deviendra valide à ce moment-là.

## Note sur les versions

L'ensemble des packages `oihana/*` est actuellement versionné en `dev-main`. Tant qu'une dépendance du graphe est en `dev-main`, le package qui la consomme reste lui aussi en `dev-main` — il serait incohérent de tager `1.0.0` un package qui pointe sur du `dev-main`. La stabilisation se fera par cascade quand l'ensemble du graphe sera prêt à recevoir un tag.

## Voir aussi

- [Introduction](introduction.md) — pourquoi cette bibliothèque existe.
- [Glossaire](glossary.md) — termes du framework.
- [Quickstart `ArangoDB`](quickstart.md) — premier exemple opérationnel.
