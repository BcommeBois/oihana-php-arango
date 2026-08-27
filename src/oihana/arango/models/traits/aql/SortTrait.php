<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\Search;
use oihana\controllers\traits\DefaultLangTrait;

use oihana\enums\Char;
use oihana\enums\Order;

use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use oihana\traits\SortDefaultTrait;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\geo\distance;
use function oihana\arango\db\functions\search\bm25;
use function oihana\arango\db\functions\notNull;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\assertLanguageCode;
use function oihana\arango\db\helpers\resolveGeoPoint;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\arango\models\helpers\isPathAuthorized;
use function oihana\arango\models\helpers\normalizeSortable;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Turns the textual `?sort=` grammar into an AQL `SORT` expression, and powers
 * distance ordering via the `?near=` anchor.
 *
 * ### `?sort=` grammar
 * A comma-separated list of keys; a leading `-` flips a key to descending. Each
 * key is resolved through the model's {@see AQL::SORTABLE} whitelist (URL key →
 * AQL field path); a key outside the whitelist is silently dropped. The gate is
 * **fail-closed**: when a model declares no whitelist (`$sortable === null`),
 * nothing sorts — a client key never reaches `doc.<key>`. When no `?sort=` is
 * given, the model's `SORT_DEFAULT` applies, and it too must name whitelisted
 * keys (it flows through the same gate). The synthetic `distance` / `score`
 * keys are the exception — they are driven by `?near=` / a View search and are
 * resolved upstream of the whitelist, so they sort even without a `SORTABLE`.
 * ```
 * ?sort=name,-created   // SORT doc.name ASC, doc.created DESC
 * ```
 *
 * ### Permission gate
 * A whitelisted key can still be **permission-gated**, so a field hidden from the
 * projection stays untriable (no sort oracle). The gate is resolved by
 * {@see authorizeSortKey()} — inherited from the projection at the **resolved
 * field path** (`address.salary`, gated at its exact sub-field via
 * {@see isPathAuthorized()}, like groupBy/bounds), or declared explicitly on the
 * `$sortable` entry:
 * ```php
 * // Inherited: `salary` is gated in $fields → its sort inherits the same subject.
 * AQL::SORTABLE => [ Prop::NAME , Prop::SALARY ]
 *
 * // Explicit: a sortable-only field (absent from the projection) carries its own gate.
 * AQL::SORTABLE => [ Prop::NAME , 'rank' => [ Field::PATH => 'internal.rank' , Field::REQUIRES => 'staff:read' ] ]
 * ```
 * A denied key drops its criterion; no subject (or no authorizer injected) sorts freely.
 *
 * ### `AQL::SORTABLE` notations
 * The whitelist is normalised by {@see normalizeSortable()} into the canonical
 * `urlKey => fieldPath` map; three forms are accepted and may be mixed:
 * ```php
 * // Indexed shorthand — token equals field (the common case, no redundant map):
 * AQL::SORTABLE => [ Prop::_FROM , Prop::_TO , Prop::CREATED , Prop::MODIFIED ]
 *
 * // Indexed alias — public token differs from the AQL field (?sort=name → givenName):
 * AQL::SORTABLE => [ [ Prop::NAME => Prop::GIVEN_NAME ] , Prop::CREATED ]
 *
 * // Associative (legacy) — still supported, returned untouched:
 * AQL::SORTABLE => [ Prop::CREATED => Prop::CREATED , Prop::NAME => Prop::GIVEN_NAME ]
 * ```
 *
 * ### Multilingual ordering
 * An entry may aim at one **locale** of a translations object and fall back:
 * ```php
 * AQL::FIELDS   => [ 'alternateName' => Filter::TRANSLATE , 'name' => [] ] ,
 * AQL::SORTABLE => [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'name' ] ] ,
 * // ?sort=label&lang=en →
 * // NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"], doc.name) ASC
 * ```
 * The chain is the requested locale (`Arango::LANG`), then the fallback one, then
 * `Field::ELSE`; equal links are emitted once, and a chain left empty degrades to
 * the stored path. The fallback is resolved by {@see resolveSortFallbackLang()},
 * the expression built by {@see translatedSortExpression()}.
 *
 * ### Distance ordering (`?near=`)
 * `?near={ "key":"geo", "latitude":48.85, "longitude":2.35 }` provides a
 * reference point and exposes the synthetic sort key **`distance`**
 * ({@see Schema::DISTANCE}). It is **sort-only** — it orders, it does not filter
 * (pair it with a `geo` `?filter=` to bound a radius). `?sort=` stays the single
 * ordering authority:
 * - `?near=…` alone (no `?sort=`) defaults to `SORT <distance> ASC`.
 * - `?near=…&sort=-distance` orders farthest first.
 * - `?near=…&sort=distance,name` orders by distance then name (you pick the priority).
 * - `?near=…&sort=name` keeps `name` only — distance is **not** auto-appended.
 * - `?sort=distance` without `?near=` is dropped (no anchor).
 *
 * The `key` names the geo field, so it is a **sort dimension** and passes the same
 * fail-closed gate as any sort key: it must be declared in `AQL::SORTABLE` (which
 * resolves the field path and, via `Field::REQUIRES`, gates it — a geo field hidden
 * from the projection stays untriable). A missing, unwhitelisted or refused key
 * simply drops the distance sort.
 *
 * The reference point is bound (`@lat` / `@lng`) and the predicate uses
 * `DISTANCE(doc.<field>.latitude, doc.<field>.longitude, @lat, @lng)`, so it is
 * index-accelerated by a two-field `GeoIndex`. Coordinates are bound **only**
 * when a `distance` criterion is actually emitted, so the query never declares
 * an unused bind variable.
 *
 * @package oihana\arango\models\traits\aql
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
trait SortTrait
{
    use BindTrait ,
        DefaultLangTrait ,
        SortDefaultTrait ;

