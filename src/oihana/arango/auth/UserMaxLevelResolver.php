<?php

namespace oihana\arango\auth;

use ReflectionException;
use Throwable;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\signals\notices\Payload;

use org\schema\constants\Schema;

use xyz\oihana\schema\auth\User;

/**
 * Persists `users.maxLevel` in real-time when role assignments or role
 * levels change.
 *
 * Wired in DI on three signals :
 * - `userHasRoles.afterInsert` → recompute the user on `_from` of the
 *   newly inserted edge.
 * - `userHasRoles.afterDelete` → recompute the user(s) on `_from` of
 *   the deleted edge(s). Handles both shapes of the payload (`data`
 *   = a single edge object for `deleteEdge` and `data` = an array of
 *   edges for `deleteEdges` cascades).
 * - `roles.afterUpdate`        → recompute every user `INBOUND` from
 *   the updated role. The level field is not diffed against the
 *   previous state — admin role PATCH is rare and the recompute is
 *   idempotent.
 *
 * The recompute runs as a single AQL `UPDATE` per call ; for the
 * `INBOUND` path the entire user set is updated in one round-trip.
 *
 * @package oihana\arango\auth
 * @author  Marc Alcaraz
 */
class UserMaxLevelResolver
{
    /**
     * Creates a new UserMaxLevelResolver instance.
     */
    public function __construct
    (
        protected ?Documents       $usersModel        = null ,
        protected ?Edges           $userHasRolesModel = null ,
        protected ?Documents       $rolesModel        = null ,
        protected ?LoggerInterface $logger            = null
    ) {}

    /**
     * Recomputes `maxLevel` on every user document.
     *
     * Used by the `auth:users:backfill:maxlevel` command after the
     * persistence is first deployed (or as a safety net when an
     * inconsistency is suspected). Returns the number of user
     * documents that were updated.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function backfillAll() :int
    {
        if( !$this->usersModel || !$this->userHasRolesModel )
        {
            return 0 ;
        }

        $usersCol = $this->usersModel->collection ;
        $edgesCol = $this->userHasRolesModel->collection ;

        if( !is_string( $usersCol ) || !is_string( $edgesCol ) )
        {
            return 0 ;
        }

        $query = sprintf
        (
            'FOR u IN %s '
          . 'LET levels = (FOR r IN OUTBOUND u %s RETURN r.level) '
          . 'UPDATE u WITH { %s: LENGTH(levels) > 0 ? MAX(levels) : 0 } IN %s '
          . 'RETURN 1' ,
            $usersCol ,
            $edgesCol ,
            User::MAX_LEVEL ,
            $usersCol
        ) ;

        $result = $this->usersModel->getResult( $query , [] , [] , true ) ;

        return is_array( $result ) ? count( $result ) : 0 ;
    }

    /**
     * Recomputes `maxLevel` for one or several users in a single AQL
     * round-trip. No-op when the input is empty.
     *
     * @param string|array<int,string|null> $userKeys A single Arango
     *        `_key`, a list of keys, or a list of `users/<key>` `_id`
     *        strings (the helper normalises every entry).
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function recompute( string|array $userKeys ) :void
    {
        if( !$this->usersModel || !$this->userHasRolesModel )
        {
            return ;
        }

        $keys = $this->normalizeUserKeys( $userKeys ) ;

        if( empty( $keys ) )
        {
            return ;
        }

        $usersCol = $this->usersModel->collection ;
        $edgesCol = $this->userHasRolesModel->collection ;

        if( !is_string( $usersCol ) || !is_string( $edgesCol ) )
        {
            return ;
        }

        $query = sprintf
        (
            'FOR u IN %s '
          . 'FILTER u.%s IN @keys '
          . 'LET levels = (FOR r IN OUTBOUND u %s RETURN r.level) '
          . 'UPDATE u WITH { %s: LENGTH(levels) > 0 ? MAX(levels) : 0 } IN %s '
          . 'RETURN 1' ,
            $usersCol ,
            Schema::_KEY ,
            $edgesCol ,
            User::MAX_LEVEL ,
            $usersCol
        ) ;

        $this->usersModel->getResult( $query , [ 'keys' => array_values( $keys ) ] , [] , true ) ;
    }

    /**
     * Recomputes `maxLevel` on every user `INBOUND` from a given role.
     *
     * Triggered by the `roles.afterUpdate` listener. The role's `level`
     * may or may not have changed — recompute is idempotent and the
     * cost is bounded by the number of users carrying the role, which
     * is typically small.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function recomputeForRole( string $roleKey ) :void
    {
        if( !$this->usersModel || !$this->userHasRolesModel || !$this->rolesModel )
        {
            return ;
        }

        $usersCol = $this->usersModel->collection ;
        $edgesCol = $this->userHasRolesModel->collection ;
        $rolesCol = $this->rolesModel->collection ;

        if( !is_string( $usersCol ) || !is_string( $edgesCol ) || !is_string( $rolesCol ) )
        {
            return ;
        }

        $query = sprintf
        (
            'FOR u IN INBOUND CONCAT(@rolesCol, "/", @roleKey) %s '
          . 'LET levels = (FOR r IN OUTBOUND u %s RETURN r.level) '
          . 'UPDATE u WITH { %s: LENGTH(levels) > 0 ? MAX(levels) : 0 } IN %s '
          . 'RETURN 1' ,
            $edgesCol ,
            $edgesCol ,
            User::MAX_LEVEL ,
            $usersCol
        ) ;

        $this->usersModel->getResult
        (
            $query ,
            [ 'rolesCol' => $rolesCol , 'roleKey' => $roleKey ] ,
            [] ,
            true
        ) ;
    }

    /**
     * Wires this resolver on the three signals it cares about.
     * Idempotent — safe to call once per instance.
     */
    public function register() :void
    {
        $this->userHasRolesModel?->afterInsert?->connect
        (
            fn( Payload $p ) => $this->onUserHasRolesEdgeInserted( $p )
        ) ;

        $this->userHasRolesModel?->afterDelete?->connect
        (
            fn( Payload $p ) => $this->onUserHasRolesEdgeDeleted( $p )
        ) ;

        $this->rolesModel?->afterUpdate?->connect
        (
            fn( Payload $p ) => $this->onRoleUpdated( $p )
        ) ;
    }

