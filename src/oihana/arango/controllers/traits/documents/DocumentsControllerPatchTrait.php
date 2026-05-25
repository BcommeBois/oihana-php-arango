<?php

namespace oihana\arango\controllers\traits\documents;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait DocumentsControllerPatchTrait
{
    use DocumentsControllerUpdateTrait ;

    /**
     * Update a part of a document in a collection with a specific identifier (by default use the _key attribute).
     *
     * Example: PATCH ../collection/{id}
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args An associative array that contains values for the current route’s named placeholders.
     * @param array $init An optional associative array to initialize the method.
     *
     * @return mixed
     */
    public function patch
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
        array     $args     = []   ,
        array     $init     = []
    )
    :mixed
    {
        return $this->update( $request , $response , $args , $init ) ;
    }
}