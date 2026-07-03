# Skins

## Qu'est-ce qu'un *skin* ?

Un *skin* (« peau » en anglais) est une **variante de projection** d'une ressource : un même document peut être renvoyé sous plusieurs formes selon le besoin du consommateur. C'est le pendant en *sortie* du *payload* en *entrée* — au lieu de décrire ce que le client envoie, le *skin* décrit ce que le serveur renvoie.

Quelques exemples concrets sur une ressource `User` :

- *« sur une **liste** d'utilisateurs (`GET /users`), renvoie seulement `_key`, `email`, `name` et `roles[]` »* — c'est le *skin* `default` ;
- *« sur une **fiche** unique (`GET /users/{id}`), renvoie tout ça PLUS `permissions[]`, `sessions[]`, `activities[]` et la dernière connexion »* — c'est le *skin* `full` ;
- *« sur la projection **interne** consommée par un middleware serveur, ajoute `tokensInvalidBefore` qui ne doit JAMAIS sortir du serveur »* — c'est le *skin* `internal` ;
- *« sur un *autocomplete* de recherche, ne renvoie que `_key` + `name` »* — c'est typiquement un *skin* `compact` ou `list`.

Le client demande un *skin* précis via le paramètre URL `?skin=full`. Le contrôleur valide qu'il est autorisé puis le passe au modèle, qui filtre les champs et les relations à inclure dans la réponse.

## Pourquoi un système de *skins*

Sans *skin*, on a deux options médiocres :

1. **Renvoyer toujours tout** — coûteux en bande passante, en temps de requête (chaque jointure coûte), et expose des champs qu'on aurait préféré masquer par défaut.
2. **Démultiplier les endpoints** — `/users/list`, `/users/full`, `/users/compact`, `/users/with-roles`... ingérable dès qu'on a une douzaine de ressources.

Le système de *skins* du framework résout le problème avec **un seul endpoint** par ressource et une **projection paramétrée**. Les champs et relations sont annotés (`Field::SKINS`, `AQL::SKIN_FIELDS`) côté modèle pour déclarer dans quels *skins* ils apparaissent ; le contrôleur déclare quels *skins* sont acceptables sur quel verbe HTTP. Au *runtime*, le pipeline filtre automatiquement.

## De quoi parle cette page

Cette page documente la **couche contrôleur** du système de *skins* :

- Le **catalogue** des *skins* canoniques (`Skin::DEFAULT`, `Skin::FULL`, ...).
- Les **clés de configuration** dans la définition DI du contrôleur (`Arango::SKINS`, `Arango::SKIN_DEFAULT`, `Arango::SKIN_METHODS`).
- La **sélection au runtime** via `?skin=` et les hooks associés (`PrepareSkin` trait).
- Le **cas particulier `Skin::INTERNAL`** — projection serveur strictement non exposable HTTP.

Pour la **projection des champs** à proprement parler (comment `Field::SKINS` et `AQL::SKIN_FIELDS` filtrent les champs et les relations côté modèle), voir [La projection des champs](../projection.md). C'est la couche modèle, complémentaire à celle décrite ici.

## Catalogue des *skins* canoniques

L'enum [`oihana\controllers\enums\Skin`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/enums/Skin.php) fournit 12 valeurs canoniques. Les contrôleurs et modèles métier peuvent en réutiliser librement, et ajouter leurs propres *skins* via l'enum local (`Acme\enums\Skin` étend `SkinTrait`).

| `Skin::*` | Sémantique typique |
|---|---|
| `DEFAULT` | Projection compacte pour les listes (`GET /resource`). Champs scalaires + relations comptées (`rolesCount`), pas les relations hydratées. |
| `FULL` | Projection complète pour la fiche unique (`GET /resource/{id}`). Tous les champs + relations hydratées + sous-edges. |
| `MAIN` | Projection minimale d'un sous-document atteint par un *edge* — sert à **couper les cycles INBOUND** (voir [edges-joins-projection.md](../edges-joins-projection.md#couper-un-cycle-inbound-avec-aqlskin)). Sans `Field::SKINS`, aucun sous-edge n'est suivi. |
| `INTERNAL` | Projection **strictement serveur** : ajoute des champs sensibles consommés par les middlewares (par exemple `tokensInvalidBefore` pour la révocation). **Jamais** exposée HTTP — voir la section dédiée. |
| `COMPACT` | Variante `LIST` ultra-courte pour les *autocomplete* (`_key` + `name` typiquement). |
| `EXTEND` | Comme `FULL` mais ajoute des données dérivées coûteuses (statistiques, agrégats). |
| `LIST` | Synonyme courant de `DEFAULT` quand on veut être explicite. |
| `NORMAL` | Valeur catch-all rare, à éviter — préférer `DEFAULT`. |
| `AUDIOS` / `PHOTOS` / `VIDEOS` | *Skins* spécialisés pour les ressources médias — ne projettent que la sous-collection appropriée. |
| `MAP` | *Skin* géo — projette uniquement les coordonnées + identifiant. |

