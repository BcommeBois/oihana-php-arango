# Signaux & cascade (cycle de vie des modèles)

Chaque écriture d'un modèle `Documents` ou `Edges` — `insert`, `update`, `replace`, `upsert`, `delete`, `truncate` — émet une paire de **signaux** *avant* / *après* l'opération. Un observateur connecté à ces signaux peut **inspecter** les données entrantes, **réagir** au résultat, ou déclencher un **effet de bord**.

Le plus puissant de ces effets de bord est intégré au framework : la **cascade de suppression**. Supprimer un document-sommet (*vertex*) retire automatiquement ses arêtes (*edges*), et — si vous l'avez déclaré — **purge les documents liés à l'autre bout** dans le sens que vous choisissez (`INBOUND` / `OUTBOUND` / `BOTH`). C'est ainsi qu'on vide d'autres collections en supprimant un seul document, sans écrire une ligne de code applicatif.

Le second est plus discret mais tout aussi utile : l'**invalidation des caches dépendants**. Un service qui garde en mémoire un état dérivé d'une collection doit l'oublier dès que la collection bouge ; `Arango::INVALIDATES` le déclare sur le modèle plutôt que de le recoder autour de chaque écriture.

```
            émission                  émission
   ┌──────────────────┐      ┌──────────────────┐
   │   beforeDelete   │      │   afterDelete    │
   └────────┬─────────┘      └────────┬─────────┘
            │                         │
   ─────────▼─────────  delete()  ────▼──────────────►  temps
            │                         │
   (inspecter / refuser)     (réagir / CASCADE des edges)
```

