<?php

namespace oihana\arango\cache;

use Closure;
use Memcached;

use Psr\Log\LoggerInterface;

use Throwable;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

/**
 * Resolves — and caches — a set of field values that documents INHERIT from
 * their ancestors.
 *
 * {@see DocumentFieldSetResolver} answers "which documents match this filter?".
 * This one answers "which documents match it, **or descend from one that does**?",
 * for the trees whose identifiers encode the hierarchy: the parent is computed
 * from the identifier itself, so the ancestry is walked without ever reading the
 * graph.
 *
 * The parentage rule belongs to the consumer, not to the lib: it is injected as
 * a closure taking an identifier and returning its parent — or `null` at a root.
 *
 * ```php
 * $resolver = new InheritedFieldSetResolver
 * (
 *     model    : $terms ,
 *     cache    : $memcached ,
 *     cacheKey : 'terms.disabled.inherited' ,
 *     filter   : [ 'status' => 'disabled' ] ,
 *     parent   : fn( int|string $id ) => strlen( (string) $id ) > 3
 *              ? substr( (string) $id , 0 , -2 )
 *              : null
 * ) ;
 * ```
 *
 * Two reads at most, and the second one is conditional:
 * 1. the **seed** set — the documents matching the filter, exactly like the sibling;
 * 2. **nothing more** when that set is empty. This is the nominal case, and it must
 *    not cost a single extra query;
 * 3. otherwise the whole collection, each value walking up its ancestors until one
 *    of them belongs to the seed set.
 *
 * The NATIVE type of each value is preserved, never normalised — the doctrine of
 * the sibling, and it matters twice as much here: AQL does not coerce across
 * types (`5 NOT IN ["5"]` is true), so a normalised set would filter nothing at
 * all, silently. Membership is tested strictly, and the consumer's closure is
 * expected to return the type its own documents carry.
 *
 * **Fail-open, but graduated**: a failing read must never WIDEN the resolved set.
 * A failing seed read yields an empty set, like the sibling; a failing expansion
 * yields the seed set alone rather than nothing. Both are logged, distinctly, and
 * neither propagates.
 *
 * @package oihana\arango\cache
 * @author  Marc Alcaraz
 * @since   1.6.0
 */
class InheritedFieldSetResolver extends DocumentFieldSetResolver
{
    /**
     * Creates a new InheritedFieldSetResolver.
     *
     * @param Documents            $model    The collection to read.
     * @param Memcached            $cache    The shared Memcached connection.
     * @param string               $cacheKey Cache key — REQUIRED and unique per resolver: two resolvers behind one key would serve each other's set.
     * @param array|null           $filter   The filter selecting the SEED documents, in the model filter shape. `null` seeds with the whole collection.
     * @param Closure              $parent   The parentage rule: `fn( int|string $id ) : int|string|null`, returning `null` at a root. It carries no default, hence the mandatory `$filter` before it.
     * @param string               $field    The field whose values are collected, and the one the closure receives.
     * @param int                  $ttl      Cache TTL in seconds. `0` disables the cache (debugging only).
     * @param LoggerInterface|null $logger   Optional logger.
     */
    public function __construct
    (
        Documents         $model    ,
        Memcached         $cache    ,
        string            $cacheKey ,
        ?array            $filter   ,
        protected Closure $parent   ,
        string            $field    = self::DEFAULT_FIELD ,
        int               $ttl      = self::DEFAULT_TTL   ,
        ?LoggerInterface  $logger   = null
    )
    {
        parent::__construct( $model , $cache , $cacheKey , $filter , $field , $ttl , $logger ) ;
    }

    /**
     * How many ancestors a single walk may climb before it is abandoned.
     *
     * A faulty closure can loop — returning its own input, or two identifiers
     * pointing at each other. The depth cap and the per-path memory below make
     * such a closure a logged non-event rather than a hung worker.
     */
    public const int MAX_DEPTH = 32 ;