Les valeurs `AUDIOS`/`PHOTOS`/`VIDEOS`/`MAP` ont du sens uniquement sur les ressources qui les consomment. Sur une ressource `User`, déclarer `Arango::SKINS => [ Skin::PHOTOS ]` n'a aucun effet utile.

## Configuration côté contrôleur

Trois clés dans la définition DI du contrôleur, toutes consommées par le trait [`PrepareSkin`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/prepare/PrepareSkin.php) :

| Clé | Type | Rôle |
|---|---|---|
| `Arango::SKINS` | `string[]` | **Liste blanche** des *skins* acceptables sur ce contrôleur. Toute valeur `?skin=` hors de cette liste est silencieusement remplacée par le *skin* par défaut. |
| `Arango::SKIN_DEFAULT` | `string` | *Skin* appliqué quand `?skin=` est absent et que le verbe HTTP n'a pas de *skin* dédié dans `SKIN_METHODS`. |
| `Arango::SKIN_METHODS` | `array<string,string>` | *Skin* par défaut **différent selon le verbe HTTP**. Map `HttpMethod::list => Skin::DEFAULT`, `HttpMethod::get => Skin::FULL`, etc. |

Exemple type :

```php
use DI\Container ;
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\enums\Arango ;
use oihana\controllers\enums\Skin ;
use oihana\api\enums\HttpMethod ;

return
[
    Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
    [
        Arango::MODEL        => Models::USERS               ,
        Arango::SKINS        => [ Skin::DEFAULT , Skin::FULL ] ,
        Arango::SKIN_DEFAULT => Skin::DEFAULT                ,
        Arango::SKIN_METHODS =>
        [
            HttpMethod::list => Skin::DEFAULT ,
            HttpMethod::get  => Skin::FULL    ,
        ] ,
    ]) ,
] ;
```

Lecture :

- `GET /users` (verbe `list`) → *skin* `default` par défaut, ou `?skin=full` si le client le demande.
- `GET /users/{id}` (verbe `get`) → *skin* `full` par défaut, ou `?skin=default` si le client préfère.
- `GET /users?skin=internal` → silencieusement remplacé par `default` (pas dans `SKINS`).

## Configuration côté modèle

Le contrôleur ne décide que **quels *skins* sont acceptables** sur quelle route. Le **contenu réel** de chaque *skin* — quels champs apparaissent, lesquels disparaissent, quelles relations sont hydratées — est déclaré **côté modèle**, dans la même définition DI que celle qui configure la collection ArangoDB.

Deux clés permettent d'attacher un *skin* à un champ ou à une projection alternative :

### `Field::SKINS` — un champ visible dans certains *skins*

