<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\enums\Arango;

use oihana\controllers\traits\prepare\PrepareLang;
use oihana\controllers\traits\prepare\PrepareSkin;
use oihana\controllers\traits\StatusTrait;
use oihana\controllers\traits\ValidatorTrait;

use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;

use oihana\models\traits\ModelTrait;

use function oihana\core\accessors\deleteKeyValue;

trait DocumentsControllerPostTrait
{
    use PayloadsTrait ,
        ModelTrait ,
        PrepareLang ,
        PrepareSkin ,
        StatusTrait ,
        ValidatorTrait ;

    /**
     * Post a new document in an Arango DB collection.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args An associative array that contains values for the current route’s named placeholders.
     * @param array $init An optional associative array to initialize the method.
     *
     * @return mixed
     */
    public function post( ?Request $request = null, ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        try
        {
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
                $raw = (bool) ( $init[ Arango::RAW ] ?? false ) ;

                // Removes all relation keys (edges)
                if( count( $relations ) > 0 )
                {
                    $payload = deleteKeyValue( $payload , array_keys( $relations ) ) ;
                }

                $modelInit =
                [
                    Arango::DOC       => $payload ,
                    Arango::RELATIONS => $relations ,
                ] ;

                $this->beforeModelCall( $request , $modelInit ) ;
                $document = $this->model->insert( $modelInit ) ;
                $this->afterModelCall( $request , $modelInit , $document ) ;

                return $this->success
                (
                    $request ,
                    $response ,
                    $raw ? $document : $this->model->get
                    ([
                        Arango::ARGS  => $args ,
                        Arango::VALUE => $document->_key ,
                        Arango::LANG  => $this->prepareLang( $request , $init )  ,
                        Arango::SKIN  => $this->prepareSkin( $request , $init , method : HttpMethod::post ) ,
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