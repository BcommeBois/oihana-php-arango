# La projection des champs AQL

Cette page décrit la couche **projection** : quels champs sortent dans la réponse, pour quel skin, avec quelles permissions et quelles transformations. Pour les **relations** — suivre une arête (edge), résoudre une référence stockée (join), traverser une hiérarchie, envelopper un résultat — voir [Projection des edges et joins](edges-joins-projection.md) : les deux couches se combinent, les mécanismes décrits ici s'appliquent aussi aux projections des relations.

## Sommaire

1. [Vue d'ensemble](#vue-densemble)
2. [Le marqueur `Field::SKINS` au niveau document](#le-marqueur-fieldskins-au-niveau-document)
3. [Projection variable selon le skin de la requête — `Field::SKINS` sur les sous-champs](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs)
4. [Projection alternative selon le skin — `AQL::SKIN_FIELDS`](#projection-alternative-selon-le-skin--aqlskin_fields)
5. [Quel mécanisme choisir ?](#quel-mécanisme-choisir-)
6. [Restreindre la projection à une permission — `AQL::REQUIRES`](#restreindre-la-projection-dun-edge-ou-dun-join-à-une-permission--aqlrequires)
7. [Transformer la valeur projetée — `Field::ALTERS`](#transformer-la-valeur-projetée--fieldalters)
8. [Référence interne — la fonction `matchesSkin`](#référence-interne--la-fonction-matchesskin)

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

### Au niveau du modèle — une projection par skin pour la racine

**La situation.** La liste `GET /products` doit rester légère (deux champs suffisent pour l'affichage en grille) ; la fiche `GET /products/{id}?skin=full` doit tout sortir. Avec les seuls marqueurs `Field::SKINS`, il faudrait annoter chaque champ un par un — illisible dès que les deux projections divergent vraiment. La même table `skin => projection` est acceptée **à la racine du modèle**, à côté (ou à la place) de `AQL::FIELDS` :

```php
Models::PRODUCTS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION  => 'products' ,
    AQL::SKIN_FIELDS =>
    [
        // La liste : deux champs, rien d'autre
        Skin::DEFAULT =>
        [
            Prop::_KEY => Filter::DEFAULT ,
            Prop::NAME => Filter::DEFAULT ,
        ] ,

        // La fiche : les mêmes + la description et la grille de prix
        Skin::FULL =>
        [
            Prop::_KEY        => Filter::DEFAULT ,
            Prop::NAME        => Filter::DEFAULT ,
            Prop::DESCRIPTION => Filter::TRANSLATE ,
            'offers'          => [ Field::FILTER => Filter::MAP , Field::FIELDS => [ 'price' => Filter::DEFAULT ] ] ,
        ] ,
    ] ,
    // AQL::FIELDS reste possible à côté : projection unique, utilisée quand aucun bucket ne correspond
])
```

`GET /products` renvoie `{ _key, name }` par produit ; `GET /products/{id}?skin=full` renvoie la fiche complète. L'ordre de résolution est **le même que pour les edges/joins** : `[$skin]` → `['*']` → `AQL::FIELDS` → rien. Deux comportements à connaître :

- **un bucket vide** (`Skin::X => []`) se lit « aucune projection pour ce skin » → le document **entier** est renvoyé, exactement comme une cible d'edge sans projection ;
- **sans registre**, rien ne change : `AQL::FIELDS` seul se comporte comme avant, au byte près.

**L'héritage par les relations.** Une edge ou un join qui ne déclare **aucune** projection prépare les champs du modèle cible avec le skin de la requête — il hérite donc automatiquement des buckets du modèle. Concrètement : si le modèle `roles` déclare ses deux projections dans son propre `AQL::SKIN_FIELDS`, chaque edge `user_has_roles` qui pointe vers lui sort la version du skin courant **sans rien déclarer** sur la définition d'edge. Une seule déclaration, côté modèle, rayonne partout.

### Sur un sous-champ structurel — deux formes pour la même clé

**La situation.** Reprenons la grille de prix. Le marqueur [`Field::SKINS` imbriqué](#fieldskins-en-profondeur--sous-champs-imbriqués-filtermap--filterdocument--filterwrap) sait **montrer ou cacher** un sous-champ selon le skin — mais il ne sait pas donner **deux formes différentes à la même clé** (une clé est unique dans une map, on ne peut pas déclarer deux `offers`). La table par skin le permet, posée sur le sous-champ `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP` lui-même :

```php
'offers' =>
[
    Field::FILTER    => Filter::MAP ,
    AQL::SKIN_FIELDS =>
    [
        // Version publique : le prix, rien d'autre
        Skin::DEFAULT => [ 'price' => Filter::DEFAULT ] ,

        // Version gestionnaire : le prix + sa décomposition
        Skin::FULL    =>
        [
            'price'              => Filter::DEFAULT ,
            'priceSpecification' => [ Field::FILTER => Filter::DOCUMENT , Field::FIELDS => [ 'basePrice' => Filter::DEFAULT , 'taxes' => Filter::DEFAULT ] ] ,
        ] ,
    ] ,
]
```

Le public reçoit `offers: [ { "price": 100 }, … ]` ; le skin `full` reçoit en plus la décomposition, **structurée autrement** — la même clé `offers`, deux formes. Tout se cumule dans l'ordre habituel : le bucket est choisi par le skin, puis les marqueurs `Field::SKINS` filtrent *à l'intérieur* du bucket, puis les verrous `REQUIRES` s'appliquent sur ce qui reste.

**La règle du « rien pour ce skin ».** Si la table déclarée ne résout rien pour le skin demandé — pas de bucket à son nom, pas de `'*'`, pas de `Field::FIELDS` à côté, ou un bucket explicitement vide — le sous-champ **disparaît** de la projection (clé absente). La déclaration se lit « je n'ai rien prévu pour ce skin ». Jamais de repli sur le sous-document brut (qui fuirait justement ce qu'on voulait cacher), jamais d'exception. Exemple : la table ci-dessus sans son bucket `Skin::DEFAULT` → en skin `default`, la clé `offers` n'apparaît tout simplement pas.

### Portée de `AQL::SKIN_FIELDS`

Deux points à connaître :

- La clé est lue à **trois niveaux** : les définitions d'edges/joins (ré-évaluée à chaque niveau d'imbrication des relations), la **racine du modèle**, et les **sous-champs structurels** (`Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`). Posée ailleurs — par exemple sur un champ scalaire — elle est ignorée en silence ; pour montrer/cacher un champ selon le skin, le mécanisme reste [`Field::SKINS`](#projection-variable-selon-le-skin-de-la-requête--fieldskins-sur-les-sous-champs).
- Un skin pinné via `AQL::SKIN` ne vaut que pour la définition qui le porte : ses sous-relations (`AQL::EDGES` / `AQL::JOINS` imbriqués) retombent sur le skin de la requête, sauf pin explicite sur leur propre définition.

## Quel mécanisme choisir ?

| Besoin | Solution recommandée |
|---|---|
| Une seule projection, peu importe le skin | `AQL::FIELDS` seul |
| Quelques sous-champs varient entre skins (count caché en full, edge caché en default…) | `Field::SKINS` posé sur les sous-champs de `AQL::FIELDS` |
| Un sous-champ **imbriqué** (grille de prix, sous-objet d'un MAP/DOCUMENT/WRAP) ne doit sortir que dans certains skins | `Field::SKINS` posé sur le sous-champ imbriqué — honoré à toute profondeur |
| La projection diffère largement entre skins (champs ajoutés, joins changés…) | `AQL::SKIN_FIELDS` avec une entrée par skin — sur la définition d'edge/join, la racine du modèle ou un sous-champ MAP/DOCUMENT/WRAP |
| La même clé imbriquée doit avoir **deux formes** selon le skin (grille minimale vs décomposée) | `AQL::SKIN_FIELDS` posé sur le sous-champ structurel |
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