    /**
     * Listener on `userHasRoles.afterInsert` — recomputes the user
     * vertex on `_from` of the inserted edge. Public to allow direct
     * invocation in tests and external orchestrators ; the production
     * call site is the signal closure wired by {@see register()}.
     */
    public function onUserHasRolesEdgeInserted( Payload $payload ) :void
    {
        $this->recomputeFromEdgePayload( $payload , __METHOD__ ) ;
    }

    /**
     * Listener on `userHasRoles.afterDelete` — recomputes every user
     * vertex referenced by `_from` of the deleted edge(s). Same
     * visibility rationale as {@see onUserHasRolesEdgeInserted()}.
     */
    public function onUserHasRolesEdgeDeleted( Payload $payload ) :void
    {
        $this->recomputeFromEdgePayload( $payload , __METHOD__ ) ;
    }

    /**
     * Listener on `roles.afterUpdate` — recomputes every user
     * `INBOUND` from the updated role. Same visibility rationale as
     * {@see onUserHasRolesEdgeInserted()}.
     */
    public function onRoleUpdated( Payload $payload ) :void
    {
        /** @var mixed $role */
        $role    = $payload->data ?? null ;
        $roleKey = null ;

        if( is_object( $role ) )
        {
            $candidate = $role->_key ?? null ;
            $roleKey   = is_string( $candidate ) && $candidate !== '' ? $candidate : null ;
        }

        if( $roleKey === null )
        {
            return ;
        }

        try
        {
            $this->recomputeForRole( $roleKey ) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning
            (
                __METHOD__ . ' failed to recompute maxLevel for role ' . $roleKey . ': ' . $e->getMessage()
            ) ;
        }
    }

    /**
     * Extracts the user vertex `_key` from a single `_id` string, a
     * full user-vertex object, or returns `null` when the value is
     * unusable. Accepts both `users/123` and a bare `123`.
     */
    private function extractUserKey( mixed $value ) :?string
    {
        $resolved = null ;

        if( is_object( $value ) )
        {
            $resolved = $value->_from ?? $value->_key ?? null ;
        }
        else if( is_array( $value ) )
        {
            $resolved = $value[ Schema::_FROM ] ?? $value[ Schema::_KEY ] ?? null ;
        }
        else if( is_string( $value ) )
        {
            $resolved = $value ;
        }

        if( !is_string( $resolved ) || $resolved === '' )
        {
            return null ;
        }

        $slash = strrpos( $resolved , Char::SLASH ) ;

        return $slash === false ? $resolved : substr( $resolved , $slash + 1 ) ;
    }

    /**
     * Reduces the input shape of a `recompute()` call to a list of
     * unique non-empty `_key` strings.
     *
     * @param string|array<int,mixed> $userKeys
     *
     * @return array<int,string>
     */
    private function normalizeUserKeys( string|array $userKeys ) :array
    {
        $list = is_string( $userKeys ) ? [ $userKeys ] : $userKeys ;
        $keys = [] ;

        foreach( $list as $entry )
        {
            $key = $this->extractUserKey( $entry ) ;

            // De-dup on the value, not on the array key — purely numeric
            // user `_key`s (e.g. Zitadel-issued `72488862`) would
            // otherwise be coerced to int by PHP when used as array
            // keys and round-trip back to the AQL bind as `int`.
            if( $key !== null && !in_array( $key , $keys , true ) )
            {
                $keys[] = $key ;
            }
        }

        return $keys ;
    }

    /**
     * Shared edge-payload handler — extracts the affected user `_key`
     * set from an `afterInsert` (single edge) or `afterDelete`
     * (single edge or array of edges), then runs a single batched
     * recompute. Failures are logged but never bubble up : the
     * signal listener must not break the originating write
     * operation.
     */
    private function recomputeFromEdgePayload( Payload $payload , string $context ) :void
    {
        /** @var mixed $data */
        $data = $payload->data ?? null ;

        if( is_object( $data ) )
        {
            $list = [ $data ] ;
        }
        else if( is_array( $data ) )
        {
            $list = $data ;
        }
        else
        {
            return ;
        }

        $keys = [] ;

        foreach( $list as $edge )
        {
            $from = null ;

            if( is_object( $edge ) )
            {
                $from = $edge->_from ?? null ;
            }
            else if( is_array( $edge ) )
            {
                $from = $edge[ Schema::_FROM ] ?? null ;
            }

            $key = $this->extractUserKey( $from ) ;

            if( $key !== null )
            {
                $keys[] = $key ;
            }
        }

        if( empty( $keys ) )
        {
            return ;
        }

        try
        {
            $this->recompute( $keys ) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning
            (
                $context . ' failed to recompute maxLevel: ' . $e->getMessage()
            ) ;
        }
    }
}