    /**
     * The collection (map) of all the sortable fields.
     */
    public ?array $sortable = null ;

    /**
     * Initialize the sortable array definition.
     *
     * The raw definition (from the `AQL::SORTABLE` init key, or the property default)
     * is normalised through {@see normalizeSortable()} into the canonical
     * `urlKey => fieldPath` map. Three interchangeable notations are accepted and may
     * be mixed: the legacy associative `urlKey => fieldPath`, the indexed shorthand
     * `fieldName` (token equals field), and the indexed alias `[ urlKey => fieldPath ]`.
     * `null` is preserved and means **fail-closed** (no whitelist → nothing client
     * sorts). The normalisation is idempotent.
     *
     * @param array $init
     * @return $this
     */
    public function initializeSortable( array $init = [] ):static
    {
        $this->sortable = normalizeSortable( $init[ AQL::SORTABLE ] ?? $this->sortable ) ;
        return $this ;
    }

    /**
     * Prepare the AQL `SORT` expression from the `?sort=` grammar and, optionally, the `?near=` anchor.
     *
     * Each comma-separated criterion in `Arango::SORT` is resolved against `$sortable`
     * (URL key → AQL field path); a leading `-` makes it descending. The synthetic
     * `distance` key ({@see Schema::DISTANCE}) is resolved from `Arango::NEAR` and only
     * honored when `$binds` is provided (so the reference point can be bound).
     *
     * @param array $init Per-call parameters. Reads `Arango::SORT` (grammar) and `Arango::NEAR` (geo anchor).
     * @param array|null $sortable URL-key → field-path whitelist. Defaults to `$this->sortable`.
     * @param string $docRef The document variable the fields hang off (default `doc`).
     * @param array|null $binds Bind variables, populated by reference. Required to enable `distance`/`?near=` sorting.
     *
     * @return string|null The `SORT` body (without the `SORT` keyword), or an empty string when nothing sorts.
     *
     * @throws BindException When a bound coordinate cannot be registered.
     * @throws ValidationException
     *
     * @example Plain field sort
     * ```php
     * $model->prepareSort( [ Arango::SORT => 'name,-created' ] ) ;
     * // "doc.name ASC, doc.created DESC"
     * ```
     *
     * @example Distance sort (nearest first) via `?near=`
     * ```php
     * $binds = [] ;
     * $model->prepareSort
     * (
     *     [ Arango::NEAR => [ FilterParam::KEY => 'geo' , 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ,
     *     binds : $binds
     * ) ;
     * // "DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC"
     * ```
     *
     * @example Distance then name
     * ```php
     * $model->prepareSort
     * (
     *     [ Arango::SORT => 'distance,name' , Arango::NEAR => [ ... ] ] ,
     *     binds : $binds
     * ) ;
     * // "DISTANCE(...) ASC, doc.name ASC"
     * ```
     */
    public function prepareSort
    (
        array  $init     = [] ,
        ?array $sortable = null ,
        string $docRef   = AQL::DOC ,
        ?array &$binds   = null
    )
    :?string
    {
        $sort       = $init[ Arango::SORT ] ?? $this->sortDefault ;
        $sortable ??= $this->sortable ;
        $orders     = is_array( $sort ) ? $sort : [] ;

        $nearActive = $binds !== null && is_array( $init[ Arango::NEAR ] ?? null ) ;

        // Synthetic relevance key, driven by an active View search (AQL::VIEW
        // declaration + ?search term) — the score counterpart of ?near=/distance.
        $scoreActive = $binds !== null
                    && is_callable( [ $this , 'hasViewSearch' ] )
                    && $this->hasViewSearch( $init ) ;

        $explicit = $init[ Arango::SORT ] ?? null ;

        // An active search alone (no ?sort=) defaults to a most-relevant-first
        // score sort (descending) — relevance outranks the model's sortDefault.
        if( $scoreActive && ( $explicit === null || $explicit === Char::EMPTY ) )
        {
            $sort = Char::HYPHEN . Search::SCORE ;
        }
        // ?near= alone (no ?sort=) defaults to a nearest-first distance sort.
        elseif( $nearActive && ( $sort === null || $sort === Char::EMPTY ) )
        {
            $sort = Schema::DISTANCE ;
        }

        $nearExpression = null ;
        $nearResolved   = false ;

        if( is_string( $sort ) )
        {
            $criteria = explode( Char::COMMA , $sort ) ;

            foreach( $criteria as $key )
            {
                if( empty( $key ) )
                {
                    continue ;
                }

                if( $key[0] === Char::HYPHEN )
                {
                    $order = Order::DESC ;
                    $key   = ltrim( $key , Char::HYPHEN ) ;
                }
                else
                {
                    $order = Order::ASC ;
                }

                // Synthetic relevance key, driven by the active View search:
                // resolves to the BM25 score of the document (descending = most
                // relevant first). Dropped when no View search is active.
                if( $key === Search::SCORE )
                {
                    if( $scoreActive )
                    {
                        $orders[] = bm25( $docRef ) . Char::SPACE . $order ;
                    }
                    continue ;
                }

                // Synthetic distance key, driven by ?near= (bound lazily, only when emitted).
                if( $key === Schema::DISTANCE )
                {
                    if( $nearActive )
                    {
                        if( !$nearResolved )
                        {
                            $nearResolved   = true ;
                            $nearExpression = $this->prepareNear( $init[ Arango::NEAR ] , $binds , $docRef , $init ) ;
                        }

                        if( $nearExpression !== null )
                        {
                            $orders[] = $nearExpression . Char::SPACE . $order ;
                        }
                    }
                    continue ;
                }

                // Whitelist gate (fail-closed): a client key is honored only when
                // the model declares it in `$sortable`. No whitelist (`null`) means
                // nothing client-supplied sorts — the key never reaches doc.<key>.
                if( is_array( $sortable ) && array_key_exists( $key , $sortable ) )
                {
                    // Permission gate: a field hidden from projection stays untriable
                    // (no sort oracle). A refused key drops its criterion.
                    $field = $this->authorizeSortKey( $key , $sortable[ $key ] ?? null , $init , $docRef ) ;

                    if( $field !== null )
                    {
                        $orders[] = $field . Char::SPACE . $order ;
                    }
                }
            }
        }

        return compile( $orders , Char::COMMA . Char::SPACE ) ;
    }

