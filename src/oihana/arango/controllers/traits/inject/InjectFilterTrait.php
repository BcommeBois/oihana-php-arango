<?php

namespace oihana\arango\controllers\traits\inject;

use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Provides filter injection helpers for ArangoDB-based controllers.
 *
 * Allows programmatic injection of filters that are transparently merged
 * with user-provided URL filters. Injected filters do NOT appear in the
 * response URL — they are passed via the `$init` array, not via query params.
 *
 * Usage in a controller:
 * ```php
 * public function list( ?Request $request , ?Response $response , array $args = [] , array $init = [] ) :mixed
 * {
 *     $this->injectFilter( $init , 'userId' , $userKey ) ; // $init is passed by reference
 *     return parent::list( $request , $response , $args , $init ) ;
 * }
 * ```
 *
 * Overrides `prepareFilter()` to merge URL filters with injected filters.
 *
 * @see FilterParam       for filter parameter keys (key, op, val, alt)
 * @see FilterComparator  for comparison operators (eq, ne, gt, ge, lt, le, like, in, etc.)
 *
 * @package oihana\arango\controllers\traits\inject
 * @author  Marc Alcaraz
 */
trait InjectFilterTrait
{
    /**
     * Init key for injected filters (internal, not exposed to user).
     */
    protected const string INJECTED_FILTERS = '__injectedFilters' ;

    /**
     * Injects a single filter into the `$init` array — modified in place.
     *
     * The filter will be transparently merged with any user-provided URL filters
     * in `prepareFilter()` without appearing in the response URL.
     *
     * @param array       $init  The init array to enrich (passed by reference).
     * @param string      $key   The field name to filter on.
     * @param mixed       $value The filter value.
     * @param string      $op    The comparison operator (default: FilterComparator::EQ).
     * @param string|null $alt   Optional alteration function (e.g., 'lower', 'length').
     *
     * @return void
     */
    protected function injectFilter( array &$init , string $key , mixed $value , string $op = FilterComparator::EQ , ?string $alt = null ) :void
    {
        $filter =
        [
            FilterParam::KEY => $key ,
            FilterParam::OP  => $op ,
            FilterParam::VAL => $value ,
        ] ;

        if( $alt !== null )
        {
            $filter[ FilterParam::ALT ] = $alt ;
        }

        $init[ self::INJECTED_FILTERS ]   = $init[ self::INJECTED_FILTERS ] ?? [] ;
        $init[ self::INJECTED_FILTERS ][] = $filter ;
    }

    /**
     * Injects a logic group into the `$init` array — modified in place.
     *
     * Injected filters stack as siblings, and siblings combine with AND — so
     * `injectFilter()` cannot express a **disjunctive** scope ("targeted at me OR
     * at everyone", "mine OR public"). This does: the group is stored as a single
     * injected entry, so `prepareFilter()` merges it as ONE operand and yields
     * `(a || b) && <the caller's filter>`, never `a || b || <the caller's filter>` —
     * which would degrade a server-side restriction into an alternative.
     *
     * ```php
     * $this->injectFilterGroup( $init , FilterLogic::OR ,
     * [
     *     [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => $userKey ] ,
     *     [ FilterParam::KEY => 'public'  , FilterParam::VAL => true     ] ,
     * ]) ;
     * // -> FILTER ( ( doc.ownerId == @a || doc.public == true ) && <url filter> )
     * ```
     *
     * Operands are filter definitions ({@see FilterParam} keys) or nested groups,
     * so an arbitrary tree can be built. An empty `$filters` injects nothing —
     * a group with no operand would otherwise widen the result set to everything,
     * which is never what a scope means.
     *
     * @param array  $init    The init array to enrich (passed by reference).
     * @param string $logic   A {@see FilterLogic} operator (`and` / `or` / `not`).
     * @param array  $filters The group's operands: filter definitions, or nested groups.
     *
     * @return void
     */
    protected function injectFilterGroup( array &$init , string $logic , array $filters ) :void
    {
        if( empty( $filters ) )
        {
            return ;
        }

        $init[ self::INJECTED_FILTERS ]   = $init[ self::INJECTED_FILTERS ] ?? [] ;
        $init[ self::INJECTED_FILTERS ][] = [ $logic , ...$filters ] ;
    }

    /**
     * Injects multiple filters into the `$init` array at once — modified in place.
     *
     * Each filter is an array with keys from FilterParam (KEY, VAL, OP, ALT).
     *
     * Example:
     * ```php
     * $this->injectFilters( $init ,
     * [
     *     [ FilterParam::KEY => 'agent'   , FilterParam::VAL => $userKey ] ,
     *     [ FilterParam::KEY => 'method'  , FilterParam::VAL => 'DELETE' ] ,
     *     [ FilterParam::KEY => 'created' , FilterParam::VAL => '2026-01-01' , FilterParam::OP => FilterComparator::GE ] ,
     * ]) ;
     * ```
     *
     * @param array $init    The init array to enrich (passed by reference).
     * @param array $filters Array of filter definitions.
     *
     * @return void
     */
    protected function injectFilters( array &$init , array $filters ) :void
    {
        foreach( $filters as $filter )
        {
            $this->injectFilter
            (
                $init ,
                $filter[ FilterParam::KEY ] ,
                $filter[ FilterParam::VAL ] ,
                $filter[ FilterParam::OP  ] ?? FilterComparator::EQ ,
                $filter[ FilterParam::ALT ] ?? null
            ) ;
        }
    }

    /**
     * Overrides PrepareFilter::prepareFilter to merge URL filters with injected filters.
     *
     * URL filters are processed normally (stored in $params for URL display).
     * Injected filters are appended transparently (NOT stored in $params).
     *
     * The URL filter always enters the merge as a SINGLE operand of an explicit
     * `and` group, never spliced into it. A filter carries three shapes — a single
     * filter (`{key,op,val}`), a plain list (an implicit `and`), and a logic group
     * (`['or', …]`) — and splicing a group would leave its operator in head
     * position, absorbing the injected filters into the caller's own logic: a
     * server-side restriction would silently degrade into an alternative
     * (`a || b || scope` instead of `(a || b) && scope`), which is a scope bypass
     * on every surface that injects a permission scope. Wrapping is shape-agnostic
     * — the filter parser recurses, so the URL filter is resolved whole, in its own
     * parentheses, and never meets the injected ones.
     *
     * @param Request|null $request The PSR-7 request.
     * @param array $args The init/args array (may contain INJECTED_FILTERS).
     * @param array|null $params Reference to params array for URL generation.
     *
     * @return array|null The merged filter array or null.
     */
    protected function prepareFilter( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        // Get the URL filter using the parent method
        $urlFilter = parent::prepareFilter( $request , $args , $params ) ;

        // Get the injected filters
        $injected = $args[ self::INJECTED_FILTERS ] ?? null ;

        if( empty( $injected ) )
        {
            return $urlFilter ;
        }

        // Merge: URL filter(s) + injected filter(s)
        if( $urlFilter === null )
        {
            // No URL filter — only injected
            return count( $injected ) === 1 ? $injected[0] : $injected ;
        }

        // URL filter exists — combine it as one operand, whatever its shape.
        return [ FilterLogic::AND , $urlFilter , ...$injected ] ;
    }
}
