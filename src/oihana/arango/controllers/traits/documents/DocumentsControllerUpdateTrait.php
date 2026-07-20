<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\enums\Arango;

use oihana\controllers\traits\ModelCallTrait;
use oihana\controllers\traits\prepare\PrepareLang;
use oihana\controllers\traits\prepare\PrepareSkin;
use oihana\controllers\traits\StatusTrait;
use oihana\controllers\traits\ValidatorTrait;

use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;

use oihana\models\traits\ModelTrait;

use org\schema\constants\Schema;

use function oihana\core\accessors\deleteKeyValue;

/**
 * Provides the functionality to update or replace documents in an ArangoDB collection
 * using PATCH or PUT HTTP methods. This trait handles:
 *   - Document existence verification.
 *   - Preparation and filtering of document data.
 *   - Validation against defined rules.
 *   - Conditional update (PATCH) or replacement (PUT) of documents.
 *   - Hooks for pre- and post-update actions (`beforeUpdate` and `afterUpdate`).
 *
 * Usage:
 * - PATCH ../collection/{id} -> Partial update
 * - PUT   ../collection/{id} -> Full replacement
 */
trait DocumentsControllerUpdateTrait
{
    use ModelCallTrait ,
        ModelTrait ,
        PayloadsTrait ,
        PrepareLang ,
        PrepareSkin ,
        StatusTrait ,
        ValidatorTrait ;

    /**
     * Provides the functionality to update or replace documents in an ArangoDB collection
     * using PATCH or PUT HTTP methods. This trait handles:
     * - Document existence verification.
     * - Preparation and filtering of document data.
     * - Validation against defined rules.
     * - Conditional update (PATCH) or replacement (PUT) of documents.
     * - Hooks for pre- and post-update actions (`beforeUpdate` and `afterUpdate`).
     *
     * Usage:
     * - PATCH ../collection/{id} -> Partial update
     * - PUT   ../collection/{id} -> Full replacement
     *
     * @param ?Request $request Optional PSR-7 ServerRequest instance.
     * @param ?Response $response Optional PSR-7 Response instance.
     * @param array $args Route parameters (e.g., ['id' => '_key']).
     * @param array $init Initialization options and additional context.
     *
     * @return mixed The updated document data on success, or a standardized error response on failure.
     */
    public function update
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
         array    $args     = []   ,
         array    $init     = []
    )
    :mixed
    {
        try
        {
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

            $relations = [] ;
            $method    = $request?->getMethod() ;

            if ( $shapeError = $this->enforceI18nShape( $request , $response , $method , $init ) )
            {
                return $shapeError ;
            }

            $payload    = $this->preparePayload( $request , $method , $init , $relations ) ;
            $validation = $this->validator->validate( $payload , $this->prepareRules( $method ) ) ;

            if( $validation->fails() )
            {
                return $this->getValidatorError( $request , $response , $validation ) ;
            }
            else
            {
                // Removes all relation keys (edges)
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

                $this->beforeModelCall( $request , $init ) ;
                $document = $method == HttpMethod::PATCH
                          ? $this->model->update  ( $init )   // PATCH -> update
                          : $this->model->replace ( $init ) ; // PUT   -> replace
                $this->afterModelCall( $request , $init , $document ) ;

                $raw = (bool) ( $init[ Arango::RAW ] ?? false ) ;

                return $this->success
                (
                    $request ,
                    $response ,
                    $raw ? $document : $this->model->get
                    ([
                        Arango::ARGS  => $args ,
                        Arango::VALUE => $document->_key ,
                        Arango::LANG  => $this->prepareLang( $request , $init )  ,
                        Arango::SKIN  => $this->prepareSkin( $request , $init , method: strtolower( $method ) ) ,
                    ])
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