    /**
     * Resolves a sortable entry that orders on a field of a **related** document,
     * reached through a relation this model already projects.
     *
     * The projection of a `Filter::EDGE` field emits a `LET`, and the compiled
     * query places every `LET` **before** the `SORT` — so ordering on the related
     * document is a matter of naming that variable, not of traversing again:
     *
     * ```aql
     * LET authorRef = ( FOR v IN OUTBOUND doc articles_authors RETURN … )
     * SORT FIRST( authorRef ).name ASC
     * ```
     *
     * Which is why the entry names the **projected field** (`AQL::EDGE => 'author'`,
     * a key of `$this->fields`) rather than an edge collection: the sort reuses
     * the traversal the projection already performs. One traversal serves both.
     *
     * Three declarations cannot be honoured, and each is refused rather than
     * dropped — a dropped criterion looks like a client typo, while these are
     * faults in the model that only its author can fix:
     *
     * - the named field is **not projected**, so there is no `LET` to name;
     * - it is not a **singular** relation — ordering on a plural one asks which
     *   of the related documents decides, a question the declaration does not
     *   answer;
     * - it carries no declared `Field::UNIQUE`, so its variable is the generated
     *   random name and cannot be designated.
     *
     * **Permission** follows the projected field: an explicit `Field::REQUIRES`
     * on the sortable entry wins, otherwise the subject of the relation field is
     * reused. What you cannot read, you cannot order by — otherwise the order
     * betrays it.
     *
     * @param array $entry The sortable definition (`AQL::EDGE`, `Field::PATH`, `Field::REQUIRES`).
     * @param array $init  The request-level init. Reads `Arango::AUTHORIZER`.
     *
     * @return string|null The `FIRST( <variable> ).<path>` expression, or null when refused by permission.
     *
     * @throws ValidationException When the declaration cannot be honoured.
     */
    private function authorizeRelationSortKey( array $entry , array $init ) : ?string
    {
        $field  = $entry[ AQL::EDGE ] ;
        $fields = property_exists( $this , 'fields' ) ? $this->fields : null ;

        $definition = is_array( $fields ) ? ( $fields[ $field ] ?? null ) : null ;

        if ( !is_array( $definition ) )
        {
            throw new ValidationException( sprintf
            (
                'Cannot sort through "%s": no such field is projected. A relational sort orders on the LET the projection emits, so the relation must be declared in the model fields.' ,
                is_scalar( $field ) ? $field : get_debug_type( $field ) ,
            )) ;
        }

        if ( ( $definition[ Field::FILTER ] ?? null ) !== Filter::EDGE )
        {
            throw new ValidationException( sprintf
            (
                'Cannot sort through "%s": only a singular Filter::EDGE relation can order a list. A plural relation leaves open which of the related documents decides the order.' ,
                $field ,
            )) ;
        }

        $variable = $definition[ Field::UNIQUE ] ?? null ;

        if ( $variable === null )
        {
            throw new ValidationException( sprintf
            (
                'Cannot sort through "%s": the relation declares no Field::UNIQUE, so its LET variable is generated at random and cannot be named. Declare one to make it designatable.' ,
                $field ,
            )) ;
        }

        $path = $entry[ Field::PATH ] ?? null ;

        if ( $path === null )
        {
            throw new ValidationException( sprintf
            (
                'Cannot sort through "%s": no Field::PATH declares which field of the related document orders the list.' ,
                $field ,
            )) ;
        }

        assertAttributeName( compile( $path , Char::DOT ) ) ; // a path INSIDE the related document.

        // Explicit subject first, then the one carried by the projected relation:
        // the same two steps the stored-path branch follows.
        $requires = $entry[ Field::REQUIRES ] ?? $definition[ Field::REQUIRES ] ?? null ;

        if ( $requires !== null && !isAuthorized( [ Field::REQUIRES => $requires ] , $init ) )
        {
            return null ;
        }

        return key( compile( $path , Char::DOT ) , first( $variable ) ) ;
    }

