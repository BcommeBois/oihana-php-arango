<?php

namespace oihana\arango\controllers\traits\properties;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\ArrayMode;

use oihana\enums\http\HttpStatusCode;

use org\schema\constants\Schema;

/**
 * Element-level operations on an **embedded array property** of a document, exposed
 * by {@see ArrayPropertyController} as REST sub-resources.
 *
 * The host controller must be a {@see PropertyController}
 * subclass (it relies on its wiring: `$model`, `$property`, `$owner`, `assertProperty()`,
 * `checkOwnerArguments()`, `success()`, `fail()`). The targeted `$property` must be a
 * field declared in the model's `AQL::ARRAYS` option.
 *
 * Each method maps an HTTP verb to a model array operation and returns a standardized
 * response. Common error responses (built by every method through {@see runArrayOp()}):
 *
 * - **400 Bad Request** — the configured property is not a declared array field.
 * - **404 Not Found** — the owner document does not exist (or, for {@see hasItem()},
 *   the value is not present in the array).
 * - **422 Unprocessable Entity** — {@see moveItem()} on a `sortedSet` field.
 *
 * The element value is resolved from the `{value}` route placeholder when present,
 * otherwise from the request body (key `value`) — use the body for **complex** (object)
 * values that cannot travel in a URL.
 *
 * @see ArrayPropertyController
 * @see DocumentsArrayTrait
 *
 * @package oihana\arango\controllers\traits\properties
 */
trait ArrayPropertyControllerTrait
{
    /**
     * Adds one or several values to the array property of a document.
     *
     * `POST /{collection}/{id}/{property}` — the value(s) are read from the request
     * body (key `value`); an optional `side` (`left`/`right`) controls the insertion end.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function addItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model ) use ( $request , $response , $args , $init )
        {
            $document = $model->arrayInsert
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
                Arango::SIDE  => $init[ Arango::SIDE ] ?? $this->bodyParam( $request , Arango::SIDE ) ,
            ]) ;

            return $this->success( $request , $response , $document?->{ $this->property } ?? null ) ;
        }) ;
    }

    /**
     * Tests whether the array property of a document contains a value.
     *
     * `GET /{collection}/{id}/{property}/{value}` — the value is read from the `{value}`
     * placeholder (or the request body for complex values).
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed 200 when the value is present, 404 when it is absent (or 400/404 on guard failures).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function hasItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model ) use ( $request , $response , $args , $init )
        {
            $exists = $model->arrayContains
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
            ]) ;

            return $exists
                 ? $this->success( $request , $response , true )
                 : $this->fail
                   (
                       request  : $request ,
                       response : $response ,
                       code     : HttpStatusCode::NOT_FOUND ,
                       details  : 'The value is not present in the array.' ,
                   ) ;
        } , requireExists : false ) ;
    }

    /**
     * Moves an existing value to a given position in the array property.
     *
     * `PATCH /{collection}/{id}/{property}/{value}` — the value comes from the `{value}`
     * placeholder (or body), the target index from the request body (key `position`).
     * Unsupported on a `sortedSet` property (the sort order overrides positions) → 422.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404/422).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function moveItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model ) use ( $request , $response , $args , $init )
        {
            if ( ( $model->arrays[ $this->property ][ Arango::MODE ] ?? null ) === ArrayMode::SORTED_SET )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::UNPROCESSABLE_ENTITY ,
                    details  : sprintf( 'Cannot move an item in the sorted property "%s".' , $this->property ?? 'undefined' ) ,
                ) ;
            }

            $document = $model->arrayMove
            ([
                ...$init ,
                Arango::OWNER    => $owner ,
                Arango::FIELD    => $this->property ,
                Arango::VALUE    => $this->resolveItemValue( $request , $args ) ,
                Arango::POSITION => (int) ( $init[ Arango::POSITION ] ?? $this->bodyParam( $request , Arango::POSITION ) ?? 0 ) ,
            ]) ;

            return $this->success( $request , $response , $document?->{ $this->property } ?? null ) ;
        }) ;
    }

    /**
     * Removes one or several values from the array property of a document.
     *
     * `DELETE /{collection}/{id}/{property}/{value}` — the value comes from the `{value}`
     * placeholder (or the request body for complex values).
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function removeItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model ) use ( $request , $response , $args , $init )
        {
            $document = $model->arrayRemove
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
            ]) ;

            return $this->success( $request , $response , $document?->{ $this->property } ?? null ) ;
        }) ;
    }

    /**
     * Reads a single parameter from the parsed request body.
     *
     * @param ?Request $request
     * @param string   $key
     *
     * @return mixed The body value, or null when absent.
     */
    protected function bodyParam( ?Request $request , string $key ) : mixed
    {
        return ( (array) ( $request?->getParsedBody() ?? [] ) )[ $key ] ?? null ;
    }

    /**
     * Resolves the array element value from the `{value}` route placeholder, falling
     * back to the request body (key `value`) for complex values that cannot be in a URL.
     *
     * @param ?Request $request
     * @param array    $args
     *
     * @return mixed
     */
    protected function resolveItemValue( ?Request $request , array $args ) : mixed
    {
        return $args[ Arango::VALUE ] ?? $this->bodyParam( $request , Arango::VALUE ) ;
    }

    /**
     * Shared skeleton for the array operations: asserts the property is configured and
     * declared as an array field, optionally verifies the owner document exists, then
     * runs the given operation. Maps thrown exceptions to a standardized failure response.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args
     * @param array $init
     * @param callable $operation fn(mixed $owner, Documents $model): mixed — performs the model call and returns the response.
     * @param bool $requireExists When true (writes), a missing owner document yields a 404; reads (hasItem) pass false.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function runArrayOp( ?Request $request , ?Response $response , array $args , array $init , callable $operation , bool $requireExists = true ) : mixed
    {
        try
        {
            $this->assertProperty() ;
            $this->checkOwnerArguments( $args ) ;

            /** @var Documents $model The configured array-capable model (declares the array* methods and the `arrays` config). */
            $model = $this->model ;

            if ( !is_array( $model->arrays[ $this->property ] ?? null ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::BAD_REQUEST ,
                    details  : sprintf( 'The property "%s" is not a declared array field.' , $this->property ?? 'undefined' ) ,
                ) ;
            }

            $owner = $args[ Schema::ID ] ?? null ;

            if ( $requireExists && !$model->exist( [ ...$init , Arango::VALUE => $owner ] ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf( 'The document "%s" does not exist' , $owner ?? 'undefined' ) ,
                ) ;
            }

            return $operation( $owner , $model ) ;
        }
        catch ( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage() ,
            ) ;
        }
    }
}
