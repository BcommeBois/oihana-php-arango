<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\AggregatablePolicy;
use oihana\arango\models\enums\Group;
use oihana\arango\models\enums\facets\FacetAggregator;
use oihana\arango\models\interfaces\AggregateExpression;

use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operations\aqlAsc;
use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\models\helpers\isPathAuthorized;
use function oihana\arango\db\helpers\requestAlt;
use function oihana\arango\models\helpers\normalizeSortable;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;
use function oihana\core\strings\key;

/**
 * Translates the high-level {@see Arango::GROUP} spec ({@see Group}) into the raw
 * `COLLECT` spec consumed by {@see \oihana\arango\db\operations\aqlCollect()} and
 * {@see \oihana\arango\db\operations\aqlCollectReturn()} in
 * {@see \oihana\arango\models\traits\queries\ListQueryTrait::buildListQuery()}.
 *
 * It is the `COLLECT` counterpart of {@see FacetTrait}, reusing the same engines:
 * - {@see FacetAggregator} for the aggregate functions (`sum`→`SUM`, …),
 * - the `alt` engine ({@see alterExpression()}) for grouping-key transforms,
 * - the `key()` helper to prefix fields with the document reference.
 *
 * A raw {@see Arango::COLLECT} spec is passed through untouched when no
 * {@see Arango::GROUP} is supplied, so power users keep full control.
 *
 * ### One gate per half, and they do not default alike
 * `by` is **fail-closed** through {@see GroupTrait::$groupable}: without a declared
 * whitelist, nothing is groupable. `agg` is **fail-open** through
 * {@see GroupTrait::$aggregatable}: without one, every projected path stays
 * aggregatable — declaring the whitelist is what closes it, and
 * {@see GroupTrait::$aggregatablePolicy} says whether an undeclared aggregate is
 * then dropped or refused outright. Both halves are permission-gated the same way,
 * on the resolved path, so a field hidden from reading is neither a dimension nor
 * an aggregate.
 *
 * @see GroupTrait::prepareCollect() The entry point.
 */
trait GroupTrait
{
    use BindTrait ;

    /**
     * Optional whitelist/mapping of aggregatable fields: `urlKey => fieldPath`.
     *
     * It is the `agg` counterpart of {@see GroupTrait::$groupable}, with one
     * deliberate difference: it keys on the **field token**, not on the output
     * name. In `[ 'total' => 'sum:speed' ]` the name `total` is chosen freely by
     * the client — whitelisting it would mean nothing — while `speed` is the token
     * this map resolves (to `speed.value`, say).
     *
     * The gate is **fail-open**: `null` (no whitelist) means every projected path
     * stays aggregatable, exactly as before this option existed. Declaring it closes
     * the gate, and {@see GroupTrait::$aggregatablePolicy} says how loudly.
     *
     * A whitelisted field is further permission-gated (`Field::REQUIRES` inherited
     * from the projection), so a field hidden from reading cannot be aggregated on
     * (`MAX`/`MIN`/`AVG`/`SUM` leak a bound of its values).
     *
     * @var array<string,string|array<int,string>>|null
     */
    public ?array $aggregatable = null ;

    /**
     * What happens to an aggregate absent from {@see GroupTrait::$aggregatable}:
     * one of the {@see AggregatablePolicy} codes.
     *
     * `null` resolves to {@see AggregatablePolicy::DROP} when a whitelist is
     * declared, and to {@see AggregatablePolicy::OPEN} when none is — so a model
     * that never heard of the option emits the query it always emitted.
     */
    public ?string $aggregatablePolicy = null ;

    /**
     * Initializes the {@see GroupTrait::$aggregatable} whitelist and its policy from
     * the model options.
     *
     * The whitelist is normalised through {@see normalizeSortable()}, so the three
     * `sortable` notations are accepted and may be mixed: the associative
     * `urlKey => fieldPath`, the indexed shorthand `fieldName` (token equals field),
     * and the indexed alias `[ urlKey => fieldPath ]`.
     *
     * @param array $init The model options (`Arango::AGGREGATABLE`, `Arango::AGGREGATABLE_POLICY`).
     *
     * @return static
     */
    public function initializeAggregatable( array $init = [] ) :static
    {
        $this->aggregatable       = normalizeSortable( $init[ Arango::AGGREGATABLE ] ?? $this->aggregatable ) ;
        $this->aggregatablePolicy = $init[ Arango::AGGREGATABLE_POLICY ] ?? $this->aggregatablePolicy ;
        return $this ;
    }