    /**
     * Resolve a whitelisted sort entry to its AQL field expression, gated by permission.
     *
     * The entry (the `$sortable[$key]` value) is either a plain field path — a string
     * or an array path (`[ 'address', 'city' ]`) — or an **explicit definition** (an
     * associative array carrying `Field::PATH` and/or `Field::REQUIRES`). The permission
     * subject is resolved in two steps, aligned on the projection's `Field::REQUIRES`:
     * - **explicit** — a `Field::REQUIRES` declared on the entry itself takes priority;
     * - **inherited** — otherwise the subject of the homonymous field declared in
     *   `$this->fields` is reused, so « what you cannot read, you cannot sort on ».
     *
     * When a subject is resolved and {@see isAuthorized()} denies it, the key is refused
     * (`null`) and the caller drops the criterion — a field hidden from the projection
     * stays untriable (no sort oracle). No subject, or no authorizer injected, sorts
     * freely (fail-open — exactly the field-level semantics).
     *
     * @param string $key The public URL key (already resolved against the whitelist).
     * @param mixed $entry The `$sortable[$key]` value (path or explicit definition).
     * @param array $init The request-level init. Reads `Arango::AUTHORIZER`.
     * @param string $docRef The document variable the field hangs off.
     *
     * @return string|null The `doc.<field>` expression, or `null` when the sort is refused.
     *
     * @throws ValidationException
     */
    private function authorizeSortKey( string $key , mixed $entry , array $init , string $docRef ) : ?string
    {
        // A sortable entry naming a relation (`AQL::EDGE`) orders on a field of the RELATED document,
        // not of this one — a different expression, and a different permission to inherit.
        // Handled apart; everything else is the stored path this trait has always compiled.
        if ( is_array( $entry ) && !array_is_list( $entry ) && isset( $entry[ AQL::EDGE ] ) )
        {
            return $this->authorizeRelationSortKey( $entry , $init ) ;
        }

        [ $path , $requires ] = $this->resolveSortEntry( $key , $entry ) ;

        if( !$this->isSortAuthorized( $path , $requires , $init ) )
        {
            return null ;
        }

        $path = compile( $path , Char::DOT ) ;

        // A multilingual entry does not order on the stored path itself — that path
        // holds the translations object — but on one of its locales, with a fallback.
        if ( $this->isTranslatedSortEntry( $entry , $path ) )
        {
            return $this->translatedSortExpression( $entry , $path , $init , $docRef ) ;
        }

        return key( $path , $docRef ) ;
    }

