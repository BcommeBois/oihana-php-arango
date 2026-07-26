<?php

namespace oihana\arango\cache;

use Memcached;

use oihana\interfaces\Invalidable;
use Psr\Log\LoggerInterface;

use Throwable;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

/**
 * Resolves — and caches — the set of values taken by one field across the
 * documents of a collection matching a filter.
 *
 * Typical use: a small, slow-moving reference set that a hot query needs on
 * every call (the switched-off terms of a thesaurus, the keys of the disabled
 * tenants, …). Asking the collection per request would cost one extra query on
 * every listing, so the set is resolved once and cached.
 *
 * The NATIVE type of each value is preserved, never normalised: a consuming
 * document stores the reference with the type its own mapping produced, and AQL
 * does not coerce across types — `5 NOT IN ["5"]` is true, so a normalised set
 * would filter nothing at all, silently.
 *
 * Cache lifecycle:
 * - **Lazy**: built on the first {@see values()} call after a cold cache.
 * - **Shared**: Memcached is shared by every worker, so one invalidation reaches
 *   the whole fleet.
 * - **TTL safety net**: bounds staleness if an invalidation point is ever missed.
 * - **Surgical invalidation**: {@see invalidate()} is wired on the source model's
 *   write signals (see `Arango::INVALIDATES`), so a write takes effect at once.
 *
 * **Fail-open by design**: a read failure yields an empty set. A cache miss or an
 * unreachable collection must never be mistaken for "everything is excluded".
 *
 * @package oihana\arango\cache
 * @author  Marc Alcaraz
 * @since   1.6.0
 */
class DocumentFieldSetResolver implements Invalidable
{
    /**
     * Creates a new DocumentFieldSetResolver.
     *
     * @param Documents            $model    The collection to read.
     * @param Memcached            $cache    The shared Memcached connection.
     * @param string               $cacheKey Cache key — REQUIRED and unique per resolver: two resolvers behind one key would serve each other's set.
     * @param array|null           $filter   The filter selecting the documents, in the model filter shape. `null` reads them all.
     * @param string               $field    The field whose values are collected.
     * @param int                  $ttl      Cache TTL in seconds. `0` disables the cache (debugging only).
     * @param LoggerInterface|null $logger   Optional logger.
     */
    public function __construct
    (
        protected Documents        $model    ,
        protected Memcached        $cache    ,
        protected string           $cacheKey ,
        protected ?array           $filter   = null ,
        protected string           $field    = self::DEFAULT_FIELD ,
        protected int              $ttl      = self::DEFAULT_TTL   ,
        protected ?LoggerInterface $logger   = null
    ) {}

    /**
     * The field collected when none is given — the foreign reference a consuming
     * document usually stores, rather than the ArangoDB `_key`.
     */
    public const string DEFAULT_FIELD = 'id' ;

    /**
     * Default cache TTL in seconds (1 hour) — a safety net, not the primary
     * refresh path (the write signals are).
     */
    public const int DEFAULT_TTL = 3600 ;

    /**
     * Drops the cached set.
     */
    public function invalidate() : void
    {
        $this->cache->delete( $this->cacheKey ) ;

        $this->logger?->debug( 'DocumentFieldSetResolver: cache invalidated (' . $this->cacheKey . ')' ) ;
    }

    /**
     * Reads the matching documents and collects their field values.
     *
     * @return array<int,int|string>
     */
    private function load() : array
    {
        try
        {
            $init = [ Arango::LIMIT => 0 ] ;

            if ( $this->filter !== null )
            {
                $init[ Arango::FILTER ] = $this->filter ;
            }

            $documents = $this->model->list( $init ) ;
        }
        catch ( Throwable $error )
        {
            $this->logger?->error( 'DocumentFieldSetResolver: failed to load ' . $this->cacheKey . ': ' . $error->getMessage() ) ;
            return [] ;
        }

        $values = [] ;

        foreach ( $documents as $document )
        {
            $value = is_object( $document )
                   ? ( $document->{ $this->field } ?? null )
                   : ( is_array( $document ) ? ( $document[ $this->field ] ?? null ) : null ) ;

            if ( is_int( $value ) || ( is_string( $value ) && $value !== '' ) )
            {
                $values[] = $value ;
            }
        }

        return array_values( array_unique( $values ) ) ;
    }

    /**
     * Returns the set of values, de-duplicated and re-indexed.
     *
     * An empty array means "nothing matched": the caller should then pose no
     * predicate at all rather than an always-true `NOT IN []`.
     *
     * @return array<int,int|string>
     */
    public function values() : array
    {
        $cached = $this->cache->get( $this->cacheKey ) ;

        if ( is_array( $cached ) )
        {
            return $cached ;
        }

        $values = $this->load() ;

        if ( $this->ttl > 0 )
        {
            $this->cache->set( $this->cacheKey , $values , $this->ttl ) ;
        }

        return $values ;
    }
}