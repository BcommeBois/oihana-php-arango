<?php

namespace tests\oihana\arango\auth\mocks;

use oihana\arango\auth\CasbinPolicySync;

/**
 * Test harness for {@see CasbinPolicySync}.
 *
 * Bypasses the heavy DI constructor of {@see CasbinPolicySync} (which would
 * resolve an Enforcer + a dozen Arango models from a container) and instead
 * sets the protected dependency properties directly from a keyed array, so a
 * test can wire just the doubles a given sync path needs (a {@see SpyEnforcer},
 * a few {@see FakeDocuments} / {@see FakeEdges}) and leave the rest null.
 *
 * The keys of the `$deps` array are the real protected property names declared
 * by the composed traits — e.g. `enforcer`, `domain`, `permissionsModel`,
 * `roleHasPermissions`, `logger`.
 *
 * {@see invoke()} reaches the protected sync handlers (the `add*` / `remove*` /
 * `on*Delete` / `resolve*` methods) from the test scope.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class MockCasbinPolicySync extends CasbinPolicySync
{
    /**
     * @param array<string,mixed> $deps Dependency properties to set, keyed by
     *        their real protected property name on {@see CasbinPolicySync}.
     */
    public function __construct( array $deps = [] )
    {
        foreach( $deps as $name => $value )
        {
            $this->$name = $value ;
        }
    }

    /**
     * Calls a protected handler of the sync class from the test scope.
     *
     * @param string $method The protected method name.
     * @param mixed  ...$args The arguments to forward.
     *
     * @return mixed The handler's return value.
     */
    public function invoke( string $method , mixed ...$args ) :mixed
    {
        return $this->$method( ...$args ) ;
    }
}
