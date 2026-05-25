<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\controllers\traits\CheckOwnerArgumentsTrait;
use oihana\controllers\traits\HttpCacheTrait;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\prepare\PrepareBench;
use oihana\controllers\traits\PrepareParamTrait;
use oihana\controllers\traits\StatusTrait;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;
use oihana\models\traits\ModelTrait;

use org\schema\constants\Prop;

trait DocumentsControllerGetTrait
{
    use CheckOwnerArgumentsTrait ,
        HttpCacheTrait ,
        ModelTrait ,
        OutputDocumentsTrait ,
        PrepareBench ,
        PrepareParamTrait ,
        StatusTrait;

    /**
     * Returns a specific document with a specific identifier.
     * Ex: ../element?search=film
     * Ex: ../element?facets={"location":12}
     * Ex: ../element?facets={"type":"-event,visual/exhibition","eventStatus":"-scheduled"}
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args An associative array that contains values for the current route’s named placeholders.
     * @param array $init An optional associative array to initialize the method.
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function get
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
            $this->checkOwnerArguments( $args ) ;

            $options = $init[ Arango::OPTIONS ] ?? [] ;
            $params  = $init[ Arango::PARAMS  ] ?? [] ;

            $timestamp = $this->startBench( $request , $init , $params ) ;

            // $route = $this->getRoute( $request ) ;
            // $uri   = $request->getUri() ;
            // $path  = $uri->getPath() ;
            //
            // $this->info( 'path       : ' . $path ) ;
            // $this->info( 'name       : ' . $route->getName() ) ;
            // $this->info( 'pattern    : ' . $route->getPattern() ) ;
            // $this->info( 'identifier : ' . $route->getIdentifier() ) ;
            // $this->info( 'methods    : ' . json_encode( $route->getMethods() ) ) ;
            // $this->info( 'arguments  : ' . json_encode( $route->getArguments() ) ) ;

            $modelInit =
            [
                Arango::ARGS       => $args ,
                Arango::CACHEABLE  => $init[ Arango::CACHEABLE  ] ?? null ,
                Arango::VALUE      => $args[ Arango::ID         ] ?? null ,
                Arango::ACTIVE     => $this->prepareActive( $request , $params ) ,
                Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? [] ,
                Arango::KEY        => $init[ Arango::KEY        ] ?? Prop::_KEY  ,
                Arango::LANG       => $this->prepareLang( $request , $init , $params )  ,
                Arango::SKIN       => $this->prepareSkin( $request , $init , $params , HttpMethod::get ) ,
            ] ;

            $this->beforeModelCall( $request , $modelInit ) ;
            $document = $this->model->get( $modelInit ) ;
            $this->afterModelCall( $request , $modelInit , $document ) ;

            $this->endBench( $timestamp , $options ) ;

            return $this->success( $request , $response , $document ,
            [
                Output::OPTIONS => $options ,
                Output::PARAMS  => $params  ,
            ]) ;
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