    /**
     * Adds the documents inheriting from the seed set.
     *
     * @param array<int,int|string> $seed The non-empty seed set.
     *
     * @return array<int,int|string>
     */
    protected function expand( array $seed ) : array
    {
        try
        {
            $inherited = [] ;

            foreach ( $this->collect( null ) as $value )
            {
                if ( !in_array( $value , $seed , true ) && $this->inherits( $value , $seed ) )
                {
                    $inherited[] = $value ;
                }
            }
        }
        catch ( Throwable $error )
        {
            $this->logger?->error( static::class . ': failed to expand ' . $this->cacheKey . ', falling back on the seed set: ' . $error->getMessage() ) ;
            return $seed ;
        }

        return array_values( array_unique( array_merge( $seed , $inherited ) ) ) ;
    }

    /**
     * Walks the ancestors of a value, looking for one that belongs to the seed set.
     *
     * The walk starts at the PARENT: a value already in the seed set has nothing
     * to prove, and is filtered out before this is called.
     *
     * @param int|string            $value The value to walk up from.
     * @param array<int,int|string> $seed  The seed set.
     *
     * @return bool `true` as soon as an ancestor belongs to the seed set.
     */
    protected function inherits( int|string $value , array $seed ) : bool
    {
        $current = $value ;
        $walked  = [ $value ] ;

        for ( $depth = 0 ; $depth < static::MAX_DEPTH ; $depth ++ )
        {
            $ancestor = ( $this->parent )( $current ) ;

            // A root — or anything the walk cannot use, held to the same standard
            // as a collected value.
            if ( !is_int( $ancestor ) && !( is_string( $ancestor ) && $ancestor !== '' ) )
            {
                return false ;
            }

            if ( in_array( $ancestor , $seed , true ) )
            {
                return true ;
            }

            if ( in_array( $ancestor , $walked , true ) )
            {
                $this->logger?->warning( static::class . ': the parent closure of ' . $this->cacheKey . ' cycles on ' . $ancestor . ', walk abandoned.' ) ;
                return false ;
            }

            $walked[] = $ancestor ;
            $current  = $ancestor ;
        }

        $this->logger?->warning( static::class . ': the ancestor walk of ' . $this->cacheKey . ' from ' . $value . ' hit the maximum depth (' . static::MAX_DEPTH . '), walk abandoned.' ) ;

        return false ;
    }

    /**
     * Builds the model init of one read, narrowed to the collected field.
     *
     * The expansion reads the WHOLE collection, where an un-projected read would
     * return every document in full — and emit the sub-query of every declared
     * join and edge along with it.
     *
     * Narrowing takes two keys, and the pair is not redundant:
     * - `Arango::IN` keeps the model's own field declaration and restricts it to
     *   the collected key. It must be `IN` rather than an empty
     *   `Arango::QUERY_FIELDS`: the latter *replaces* the declaration instead of
     *   filtering it, and would read a raw `doc.<field>` — the wrong attribute
     *   whenever the model declares that key against another one (`Field::NAME`),
     *   yielding an empty set with no error at all.
     * - `Arango::FIELDS` catches the case where the key is NOT declared: the
     *   intersection is then empty, no declaration survives, and `IN` alone would
     *   fall back on the whole document — the opposite of the intent.
     *
     * A dotted field is left un-projected: it would render as an unquoted `a.b`
     * object key, which is not valid AQL. The wide read is slower, never wrong.
     *
     * @param array|null $filter The filter selecting the documents. `null` reads them all.
     *
     * @return array<string,mixed>
     */
    protected function listInit( ?array $filter ) : array
    {
        $init = parent::listInit( $filter ) ;

        if ( !str_contains( $this->field , '.' ) )
        {
            $init[ Arango::IN     ] = [ $this->field ] ;
            $init[ Arango::FIELDS ] = [ $this->field ] ;
        }

        return $init ;
    }

    /**
     * Resolves the seed set, then the values inheriting from it.
     *
     * @return array<int,int|string>
     */
    protected function load() : array
    {
        try
        {
            $seed = $this->collect( $this->filter ) ;
        }
        catch ( Throwable $error )
        {
            $this->logger?->error( static::class . ': failed to load the seed set of ' . $this->cacheKey . ': ' . $error->getMessage() ) ;
            return [] ;
        }

        // The nominal case: nothing seeds the inheritance, so nothing can inherit.
        // Returning here is what keeps it free of a second query.
        if ( count( $seed ) === 0 )
        {
            return [] ;
        }

        return $this->expand( $seed ) ;
    }
}
