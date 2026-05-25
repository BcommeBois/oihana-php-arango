<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use oihana\controllers\traits\CheckOwnerArgumentsTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\prepare\PrepareBench;
use oihana\controllers\traits\PrepareParamTrait;
use oihana\controllers\traits\StatusTrait;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;
use oihana\models\traits\ModelTrait;

trait DocumentsControllerLastTrait
{
    use CheckOwnerArgumentsTrait ,
        ModelTrait ,
        OutputDocumentsTrait ,
        PrepareBench ,
        PrepareParamTrait ,
        StatusTrait;

    /**
     * Returns the last document modified or with a specific date property in the collection.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args An associative array that contains values for the current route’s named placeholders.
     * @param array $init An optional associative array to initialize the method.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function last( ?Request $request = null, ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        try
        {
            $this->checkOwnerArguments( $args ) ;

            $options   = $init[ Arango::OPTIONS ] ?? [] ;
            $params    = $init[ Arango::PARAMS  ] ?? [] ;
            $timestamp = $this->startBench( $request , $init , $params ) ;

            $modelInit =
            [
                Arango::ARGS       => $args ,
                Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? [] ,
                Arango::SKIN       => $this->prepareSkin( $request , $init , $params , HttpMethod::get ) ,
            ] ;

            $this->beforeModelCall( $request , $modelInit ) ;
            $document = $this->model->last( $modelInit ) ;
            $this->afterModelCall( $request , $modelInit , $document ) ;

            $this->endBench( $timestamp , $options ) ;
            return $this->success( $request , $response , $document ,
            [
                Output::OPTIONS => $options ,
                Output::PARAMS  => $params  ,
            ] ) ;
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