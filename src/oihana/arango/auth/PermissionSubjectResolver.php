<?php

namespace oihana\arango\auth;

use Memcached;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\auth\PermissionSubjectResolverInterface;

use Psr\Log\LoggerInterface;

use Throwable;

/**
 * Resolves a permission `subject` (e.g. `roles.permissions:list`) to the
 * (`object`, `action`) couple Casbin actually enforces against.
 *
 * The Casbin policy table stores `(subject_user, domain, object, action, effect)`
 * — the permission `subject` from the seed (the human-readable label) is **not**
 * carried into Casbin. To answer the question "does this user hold the
 * permission identified by `roles.permissions:list`?", a translation step from
 * the label to the `(object, action)` couple is required.
 *
 * That translation is read from the ArangoDB `permissions` collection, where
 * every row already exposes `subject`, `object` and `action`. The resolver
 * loads the full table once and caches the result in Memcached so subsequent
 * lookups are O(1) memory accesses.
 *
 * Cache lifecycle:
 * - **Lazy**: the map is built on the first {@see resolve()} call after a
 *   cold cache. No work is done at boot.
 * - **TTL safety net**: a TTL (default 1 hour) prevents stale state if an
 *   explicit invalidation point is ever missed.
 * - **Surgical invalidation**: the catalog only changes through three paths,
 *   and each one calls {@see invalidate()}:
 *     1. `php bin/console.php auth:materialize` (and `php bin/console.php auth:import`)
 *     2. `POST /permissions`
 *     3. `DELETE /permissions/{key}`
 *
 * The resolver is **stateless per request** beyond the in-process Memcached
 * connection — it is safe to share a single instance across the container.
 *
 * @package oihana\arango\auth
 * @author  Marc Alcaraz
 */
class PermissionSubjectResolver implements PermissionSubjectResolverInterface
{
    /**
     * Memcached key for the cached subject → (object, action) map.
     *
     * Hardcoded — the catalog is global and must not be partitioned per
     * caller / per role / per anything. Exposing it as a config knob would
     * only invite drift between writers (the controllers that invalidate)
     * and readers (the resolver itself).
     */
    public const string CACHE_KEY = 'auth.permissions.subject_map' ;

    /**
     * Default cache TTL in seconds (1 hour). May be overridden via the
     * constructor — typical override is 60s in dev to confirm invalidation
     * paths during a chantier, or 0 to bypass the cache entirely (every
     * lookup hits ArangoDB).
     */
    public const int DEFAULT_TTL = 3600 ;

    /**
     * Creates a new PermissionSubjectResolver.
     *
     * @param Documents            $permissionsModel The ArangoDB `permissions` collection model.
     * @param Memcached            $cache            The shared Memcached connection — same one used by JWKS / route caches.
     * @param int                  $ttl              Cache TTL in seconds. `0` disables the cache (debugging only).
     * @param LoggerInterface|null $logger           Optional logger for hot-reload telemetry.
     */
    public function __construct
    (
        protected Documents         $permissionsModel ,
        protected Memcached         $cache ,
        protected int               $ttl    = self::DEFAULT_TTL ,
        protected ?LoggerInterface  $logger = null
    ) {}

    /**
     * Returns the `(object, action)` couple bound to a permission subject,
     * or `null` when the subject is unknown.
     *
     * The first call after a cold cache materializes the full map; every
     * subsequent call inside the TTL window is a Memcached lookup followed
     * by an in-memory array dereference.
     *
     * @param string $subject The permission subject label, e.g. `roles.permissions:list`.
     *
     * @return array{object: string, action: string}|null
     */
    public function resolve( string $subject ) : ?array
    {
        $map = $this->getMap() ;

        return $map[ $subject ] ?? null ;
    }

    /**
     * Returns the full subject → (object, action) map.
     *
     * Exposed for tests and for callers that need to enumerate the catalog
     * (e.g. doctor commands, debug endpoints). Not intended for hot paths —
     * the per-subject {@see resolve()} should be preferred since the map
     * grows with the seed.
     *
     * @return array<string, array{object: string, action: string}>
     */
    public function getMap() : array
    {
        $cached = $this->cache->get( self::CACHE_KEY ) ;

        if ( is_array( $cached ) )
        {
            return $cached ;
        }

        $map = $this->loadFromDatabase() ;

        if ( $this->ttl > 0 )
        {
            $this->cache->set( self::CACHE_KEY , $map , $this->ttl ) ;
        }

        return $map ;
    }

    /**
     * Drops the cached map.
     *
     * Called by the three points that mutate the `permissions` catalog —
     * `auth:materialize`, `auth:import`, and `PermissionsController` writes.
     * The next {@see resolve()} after invalidation triggers a fresh load.
     */
    public function invalidate() : void
    {
        $this->cache->delete( self::CACHE_KEY ) ;

        $this->logger?->debug( 'PermissionSubjectResolver: cache invalidated' ) ;
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * Reads the full `permissions` collection and projects it as a map.
     *
     * On read failure the map is returned empty — every subsequent
     * `resolve()` falls open (returns `null`), which the caller must
     * interpret as "subject unknown" and translate into the safe default
     * for the caller's policy (typically: deny the projection, since
     * gating cannot be evaluated).
     *
     * @return array<string, array{object: string, action: string}>
     */
    private function loadFromDatabase() : array
    {
        $map = [] ;

        try
        {
            $permissions = $this->permissionsModel->list([ Arango::LIMIT => 0 ]) ;
        }
        catch ( Throwable $e )
        {
            $this->logger?->error( 'PermissionSubjectResolver: failed to load permissions catalog: ' . $e->getMessage() ) ;
            return $map ;
        }

        foreach ( $permissions as $permission )
        {
            $subject = is_object( $permission ) ? ( $permission->subject ?? null ) : null ;
            $object  = is_object( $permission ) ? ( $permission->object  ?? null ) : null ;
            $action  = is_object( $permission ) ? ( $permission->action  ?? null ) : null ;

            if ( !is_string( $subject ) || $subject === '' )
            {
                continue ;
            }

            $map[ $subject ] =
            [
                'object' => is_string( $object ) ? $object : '' ,
                'action' => is_string( $action ) ? $action : '' ,
            ] ;
        }

        $this->logger?->debug( 'PermissionSubjectResolver: loaded ' . count( $map ) . ' subject mappings from ArangoDB' ) ;

        return $map ;
    }
}