    /**
     * Decides whether a sortable entry orders on a **multilingual** field — one whose
     * stored value is a translations object (`{ fr: "…", en: "…" }`) rather than the
     * text to compare.
     *
     * Two declarations say so, and they follow the two steps `Field::REQUIRES` already
     * follows — inherited first, explicit second:
     * - **inherited** — the resolved path names a field declared `Filter::TRANSLATE` in
     *   `$this->fields`, the very declaration that makes the projection translate it;
     * - **explicit** — the entry carries `Field::FILTER => Filter::TRANSLATE` itself,
     *   for a field that is sortable but **not** projected (nothing to inherit from).
     *
     * ⚠ The inherited form reads a **root** field (a path of a single segment). A
     * translated field nested inside a structural one is not walked into: declare
     * `Field::FILTER` on the entry instead. A miss is not a hole — the entry then
     * behaves as the stored path it has always been.
     *
     * @param mixed  $entry The `$sortable[$key]` value (path or explicit definition).
     * @param string $path  The resolved (dotted) field path.
     *
     * @return bool `true` when the entry orders on a translations object.
     */
    private function isTranslatedSortEntry( mixed $entry , string $path ) : bool
    {
        // Explicit (on the entry itself).
        if ( is_array( $entry ) && !array_is_list( $entry ) && ( $entry[ Field::FILTER ] ?? null ) === Filter::TRANSLATE )
        {
            return true ;
        }

        // Inherited (from the projection of the same field).
        $fields = property_exists( $this , 'fields' ) ? $this->fields : null ;

        if ( !is_array( $fields ) || str_contains( $path , Char::DOT ) )
        {
            return false ;
        }

        $definition = $fields[ $path ] ?? null ;

        if ( is_array( $definition ) )
        {
            return ( $definition[ Field::FILTER ] ?? null ) === Filter::TRANSLATE ;
        }

        return $definition === Filter::TRANSLATE ;
    }

