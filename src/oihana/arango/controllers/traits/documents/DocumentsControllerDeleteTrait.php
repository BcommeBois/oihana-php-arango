<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\enums\Char;
use oihana\enums\http\HttpStatusCode;
use oihana\controllers\traits\StatusTrait;
use oihana\models\traits\ModelTrait;

use function oihana\controllers\helpers\getQueryParam;
use function oihana\core\arrays\toArray;
use function oihana\core\strings\compile;

/**
 * Provides the `delete` method for a document-based controller.
 *
 * This trait encapsulates the logic for handling HTTP DELETE requests to remove
 * one or more documents. It is designed to be used in controllers that manage a
 * resource collection, offering a flexible and robust deletion endpoint.
 *
 * The implementation intelligently sources document IDs, prioritizing route
 * arguments (e.g., `/collection/{id}`) for single-document deletion and falling
 * back to a query parameter (e.g., `/collection?id=1,2,3`) for bulk operations.
 * Input IDs from the query string are automatically sanitized to remove duplicates
 * and empty values, and then sorted naturally.
 *
 * It relies on `ModelTrait` to interact with the underlying data model and provides
 * lifecycle hooks (`beforeDelete`, `afterDelete`) for custom logic execution.
 * Standardized API responses are returned using `StatusTrait`.
 *
 * @package oihana\arango\controllers\traits\documents
 *
 * @uses    ModelTrait For accessing the `$this->model` property.
 * @uses    StatusTrait For standardized success/fail responses.
 */
trait DocumentsControllerDeleteTrait
{
    use ModelTrait ,
        StatusTrait ;

    /**
     * Deletes one or more documents from the collection.
     *
     * This method provides a flexible endpoint for document deletion. It can handle a single
     * document ID provided as a route placeholder or one or more IDs supplied via a query
     * parameter.
     *
     * When multiple IDs are passed in the query string, they are cleaned to remove empty
     * values and duplicates, then sorted using a natural sort algorithm before being
     * processed by the model. The response format adapts to return the key of a single
     * deleted document or an array of keys for multiple deletions.
     *
     * @param Request|null  $request  The PSR-7 request object, used to access query parameters.
     * @param Response|null $response The PSR-7 response object, used to build the HTTP response.
     * @param array         $args     An associative array of route placeholders. It is expected
     *                                to contain the 'id' key for single-item deletion.
     * @param array         $init An optional associative array to pass custom settings to the model layer (e.g., skipping existence checks).
     *
     * @return Response Returns an HTTP response object indicating the result of the operation.
     *                  On success, the body contains the `_key` of the deleted document(s).
     *                  On failure, it returns a formatted error response (400, 404, or 500).
     *
     * @example
     * ```http
     * // Delete a single document via route argument
     * DELETE /things/12515
     *
     * // Delete a single document via query parameter
     * DELETE /things?id=12515
     *
     * // Delete multiple documents via query parameter
     * DELETE /things?id=12515,241545,10
     * ```
     */
    public function delete
    (
        ?Request  $request  = null,
        ?Response $response = null ,
        array     $args     = [] ,
        array     $init     = []
    )
    :mixed
    {
        try
        {
            $id = $args[ Arango::ID ] ?? [] ;

            if ( !empty( $id ) )
            {
                $ids = toArray( $args[ Arango::ID ] );
            }
            else
            {
                $ids = getQueryParam( $request , Arango::ID ) ;
                if ( !empty( $ids ) )
                {
                    $ids = explode(Char::COMMA , $ids ) ;
                    $ids = array_values( array_unique( array_filter( $ids ) ) ) ;
                    sort($ids, SORT_NATURAL);
                }
            }

            if( empty( $ids ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::BAD_REQUEST ,
                    details  : 'No document ID provided.'
                ) ;
            }

            // $this->logger->debug( __METHOD__ . '(' . $id . ')' ) ;

            $init = [ ...$init , Arango::VALUE => $ids ] ;

            $exist = $init[ Arango::EXIST ] ?? false ;
            if( !$exist && !$this->model->exist( $init ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf
                    (
                        'No document found with the id%s: %s' ,
                        count( $ids ) > 1 ? 's' : '' ,
                        compile( $ids , ',' )
                    )
                ) ;
            }

            $this->beforeModelCall( $request , $init ) ;
            $result = $this->model->delete( $init ) ;
            $this->afterModelCall( $request , $init , $result ) ;

            return $this->success
            (
                request  : $request ,
                response : $response ,
                data     : is_array( $result ) ? array_map( fn( $doc ) => $doc?->_key , $result ) : $result?->_key
            ) ;
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request  ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            ) ;
        }
    }
}