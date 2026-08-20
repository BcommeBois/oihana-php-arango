<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\AggregatablePolicy;
use oihana\arango\models\enums\Group;
use oihana\arango\models\enums\facets\FacetAggregator;

use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operations\aqlAsc;
use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\models\helpers\isPathAuthorized;
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
    public function prepareCollect( array $init = [] , string $docRef = AQL::DOC ) :array
    {
        $group = $init[ Arango::GROUP ] ?? null ;

        if ( !is_array( $group ) || empty( $group ) )
        {
            return $init[ Arango::COLLECT ] ?? [] ;
        }

        $spec      = [] ;
        $assign    = $this->collectAssign( $group , $docRef , $init ) ;
        $aggregate = $this->collectAggregate( $group , $docRef , $init ) ;

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
     * @param string $field The field token written by the client (`sum:speed` → `speed`).
     * @param string $name  The aggregate output name, quoted in the strict error.
     *
     * @return string|null The resolved field path, or `null` when the aggregate is dropped.
     *
     * @throws ValidationException Under {@see AggregatablePolicy::STRICT}, naming the refused token.
     */
    private function aggregatableField( string $field , string $name ) :?string
    {
        $aggregatable = $this->aggregatable ;

        if ( is_array( $aggregatable ) && array_key_exists( $field , $aggregatable ) )
        {
            $entry = $aggregatable[ $field ] ;
            $path  = is_string( $entry ) || is_array( $entry ) ? key( $entry ) : Char::EMPTY ;
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
     * Builds the `AQL::AGGREGATE` map from {@see Group::AGG}.
     *
     * @param array $group The group spec.
     * @param string $docRef The document reference.
     *
     * @return array `[ outName => 'FN(doc.field)' ]`.
     *
     * @throws ValidationException
     */
    private function collectAggregate( array $group , string $docRef , array $init = [] ) :array
    {
        $agg = $group[ Group::AGG ] ?? null ;
        if ( !is_array( $agg ) || empty( $agg ) )
        {
            return [] ;
        }

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
            $field = $this->aggregatableField( (string) $field , (string) $name ) ;
            if ( $field === null )
            {
                continue ;
            }

            // Permission gate: aggregating a field hidden from the projection leaks
            // a bound of its value (MAX/MIN/AVG/SUM) — the aggregate is dropped. The
            // whole path is gated (not only its root), so a locked sub-field
            // (`address.city`) is honored in depth.
            if ( !isPathAuthorized( $field, $this->fields ?? null , $init ) )
            {
                continue ;
            }

            assertAttributeName( $field ) ; // guards against AQL injection through the field path.

            $out[ $name ] = func( $function , key( $field , $docRef ) ) ;
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
    private function collectAssign( array $group , string $docRef , array $init = [] ) :array
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

        $alt    = $group[ Group::ALT ] ?? [] ;
        $assign = [] ;
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

            $chain          = is_array( $alt ) ? ( $alt[ $var ] ?? null ) : null ;
            $assign[ $var ] = alterExpression( key( $field , $docRef ) , $chain ) ;
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
