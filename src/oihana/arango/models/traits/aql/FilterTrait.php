<?php

namespace oihana\arango\models\traits\aql;

use DI\DependencyException;
use DI\NotFoundException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Filter;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterType;
use oihana\arango\models\traits\aql\filters\HasFilterArray;
use oihana\arango\models\traits\aql\filters\HasFilterBoolean;
use oihana\arango\models\traits\aql\filters\HasFilterConditions;
use oihana\arango\models\traits\aql\filters\HasFilterDate;
use oihana\arango\models\traits\aql\filters\HasFilterGeo;
use oihana\arango\models\traits\aql\filters\HasFilterDocumentation;
use oihana\arango\models\traits\aql\filters\HasFilterNumber;
use oihana\arango\models\traits\aql\filters\HasFilterString;
use oihana\arango\models\traits\aql\filters\HasHierarchicalFilter;
use oihana\enums\Boolean;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\arrayMap;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\buildBetweenClauses;
use function oihana\arango\db\helpers\resolveAltSides;
use function oihana\arango\models\helpers\isAttributeAuthorized;
use function oihana\core\arrays\isAssociative;
use function oihana\core\callables\resolveCallable;
use function oihana\core\strings\key;

/**
 * Defines the 'filtering' strategy property in the models (AQL Documents) definition.
 *
 * ```
 * Models::APIS => fn( ContainerInterface $container ) => new Documents
 * (
 *     $container ,
 *     [
 *         AQL::COLLECTION => Collections::APIS ,
 *         ...
 *         AQL::FILTERS =>
 *         [
 *              Prop::ACTIVE     => FilterType::BOOL ,
 *              Prop::CREATED    => FilterType::DATE ,
 *              Prop::IDENTIFIER => FilterType::STRING ,
 *              Prop::NAME       => FilterType::STRING ,
 *         ]
 *         ...
 * ```
 *
 * ### Usage in the routes parameters
 * ```
 * ?filter={ "key":"key", "val":"value" , "op":"operator" , "alt":"func" , ...options }
 * ```
 *
 * ### Conditions filters (group)
 * ```
 * ?filter=[ condition1 , condition2 ]
 * -> FILTER (condition1 && condition2)
 *
 * ?filter=[ "and" , condition1 , condition2 ]
 * -> FILTER (condition1 && condition2)
 *
 * ?filter=[ "or"  , condition1 , condition2 ]
 * -> FILTER (condition1 || condition2)
 *
 * ?filter=[ "and" , ["or",condition1,condition2],["or",condition3,condition4]]
 * -> FILTER ( (condition1 || condition2) && (condition3 || condition4) )
 *
 * ?filter=[ "not" , condition]
 * -> FILTER !(condition)
 * ```
 * Example :
 * ```
 * ?filter=["or",{"key":"name","val":"marc"},{"key":"identifier","val":"xyz"}]
 * -> FILTER (doc.name=="marc" || doc.identifier=="xyz")
 * ```
 *
 * ### Basic operators
 *
 * **equals**
 * ```
 * ?filter={ "key":"name" , "op":"eq" , "val":"xyz" }
 * -> FILTER doc.name == "xyz"
 * ```
 *
 * **not equals**
 * ```
 * ?filter={ "key":"name" , "op":"ne" , "val":"xyz" }
 * -> FILTER doc.name != "xyz"
 * ```
 *
 * **greater than**
 * ```
 * ?filter={ "key":"name" , "op":"gt" , "val":"xyz" }
 * -> FILTER doc.name > "xyz"
 * ```
 *
 * **greater than or equals**
 * ```
 * ?filter={ "key":"name" , "op":"ge" , "val":"xyz" }
 * -> FILTER doc.name >= "xyz"
 * ```
 *
 * **less than**
 * ```
 * ?filter={ "key":"name" , "op":"lt" , "val":"xyz" }
 * -> FILTER doc.name < "xyz"
 * ```
 *
 * **less than or equals**
 * ```
 * ?filter={ "key":"name" , "op":"le" , "val":"xyz" }
 * -> FILTER doc.name <= "xyz"
 * ```
 *
 * **like**
 * ```
 * ?filter={ "key":"name" , "op":"like" , "val":"xyz%" }
 * -> FILTER doc.name LIKE "xyz%"
 * ```
 *
 * **not like**
 * ```
 * ?filter={ "key":"name" , "op":"nlike" , "val":"%xyz" }
 * -> FILTER doc.name NOT LIKE "%xyz"
 * ```
 *
 * **in**
 * ```
 * ?filter={ "key":"category" , "op":"in" , "val":["xyz","abc"] }
 * -> FILTER doc.category IN ["xyz","abc"]
 * ```
 *
 * **not in**
 * ```
 * ?filter={ "key":"name" , "op":"nin" , "val":["xyz","abc"] }
 * -> FILTER doc.name NOT IN ["xyz","abc"]
 * ```
 *
 * **match (regex)**
 * ```
 * ?filter={ "key":"name" , "op":"match" , "val":"^f[o].$" }
 * -> FILTER doc.name =~ "^f[o].$"
 * ```
 *
 * **not match (regex)**
 * ```
 * ?filter={ "key":"name" , "op":"nmatch" , "val":"[a-z]+bar$" }
 * -> FILTER doc.name !~ "[a-z]+bar$"
 * ```
 *
 * ### Boolean filters
 * ```
 * ?filter={ "key":"active" , "val":true  }
 * -> FILTER doc.active == true
 *
 * ?filter={ "key":"active" , "val":false }
 * -> FILTER doc.active == false
 * ```
 *
 * ### Number filters
 * ```
 * ?filter={ "key":"price" , "val":25 }
 * -> FILTER doc.price == 25
 *
 * ?filter={ "key":"price" , "val":25 , "op":"ge" }
 * -> FILTER doc.price >= 25
 *
 * ?filter={ "key":"price" , "val":25 , "op":"ge" , "alt":"abs" }
 * -> FILTER ABS(doc.price) >= 25
 * ```
 *
 * ### String filters
 * ```
 * ?filter={ "key":"name" , "val":"ekameleon" }
 * -> FILTER doc.name == "ekameleon"
 *
 * ?filter={ "key":"name" , "val":9 , "alt":"length" }
 * -> FILTER LENGTH(doc.name) == 9
 *
 * ?filter={ "key":"name" , "val":"ekameleon" , "alt":"lower" }
 * -> FILTER LOWER(doc.name) == "ekameleon"
 *
 * ?filter={ "key":"name" , "val":"ekameleon" , "alt":"trim" , "type":0 }
 * -> FILTER TRIM(doc.name, 0) == "ekameleon"
 *
 * ?filter={ "key":"name" , "val":"EKAMELEON" , "alt":"upper" }
 * -> FILTER UPPER(doc.name) == "EKAMELEON"
 * ```
 *
 * ### Date filters
 * ```
 * ?filter={ "key":"created" , "val":"2024-01-01" , "op":"ge" }
 * -> FILTER doc.created >= "2024-01-01"
 *
 * ?filter={ "key":"created" , "val":"2024-01-01" , "op":"between" , "max":"2024-12-31" }
 * -> FILTER doc.created >= "2024-01-01" && doc.created <= "2024-12-31"
 * ```
 *
 * ### Array filters with functions
 * ```
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"avg" }
 * -> FILTER AVERAGE(doc.values) >= 10
 *
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"count" }
 * -> FILTER LENGTH(doc.values) >= 10
 *
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"max" }
 * -> FILTER MAX(doc.values) >= 10
 *
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"sum" }
 * -> FILTER SUM(doc.values) >= 10
 * ```
 *
 * ### Array filters with comparators (ALL, ANY, NONE)
 * ```
 * ?filter={ "key":"values" , "op":"all.eq" , "val":4 }
 * -> FILTER doc.values ALL == 4
 *
 * ?filter={ "key":"values" , "op":"any.gt" , "val":100 }
 * -> FILTER doc.values ANY > 100
 *
 * ?filter={ "key":"values" , "op":"none.in" , "val":[1,2,3] }
 * -> FILTER doc.values NONE IN [1,2,3]
 * ```
 *
 * ### Hierarchical filters - Nested documents
 * ```
 * ?filter={ "key":"address.email" , "val":"john@doe.com" }
 * -> FILTER doc.address.email == "john@doe.com"
 *
 * ?filter={ "key":"address.postalCode" , "val":"75001" }
 * -> FILTER doc.address.postalCode == "75001"
 * ```
 *
 * ### Hierarchical filters - Array expansion
 * ```
 * ?filter={ "key":"contactPoint[*].email" , "op":"ne" , "val":null }
 * -> FILTER LENGTH(doc.contactPoint[* FILTER CURRENT.email != null]) > 0
 *
 * ?filter={ "key":"contactPoint[*].email" , "val":"admin@acme.com" }
 * -> FILTER LENGTH(doc.contactPoint[* FILTER CURRENT.email == "admin@acme.com"]) > 0
 *
 * ?filter={ "key":"contactPoint[*].telephone" , "op":"like" , "val":"06%" }
 * -> FILTER LENGTH(doc.contactPoint[* FILTER CURRENT.telephone LIKE "06%"]) > 0
 * ```
 *
 * ### Hierarchical filters - Array expansion with combined conditions (match)
 *
 * **Simple syntax (all fields use "eq" operator, combined with AND logic):**
 * ```
 * ?filter={ "key":"additionalProperty[*]" , "match":{ "propertyID":"generateReceipt" , "value":true } }
 * -> FILTER LENGTH(doc.additionalProperty[* FILTER CURRENT.propertyID == "generateReceipt" && CURRENT.value == true]) > 0
 * ```
 *
 * **Explicit syntax with ALL logic (AND - all conditions must be true):**
 * ```
 * ?filter={
 *   "key":"additionalProperty[*]",
 *   "match":{
 *     "all":[
 *       {"key":"propertyID","op":"eq","val":"generateReceipt"},
 *       {"key":"value","op":"eq","val":false}
 *     ]
 *   }
 * }
 * -> FILTER LENGTH(doc.additionalProperty[* FILTER CURRENT.propertyID == "generateReceipt" && CURRENT.value == false]) > 0
 * ```
 *
 * **ANY logic (OR - at least one condition must be true):**
 * ```
 * ?filter={
 *   "key":"contactPoint[*]",
 *   "match":{
 *     "any":[
 *       {"key":"email","op":"ne","val":null},
 *       {"key":"telephone","op":"ne","val":null}
 *     ]
 *   }
 * }
 * -> FILTER LENGTH(doc.contactPoint[* FILTER CURRENT.email != null || CURRENT.telephone != null]) > 0
 * ```
 *
 * **NONE logic (NOT - no condition must be true):**
 * ```
 * ?filter={
 *   "key":"additionalProperty[*]",
 *   "match":{
 *     "none":[
 *       {"key":"propertyID","op":"eq","val":"archived"},
 *       {"key":"propertyID","op":"eq","val":"deleted"}
 *     ]
 *   }
 * }
 * -> FILTER LENGTH(doc.additionalProperty[* FILTER !(CURRENT.propertyID == "archived" || CURRENT.propertyID == "deleted")]) > 0
 * ```
 *
 * ### Hierarchical filters - Edges (single level)
 * ```
 * ?filter={ "key":"employee[*].givenName" , "val":"John" }
 * -> FILTER LENGTH(FOR v IN OUTBOUND doc edge FILTER v.givenName == "John" LIMIT 1 RETURN 1) > 0
 *
 * ?filter={ "key":"employee[*].familyName" , "op":"like" , "val":"Do%" }
 * -> FILTER LENGTH(FOR v IN OUTBOUND doc edge FILTER v.familyName LIKE "Do%" LIMIT 1 RETURN 1) > 0
 * ```
 *
 * ### Hierarchical filters - Edges (nested multi-level)
 * ```
 * ?filter={ "key":"employee[*].workLocation.address.email" , "val":"office@acme.com" }
 * -> FILTER LENGTH(FOR v1 IN OUTBOUND doc edge1
 *      FILTER LENGTH(FOR v2 IN OUTBOUND v1 edge2
 *        FILTER v2.address.email == "office@acme.com"
 *        LIMIT 1 RETURN 1) > 0
 *      LIMIT 1 RETURN 1) > 0
 *
 * ?filter={ "key":"employee[*].workLocation.name" , "op":"like" , "val":"%Paris%" }
 * -> FILTER LENGTH(FOR v1 IN OUTBOUND doc edge1
 *      FILTER LENGTH(FOR v2 IN OUTBOUND v1 edge2
 *        FILTER v2.name LIKE "%Paris%"
 *        LIMIT 1 RETURN 1) > 0
 *      LIMIT 1 RETURN 1) > 0
 * ```
 *
 * ### Hierarchical filters - Array expansion within edges
 * ```
 * ?filter={ "key":"employee[*].contactPoint[*].email" , "op":"ne" , "val":null }
 * -> FILTER LENGTH(FOR v IN OUTBOUND doc edge
 *      FILTER LENGTH(v.contactPoint[* FILTER CURRENT.email != null]) > 0
 *      LIMIT 1 RETURN 1) > 0
 *
 * ?filter={ "key":"employee[*].contactPoint[*].email" , "op":"like" , "val":"%@gmail.com" }
 * -> FILTER LENGTH(FOR v IN OUTBOUND doc edge
 *      FILTER LENGTH(v.contactPoint[* FILTER CURRENT.email LIKE "%@gmail.com"]) > 0
 *      LIMIT 1 RETURN 1) > 0
 * ```
 *
 * ### Hierarchical filters - Joins
 * ```
 * ?filter={ "key":"assignedSeller.name" , "val":"John Doe" }
 * -> FILTER LENGTH(FOR j IN collection FILTER j.id == doc.assignedSeller && j.name == "John Doe" LIMIT 1 RETURN 1) > 0
 *
 * ?filter={ "key":"assignedSeller.id" , "val":"300" }
 * -> FILTER LENGTH(FOR j IN collection FILTER j.id == doc.assignedSeller && j.id == "300" LIMIT 1 RETURN 1) > 0
 * ```
 *
 * ### Complex hierarchical examples
 * ```
 * // Multiple conditions on different arrays (separate elements can satisfy each condition)
 * ?filter=[
 *   {"key":"additionalProperty[*].propertyID","val":"generateReceipt"},
 *   {"key":"additionalProperty[*].value","val":true}
 * ]
 * -> FILTER LENGTH(doc.additionalProperty[* FILTER CURRENT.propertyID == "generateReceipt"]) > 0
 *    AND LENGTH(doc.additionalProperty[* FILTER CURRENT.value == true]) > 0
 *
 * // Same element must satisfy all conditions (use match)
 * ?filter={
 *   "key":"additionalProperty[*]",
 *   "match":{
 *     "propertyID":"generateReceipt",
 *     "value":true
 *   }
 * }
 * -> FILTER LENGTH(doc.additionalProperty[* FILTER CURRENT.propertyID == "generateReceipt" && CURRENT.value == true]) > 0
 *
 * // Complex nested traversal with multiple levels
 * ?filter={ "key":"location[*].address.email" , "val":"site@acme.com" }
 * -> FILTER LENGTH(FOR v IN OUTBOUND doc edge FILTER v.address.email == "site@acme.com" LIMIT 1 RETURN 1) > 0
 * ```
 */