    /**
     * Optional whitelist/mapping of groupable dimensions: `urlKey => fieldPath`.
     *
     * When set, only whitelisted {@see Group::BY} keys are allowed and each
     * resolves to its real field path (decoupling the public group key from the
     * internal attribute, like {@see SortTrait::$sortable}). The gate is
     * **fail-closed**: `null` (no whitelist) means **nothing is groupable** — a
     * client key never reaches `doc.<key>`. A whitelisted dimension is further
     * permission-gated (`Field::REQUIRES` inherited from the projection), so a
     * field hidden from reading cannot be grouped on (no group-by oracle).
     *
     * @var array<string,string>|null
     */
    public ?array $groupable = null ;

    /**
     * Initializes the {@see GroupTrait::$groupable} whitelist from the model options.
     *
     * @param array $init The model options (`Arango::GROUPABLE`).
     *
     * @return static
     */
    public function initializeGroupable( array $init = [] ) :static
    {
        $this->groupable = $init[ Arango::GROUPABLE ] ?? $this->groupable ;
        return $this ;
    }

    /**
     * Tells whether a list query built from `$init` carries a `COLLECT`.
     *
     * 🔑 **A grouped row is not a document.** After a `COLLECT` the projection is
     * made of the declared variables — the dimensions, the aggregates, the count —
     * and nothing of the collection's own shape survives. Hydrating such a row in
     * the model's schema keeps only the names that class happens to declare, so the
     * list and stream entry points read this to decide whether their result is taken
     * raw.
     *
     * 🚨 **The test is the emitted `COLLECT`, not the requested one.** A group spec
     * whose every dimension is dropped — undeclared, or closed by the permission
     * gate — and which carries no aggregate emits no `COLLECT` at all: the query
     * still returns documents, and they must still be hydrated. The emptiness test
     * below mirrors, key for key, the one of {@see \oihana\arango\db\operations\aqlCollect()}.
     *
     * The spec is resolved a second time rather than carried over from
     * {@see \oihana\arango\models\traits\queries\ListQueryTrait::buildListQuery()},
     * which already knows the answer: the reading stays self-contained, and no
     * shared signature has to grow a return channel for it. Nothing is bound along
     * the way — {@see \oihana\arango\models\traits\aql\BindTrait::binder()} builds a
     * throwaway map when it is handed none.
     *
     * @param array $init The list query options.
     *
     * @return bool True when the built query groups its rows.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function isGroupedQuery( array $init = [] ) :bool
    {
        $spec = $this->prepareCollect( $init ) ;

        return !empty( $spec[ AQL::ASSIGN     ] )
            || !empty( $spec[ AQL::AGGREGATE  ] )
            || !empty( $spec[ AQL::WITH_COUNT ] ) ;
    }

    /**
     * Resolves the `COLLECT` spec for a list query.
     *
     * Translates a friendly {@see Arango::GROUP} spec ({@see Group::BY},
     * {@see Group::AGG}, {@see Group::COUNT}, {@see Group::ALT}) into the raw
     * {@see \oihana\arango\db\operations\aqlCollect()} keys. Falls back to the raw
     * {@see Arango::COLLECT} spec (or an empty array) when no group is requested.
     *
     * @param array  $init   The list query options.
     * @param string $docRef The document reference grouping fields are read from.
     *
     * @return array The raw COLLECT spec (`AQL::ASSIGN`, `AQL::AGGREGATE`, `AQL::WITH_COUNT`).
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function prepareCollect( array $init = [] , string $docRef = AQL::DOC , ?array &$binds = null ) :array
    {
        $group = $init[ Arango::GROUP ] ?? null ;

        if ( !is_array( $group ) || empty( $group ) )
        {
            return $init[ Arango::COLLECT ] ?? [] ;
        }

        $spec      = [] ;
        $assign    = $this->collectAssign( $group , $docRef , $init , $binds ) ;
        $aggregate = $this->collectAggregate( $group , $docRef , $init , $binds ) ;

        if ( !empty( $assign ) )
        {
            $spec[ AQL::ASSIGN ] = $assign ;
        }

        // Per-group count: WITH COUNT INTO when alone, LENGTH(1) aggregate when
        // combined with other aggregates (AGGREGATE and WITH COUNT are exclusive).
        $count    = $group[ Group::COUNT ] ?? null ;
        $countVar = match( true )
        {
            $count === true                                  => Group::COUNT_NAME ,
            is_string( $count ) && $count !== Char::EMPTY    => $count ,
            default                                          => null ,
        } ;

        if ( $countVar !== null )
        {
            if ( empty( $aggregate ) )
            {
                $spec[ AQL::WITH_COUNT ] = $countVar ;
            }
            else
            {
                $aggregate[ $countVar ] = length( 1 ) ;
            }
        }

        if ( !empty( $aggregate ) )
        {
            $spec[ AQL::AGGREGATE ] = $aggregate ;
        }

        return $spec ;
    }

    /**
     * Builds the `SORT` clause applied to a grouped result, from {@see Group::SORT}.
     *
     * The sort operates on group/aggregate variable names (never on `doc`, which
     * is out of scope after `COLLECT`): a CSV with a leading `-` for descending,
     * e.g. `'-count'` → `count DESC`, `'category,-total'` → `category ASC, total DESC`.
     *
     * @param array $init The list query options.
     *
     * @return string|null The inner sort expression, or null when none.
     */
    public function prepareGroupSort( array $init = [] , ?array $availableVars = null ) :?string
    {
        $group = $init[ Arango::GROUP ] ?? null ;
        $sort  = is_array( $group ) ? ( $group[ Group::SORT ] ?? null ) : null ;

        if ( !is_string( $sort ) || $sort === Char::EMPTY )
        {
            return null ;
        }

        $parts = [] ;
        foreach ( explode( Char::COMMA , $sort ) as $token )
        {
            $token = trim( $token ) ;
            if ( $token === Char::EMPTY )
            {
                continue ;
            }

            $desc = $token[ 0 ] === Char::HYPHEN ;
            $name = $desc ? ltrim( $token , Char::HYPHEN ) : $token ;

            // Guardrail: only sort on group/aggregate variables actually emitted.
            // A dimension dropped by the permission gate leaves no variable, so
            // sorting on it would reference an undefined name (invalid AQL).
            if ( $availableVars !== null && !in_array( $name , $availableVars , true ) )
            {
                continue ;
            }

            $parts[] = $desc ? aqlDesc( $name ) : aqlAsc( $name ) ;
        }

        return empty( $parts ) ? null : compile( $parts , Char::COMMA . Char::SPACE ) ;
    }

