# Rules

## Qu'est-ce qu'une *rule* ?

Une *rule* (« règle » de validation) est une **contrainte appliquée à une valeur** pour vérifier qu'elle respecte ce qu'on attend d'elle avant de l'écrire en base. Quelques exemples concrets :

- *« le champ `name` est obligatoire, doit faire entre 2 et 70 caractères, et ne peut contenir que des minuscules ASCII, des chiffres, espaces, tirets et underscores »* ;
- *« le champ `color` doit être une chaîne au format `#RRGGBB` »* ;
- *« le champ `description` doit être un objet i18n typé (`{ fr: "...", en: "..." }`), pas une chaîne plate »* ;
- *« le champ `level` doit être un entier compris entre 0 et 9000 »*.

Le framework `oihana/php-arango` exécute ces contraintes **après** la préparation du payload (cf. [Payloads](payloads.md)) et **avant** l'écriture en base. Si une seule règle échoue, la requête est rejetée avec un code HTTP `422 Unprocessable Entity` et un tableau d'erreurs détaillé par champ — aucune donnée invalide n'atteint la collection.

## Pourquoi une couche dédiée

Sans cadre, la validation ressemble vite à un patchwork de `if`/`throw` éparpillé dans chaque verbe HTTP de chaque contrôleur :

```php
// À ne pas faire — verbeux, asymétrique, facile à oublier en PATCH
public function post( ... ) : mixed
{
    $name = $payload[ 'name' ] ?? null ;
    if ( $name === null )                   throw new ValidationException( 'name required'   ) ;
    if ( strlen( $name ) < 2 )              throw new ValidationException( 'name too short'  ) ;
    if ( strlen( $name ) > 70 )             throw new ValidationException( 'name too long'   ) ;
    if ( !preg_match( '/^[a-z]+$/' , $name ) ) throw new ValidationException( 'name invalid' ) ;
    // ... idem pour color, description, level
}
```

Le framework remplace ce code par une **déclaration** dans la définition DI du contrôleur. Les avantages :

- **Toutes les règles d'un endpoint sont visibles en un seul endroit** (la définition `Arango::RULES`), pas dispersées dans le contrôleur.
- **Toutes les erreurs sont collectées en un seul passage**, pas remontées en cascade au premier `throw`. La réponse 422 liste **tous** les problèmes pour que le client puisse corriger d'un coup.
- **Le même catalogue de règles est réutilisable** entre les verbes (`POST`, `PATCH`, `PUT`) avec des différences ciblées (`required` uniquement sur POST, par exemple).
- **Les règles applicables au métier** (un nom de rôle doit matcher une regex précise) vivent dans des classes dédiées et testables (`RoleNameRule`, `ColorRule`...) plutôt qu'en *inline* dans les contrôleurs.

## De quoi parle cette page

Le framework expose **deux clés** dans la définition DI :

- [`Arango::RULES`](../enums.md) — les règles de validation par champ et par méthode HTTP, exprimées avec les *helpers* de la bibliothèque Somnambulist (`rules()`, `min()`, `max()`, `between()`, etc.).
- [`Arango::CUSTOM_RULES`](../enums.md) — un mapping `champ → identifiant DI` pour brancher des classes de *Rule* custom du projet (`ColorRule`, `RoleNameRule`, `Iso8601DateOrDurationRule`, `I18nRule`...).

On documente ici :