    /**
     * Resolve the **fallback** language of a multilingual sort entry — the locale used
     * when the requested one is absent from a document, or when the call requests none.
     *
     * Three declaration sites, from the most local to the most general; the first that
     * answers wins:
     * 1. the sortable entry (`Field::DEFAULT_LANG`),
     * 2. the model (`$this->defaultLang`, see {@see DefaultLangTrait}),
     * 3. the host, pushed per call (`$init[ Arango::DEFAULT_LANG ]`).
     *
     * ⚠ **The model outranks the host on purpose.** What the host pushes is a *default*,
     * and a default must never override an explicit declaration — otherwise a model would
     * change behaviour depending on which site loads it, without a line of it moving.
     * `Arango::LANG` (the *requested* language) is the opposite case: an instruction, and
     * it wins over all three.
     *
     * @param mixed $entry The `$sortable[$key]` value.
     * @param array $init  The request-level init. Reads `Arango::DEFAULT_LANG`.
     *
     * @return string|null The lowercased fallback tag, or `null` when none is declared.
     *
     * @throws ValidationException When a declared tag is not a valid language code.
     */
    private function resolveSortFallbackLang( mixed $entry , array $init ) : ?string
    {
        $declared = is_array( $entry ) && !array_is_list( $entry ) ? ( $entry[ Field::DEFAULT_LANG ] ?? null ) : null ;

        $lang = $declared ?? $this->defaultLang ?? $init[ Arango::DEFAULT_LANG ] ?? null ;

        if ( $lang === null )
        {
            return null ;
        }

        if ( is_string( $lang ) )
        {
            $lang = strtolower( $lang ) ;
        }

        assertLanguageCode( $lang ) ; // declared in code — nothing a request can fix.

        return $lang ;
    }