trait FilterTrait
{
    use BindTrait               ,
        HasFilterArray          ,
        HasFilterBoolean        ,
        HasFilterConditions     ,
        HasFilterDate           ,
        HasFilterGeo            ,
        HasFilterNumber         ,
        HasFilterString         ,
        HasFilterDocumentation  ,
        HasHierarchicalFilter   ;

    /**
     * Defines all valid filtering conditions for queries used in the list() and count() methods.
     */
    public ?array $filters = [] ;

    /**
     * The 'filters' parameter constant.
     */
    public const string FILTERS = 'filters' ;

    /**
     * Initialize the 'filters' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeFilters( array $init = [] ):static
    {
        $this->filters = $init[ self::FILTERS ] ?? $this->filters ;
        return $this ;
    }

    /**
     * Prepare the AQL query filtering with specific definitions.
     *
     * @param array|null $init
     * @param ?array $binds
     * @param string $docRef
     *
     * @return ?string
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function prepareFilter
    (
        ?array $init   = [] ,
        ?array &$binds = null ,
        string $docRef = AQL::DOC ,
        array  $auth   = []
    )
    :?string
    {
        // Capture the request-level authorizer BEFORE `$init` is narrowed to the
        // filter payload — it powers the permission gate at every nesting depth.
        // The root call reads it from `$init`; recursive calls receive it via `$auth`.
        if ( empty( $auth ) && is_array( $init ) && array_key_exists( Arango::AUTHORIZER , $init ) )
        {
            $auth = [ Arango::AUTHORIZER => $init[ Arango::AUTHORIZER ] ] ;
        }

        $init = $init[ Arango::FILTER ] ?? $init ?? null ;

        if ( !is_array( $this->filters ) || empty( $this->filters ) || !is_array( $init ) || empty( $init ) )
        {
            return null;
        }

        if( isAssociative( $init ) )
        {
            $key = $init[ FilterParam::KEY ] ?? null ;

            if ( !isset( $key ) )
            {
                return null ;
            }

            // Permission gate: a field hidden from the projection (Field::REQUIRES)
            // stays unfilterable (no filter oracle). The refused predicate is
            // NEUTRALISED to `false` — never dropped, which would loosen an AND —
            // so it composes safely in and/or/not (a && false → ∅, a || false → a,
            // NOT false → true). The subject is inherited from the homonymous root
            // field; the leaf of a deeper path is gated by its own relation (Gate C)
            // or left to a later pass. Gates compose (AND) with the relation gate.
            $rootSegment = str_replace( Operator::ARRAY_EXPANSION , Char::EMPTY , explode( Char::DOT , $key )[ 0 ] ) ;

            if ( property_exists( $this , 'fields' ) && !isAttributeAuthorized( $rootSegment , $this->fields , $auth ) )
            {
                return Boolean::FALSE ;
            }

            if ( str_contains( $key , Char::DOT ) )
            {
                return $this->prepareHierarchicalFilter( $init , $binds , $docRef , $auth ) ;
            }

            // A single-segment relation key (e.g. `members[*]` for an edge, or a
            // `company` join) carries no leaf field: it is a pure existence /
            // absence / cardinality check on the relation itself. Route it to the
            // hierarchical builder, which owns the traversal logic and `quant`.
            $relationKey = str_replace( Operator::ARRAY_EXPANSION , '' , $key ) ;
            $relationDef = $this->filters[ $relationKey ] ?? null ;

            if ( is_array( $relationDef ) && in_array( $relationDef[ AQL::TYPE ] ?? null , [ Filter::EDGE , Filter::EDGES , Filter::JOIN , Filter::JOINS ] , true ) )
            {
                return $this->prepareHierarchicalFilter( $init , $binds , $docRef , $auth ) ;
            }

            if ( str_contains( $key , Operator::ARRAY_EXPANSION ) && isset( $init[ FilterParam::MATCH ] ) )
            {
                // Extract base key (e.g., "additionalProperty[*]" → "additionalProperty")
                $baseKey = str_replace( Operator::ARRAY_EXPANSION , '' , $key ) ;

                // Check if base key exists in filters
                $definition = $this->filters[ $baseKey ] ?? null ;

                if ( $definition !== null )
                {
                    return $this->prepareFilterArray( $init , $binds , $docRef ) ;
                }
            }

            $definition = $this->filters[ $key ] ?? null ;

            if( is_string( $definition ) && FilterType::includes( $definition ) )
            {
                return match( $definition )
                {
                    FilterType::ARRAY   => $this->prepareFilterArray   ( $init , $binds , $docRef ) ,
                    FilterType::BOOL    => $this->prepareFilterBoolean ( $init , $binds , $docRef ) , // ?filter={ "key":"flag"  , "val":true    }
                    FilterType::DATE    => $this->prepareFilterDate    ( $init , $binds , $docRef ) , // ?filter={ "key":"hello" , "val":"world" }
                    FilterType::GEO     => $this->prepareFilterGeo     ( $init , $binds , $docRef ) , // ?filter={ "key":"geo" , "op":"distance" , "val":{ "latitude":48.85 , "longitude":2.35 } , "max":5000 }
                    FilterType::NUMBER  => $this->prepareFilterNumber  ( $init , $binds , $docRef ) ,
                    FilterType::STRING  => $this->prepareFilterString  ( $init , $binds , $docRef ) , // ?filter={ "key":"hello" , "val":"world" }
                    // FilterType::VIRTUAL => null -> declared filterable but emits no AQL predicate — see FilterType::VIRTUAL
                    default             => null
                };
            }
            else if ( ( $customFilter = resolveCallable( $definition ) ) !== null )
            {
                return $customFilter( $init , $binds , $docRef ) ;
            }
            else
            {
                $this->logger->warning( __METHOD__ . ' failed , the key: "' . $key . '" is not a valid filterable attribute' ) ;
            }
        }
        else
        {
            return $this->prepareFilterConditions( $init , $binds , $docRef , $auth ) ;
        }

        return null ;
    }

    /**
     * Apply the key-side (left) `alt` transformation to a key expression.
     *
     * Thin wrapper over {@see static::alterExpression()}: it resolves the `alt`
     * parameter into its key/value sides via {@see static::resolveAltSides()} and
     * applies the key-side chain. The three legacy `alt` forms (string, list of
     * functions, function-with-params) keep transforming the key only, unchanged.
     *
     * @param string $key The key expression to transform.
     * @param array $init Filter initialization array containing the 'alt' parameter.
     *
     * @return string The transformed key expression.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function alterFilterKey( string $key , array $init = [] ): string
    {
        [ $keyChain ] = resolveAltSides( $init[ FilterParam::ALT ] ?? null ) ;
        return alterExpression( $key , $keyChain , $init ) ;
    }

    /**
     * Prepares an inclusive `between` (range) clause: `key >= @min && key <= @max`.
     *
     * The compared key is alt-aware (it flows through {@see static::alterFilterKey()}).
     * Each bound is resolved by `$resolve`, which differs per filter type — a raw
     * bind for numbers/strings, the date machinery (now / timezone) for dates.
     *
     * Bound omission is type-driven:
     * - `$defaultBounds = false` (number/string): an omitted bound drops its side,
     *   yielding a one-sided range (`key >= @min` or `key <= @max`).
     * - `$defaultBounds = true` (date): an omitted bound still emits a clause; the
     *   resolver maps the null value to "now", so the range is always two-sided.
     *
     * @param array $init The filter init (reads `min` / `max`).
     * @param ?array $binds The bind variables, populated by reference.
     * @param string $docRef The document reference.
     * @param callable $resolve fn(mixed $value, ?array &$binds): string — resolves a bound to AQL.
     * @param bool $defaultBounds Whether an omitted bound still emits a clause (dates) or is dropped.
     *
     * @return string
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterBetween( array $init , ?array &$binds , string $docRef , callable $resolve , bool $defaultBounds ) :string
    {
        $left = $this->prepareFilterKey( $init , $docRef ) ;

        $min = ( array_key_exists( FilterParam::MIN , $init ) || $defaultBounds ) ? $resolve( $init[ FilterParam::MIN ] ?? null , $binds ) : null ;
        $max = ( array_key_exists( FilterParam::MAX , $init ) || $defaultBounds ) ? $resolve( $init[ FilterParam::MAX ] ?? null , $binds ) : null ;

        return buildBetweenClauses( $left , $min , $max ) ;
    }

    /**
     * Prepares the filter clause with a specific operator.
     * @param array $init
     * @return string
     */
    protected function prepareFilterComparator( array $init = [] ):string
    {
        return FilterComparator::getAlias($init[ FilterParam::OP ] ?? null ) ;
    }