- La **position** des règles dans le pipeline du contrôleur (après le payload, avant le modèle).
- Le **format** des deux clés et le pattern d'activation des *custom rules*.
- Le **catalogue vendor** `Rules::*` (les règles standard fournies par Somnambulist).
- Le **catalogue projet** des `CustomRules::*` (les règles métier propres à l'application hôte).
- Le **format d'erreur 422** renvoyé au client.

## Position dans le pipeline

```
HTTP body
  → enforceI18nShape($init)              shape i18n  (cf. payloads.md)
  → preparePayload($init, &$relations)   extraction  (cf. payloads.md)
  → prepareRules($method)                fusion ALL + méthode courante
  → validator->validate($payload, $rules)  VALIDATION (cette page)
  → 422 si erreurs  OU
  → beforeModelCall($request, &$init)    hook utilisateur
  → model->insert/update(...)            écriture AQL
  → afterModelCall(...)                  hook utilisateur
  → response
```

Le validateur consomme **le payload déjà préparé** (typé, *whitelisté*, normalisé). Les règles s'appliquent donc à une donnée propre — `min(2)` sur un `string` extrait, pas sur une chaîne JSON brute encore non décodée.

## La clé `Arango::RULES`

La clé `Arango::RULES` accepte un tableau **double-indexé** : par méthode HTTP, puis par nom de champ. Même format que [`Arango::PAYLOAD`](payloads.md#format-de-définition-arangopayload) — la cohérence est volontaire.

```php
use function oihana\validations\rules\helpers\rules ;
use function oihana\validations\rules\helpers\min   ;
use function oihana\validations\rules\helpers\max   ;
use function oihana\validations\rules\helpers\between ;
use oihana\validations\enums\Rules ;

Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        Prop::NAME  => rules( min(2) , max(70) ) ,
        Prop::LEVEL => rules( Rules::INTEGER , between( 0 , 9000 ) ) ,
    ] ,
    HttpMethod::POST =>
    [
        Prop::NAME => rules( Rules::REQUIRED , min(2) , max(70) ) ,
    ] ,
] ,
```

**Règle de fusion** : `prepareRules($method)` fait `array_merge(rules[ALL], rules[$method])`. Sur `POST`, la définition `NAME` du bloc `POST` **remplace entièrement** celle du bloc `ALL`. Il faut donc répéter `min(2)` et `max(70)` dans le bloc `POST` si on veut conserver ces contraintes en plus du `required`.

**Pourquoi `required` n'est pas dans `ALL`** : sur `PATCH`, l'absence d'un champ signifie « ne pas le toucher ». Mettre `required` dans `ALL` casserait toute mise à jour partielle.

### Helpers de composition

| Helper | Signature | Sortie |
|---|---|---|
| `rules` | `rules( ...$rules ) : string` | Concatène plusieurs règles avec le séparateur `\|` attendu par Somnambulist. |
| `min` | `min( int\|float $value ) : string` | `'min:2'` |
| `max` | `max( int\|float $value ) : string` | `'max:70'` |
| `between` | `between( int\|float $min , int\|float $max ) : string` | `'between:0,9000'` |

Les autres règles s'écrivent soit comme constantes (`Rules::REQUIRED` = `'required'`, `Rules::INTEGER` = `'integer'`, ...), soit comme chaînes paramétrées (`'in:foo,bar,baz'`, `'regex:/^[a-z]+$/'`, ...).

## Le pattern « final tag » — activer une *custom rule*

Au cœur du système, un détail très important : si le **dernier élément** passé à `rules()` est un nom de champ qui matche une clé de `Arango::CUSTOM_RULES`, la *custom rule* correspondante est ajoutée à la chaîne. C'est ce qui branche les classes `ColorRule`, `RoleNameRule`, etc.

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR => CustomRules::COLOR ,         // 'rules:color' (identifiant DI)
    Prop::NAME  => CustomRules::ROLE_NAME ,     // 'rules:auth:role:name'
] ,
Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        // Le dernier `Prop::NAME` est un tag qui active CustomRules::ROLE_NAME
        Prop::NAME  => rules( min(2) , max(70) , Prop::NAME ) ,
        // Idem pour color
        Prop::COLOR => rules( Rules::STRING , max(7) , Prop::COLOR ) ,
    ] ,
] ,
```

Le pattern est parfois condensé quand on n'a **que** la custom rule à appliquer :

```php
HttpMethod::ALL =>
[
    Prop::DESCRIPTION => Prop::DESCRIPTION ,    // active CustomRules::I18N, rien d'autre
] ,
```

## La clé `Arango::CUSTOM_RULES`

Elle mappe un nom de champ vers un **identifiant DI** de classe Rule, déclarée séparément dans `api/definitions/@api/rules/`. Le validateur résout chaque identifiant via le conteneur au moment de la validation.

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR       => CustomRules::COLOR ,
    Prop::DESCRIPTION => CustomRules::I18N  ,
    Prop::NAME        => CustomRules::ROLE_NAME ,
] ,
```

Côté définition de la Rule (extrait simplifié) :

```php
// api/definitions/@api/rules/auth.php
return
[
    CustomRules::ROLE_NAME => fn() => new RoleNameRule() ,
    // ...
] ;
```

Chaque classe Rule étend `Somnambulist\Components\Validation\Rule` et expose une méthode `check(mixed $value) : bool`.

## Catalogue vendor `Rules::*`

L'enum [`oihana\validations\enums\Rules`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/validations/enums/Rules.php) liste toutes les règles standard fournies par la bibliothèque Somnambulist. Les plus courantes :