    /**
     * Resolves an aggregate field token against {@see GroupTrait::$aggregatable},
     * applying {@see GroupTrait::$aggregatablePolicy} when the token is undeclared.
     *
     * A declared token always resolves to its mapped path, whatever the policy — so
     * the whitelist doubles as a pure `publicKey => fieldPath` alias map. An
     * undeclared one is answered by the policy: passed through
     * ({@see AggregatablePolicy::OPEN}), dropped ({@see AggregatablePolicy::DROP}),
     * or refused ({@see AggregatablePolicy::STRICT}). An unrecognised policy code
     * drops, so a typo closes the gate rather than opening it.
     *
     * 🚨 This gate answers for the **whitelist only**, never for the permission gate
     * that follows it. A whitelisted field refused by `Field::REQUIRES` is dropped in
     * silence even under `STRICT`: an error naming a protected field would tell the
     * client that field exists — the very oracle the permission gate closes.
     *
     * A declared entry may also be an {@see AggregateExpression} rather than a path,
     * which is handed back as it is: what it reads and what it compiles is decided
     * further down, by {@see GroupTrait::aggregateExpression()}. Only a **declared**
     * entry can be one — `OPEN` answers an undeclared token with the client's own
     * token, which is a path by construction.
     *
     * @param string $field The field token written by the client (`sum:speed` → `speed`).
     * @param string $name  The aggregate output name, quoted in the strict error.
     *
     * @return string|AggregateExpression|null The resolved field path, the declared expression,
     *                                          or `null` when the aggregate is dropped.
     *
     * @throws ValidationException Under {@see AggregatablePolicy::STRICT}, naming the refused token.
     */
    private function aggregatableEntry( string $field , string $name ) :string|AggregateExpression|null
    {
        $aggregatable = $this->aggregatable ;

        if ( is_array( $aggregatable ) && array_key_exists( $field , $aggregatable ) )
        {
            $entry = $aggregatable[ $field ] ;

            if ( $entry instanceof AggregateExpression )
            {
                return $entry ;
            }

            $path = is_string( $entry ) || is_array( $entry ) ? key( $entry ) : Char::EMPTY ;
            return $path === Char::EMPTY ? null : $path ;
        }

        // No whitelist at all keeps the historical behaviour; a declared one closes
        // the gate, and drops unless the model asked for another noise.
        $policy = $this->aggregatablePolicy ?? ( is_array( $aggregatable ) ? AggregatablePolicy::DROP : AggregatablePolicy::OPEN ) ;

        return match( $policy )
        {
            AggregatablePolicy::OPEN   => $field ,
            AggregatablePolicy::STRICT => throw new ValidationException( sprintf( 'The aggregate "%s" targets a field that is not aggregatable: "%s".' , $name , $field ) ) ,
            default                    => null ,
        } ;
    }

