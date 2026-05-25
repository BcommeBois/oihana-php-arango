<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use Psr\Container\ContainerInterface;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

/**
 * Generates a string of multiple AQL 'LET' statements for all defined edge variables.
 *
 * This is a convenience method that iterates over a list of definitions
 * and calls getEdgeVariable() for each one, concatenating the results.
 *
 * @param array               $variables    The variables list reference to fill.
 * @param array               $definitions  An associative array of edge definitions [ $name => $definition ].
 * @param string              $startVertex  The default AQL document reference (start vertex) for all traversals.
 * @param ?ContainerInterface $container    The DI Container reference.
 * @param array               $init         Optional associative array definition.
 *
 * @return string A string containing all generated AQL 'LET' statements, or an empty string if none.
 *
 * @throws ContainerExceptionInterface | NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws Exception
 */
function buildEdgesVariables
(
    array               &$variables  = [] ,
    array               $definitions = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = []
)
: string
{
    foreach( $definitions as $name => $definition )
    {
        if( $name == AQL::RESOLVE )
        {
            continue ;
        }
        if( is_string( $definition ) )
        {
            $definition = $definitions[ $definition ] ?? null ;
            if( !is_array( $definition ) )
            {
                continue ;
            }
        }
        $variables[] = buildEdgeVariable( $name , $definition , $startVertex , $container , $init ) ;
    }
    return count( $variables ) > 0 ? implode( Char::SPACE , $variables ) : Char::EMPTY ;
}