> **Le mécanisme générique** (les primitives `Signal` / `Payload`, l'ajout de signaux à un modèle quelconque) est documenté en amont dans `oihana/php-models` → [Signals & notices](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/fr/signals-notices.md). Cette page se concentre sur **ce que les modèles `Documents`/`Edges` d'`oihana/php-arango` en font** : les signaux qu'ils émettent et la cascade de suppression.

## Sommaire

1. [Les six signaux du cycle de vie](#les-six-signaux-du-cycle-de-vie)
2. [Connecter un écouteur](#connecter-un-écouteur)
3. [La cascade de suppression](#la-cascade-de-suppression)
   - [Couche 1 — purge automatique des arêtes](#couche-1--purge-automatique-des-arêtes)
   - [Couche 2 — purge dirigée des documents liés (`Purge`)](#couche-2--purge-dirigée-des-documents-liés-purge)
4. [Exemple de bout en bout](#exemple-de-bout-en-bout)
5. [Invalider les caches dépendants (`Arango::INVALIDATES`)](#invalider-les-caches-dépendants-arangoinvalidates)
   - [Le contrat `Invalidable`](#le-contrat-invalidable)
   - [`DocumentFieldSetResolver` — un ensemble de valeurs caché](#documentfieldsetresolver--un-ensemble-de-valeurs-caché)
   - [Ce qui est ignoré en silence](#ce-qui-est-ignoré-en-silence)
6. [Pièges & garanties](#pièges--garanties)
7. [Voir aussi](#voir-aussi)

## Les six signaux du cycle de vie

Chaque opération CRUD expose deux propriétés publiques `oihana\signals\Signal` — une `before*` et une `after*` — et transporte une **notice** fortement typée (`oihana\models\notices\Before*` / `After*`) qui regroupe :

- `data` — le ou les documents concernés / le résultat ;
- `target` — le modèle qui a émis le signal ;
- `context` — le tableau `$init` de l'appel (skin, locale, filtres… selon l'opération) ;
- `type` — le discriminant textuel issu de `oihana\models\enums\NoticeType` (p. ex. `'afterDelete'`).

| Opération | Signal *avant* → notice | Signal *après* → notice |
|---|---|---|
| `insert()`   | `$beforeInsert` → `BeforeInsert`     | `$afterInsert` → `AfterInsert`     |
| `update()`   | `$beforeUpdate` → `BeforeUpdate`     | `$afterUpdate` → `AfterUpdate`     |
| `replace()`  | `$beforeReplace` → `BeforeReplace`   | `$afterReplace` → `AfterReplace`   |
| `upsert()`   | `$beforeUpsert` → `BeforeUpsert`     | `$afterUpsert` → `AfterUpsert`     |
| `delete()`   | `$beforeDelete` → `BeforeDelete`     | `$afterDelete` → `AfterDelete`     |
| `truncate()` | `$beforeTruncate` → `BeforeTruncate` | `$afterTruncate` → `AfterTruncate` |

> **Les notices `truncate` ne portent pas de `data`.** Un `truncate()` vide une collection entière : il n'y a pas de document unique concerné. Le constructeur n'accepte que `target` et `context`.

> **Initialisation automatique.** Contrairement à la lib amont (où il faut appeler `initialize*Signals()` à la main), les modèles `Documents`/`Edges` **initialisent leurs six signaux dans le constructeur** (via `initializeDocumentsMethods()`). Vous pouvez donc `connect()` directement après l'instanciation, sans étape préalable.

> **Les écritures de tableaux émettent `*Update`.** `arrayInsert` / `arrayRemove` / `arrayMove` / `arrayPurgeRef` émettent `beforeUpdate` / `afterUpdate`, exactement comme `update()` (voir [Champs-tableaux](db/arrays.md#signaux)). `arrayContains` est une lecture : aucun signal.

## Connecter un écouteur

Un écouteur est n'importe quel *callable* connecté via `connect()`. Il reçoit la notice et lit ses propriétés publiques.

```php
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;
use oihana\models\notices\AfterDelete ;

$users = new Documents( $container , [ AQL::COLLECTION => 'users' , /* … */ ] ) ;

// Aucun initialize*Signals() à appeler : le modèle l'a déjà fait.
$users->afterDelete?->connect( function( AfterDelete $notice )
{
    // $notice->data    : le(s) document(s) supprimé(s) (OLD), ou null si rien n'a matché
    // $notice->target  : le modèle émetteur ($users)
    // $notice->context : le tableau $init passé à delete()
    // $notice->type    : NoticeType::AFTER_DELETE ('afterDelete')

    $this->logger?->info( 'Utilisateurs supprimés : ' . json_encode( $notice->data ) ) ;
} ) ;

$users->delete( [ Arango::KEY => Schema::_KEY , Arango::VALUE => 'alice' ] ) ;
```

> **Priorités, écouteur unique, nettoyage.** `connect()` accepte une `priority` (la plus haute s'exécute en premier) et un indicateur `autoDisconnect` (retiré après le premier appel). Pour tout démonter, `release*Signals()`. Détails dans l'amont [Signals & notices](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/fr/signals-notices.md#priorités-écouteurs-uniques-et-nettoyage).

## La cascade de suppression

C'est l'effet de bord clé du framework, et la réponse à *« comment vider d'autres collections/edges automatiquement quand je supprime un document ? »*.

Quand on `delete()` un **sommet** (un `Documents` qui sert de vertex), son signal `afterDelete` est intercepté par les modèles `Edges` qui le référencent. La cascade procède en **deux couches** :

### Couche 1 — purge automatique des arêtes

**Toujours active, rien à déclarer.** Un modèle `Edges` reçoit ses sommets `from` (source, `_from`) et `to` (cible, `_to`) à la construction. Au câblage (`initializeFrom()` / `initializeTo()`), l'`Edges` **s'abonne** au signal `afterDelete` de chaque sommet :

```php
// EdgesFromTrait::registerFrom() — abonnement automatique
$this->from->afterDelete->connect( [ $this , 'onDeleteVertex' ] ) ;
```

Quand le sommet est supprimé, `onDeleteVertex()` appelle `deleteEdges()`, qui retire **toutes les arêtes touchant ce sommet** — côté `_from` **et** côté `_to`. Résultat : aucune arête orpheline ne subsiste. C'est la garantie d'intégrité référentielle, sans code applicatif.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Edges ;

$userHasRoles = new Edges( $container ,
[
    AQL::COLLECTION => 'user_has_roles' ,
    AQL::FROM       => $users ,   // sommet source
    AQL::TO         => $roles ,   // sommet cible
]) ;

$users->delete( [ AQL::VALUE => 'alice' ] ) ;
// → toutes les arêtes user_has_roles partant de (ou pointant vers) 'alice' sont supprimées.
//   Les documents 'roles' liés, eux, restent intacts (voir couche 2).
```

### Couche 2 — purge dirigée des documents liés (`Purge`)

**Optionnelle, déclarée par sommet.** En plus de retirer l'arête, on peut supprimer le **document à l'autre bout**. C'est exactement « supprimer X vide la collection Y ». On l'active avec la clé `AQL::PURGE` à la construction de l'`Edges`, alimentée par l'énumération [`Purge`](../../src/oihana/arango/models/enums/Purge.php) :

| `AQL::PURGE` | Sens | Effet |
|---|---|---|
| `Purge::OUTBOUND` | on supprime le **`from`** | purge aussi les **`to`** liés |
| `Purge::INBOUND`  | on supprime le **`to`**   | purge aussi les **`from`** liés |
| `Purge::BOTH`     | l'un **ou** l'autre        | purge l'autre bout dans les deux cas |
| *(absente / `null`)* | — | **aucune** purge de sommet : seules les arêtes partent (couche 1) |

Schéma, sur l'exemple d'un `WebAPI` relié à des `Permission` par des arêtes :

```
[FROM: WebAPI] ──edge──> [TO: Permission]

OUTBOUND   delete WebAPI      → supprime aussi les Permission liées
INBOUND    delete Permission  → supprime aussi les WebAPI liés
BOTH       delete WebAPI      → supprime les Permission
           delete Permission  → supprime les WebAPI
null       delete WebAPI      → ne supprime QUE les arêtes ; Permission intactes
```

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Edges ;
use oihana\arango\models\enums\Purge ;

$apiHasPermissions = new Edges( $container ,
[
    AQL::COLLECTION => 'api_has_permissions' ,
    AQL::FROM       => $webAPI ,
    AQL::TO         => $permissions ,
    AQL::PURGE      => Purge::OUTBOUND ,   // supprimer un WebAPI purge ses Permission
]) ;

$webAPI->delete( [ AQL::VALUE => 'documents-api' ] ) ;
// 1) les arêtes api_has_permissions de 'documents-api' sont supprimées (couche 1)
// 2) les Permission ciblées par ces arêtes sont supprimées (couche 2, OUTBOUND)
```

> **Le sens est résolu au moment de la suppression.** `onDeleteVertex()` compare le `target` du signal (le sommet réellement supprimé) à `from` / `to`, puis n'applique la purge que dans le sens autorisé. Un `Purge::OUTBOUND` ne purge donc *jamais* les `from` quand c'est un `to` qui est supprimé.

> **La purge des sommets est récursive par construction.** Elle s'effectue via un `delete()` sur le modèle de l'autre bout — qui émet à son tour son propre `afterDelete`. Si ce modèle est lui-même la source d'autres arêtes en cascade, la suppression se propage. Attention aux **cycles** de purge (voir pièges).

## Exemple de bout en bout

Un compte (`accounts`) relié à des sessions (`sessions`) par des arêtes `account_has_session`, avec purge `OUTBOUND` : supprimer un compte doit effacer ses sessions.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Documents ;
use oihana\arango\models\Edges ;
use oihana\arango\models\enums\Purge ;
use oihana\models\notices\AfterDelete ;

$accounts = new Documents( $container , [ AQL::COLLECTION => 'accounts' , /* … */ ] ) ;
$sessions = new Documents( $container , [ AQL::COLLECTION => 'sessions' , /* … */ ] ) ;

$accountHasSession = new Edges( $container ,
[
    AQL::COLLECTION => 'account_has_session' ,
    AQL::FROM       => $accounts ,
    AQL::TO         => $sessions ,
    AQL::PURGE      => Purge::OUTBOUND ,
]) ;

// Observateur facultatif : auditer ce qui part.
$sessions->afterDelete?->connect(
    fn( AfterDelete $n ) => $logger->info( 'Sessions purgées : ' . json_encode( $n->data ) )
) ;

$accounts->delete( [ AQL::VALUE => 'acc-42' ] ) ;
// Effet :
//   • arêtes account_has_session de 'acc-42' supprimées      (couche 1)
//   • documents 'sessions' ciblés supprimés                  (couche 2, OUTBOUND)
//   • afterDelete de $sessions émis → l'observateur logge     (cascade observable)
```

## Invalider les caches dépendants (`Arango::INVALIDATES`)

Certains services gardent en mémoire un état **dérivé** d'une collection. Un thésaurus expose la liste de ses termes désactivés, que chaque listing doit exclure ; un annuaire expose les clés des locataires suspendus. Cet ensemble est petit, il bouge rarement, et une requête chaude en a besoin à chaque appel — le relire à chaque fois coûte une requête de plus par requête. On le résout donc **une fois** et on le garde.

Reste la question qui décide de tout : **qui prévient le cache quand la collection change ?** Sans réponse, un terme réactivé ce matin reste filtré jusqu'à l'expiration du TTL, et personne ne voit rien — la panne est silencieuse, ce qui est le pire genre.

La réponse habituelle consiste à décorer la fabrique du modèle, à la main, pour rebrancher `invalidate()` sur chaque écriture. C'est du copier-coller, et son unique mode de défaillance est précisément l'oubli. `Arango::INVALIDATES` le remplace par une **déclaration** sur le modèle : la liste des identifiants de conteneur des services que cette collection alimente.

La situation. Un modèle `terms` alimente le cache des termes désactivés. La déclaration se pose dans le `$init`, à côté des autres clés du modèle — il n'y a **rien d'autre à écrire** :

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;

$terms = new Documents( $container ,
[
    AQL::COLLECTION     => 'terms' ,
    Arango::INVALIDATES => [ 'thesaurus.disabledTerms' ] , // une chaîne seule est acceptée
]) ;

$terms->update( [ Arango::DOC => [ 'disabled' => false ] , Arango::VALUE => 'term-42' ] ) ;
// → afterUpdate émis → le conteneur résout 'thesaurus.disabledTerms' → invalidate()
//   La prochaine lecture du service reconstruit l'ensemble.
```

Le câblage est **automatique** : `Documents` compose `InvalidatesOnWriteTrait` et appelle `initializeInvalidations()` en dernier dans son constructeur — après `initializeDocumentsMethods()`, qui crée les signaux auxquels il se branche. `Edges` en hérite. Aucune sous-classe, aucun appel à placer.

Les **trois** signaux d'écriture sont branchés — `afterInsert`, `afterUpdate`, `afterDelete` — sur la même closure. Une insertion, une modification et une suppression comptent toutes comme « la collection a bougé » : un ensemble dérivé n'a aucune raison de survivre à l'une plus qu'à l'autre.

> **Le conteneur n'est interrogé qu'à l'émission, jamais au câblage.** C'est délibéré, et ce n'est pas un détail de performance : le service invalidé dépend en général du modèle qui l'invalide — `thesaurus.disabledTerms` lit la collection `terms`. Le résoudre dans le constructeur du modèle refermerait la boucle et ferait exploser le conteneur. Quand une écriture émet enfin le signal, le modèle est entièrement construit et la résolution est sûre.

### Le contrat `Invalidable`

Un service invalidable implémente [`oihana\interfaces\Invalidable`](https://github.com/BcommeBois/oihana-php-core/blob/main/src/oihana/interfaces/Invalidable.php), une seule méthode :

```php
interface Invalidable
{
    public function invalidate() : void ;
}
```

C'est tout le couplage. Le modèle qui invalide ne sait **pas** ce que le service cache, ni où, ni comment — seulement qu'il faut le lui faire oublier. Memcached, un tableau en mémoire ou un fichier : le producteur du changement n'a pas à en connaître un mot.

### `DocumentFieldSetResolver` — un ensemble de valeurs caché

La lib fournit l'implémentation la plus courante : [`DocumentFieldSetResolver`](../../src/oihana/arango/cache/DocumentFieldSetResolver.php) résout — et cache dans Memcached — **l'ensemble des valeurs prises par un champ** sur les documents d'une collection filtrée.

```php
use oihana\arango\cache\DocumentFieldSetResolver ;

$disabledTerms = new DocumentFieldSetResolver
(
    model    : $terms ,
    cache    : $memcached ,
    cacheKey : 'thesaurus.disabledTerms' ,  // requis, unique par résolveur
    filter   : [ 'key' => 'disabled' , 'op' => 'eq' , 'val' => true ] ,
    field    : 'id' ,                       // défaut : DocumentFieldSetResolver::DEFAULT_FIELD
    ttl      : 3600                         // défaut : 1 heure
) ;

$disabledTerms->values() ; // ['t-12', 't-40'] — dédoublonné, réindexé
```

Trois décisions de conception méritent d'être connues, parce qu'elles se retournent contre vous si vous les prenez à l'envers dans un résolveur maison.

**Le type natif de chaque valeur est préservé, jamais normalisé.** AQL ne convertit pas d'un type à l'autre : `5 NOT IN ["5"]` vaut **vrai**. Un ensemble dont on aurait « proprement » casté toutes les valeurs en chaînes ne filtrerait donc plus rien du tout — sans erreur, sans log, sans le moindre signe. Un `id` numérique reste un entier, et un code à zéro initial (`'0608'`) reste une chaîne.

**Une lecture qui échoue rend un ensemble vide.** Une base injoignable ne doit pas se traduire par « tout est exclu ». Un ensemble vide veut dire « rien ne correspond », et l'appelant doit alors ne poser **aucun** prédicat plutôt qu'un `NOT IN []` toujours vrai. L'échec est journalisé si un logger a été fourni, mais il ne remonte pas : une panne de cache ne fait pas tomber la requête.

**La clé de cache est requise, et unique par résolveur.** Deux résolveurs derrière une même clé se serviraient l'ensemble l'un de l'autre. Il n'y a pas de valeur par défaut, précisément pour qu'on ne puisse pas en hériter une par mégarde.

Le TTL, lui, n'est pas le mécanisme de rafraîchissement — c'est un **filet**. Le chemin normal reste l'invalidation par signal, immédiate ; le TTL borne la fraîcheur au cas où un point d'invalidation aurait été oublié. Un `ttl: 0` court-circuite complètement le cache et relit à chaque appel : pratique pour déboguer, ruineux en production.

> **Memcached est partagé par tous les workers**, donc une seule invalidation atteint toute la flotte — pas seulement le processus qui a reçu l'écriture.

### Ce qui est ignoré en silence

Le câblage est **tolérant** : une déclaration douteuse est sautée, jamais fatale. Une invalidation qui explose transformerait une écriture parfaitement valide en erreur 500.

| Déclaration | Effet |
|---|---|
| `Arango::INVALIDATES` absent, `null`, `[]` | aucun signal connecté — le modèle est intact |
| Valeur ni tableau ni chaîne (`42`, un objet…) | traitée comme absente |
| Chaîne seule (`'a.service'`) | acceptée, normalisée en `['a.service']` |
| Identifiant non-string dans le tableau | sauté ; le reste de la liste s'applique |
| Identifiant inconnu du conteneur | sauté ; le reste de la liste s'applique |
| Service résolu n'implémentant pas `Invalidable` | sauté ; le reste de la liste s'applique |

## Pièges & garanties

| Point | À retenir |
|---|---|
| **Purge `null` par défaut** | Sans `AQL::PURGE`, seules les arêtes sont retirées ; les sommets de l'autre bout restent. C'est le comportement sûr (fail-safe). |
| **La cascade part sur `delete()` d'un sommet** | Elle est branchée sur `afterDelete`. Un `update()` / `replace()` n'enclenche **aucune** cascade d'arêtes. |
| **Câblage = `from` / `to` fournis** | L'abonnement n'existe que si l'`Edges` connaît ses sommets (`AQL::FROM` / `AQL::TO`). Un `Edges` sans sommets ne purge rien automatiquement. |
| **Le `target` distingue le sens** | La purge dirigée s'appuie sur le sommet réellement supprimé. `OUTBOUND`/`INBOUND` sont donc respectés même si `from` et `to` pointent vers la même collection. |
| **Cycles de purge** | Une purge déclenche un `delete()` qui ré-émet `afterDelete`. Deux modèles qui se purgent mutuellement en `BOTH` peuvent boucler — déclarez la purge d'un seul côté, ou cassez le cycle. |
| **Performance** | La purge supprime en **masse** via une requête AQL `REMOVE` (pas de boucle PHP document par document). |
| **`?->` sur les signaux** | Les modèles initialisent leurs signaux, mais émettent toujours en `?->emit()` : si un signal était libéré (`release*Signals()`), l'émission est simplement ignorée, jamais une erreur. |
| **L'invalidation est câblée d'office** | `Documents` appelle `initializeInvalidations()` en fin de constructeur ; sans `Arango::INVALIDATES` déclaré, rien n'est connecté et le modèle est inchangé. |
| **L'invalidation ne suit pas la cascade** | Elle est branchée sur les écritures **du modèle qui la déclare**. Une purge de couche 2 émet l'`afterDelete` du modèle purgé : c'est *ce* modèle qui doit déclarer son propre `Arango::INVALIDATES`. |
| **Une invalidation ne fait jamais échouer l'écriture** | Identifiant inconnu, service non `Invalidable`, déclaration malformée : tout est sauté en silence. L'écriture, elle, était valide. |

## Voir aussi

- [Modèles `Documents` et `Edges`](models.md) — architecture des traits, clés `AQL::*`, section *Cycle de vie et hooks*.
- [Projection des edges et joins](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, traversées de lecture.
- [Champs-tableaux embarqués](db/arrays.md) — mutations atomiques et leurs signaux `*Update`.
- [Glossaire](getting-started/glossary.md#cascade) — entrées *Cascade* et *Signal*.
- [`DocumentFieldSetResolver`](../../src/oihana/arango/cache/DocumentFieldSetResolver.php) et [`InvalidatesOnWriteTrait`](../../src/oihana/arango/cache/InvalidatesOnWriteTrait.php) — les deux briques du namespace `oihana\arango\cache`.
- [Dépendances](getting-started/dependencies.md#oihanaphp-signals) — le rôle de `oihana/php-signals`.
- Amont : [Signals & notices (`oihana/php-models`)](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/fr/signals-notices.md) — primitives `Signal` / `Payload`, ajout de signaux à un modèle.