    /**
     * Resolves a declared {@see AggregateExpression} into the operand the aggregate
     * function wraps, or `null` when it must be withdrawn.
     *
     * 🚨 **Every path the expression reads is gated, and one refusal is enough.** A
     * path-based aggregate has a single path to check; an expression has several —
     * that is what it is for. Checking none of them, or only the first, would make a
     * derived expression the way around `Field::REQUIRES`: a field closed to the
     * projection would come back out as a sum, in silence, without a single existing
     * essay turning red.
     *
     * ⚠ **An empty `paths()` withdraws the aggregate.** An expression that declares
     * no path declares that it reads nothing. Read as "nothing to gate", it would be
     * precisely the hole above; read as a refusal, a mis-declaration costs the
     * aggregate and shows. The refusal is silent, like every permission refusal here
     * — naming a protected field would tell the client it exists.
     *
     * The attribute-name guard does not apply: an expression is not an attribute
     * name, and its paths are never interpolated — they only feed the gate. What
     * replaces that guard is origin, not trust: an expression is always a
     * declaration of the consumer's own code, reachable only through a public key
     * already on the whitelist, and any value from the request goes through
     * {@see Arango::BINDER}.
     *
     * @param AggregateExpression $expression The declared expression.
     * @param string              $docRef     The document reference to read from.
     * @param array               $init       The query init, carrying the binder and the authorizer.
     *
     * @return string|null The per-document expression, or `null` when the aggregate is dropped.
     */
    private function aggregateExpression( AggregateExpression $expression , string $docRef , array $init ) :?string
    {
        $paths = $expression->paths() ;

        if ( empty( $paths ) )
        {
            return null ;
        }

        foreach ( $paths as $path )
        {
            if ( !isPathAuthorized( (string) $path , $this->fields ?? null , $init ) )
            {
                return null ;
            }
        }

        return $expression->compile( $docRef , $init ) ;
    }

    /**
     * Builds the `AQL::AGGREGATE` map from {@see Group::AGG}.
     *
     * An entry of the whitelist may be an {@see AggregateExpression} instead of a
     * path, in which case the engine wraps what the expression compiles rather than
     * `doc.<path>` — one function, one **computed** operand.
     *
     * @param array  $group  The group spec.
     * @param string $docRef The document reference.
     * @param array  $init   The query init.
     * @param array|null $binds The bind map, by reference: an expression binds its values through it.
     *
     * @return array `[ outName => 'FN(doc.field)' ]`.
     *
     * @throws ValidationException
     */
    private function collectAggregate( array $group , string $docRef , array $init = [] , ?array &$binds = null ) :array
    {
        $agg = $group[ Group::AGG ] ?? null ;
        if ( !is_array( $agg ) || empty( $agg ) )
        {
            return [] ;
        }

        // An expression may carry values, and a value never reaches the query text:
        // it is bound. Same channel as the `alt` chains of collectAssign().
        $init[ Arango::BINDER ] = $this->binder( $binds ) ;

        $out = [] ;
        foreach ( $agg as $name => $definition )
        {
            [ $code , $field ] = $this->normalizeAggregate( $definition ) ;

            $function = FacetAggregator::getAlias( $code ) ;
            if ( $function === null || $field === null )
            {
                continue ;
            }

            // Aggregatable whitelist: the client token resolves to its real field
            // path, and an undeclared one meets the model's policy (pass, drop, or
            // fail). Runs *before* the permission gate, so the two refusals stay
            // distinguishable — see aggregatableField().
            $entry = $this->aggregatableEntry( (string) $field , (string) $name ) ;
            if ( $entry === null )
            {
                continue ;
            }

            // A declared expression carries its own guards — it reads several paths,
            // and it writes its own AQL, so neither the single-path gate nor the
            // attribute-name guard below applies to it.
            if ( $entry instanceof AggregateExpression )
            {
                $expression = $this->aggregateExpression( $entry , $docRef , $init ) ;

                if ( $expression !== null )
                {
                    $out[ $name ] = func( $function , $expression ) ;
                }

                continue ;
            }

            // Permission gate: aggregating a field hidden from the projection leaks
            // a bound of its value (MAX/MIN/AVG/SUM) — the aggregate is dropped. The
            // whole path is gated (not only its root), so a locked sub-field
            // (`address.city`) is honored in depth.
            if ( !isPathAuthorized( $entry , $this->fields ?? null , $init ) )
            {
                continue ;
            }

            assertAttributeName( $entry ) ; // guards against AQL injection through the field path.

            $out[ $name ] = func( $function , key( $entry , $docRef ) ) ;
        }

        return $out ;
    }