    /**
     * Build the ordering expression of a multilingual entry: the requested locale, then
     * the fallback one, then the field named by `Field::ELSE`.
     *
     * ```aql
     * SORT NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"], doc.name) ASC
     * ```
     *
     * The locale is a **bracket** accessor rather than a dotted one, uniformly: a tag
     * carrying a dash reads as a subtraction in dot notation (`doc.alternateName.pt-BR`),
     * and one shape for every tag beats a shape that depends on the tag. The tag is
     * written verbatim — an attribute name can never be bound — hence the guards.
     *
     * Links are dropped rather than duplicated: a requested locale equal to the fallback
     * yields two terms, not three. `Field::ELSE` is optional; without it a document that
     * has no translation at all orders on `null`, which is where it ordered before.
     *
     * ⚠ `Field::ELSE` names another field, so it passes the same permission gate as any
     * sort key — an unreadable one is dropped from the chain instead of leaking its
     * values through the order.
     *
     * ⚠ When nothing answers — no requested locale, no fallback, no `Field::ELSE` — the
     * expression is the stored path itself, exactly what an ordinary entry would emit.
     * An incomplete declaration degrades to today's behaviour; it never drops the
     * criterion in silence.
     *
     * @param mixed  $entry  The `$sortable[$key]` value.
     * @param string $path   The resolved (dotted) path of the translations object.
     * @param array  $init   The request-level init. Reads `Arango::LANG` and `Arango::DEFAULT_LANG`.
     * @param string $docRef The document variable the fields hang off.
     *
     * @return string The ordering expression.
     *
     * @throws ValidationException When a language tag or the `Field::ELSE` path is invalid.
     */
    private function translatedSortExpression( mixed $entry , string $path , array $init , string $docRef ) : string
    {
        $source = key( $path , $docRef ) ;

        $languages = [] ;

        $requested = $init[ Arango::LANG ] ?? null ;

        if ( $requested !== null )
        {
            if ( is_string( $requested ) )
            {
                $requested = strtolower( $requested ) ;
            }

            assertLanguageCode( $requested , fromRequest: true ) ; // came from the wire.

            $languages[] = $requested ;
        }

        $fallback = $this->resolveSortFallbackLang( $entry , $init ) ;

        if ( $fallback !== null && !in_array( $fallback , $languages , true ) )
        {
            $languages[] = $fallback ;
        }

        $sources = [] ;

        foreach ( $languages as $language )
        {
            $sources[] = $source . Char::LEFT_BRACKET . betweenDoubleQuotes( $language ) . Char::RIGHT_BRACKET ;
        }

        $else = is_array( $entry ) && !array_is_list( $entry ) ? ( $entry[ Field::ELSE ] ?? null ) : null ;

        if ( $else !== null )
        {
            $else = compile( $else , Char::DOT ) ;

            assertAttributeName( $else ) ;

            if ( $this->isSortAuthorized( $else , null , $init ) )
            {
                $sources[] = key( $else , $docRef ) ;
            }
        }

        return match( count( $sources ) )
        {
            0       => $source ,
            1       => $sources[ 0 ] ,
            default => notNull( ...$sources ) ,
        } ;
    }

    /**
     * Resolve a whitelisted sort entry to its `[ fieldPath, requires ]` pair.
     *
     * The entry (the `$sortable[$key]` value) is either a plain field path — a string
     * or an array path (`[ 'address', 'city' ]`) — or an **explicit definition** (an
     * associative array carrying `Field::PATH` and/or `Field::REQUIRES`). Only the
     * **explicit** `Field::REQUIRES` (Façon A) is returned here; the **inherited**
     * permission (Façon B) is decided by {@see isSortAuthorized()} against the
     * resolved `$path` — never against the URL key — so a dotted/aliased path
     * (`salary` → `address.salary`) is gated at its exact (sub-)field, symmetric
     * with groupBy/bounds and free of the "wrong homonym" pitfall.
     *
     * Shared by the textual `?sort=` grammar and the `?near=` distance anchor, so both
     * resolve a geo/scalar field the same way.
     *
     * @param string $key   The public URL key (already resolved against the whitelist).
     * @param mixed  $entry The `$sortable[$key]` value (path or explicit definition).
     *
     * @return array{0:mixed,1:mixed} The `[ fieldPath, requires ]` pair (`requires` is the
     *                                explicit subject, or `null` when the entry declares none).
     */
    private function resolveSortEntry( string $key , mixed $entry ) : array
    {
        // Explicit definition (Façon A): an associative array carries its own path
        // and/or permission. A pure list is an array path, not a definition.
        $isDefinition = is_array( $entry ) && !array_is_list( $entry ) ;

        $path     = $isDefinition ? ( $entry[ Field::PATH     ] ?? $key ) : $entry ;
        $requires = $isDefinition ? ( $entry[ Field::REQUIRES ] ?? null ) : null ;

        return [ $path , $requires ] ;
    }

