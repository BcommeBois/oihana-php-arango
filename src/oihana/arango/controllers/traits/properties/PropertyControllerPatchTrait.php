<?php

namespace oihana\arango\controllers\traits\properties;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\documents\DocumentsControllerUpdateTrait;
use oihana\arango\enums\Arango;
use oihana\enums\http\HttpStatusCode;

use org\schema\constants\Schema;

use function oihana\core\accessors\deleteKeyValue;

trait PropertyControllerPatchTrait
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
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
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
        try
        {
            $this->assertProperty();
            $this->checkOwnerArguments( $args ) ;

            $value = $args[ Schema::ID ] ?? null ;

            if( !$this->model->exist( [ ...$init , Arango::VALUE => $value ] ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf( 'The document "%s" does not exist' , ( $value ?? 'undefined' ) )
                ) ;
            }

            $relations  = [] ;
            $payload    = $this->propertyPayload( $request , $this->property , $relations ) ;
            $validation = $this->validator->validate( $payload , $this->rules ) ;

            if( $validation->fails() )
            {
                return $this->getValidatorError( $request , $response , $validation ) ;
            }
            else
            {
                // Removes all relation keys -> special case (edges)
                if( count( $relations ) > 0 )
                {
                    $payload = deleteKeyValue( $payload , array_keys( $relations ) ) ;
                }

                $init =
                [
                    ...$init ,
                    Arango::DOC       => $payload ,
                    Arango::RELATIONS => $relations ,
                    Arango::VALUE     => $value ,
                ] ;

                $document = $this->model->update( $init )  ;

                $raw = (bool) ( $init[ Arango::RAW ] ?? false ) ;

                return $this->success
                (
                    $request ,
                    $response ,
                    $raw ? ( $payload->{ $this->property } ?? null ) : ( $this->model->get
                    ([
                        Arango::ARGS  => $args ,
                        Arango::VALUE => $document->_key ,
                        Arango::IN     => $this->property , // returns only the specific property field
                        Arango::LANG  => $this->prepareLang( $request , $init )
                    ])->{ $this->property } ?? null )
                );
            }
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            ) ;
        }
    }
}