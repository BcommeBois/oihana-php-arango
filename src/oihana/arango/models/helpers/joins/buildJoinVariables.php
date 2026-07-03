<?php

namespace oihana\arango\models\helpers\joins;

use oihana\enums\Char;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Psr\Container\ContainerInterface;

use oihana\arango\db\enums\AQL;
use ReflectionException;

use function oihana\arango\models\helpers\isAuthorized;

/**
 * Generates a string of multiple AQL 'LET' statements for all defined joins variables.
 *
 * Definition-level gating: a definition declaring `AQL::REQUIRES` that the
 * request-scoped authorizer denies is skipped — its `LET` is not emitted.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 */
function buildJoinVariables
(
    array              &$variables   = [] ,
    array               $definitions = [] ,
    string              $docRef      = AQL::DOC,
    ?ContainerInterface $container   = null ,
    array               $init        = []
)
: string
{
    foreach( $definitions as $name => $definition )
    {
        if( is_string( $definition ) )
        {
            $definition = $definitions[ $definition ] ?? null ;
            if( !is_array( $definition ) )
            {
                continue ;
            }
        }

        // Definition-level gating (`AQL::REQUIRES` on the join definition):
        // a denied definition emits no `LET` — see buildEdgesVariables().
        if( !isAuthorized( $definition , $init ) )
        {
            continue ;
        }

        $variables[] = buildJoinVariable( $name , $definition , $docRef , $container , $init ) ;
    }
    return count( $variables ) > 0 ? implode( Char::SPACE , $variables ) : Char::EMPTY ;
}