# Client ArangoDB *legacy*

Le dossier [`api/src/oihana/arango/client/`](../../../api/src/oihana/arango/client/) est un **fork** du [driver PHP officiel ArangoDB](https://github.com/arangodb/arangodb-php) (`triagens/ArangoDb`). Il a été aspiré tel quel pour deux raisons :

1. **Le driver officiel n'a plus reçu de mise à jour majeure depuis plusieurs années** et ne supporte pas PHP 8.4 sans patches.
2. Aucun client communautaire de remplacement ne couvre l'ensemble des besoins (compatibilité driver complète + intégration framework).

Le fork a été **patché *a minima*** pour fonctionner en PHP 8.4. Il n'a **pas** été refactoré, modernisé, ou aligné sur les conventions du reste du framework. Il vit dans le dossier `client/` et est consommé exclusivement par la classe [`ArangoDB`](quickstart.md) en interface.

> Cette page est volontairement courte. Le code du dossier `client/` est *legacy* et destiné à être remplacé — il n'a pas vocation à devenir une référence d'API stable.

## Ne pas l'utiliser directement

Le contrat du framework est clair : **toute interaction avec ArangoDB passe par [`ArangoDB`](quickstart.md), les [modèles `Documents` / `Edges`](models.md), ou les [contrôleurs](controllers/README.md) / [commandes](commands.md)**. Les classes du dossier `client/` sont des détails d'implémentation.

Code applicatif **incorrect** :

```php
use oihana\arango\client\Connection ;
use oihana\arango\client\DocumentHandler ;

$conn = new Connection( $options ) ;
$dh   = new DocumentHandler( $conn ) ;
$doc  = $dh->get( 'users' , 'abc' ) ;  // à éviter
```

Forme **canonique** :

```php
$users = $container->get( Models::USERS ) ;
$doc   = $users->get( [ Arango::ID => 'abc' ] ) ;
```

Une dépendance directe sur `oihana\arango\client\*` dans le code applicatif sera **cassée** lors de la réécriture du client (cf. [Roadmap](#roadmap-de-réécriture)) — alors qu'un appel à travers `Documents` survivra.

## Classes pivots

Les classes du fork sont nommées comme dans le driver officiel. Documentées ici uniquement à titre de **cartographie**, pour aider à lire les traces d'erreur ou les *stack traces*.

| Classe | Rôle dans le driver |
|---|---|
| `Connection` | Connexion HTTP au serveur ArangoDB (gestion *keep-alive*, retry). |
| `ConnectionOptions` | Tableau d'options de connexion (`OPTION_DATABASE`, `OPTION_ENDPOINT`, `OPTION_AUTH_TYPE`, ...). |
| `Statement` | Requête AQL préparée — combine texte de requête + bind variables. |
| `Cursor` | Itérateur sur le résultat d'un `Statement::execute()`. |
| `DocumentHandler` | CRUD bas niveau sur des documents (`get`, `save`, `update`, `remove`). |
| `EdgeHandler` | Idem pour les *edges* (avec validation `_from`/`_to`). |
| `CollectionHandler` | CRUD bas niveau sur les collections (création, *drop*, *truncate*, *rename*, indexes). |
| `Document` | Représentation d'un document (avec `_key`, `_id`, `_rev`). |
| `Edge` | Représentation d'une *edge* (étend `Document`, ajoute `_from`, `_to`). |
| `EdgeDefinition` | Description d'une *edge collection* dans un *graph*. |
| `Graph` | Représentation d'un *graph* nommé (collections de sommets + edges). |
| `BindVars` | Conteneur typé pour les *bind variables* d'un `Statement`. |
| `Batch` / `BatchPart` | Mode *batch* HTTP (multi-requêtes en une transaction réseau). |
| `Export` / `ExportCursor` | API d'export en masse (lecture séquentielle d'une collection complète). |
| `Transaction` | Transaction JavaScript embarquée — déprécié au profit des *stream transactions*. |
| `StreamingTransaction` / `StreamingTransactionHandler` | Transactions multi-documents standard. |
| `Exception` (et `ClientException`, `ServerException`, `ConnectException`, `FailoverException`) | Famille d'exceptions du driver. |
| `AdminHandler` | Endpoints d'administration (`/_admin/*`). |
| `FoxxHandler` | Gestion des microservices Foxx. |
| `AqlUserFunction` | Définition de fonctions AQL côté utilisateur. |
| `Analyzer` / `AnalyzerHandler` | Analyseurs linguistiques pour les vues ArangoSearch. |
| `View` / `ViewHandler` | Vues ArangoSearch. |

Total : **56 classes** dans le fork. Aucune n'est documentée page par page — pour le détail de leurs API, se reporter à la [documentation officielle du driver](https://docs.arangodb.com/3.10/drivers/php/) (versions 3.10 / 3.11 — la dernière à avoir été mise à jour).

## Roadmap de réécriture

À terme, le dossier `client/` sera remplacé par un client autonome écrit aux standards du framework :

- **Architecture moderne** alignée sur la dernière version d'ArangoDB.
- **Zéro *magic string*** — toutes les options et URLs passent par des enums.
- **Restructuration des classes** — séparation propre entre connexion HTTP, *statement*, sérialisation.
- **Refonte des handlers** sur le modèle des `Documents` / `Edges` haut niveau.
- **Interfaces explicites** pour permettre les *mocks* en test.
- **Compatibilité PHP ≥ 8.4** native.
- **Tests** unitaires + intégration complets.

Aucune date n'est fixée — la réécriture interviendra quand les autres pièces du framework seront stabilisées et qu'on aura le temps de mener un chantier autonome de cette ampleur.

## Que faire en attendant

Trois règles pour la période de transition :

1. **Toujours passer par `ArangoDB` ou un modèle** — jamais d'import direct de `oihana\arango\client\*`.
2. **Signaler tout bug du fork** dans une issue dédiée. Un correctif *upstream* sur le projet officiel ne sera pas appliqué automatiquement.
3. **Ne pas s'attacher aux signatures du fork** — elles changeront lors de la réécriture, et l'objectif est qu'aucun code applicatif n'ait à être modifié pour autant (le contrat public reste celui de `ArangoDB` et des modèles).

## Voir aussi

- [Quickstart `ArangoDB`](quickstart.md) — l'API publique stable au-dessus de ce fork.
- [Modèles `Documents` et `Edges`](models.md) — la couche métier au-dessus.
- [Documentation officielle du driver ArangoDB PHP](https://docs.arangodb.com/3.10/drivers/php/) — référence du driver origine.