Sur un champ individuel de `AQL::FIELDS`, le marqueur `Field::SKINS` déclare la **liste des *skins* qui activent le champ**. Un champ sans marqueur est visible dans tous les *skins*. Un champ marqué `Skin::FULL` n'apparaît que dans la projection `full`. Le marqueur est honoré à **toute profondeur** : il peut aussi être posé sur un sous-champ imbriqué d'un `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP` — voir [`Field::SKINS` en profondeur](../projection.md#fieldskins-en-profondeur--sous-champs-imbriqués-filtermap--filterdocument--filterwrap).

```php
use oihana\arango\enums\AQL    ;
use oihana\arango\enums\Field  ;
use oihana\arango\enums\Filter ;
use Acme\enums\Skin       ;

Models::USERS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'users'           ,
    AQL::DATABASE   => Databases::ARANGO ,
    AQL::SCHEMA     => User::class       ,

    AQL::FIELDS =>
    [
        // Champs scalaires — visibles partout (pas de marqueur)
        Prop::_KEY      => Filter::DEFAULT  ,
        Prop::EMAIL     => Filter::DEFAULT  ,
        Prop::NAME      => Filter::DEFAULT  ,
        Prop::CREATED   => Filter::DATETIME ,

        // Comptes visibles uniquement en `default` (liste)
        Prop::ROLES_COUNT =>
        [
            Field::FILTER => Filter::EDGES_COUNT ,
            Field::SKINS  => [ Skin::DEFAULT ]   ,
        ] ,

        // Relations hydratées — visibles uniquement en `full` (fiche)
        Prop::ROLES =>
        [
            Field::FILTER => Filter::EDGES ,
            Field::SKINS  => [ Skin::FULL ] ,
        ] ,
        Prop::PERMISSIONS =>
        [
            Field::FILTER => Filter::EDGES ,
            Field::SKINS  => [ Skin::FULL ] ,
        ] ,

        // Champ sensible — visible uniquement en `internal` (serveur only)
        Prop::TOKENS_INVALID_BEFORE =>
        [
            Field::FILTER => Filter::DATETIME ,
            Field::SKINS  => [ Skin::INTERNAL ] ,
        ] ,
    ] ,
]) ,
```

Lecture :

- `GET /users` (*skin* `default`) renvoie `_key`, `email`, `name`, `created`, `rolesCount`. Pas de `roles[]` ni `permissions[]` (trop lourd pour une liste).
- `GET /users/{id}` (*skin* `full`) renvoie tout ce qui précède **sans** `rolesCount` (caché par le marqueur) **plus** `roles[]` et `permissions[]` hydratés.
- `usersModel->get([ Arango::SKIN => Skin::INTERNAL ])` côté serveur renvoie tout ce qui précède **plus** `tokensInvalidBefore`. Aucun client HTTP ne peut atteindre cette projection (cf. [`Skin::INTERNAL`](#le-cas-particulier-skininternal)).

Le marqueur accepte trois formes : tableau de *skins*, chaîne séparée par virgules (`'main,full'`), ou `null` (équivalent à pas de marqueur).

### `AQL::SKIN_FIELDS` — projections alternatives massives

Quand la projection diffère **largement** entre deux *skins* (champs ajoutés, relations différentes, sous-objets restructurés), poser des `Field::SKINS` partout devient illisible. La clé `AQL::SKIN_FIELDS` permet de déclarer plusieurs jeux de champs distincts, sélectionnés au *runtime* selon le *skin* :

```php
AQL::SKIN_FIELDS =>
[
    Skin::DEFAULT => [ /* projection liste plate */    ] ,
    Skin::FULL    => [ /* projection riche avec edges */ ] ,
    Skin::COMPACT => [ /* projection minimale */          ] ,
    '*'           => [ /* fallback générique          */ ] ,   // optionnel
] ,
```

La table est acceptée à **trois niveaux** : sur une définition d'*edge*/*join* (par exemple, un `Role` qui expose ses `permissions[]` uniquement dans la fiche utilisateur en `full`), à la **racine du modèle** (la liste légère vs la fiche complète, sans marqueurs champ par champ), et sur un **sous-champ structurel** `MAP`/`DOCUMENT`/`WRAP` (deux formes pour la même clé imbriquée). Voir [Projection des edges et joins — `AQL::SKIN_FIELDS`](../projection.md#projection-alternative-selon-le-skin--aqlskin_fields) pour la sémantique complète et les règles de résolution.

### Le contrat parallèle modèle ↔ contrôleur

Les deux couches travaillent en **paire stricte** :

| Couche | Responsabilité | Clé(s) DI |
|---|---|---|
| **Contrôleur** | Quels *skins* sont **acceptés** par l'URL et lequel par défaut par verbe. | `Arango::SKINS`, `Arango::SKIN_DEFAULT`, `Arango::SKIN_METHODS` |
| **Modèle** | Quels **champs et relations** apparaissent dans chaque *skin*. | `Field::SKINS` sur `AQL::FIELDS`, `AQL::SKIN_FIELDS` sur les *edges* / *joins*, la racine du modèle ou un sous-champ structurel |

Sans l'une des deux couches, le système ne fait rien : un contrôleur qui accepte `?skin=full` sans modèle qui change sa projection retourne toujours les mêmes champs ; un modèle riche en `Field::SKINS` sans contrôleur qui propage la valeur retourne toujours le *skin* par défaut.

## Sélection au runtime — `?skin=`

Le pipeline `PrepareSkin` s'exécute **avant** `beforeModelCall` et fixe `$init[ Arango::SKIN ]` à la valeur retenue :

```
HTTP request
  → PrepareSkin::prepareSkin($request, $init)
    1. Lit ?skin= depuis la query string
    2. Si absent → utilise SKIN_METHODS[$verbe] ou SKIN_DEFAULT
    3. Si présent mais hors SKINS → utilise le défaut (silencieux)
    4. Écrit la valeur retenue dans $init[ Arango::SKIN ]
  → beforeModelCall($request, &$init)
  → model->get/list/...($init)
    Le modèle filtre les Field::SKINS sur cette valeur
  → response
```

Côté modèle, la valeur de *skin* est propagée à `returnFields` et `buildVariables` qui appliquent `Field::SKINS` et `AQL::SKIN_FIELDS`. Tout ça est documenté en détail dans [La projection des champs](../projection.md).

## Le cas particulier `Skin::INTERNAL`

`Skin::INTERNAL` est une **projection strictement serveur**. Elle expose des champs sensibles consommés par les middlewares (par exemple `tokensInvalidBefore` pour la révocation de session, le hash SHA-256 du code de vérification d'un changement d'email pending, etc.) mais qui ne doivent **jamais** transiter par HTTP, même pour un superadmin.

### Règle d'or

`Skin::INTERNAL` **ne doit jamais** être présent dans `Arango::SKINS` d'un contrôleur, ni avoir une permission Casbin associée.

```php
// Mauvais — autorise ?skin=internal côté URL
Arango::SKINS => [ Skin::DEFAULT , Skin::FULL , Skin::INTERNAL ] ,
```

La garantie de sécurité repose sur **un seul invariant** : tant que `INTERNAL` n'est pas listé dans `Arango::SKINS`, le filtre `PrepareSkin::isValidSkin` rejette `?skin=internal` et tombe sur la projection par défaut. Aucun appelant HTTP ne peut donc forcer cette projection.

**Pas de permission Casbin non plus, par construction.** Si on créait par exemple `users:skin.internal`, un superadmin pourrait l'attribuer à un compte via `POST /users/{id}/permissions/{permKey}` et casser l'invariant en une seule requête.

### Usage côté serveur

Les middlewares serveur appellent le modèle directement, **en court-circuitant la couche HTTP** :

```php
$user = $this->usersModel->get
([
    Arango::ID   => $userKey      ,
    Arango::SKIN => Skin::INTERNAL ,
]) ;
```

Le framework de *capabilities* ([capabilities.md](capabilities.md)) vit sur la couche contrôleur HTTP, **pas** sur le modèle. Les appels directs au modèle ne sont donc pas restreints — ils restent de confiance parce qu'ils proviennent du code PHP serveur et non d'un *input* utilisateur.

Pour le détail complet du pattern et la liste des champs `INTERNAL` aujourd'hui en place, voir [Tips et pièges — `Skin::INTERNAL`](../tips.md#skininternal--projection-serveur-uniquement).

## Exemple complet — choisir les *skins* d'une ressource

Définition complète pour la ressource `users`, avec un *skin* métier ajouté en plus des canoniques :

```php
use Acme\enums\Skin ;     // étend SkinTrait — expose les valeurs métier

Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
[
    Arango::MODEL => Models::USERS ,

    Arango::SKINS =>
    [
        Skin::DEFAULT  ,   // liste compacte
        Skin::FULL     ,   // fiche complète avec roles + permissions
        Skin::COMPACT  ,   // autocomplete (_key + email + name)
    ] ,

    Arango::SKIN_DEFAULT => Skin::DEFAULT ,

    Arango::SKIN_METHODS =>
    [
        HttpMethod::list => Skin::DEFAULT ,
        HttpMethod::get  => Skin::FULL    ,
    ] ,
]) ,
```

Côté modèle, la projection effective de chaque *skin* est contrôlée par `Field::SKINS` sur les champs et `AQL::SKIN_FIELDS` sur les *edges* — cf. [La projection des champs](../projection.md).

## Voir aussi

- [Vue d'ensemble des contrôleurs](README.md) — pipeline complet, hooks de cycle de vie.
- [La projection des champs](../projection.md) — couche modèle : `Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::SKIN`.
- [Tips et pièges — `Skin::INTERNAL`](../tips.md#skininternal--projection-serveur-uniquement) — règle d'or détaillée + cas d'usage actuels.
- [Capabilities](capabilities.md) — système orthogonal qui peut restreindre la **valeur** d'un *skin* à une permission Casbin (`Capability::PARAMS`).
- [Référence des enums](../enums.md) — `Skin`, `Arango::*`.