| Constante | Effet |
|---|---|
| `Rules::REQUIRED` | Le champ doit être présent et non vide. |
| `Rules::STRING` | Doit être une chaîne. |
| `Rules::INTEGER` | Doit être un entier. |
| `Rules::NUMERIC` | Doit être numérique (int ou float). |
| `Rules::BOOLEAN` | Doit être un booléen (ou *truthy*/*falsy* selon le mode). |
| `Rules::ARRAY` | Doit être un tableau. |
| `Rules::DATE` | Doit être une date parsable. |
| `Rules::EMAIL` | Format email. |
| `Rules::URL` | URL valide. |
| `Rules::IP` | Adresse IP valide. |
| `Rules::ALPHA` | Caractères alphabétiques uniquement. |
| `Rules::ALPHA_NUM` | Alphanumériques uniquement. |
| `Rules::ALPHA_DASH` | Alphanumériques + tiret + underscore. |
| `Rules::ALPHA_SPACES` | Alphanumériques + espace. |
| `Rules::IN` | Valeur parmi une liste fermée (`in:foo,bar,baz`). |
| `Rules::NOT_IN` | Valeur hors d'une liste fermée. |
| `Rules::REGEX` | Match d'une *regex*. |
| `Rules::ACCEPTED` | Valeur d'acceptation (`yes`, `on`, `1`, `true`). |
| `Rules::DIFFERENT` | Différente d'un autre champ. |
| `Rules::SAME` | Identique à un autre champ. |
| `Rules::ANY_OF` | Au moins une règle parmi un groupe. |
| `Rules::CALLBACK` | Validation par *callback* PHP. |
| `Rules::COLOR` | Format couleur (utilisée en interne par `ColorRule`). |
| `Rules::DEFAULT` | Valeur par défaut si absent. |
| `Rules::DIGITS` | Chiffres uniquement, longueur exacte. |

L'enum complet contient une cinquantaine de constantes. Voir la [doc Somnambulist](https://github.com/somnambulist-projects/validation) pour le détail de la syntaxe paramétrée (`min:N`, `between:A,B`, `regex:/pattern/`, ...).

## Catalogue des `CustomRules::*` du projet

L'enum `Acme\enums\CustomRules` liste les règles métier propres à l'application hôte. Toutes étendent `Somnambulist\Components\Validation\Rule` et sont enregistrées dans le conteneur DI.

| Constante | Identifiant DI | Classe sous-jacente | Rôle |
|---|---|---|---|
| `CustomRules::COLOR` | `rules:color` | `ColorRule` | Valide la forme `#RRGGBB` (regex hex). |
| `CustomRules::I18N` | `rules:i18n` | `I18nRule` | Valide qu'un objet a la forme `{ fr: "...", en: "..." }` avec des codes langues autorisés. |
| `CustomRules::ROLE_NAME` | `rules:auth:role:name` | `RoleNameRule` | Valide la grammaire canonique d'un nom de rôle : `[a-z0-9 _-]{2,70}`. |
| `CustomRules::ISO_DATE_OR_DURATION` | `rules:iso8601:date:or:duration` | `Iso8601DateOrDurationRule` | Valide une date ou une durée ISO 8601 (`2026-05-18` ou `P30D`). |
| `CustomRules::POSTAL_CODE` | `rules:postal:code` | `PostalCodeRule` | Valide un code postal selon le pays. |
| `CustomRules::GREATER_THAN` | `rules:greater:than` | `GreaterThanRule` | Comparaison stricte avec un seuil. |
| `CustomRules::GREATER_THAN_OR_EQUAL` | `rules:greater:than:or:equal` | `GreaterThanOrEqualRule` | Idem, comparaison ≥. |
| `CustomRules::LESS_THAN` | `rules:less:than` | `LessThanRule` | Comparaison stricte avec un plafond. |
| `CustomRules::LESS_THAN_OR_EQUAL` | `rules:less:than:or:equal` | `LessThanOrEqualRule` | Idem, comparaison ≤. |
| `CustomRules::EQUAL` | `rules:equal` | `EqualRule` | Égalité stricte avec une valeur de référence. |
| `CustomRules::HTTP_METHOD` | `rules:http:method` | `HttpMethodRule` | Valide un verbe HTTP (`GET`, `POST`, `PATCH`, ...). |
| `CustomRules::LATITUDE` | `rules:geo:latitude` | rule géo | Latitude (`-90..+90`). |
| `CustomRules::LONGITUDE` | `rules:geo:longitude` | rule géo | Longitude (`-180..+180`). |
| `CustomRules::ELEVATION` | `rules:geo:elevation` | rule géo | Altitude raisonnable. |
| `CustomRules::APIS_HAS_API` | `rules:apis:has:api` | rule métier | Vérifie qu'une API référencée existe en base. |
| `CustomRules::API_HAS_IDENTIFIER` | `rules:api:has:identifier` | rule métier | Vérifie qu'une API a un identifiant déclaré. |
| `CustomRules::API_HAS_UNIQUE_IDENTIFIER` | `rules:api:has:unique:identifier` | rule métier | Vérifie l'unicité d'un identifiant d'API. |
| `CustomRules::USERS_HAS_USER` | `rules:users:has:user` | rule métier | Vérifie qu'un user référencé existe en base. |

> Note : les rules avec préfixe `rules:apis:`, `rules:api:`, `rules:users:` effectuent des **lookups en base** au moment de la validation. À utiliser avec parcimonie sur les endpoints à fort trafic (chaque insertion coûte un AQL `EXIST` supplémentaire).

### Écrire une rule custom

```php
namespace Acme\rules ;

use Somnambulist\Components\Validation\Rule ;

class MyCustomRule extends Rule
{
    protected string $message = ':attribute must be a valid foo bar' ;

    public function check( mixed $value ) : bool
    {
        return is_string( $value ) && preg_match( '/^foo-[a-z0-9]+-bar$/' , $value ) === 1 ;
    }
}
```

Déclaration DI :

```php
CustomRules::MY_CUSTOM => fn() => new MyCustomRule() ,
```

Activation dans une définition de contrôleur :

```php
Arango::CUSTOM_RULES =>
[
    Prop::SOMETHING => CustomRules::MY_CUSTOM ,
] ,
Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        Prop::SOMETHING => rules( Rules::REQUIRED , Prop::SOMETHING ) ,
    ] ,
] ,
```

## Format d'erreur 422

Quand au moins une règle échoue, le validateur collecte **toutes** les erreurs (pas seulement la première) et le contrôleur renvoie une réponse `422 Unprocessable Entity` avec ce *body* JSON :

```json
{
    "status": "error",
    "code":   422,
    "result":
    {
        "errors":
        {
            "name":  "name must be a valid name (required, min:2, max:70)",
            "color": "color must be a valid color expression, ex: #ff0000"
        }
    }
}
```

Conventions :

- **Une clé par champ** invalide. Les champs valides ne sont pas listés.
- **Une chaîne de description** plutôt qu'une liste, même quand plusieurs règles ont échoué — le message est composé pour rester lisible.
- **Pas de leak technique** — la chaîne ne contient pas de regex brute ni de nom de classe Rule. Si un message doit être très précis (cas géo, cas métier), c'est à la Rule de fournir sa propre `$message` claire.

Côté client, le pattern recommandé : afficher chaque erreur à côté du champ correspondant dans le formulaire, sans relancer la requête tant que toutes les erreurs ne sont pas résolues.

## Exemple complet — `roles.php`

Définition annotée complète pour la collection `roles`, qui combine RULES + CUSTOM_RULES :

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR       => CustomRules::COLOR     ,   // valide #RRGGBB via ColorRule
    Prop::DESCRIPTION => CustomRules::I18N      ,   // valide la forme i18n (clés langues + valeurs string|null)
    Prop::NAME        => CustomRules::ROLE_NAME ,   // valide la grammaire `[a-z0-9 _-]{2,70}` via RoleNameRule
] ,

Arango::RULES =>
[
    // Bloc ALL : contraintes appliquées sur tout verbe. PAS de `required` ici —
    // un PATCH partiel doit pouvoir omettre un champ sans planter.
    HttpMethod::ALL =>
    [
        Prop::NAME        => rules( min(2) , max(70) , Prop::NAME ) ,                       // tag final active ROLE_NAME
        Prop::COLOR       => rules( Rules::STRING , max(7) , Prop::COLOR ) ,                // tag final active COLOR
        Prop::DESCRIPTION => Prop::DESCRIPTION ,                                            // tag seul active I18N
        Prop::LEVEL       => rules( Rules::INTEGER , between( $minLevel , $maxLevel ) ) ,   // pas de custom rule
    ] ,

    // Bloc POST : ajoute `required` sur les champs obligatoires à la création.
    // L'array_merge écrase NAME → on doit réécrire min/max si on les veut aussi.
    HttpMethod::POST =>
    [
        Prop::NAME => rules( Rules::REQUIRED , min(2) , max(70) , Prop::NAME ) ,
    ] ,

    HttpMethod::PATCH => [] ,                                                                // rien à ajouter à ALL
] ,
```

Comportement :

- `POST /roles { "name": "ED" }` → 422 : `name` trop court (`min:2` accepte 2, mais ROLE_NAME exige minuscule donc `'ED'` viole la regex).
- `POST /roles { }` → 422 : `name` *required*.
- `PATCH /roles/{id} { "name": "editor" }` → OK : `required` absent en PATCH, regex respectée.
- `PATCH /roles/{id} { "level": 9001 }` → 422 : `level` hors *range* `[0, 9000]`.
- `POST /roles { "name": "editor", "color": "purple" }` → 422 : `color` invalide (`max:7` accepte, mais ColorRule exige `#RRGGBB`).

## Voir aussi

- [Payloads](payloads.md) — la couche d'extraction qui produit la donnée validée par les rules.
- [Vue d'ensemble des contrôleurs](README.md) — pipeline complet, hooks, traits d'injection.
- [Référence des enums](../enums.md) — `Arango::*`, `HttpMethod::*`.
- [Tips et pièges](../tips.md) — règles d'or transverses.
- [Documentation Somnambulist](https://github.com/somnambulist-projects/validation) — bibliothèque de validation sous-jacente.