    /**
     * Decide whether a sort/near field is granted for the request.
     *
     * Two paths, mirroring the projection's own gating:
     * - **explicit (Façon A)** — an entry that declared its own `Field::REQUIRES`
     *   is run through {@see isAuthorized()};
     * - **inherited (Façon B)** — otherwise the `Field::REQUIRES` is inherited from
     *   the projection at the **resolved `$path`** via {@see isPathAuthorized()},
     *   which descends `Field::FIELDS` / `AQL::SKIN_FIELDS` and strips `[*]`, so a
     *   dotted/aliased path (`address.salary`) is gated at its exact sub-field —
     *   never at the homonym of the URL key.
     *
     * Both fail open: no explicit subject with a projection that carries no
     * `Field::REQUIRES` on the path (or no authorizer injected) sorts freely —
     * exactly the field-level semantics, symmetric with `?filter=`.
     *
     * @param string $path     The resolved field path (`address.salary`, `location.point`, …).
     * @param mixed  $requires The explicit `Field::REQUIRES` subject(s) declared on the entry, or `null`.
     * @param array  $init     The request-level init. Reads `Arango::AUTHORIZER`.
     *
     * @return bool `true` when the field may be sorted on, `false` when refused.
     */
    private function isSortAuthorized( string $path , mixed $requires , array $init ) : bool
    {
        if( $requires !== null )
        {
            return isAuthorized( [ Field::REQUIRES => $requires ] , $init ) ;
        }

        $fields = property_exists( $this , 'fields' ) ? $this->fields : null ;

        return isPathAuthorized( $path , $fields , $init ) ;
    }

    /**
     * Build the `DISTANCE(...)` expression for a `?near=` anchor and bind its coordinates.
     *
     * The `key` of the payload names the geo field to order by distance from, so it is a
     * **sort dimension** and travels through the same fail-closed gate as any sort key: it
     * must be declared in `$this->sortable` (URL key → geo field path) and it inherits (or
     * declares) a `Field::REQUIRES` permission — a geo field hidden from the projection
     * stays untriable (no distance oracle). Returns `null` when the key is missing, is not
     * whitelisted, is refused by permission, or the coordinates are incomplete.
     *
     * @param array      $near   The `?near=` payload (`{ key, latitude, longitude }`), already array-checked by the caller.
     * @param array|null $binds  Bind variables, populated by reference.
     * @param string     $docRef The document variable the fields hang off.
     * @param array      $init   The request-level init. Reads `Arango::AUTHORIZER`.
     *
     * @return string|null `DISTANCE(doc.<field>.latitude, doc.<field>.longitude, @lat, @lng)` or `null`.
     *
     * @throws BindException When a bound coordinate cannot be registered.
     */
    protected function prepareNear( array $near , ?array &$binds , string $docRef = AQL::DOC , array $init = [] ): ?string
    {
        $key = $near[ FilterParam::KEY ] ?? null ;

        if( !is_string( $key ) || $key === Char::EMPTY )
        {
            return null ;
        }

        // Fail-closed whitelist: the geo key must be a declared sortable dimension.
        if( !is_array( $this->sortable ) || !array_key_exists( $key , $this->sortable ) )
        {
            return null ;
        }

        [ $field , $requires ] = $this->resolveSortEntry( $key , $this->sortable[ $key ] ) ;

        if( !$this->isSortAuthorized( $field , $requires , $init ) )
        {
            return null ;
        }

        [ $latitude , $longitude ] = resolveGeoPoint( $near ) ;

        if( $latitude === null || $longitude === null )
        {
            return null ;
        }

        $field = compile( $field , Char::DOT ) ;

        return distance
        (
            key( $field . Char::DOT . Schema::LATITUDE  , $docRef ) ,
            key( $field . Char::DOT . Schema::LONGITUDE , $docRef ) ,
            $this->bind( $latitude  , $binds ) ,
            $this->bind( $longitude , $binds )
        ) ;
    }
}
