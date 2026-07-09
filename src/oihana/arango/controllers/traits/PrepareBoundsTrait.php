<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the list of bound fields whose numeric `{ min, max }` extent is
 * computed alongside the document list (see
 * {@see \oihana\arango\models\traits\documents\DocumentsBoundsTrait::bounds()}).
 *
 * Driven by `?bounds=key1,key2` (CSV). Each key must be a configured bound
 * (`AQL::BOUNDS`); unknown keys are ignored at the model layer.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait PrepareBoundsTrait
{
    /**
     * Resolves the bound fields for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::BOUNDS]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return array|null The list of bound keys, or null when none requested.
     */
    protected function prepareBounds( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        $fields = $args[ Arango::BOUNDS ] ?? [] ;
        if ( is_string( $fields ) )
        {
            $fields = array_map( 'trim' , explode( Char::COMMA , $fields ) ) ;
        }
        if ( !is_array( $fields ) )
        {
            $fields = [] ;
        }

        if ( isset( $request ) )
        {
            $value = getQueryParam( $request , Arango::BOUNDS ) ;
            if ( is_string( $value ) && $value !== Char::EMPTY )
            {
                $params[ Arango::BOUNDS ] = $value ;
                $fields = array_map( 'trim' , explode( Char::COMMA , $value ) ) ;
            }
        }

        $fields = array_values( array_filter( $fields , fn( $f ) => is_string( $f ) && $f !== Char::EMPTY ) ) ;

        return empty( $fields ) ? null : $fields ;
    }
}
