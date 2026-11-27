<?php

namespace oihana\arango\auth\traits;

use oihana\auth\enums\PermissionEffect;

/**
 * The Casbin rule a permission writes, read the same way by every handler of
 * the live sync.
 *
 * A rule is `( domain , object , action , effect )` after the subject. An
 * absent or non-string attribute falls back to the sync domain, an empty
 * object or action, and the `allow` effect.
 *
 * The trait expects the consumer class to expose `string $domain` (declared
 * on {@see \oihana\arango\auth\CasbinPolicySync}).
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncRuleTrait
{
    /**
     * Builds the key of the Casbin rule a permission writes.
     *
     * @param object $permission The permission.
     *
     * @return string The rule key : its four parts, one per line.
     */
    protected function ruleKey( object $permission ) :string
    {
        return implode( "\n" , $this->ruleOf( $permission ) ) ;
    }

    /**
     * Returns the rule a permission writes, after its subject : domain, object,
     * action and effect, with the defaults of the sync.
     *
     * @param object $permission The permission.
     *
     * @return array{0:string,1:string,2:string,3:string} The domain, the object, the action and the effect.
     */
    protected function ruleOf( object $permission ) :array
    {
        return
        [
            is_string( $permission->domain ?? null ) ? $permission->domain : $this->domain ,
            is_string( $permission->object ?? null ) ? $permission->object : ''            ,
            is_string( $permission->action ?? null ) ? $permission->action : ''            ,
            is_string( $permission->effect ?? null ) ? $permission->effect : PermissionEffect::ALLOW ,
        ] ;
    }
}
