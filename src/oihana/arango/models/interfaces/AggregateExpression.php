<?php

namespace oihana\arango\models\interfaces;

/**
 * An aggregate that is **computed** rather than read from one place.
 *
 * A `?group=` aggregate normally names a path, and the engine compiles
 * `FUNCTION(doc.path)`: one function, one place in the document, nothing in
 * between. Anything needing a composed read was out of reach — the sum of a slice
 * of an array, the sum of a difference between two arrays, any derived measure the
 * source does not store.
 *
 * An entry of {@see \oihana\arango\enums\Arango::AGGREGATABLE} may therefore hold
 * an implementation of this interface instead of a path. The library learns that an
 * aggregate can be computed; **what** it computes stays the business of the model
 * that declares it.
 *
 * 🔑 **The expression is per document, the aggregation stays with the engine.**
 * {@see self::compile()} returns a scalar — `SLICE(doc.pressure.values,3,3)` — and
 * the engine wraps it in the function the request asked for, exactly as it wraps a
 * path. `sum`, `avg`, `min` and `max` keep the meaning they had on a path.
 *
 * ### Two guards, and the reason `paths()` exists
 *
 * `assertAttributeName()` cannot apply here: an expression is not an attribute name
 * by construction. What replaces it is not trust, it is origin — an expression is
 * **always** a declaration of the consumer's own code, never a value from a request.
 * The caller only ever supplies a **public key already on the whitelist**, which
 * stays the only door, and everything coming from the wire enters through
 * {@see \oihana\arango\enums\Arango::BINDER} rather than through concatenation. It
 * is the same distinction {@see \oihana\arango\db\helpers\AltChain} draws between a
 * signed chain and a request one.
 *
 * 🚨 **And the permission gate must interrogate every path the expression reads.**
 * A path-based aggregate has exactly one path to gate; an expression has several —
 * that is its whole purpose. Gate none of them, or only the first, and a derived
 * expression becomes the way around `Field::REQUIRES`: a field closed to the
 * projection comes back out as a sum, in silence. Hence {@see self::paths()}: the
 * engine hands them **all** to `isPathAuthorized()`, and a single refusal withdraws
 * the whole aggregate.
 *
 * ⚠ **Implementations must be pure.** `compile()` runs more than once per request:
 * {@see \oihana\arango\models\traits\aql\GroupTrait::isGroupedQuery()} resolves the
 * `COLLECT` spec once to decide whether the query groups at all, and the query
 * builder resolves it again to build it. The binds of that first pass are thrown
 * away. An implementation that counted its calls, incremented a counter or cached
 * its first answer would emit a query that does not say what it means.
 *
 * @example
 * ```php
 * final class PressureWindow implements AggregateExpression
 * {
 *     public function paths() : array
 *     {
 *         return [ 'pressure.values' ] ;
 *     }
 *
 *     public function compile( string $docRef , array $init ) : ?string
 *     {
 *         $binder = $init[ Arango::BINDER ] ?? null ;
 *
 *         // A value from the request is bound, never written into the query text.
 *         $offset = is_callable( $binder ) ? $binder( 3 ) : 3 ;
 *
 *         return sprintf( 'SUM(SLICE(%s.pressure.values,%s,3))' , $docRef , $offset ) ;
 *     }
 * }
 *
 * // Declared, and reachable under its public key alone:
 * Arango::AGGREGATABLE => [ 'pressureWindow' => new PressureWindow() ] ,
 * // ?group={"by":"sensor","agg":{"total":"sum:pressureWindow"}}
 * ```
 *
 * @package oihana\arango\models\interfaces
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
interface AggregateExpression
{
    /**
     * Every document path this expression reads.
     *
     * Feeds the permission gate, and nothing else: these paths are never
     * interpolated into the query — {@see self::compile()} writes the AQL. They are
     * the dotted public paths the projection map declares, so a locked sub-field
     * (`pressure.values`) is recognised at the depth it is declared.
     *
     * ⚠ **An empty list withdraws the aggregate.** Read as "nothing to gate", it
     * would be exactly the way around `Field::REQUIRES` this interface is careful to
     * close; read as a refusal, a mis-declaration costs the aggregate and shows.
     * Declare what you read.
     *
     * @return array<int,string> The dotted paths, e.g. `[ 'pressure.values' , 'reference.values' ]`.
     */
    public function paths() : array ;

    /**
     * The per-document AQL expression, which the engine wraps in the requested
     * aggregate function.
     *
     * Must be pure — see the class note: it runs more than once per request.
     *
     * @param string                 $docRef The document reference to read from (`doc`, or the loop
     *                                       variable in use).
     * @param array<array-key,mixed> $init   The query init. Carries `Arango::BINDER`, the callable
     *                                       turning a value into a bind token, and
     *                                       `Arango::AUTHORIZER` when one is posed.
     *
     * @return string|null The expression, or `null` to withdraw this aggregate — the dimension,
     *                     the count and the group sort survive, exactly as they do for a path that
     *                     is not aggregatable.
     */
    public function compile( string $docRef , array $init ) : ?string ;
}