    /**
     * Builds the `AQL::ASSIGN` map from {@see Group::BY} and {@see Group::ALT}.
     *
     * @param array  $group  The group spec.
     * @param string $docRef The document reference.
     *
     * @return array `[ varName => 'doc.field' | 'FN(doc.field)' ]`.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function collectAssign( array $group , string $docRef , array $init = [] , ?array &$binds = null ) :array
    {
        $fields = $this->normalizeGroupFields( $group[ Group::BY ] ?? null ) ;
        if ( empty( $fields ) )
        {
            return [] ;
        }

        // Fail-closed whitelist: without a declared `$groupable`, nothing is
        // groupable — a client key never reaches doc.<key> (aligned on $sortable).
        if ( !is_array( $this->groupable ) )
        {
            return [] ;
        }

        // `?group={"alt":…}` is a request slot: each chain is presumed to come from the
        // wire, so its parameters are bound rather than written into the query.
        $alt    = $group[ Group::ALT ] ?? [] ;
        $assign = [] ;

        $init[ Arango::BINDER ] = $this->binder( $binds ) ;
        foreach ( $fields as $var => $field )
        {
            // The variable (URL key) must be whitelisted and resolves to its field path.
            if ( !array_key_exists( $var , $this->groupable ) )
            {
                continue ;
            }
            $field = $this->groupable[ $var ] ;

            // Permission gate: grouping by a field hidden from the projection
            // (Field::REQUIRES) would leak its distinct values — the dimension is
            // dropped (an output, not a constraint, so removing it leaks nothing).
            // The whole path is gated (not only its root), so a locked sub-field
            // (`address.city`) is honored in depth.
            if ( !isPathAuthorized( (string) $field , $this->fields ?? null , $init ) )
            {
                continue ;
            }

            assertAttributeName( $field ) ; // guards against AQL injection through the field path.

            $chain          = requestAlt( is_array( $alt ) ? ( $alt[ $var ] ?? null ) : null ) ;
            $assign[ $var ] = alterExpression( key( $field , $docRef ) , $chain , $init ) ;
        }

        return $assign ;
    }

    /**
     * Normalizes an aggregate definition into a `[ code, field ]` pair.
     *
     * Accepts `'sum:amount'` (string) or `['sum','amount']` (list).
     *
     * @param mixed $definition
     *
     * @return array{0:?string,1:?string}
     */
    private function normalizeAggregate( mixed $definition ) :array
    {
        if ( is_string( $definition ) )
        {
            $definition = explode( Char::COLON , $definition , 2 ) ;
        }

        if ( is_array( $definition ) )
        {
            return [ $definition[ 0 ] ?? null , $definition[ 1 ] ?? null ] ;
        }

        return [ null , null ] ;
    }

    /**
     * Normalizes {@see Group::BY} into a `[ varName => field ]` map.
     *
     * - CSV string `'category,status'` → `[ 'category' => 'category', 'status' => 'status' ]`.
     * - list `['category','status']`   → same.
     * - assoc `['year' => 'created']`   → kept as-is.
     *
     * Dotted fields yield underscore variable names (`address.city` → `address_city`).
     *
     * @param mixed $by
     *
     * @return array<string,string>
     */
    private function normalizeGroupFields( mixed $by ) :array
    {
        if ( is_string( $by ) )
        {
            $by = explode( Char::COMMA , $by ) ;
        }

        if ( !is_array( $by ) || empty( $by ) )
        {
            return [] ;
        }

        $fields = [] ;
        foreach ( $by as $var => $field )
        {
            $field = is_string( $field ) ? trim( $field ) : $field ;
            if ( !is_string( $field ) || $field === Char::EMPTY )
            {
                continue ;
            }

            // List/CSV entries are keyed by position → derive the variable name
            // from the field (dots become underscores for a valid identifier).
            $name = is_int( $var ) ? str_replace( Char::DOT , Char::UNDERLINE , $field ) : $var ;

            $fields[ $name ] = $field ;
        }

        return $fields ;
    }
}