    /**
     * Prepares the filter clause with a specific key and document,
     * with optional function transformations via 'alt' parameter.
     *
     * Supports function chaining:
     * - Single function: "alt":"lower"
     * - Multiple functions: "alt":["trim","lower"]
     * - Functions with params: "alt":[["trim",1],"lower"]
     *
     * @param string|array|null $init Filter initialization array
     * @param string $docRef Document reference (default: AQL::DOC)
     *
     * @return string The transformed key expression
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     *
     * @example
     * ```php
     * // Simple key
     * prepareFilterKey(['key' => 'name'], 'doc')
     * // Returns: "doc.name"
     *
     * // With single function
     * prepareFilterKey(['key' => 'name', 'alt' => 'lower'], 'doc')
     * // Returns: "LOWER(doc.name)"
     *
     * // With function chain
     * prepareFilterKey(['key' => 'name', 'alt' => ['trim', 'lower']], 'doc')
     * // Returns: "LOWER(TRIM(doc.name))"
     *
     * // With parameters
     * prepareFilterKey(['key' => 'code', 'alt' => [['substring', 0, 3]]], 'doc')
     * // Returns: "SUBSTRING(doc.code, 0, 3)"
     * ```
     */
    protected function prepareFilterKey
    (
        string|array|null $init   = [] ,
        string            $docRef = AQL::DOC
    )
    :string
    {
        $key = key( $init[ FilterParam::KEY ] ?? null , $docRef ) ;
        return $this->alterFilterKey( $key , $init ) ;
    }

    /**
     * Prepare the filter clause with a specific value to evaluates.
     *
     * Binds the raw value, then applies the value-side (right) `alt` chain when
     * one is set (object form `alt:{ key:.. , val:.. }` or `val:true` mirror):
     * - scalar value → the chain wraps the bind placeholder, e.g. `LOWER(@value)`.
     * - array value (e.g. `op:in`) → the chain is mapped over each element via an
     *   inline projection, e.g. `@value[* RETURN LOWER(CURRENT)]`. The single bind
     *   still holds the whole array, so existing binding behavior is preserved.
     *
     * @param array|null $init
     * @param array|null $binds
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterValue( ?array $init = [] , ?array &$binds = null ):string
    {
        $value = $init[ FilterParam::VAL ] ?? null ;
        $bound = $this->bind( $value , $binds ) ;

        [ , $valChain ] = resolveAltSides( $init[ FilterParam::ALT ] ?? null ) ;

        if ( $valChain === null )
        {
            return $bound ;
        }

        if ( is_array( $value ) )
        {
            return arrayMap( $bound , alterExpression( Clause::CURRENT , $valChain , $init ) ) ;
        }

        return alterExpression( $bound , $valChain , $init ) ;
    }
}