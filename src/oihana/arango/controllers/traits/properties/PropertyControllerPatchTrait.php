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

use function oihana\core\accessors\getKeyValue;

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

            $existInit = [ ...$init , Arango::VALUE => $value ] ;

            $this->beforeModelCall( $request , $existInit ) ;

            if( !$this->model->exist( $existInit ) )
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
                $payload = $this->stripRelationKeys( $payload , $relations ) ;

                $updateInit =
                [
                    ...$init ,
                    Arango::DOC       => $payload ,
                    Arango::RELATIONS => $relations ,
                    Arango::VALUE     => $value ,
                ] ;

                $this->beforeModelCall( $request , $updateInit ) ;
                $document = $this->model->update( $updateInit )  ;
                $this->afterModelCall( $request , $updateInit , $document ) ;

                // `UPDATE … RETURN NEW` yields a row only when its FILTER matched, so a
                // null document means the write reached nothing: the target was deleted
                // between the existence probe above and this line, or the scope the hook
                // just posed excludes it. Both are the fact the probe reports, hence the
                // same status and the same wording. Without this guard the reload below
                // dereferences null and raises an `Error`, which `catch( Exception )`
                // does not intercept; in raw mode nothing would crash at all and the
                // response would claim success for a write that touched nothing.
                if( $document === null )
                {
                    return $this->fail
                    (
                        request  : $request ,
                        response : $response ,
                        code     : HttpStatusCode::NOT_FOUND ,
                        details  : sprintf( 'The document "%s" does not exist' , ( $value ?? 'undefined' ) )
                    ) ;
                }

                $raw = (bool) ( $init[ Arango::RAW ] ?? false ) ;

                return $this->success
                (
                    $request ,
                    $response ,
                    $raw ? getKeyValue( (array) $payload , $this->property ) : $this->reloadProperty( $request , $args , $init , $document )
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

    /**
     * Re-reads the updated property so the response carries the stored value
     * rather than the submitted one (`Arango::RAW` skips this round-trip).
     *
     * It is a **read**, so it goes through the same hooks and carries the same
     * `Arango::CONDITIONS` as {@see PropertyControllerGetTrait::get()} : a write
     * whose response bypassed the scope would hand back exactly what the scope
     * is meant to withhold.
     *
     * @param ?Request            $request  The current PSR-7 request.
     * @param array               $args     The route placeholders.
     * @param array               $init     The method init array (source of the declared conditions).
     * @param ?object             $document The document returned by the write.
     *
     * @return mixed The stored property value, or null when the re-read returns nothing.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function reloadProperty( ?Request $request , array $args , array $init , ?object $document ) :mixed
    {
        $modelInit =
        [
            Arango::ARGS       => $args ,
            Arango::VALUE      => $document->_key ,
            Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? [] ,
            Arango::IN         => $this->property , // returns only the specific property field
            Arango::LANG       => $this->prepareLang( $request , $init ) ,
        ] ;

        $this->beforeModelCall( $request , $modelInit ) ;
        $reloaded = $this->model->get( $modelInit ) ;
        $this->afterModelCall( $request , $modelInit , $reloaded ) ;

        return $reloaded->{ $this->property } ?? null ;
    }
}