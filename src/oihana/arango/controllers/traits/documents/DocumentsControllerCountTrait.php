<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\controllers\enums\ControllerParam;
use oihana\controllers\traits\CheckOwnerArgumentsTrait;
use oihana\controllers\traits\HttpCacheTrait;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\PrepareParamTrait;
use oihana\controllers\traits\StatusTrait;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;
use oihana\models\traits\ModelTrait;

trait DocumentsControllerCountTrait
{
    use CheckOwnerArgumentsTrait ,
        HttpCacheTrait ,
        ModelTrait ,
        OutputDocumentsTrait ,
        PrepareParamTrait ,
        StatusTrait;

    /**
     * Returns the number of documents in the collection.
     *
     * @param ?Request  $request
     * @param ?Response $response
     * @param array     $args     An associative array that contains values for the current route’s named placeholders.
     * @param array     $init     An optional associative array to initialize the method.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function count( ?Request $request = null, ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        try
        {
            $this->checkOwnerArguments( $args ) ;

            $options = $init[ Arango::OPTIONS ] ?? [] ;
            $params  = $init[ ControllerParam::PARAMS ] ?? [] ;

            $timestamp = $this->startBench( $request , $init , $params ) ;

            $modelInit =
            [
                Arango::ACTIVE => $this->prepareActive( $request , $init ) ,
                Arango::FACETS => $this->prepareFacets( $request , $init , $params ) ,
                Arango::FILTER => $this->prepareFilter( $request , $init , $params ) ,
                Arango::SEARCH => $this->prepareSearch( $request , $init , $params ) ,
            ] ;

            $this->beforeModelCall( $request , $modelInit ) ;
            $count = $this->model->count( $modelInit ) ;
            $this->afterModelCall( $request , $modelInit , $count ) ;

            $this->endBench( $timestamp , $options ) ;

            return $this->success
            (
                request  : $request ,
                response : $response ,
                data     : $count ,
                init     : [ Output::PARAMS => $params , Output::OPTIONS => $options ]
            ) ;
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            );
        }
    }
}