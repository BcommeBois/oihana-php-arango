<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\PrepareFacetCountsTrait;
use oihana\arango\controllers\traits\PrepareGroupTrait;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\controllers\traits\BenchTrait;
use oihana\controllers\traits\CheckOwnerArgumentsTrait;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\PrepareParamTrait;
use oihana\controllers\traits\StatusTrait;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;
use oihana\models\traits\ModelTrait;

trait DocumentsControllerListTrait
{
    use BenchTrait ,
        CheckOwnerArgumentsTrait ,
        ModelTrait ,
        OutputDocumentsTrait ,
        PrepareFacetCountsTrait ,
        PrepareGroupTrait ,
        PrepareParamTrait ,
        StatusTrait;

    /**
     * List a set of elements with the model.
     *
     * Ex: ../element?search=film
     * Ex: ../element?facets={"location":12}
     * Ex: ../element?facets={"type":"-event,visual/exhibition","eventStatus":"-scheduled"}
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args The route arguments
     * @param array $init ex: [ 'limit' => 0 ]
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function list( ?Request $request = null, ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        try
        {
            $this->checkOwnerArguments( $args ) ;

            $params    = $init[ Arango::PARAMS ] ?? [] ;
            $limit     = $this->prepareLimit( $request , $init , $params )  ;
            $timestamp = $this->startBench( $request , $init , $params ) ;

            $modelInit =
            [
                Arango::ACTIVE     => $this->prepareActive( $request , $init ) ,
                Arango::ARGS       => $args ,
                Arango::BINDS      => $init[ Arango::BINDS      ] ?? null ,
                Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? null ,
                Arango::FACETS     => $this->prepareFacets( $request , $init , $params ) ,
                Arango::FILTER     => $this->prepareFilter( $request , $init , $params ) ,
                Arango::GROUP      => $this->prepareGroup ( $request , $init , $params ) ,
                Arango::LANG       => $this->prepareLang  ( $request , $init , $params ) ,
                Arango::LIMIT      => $limit ,
                Arango::OFFSET     => $this->prepareOffset( $request , $init , $params ) ,
                Arango::SEARCH     => $this->prepareSearch( $request , $init , $params ) ,
                Arango::SKIN       => $this->prepareSkin  ( $request , $init , $params , HttpMethod::list ) ,
                Arango::SORT       => $this->prepareSort   ( $request , $init , $params ) ,
            ] ;

            $facetCounts = $this->prepareFacetCounts( $request , $init , $params ) ;

            $this->beforeModelCall( $request , $modelInit ) ;
            $documents = $this->model->list( $modelInit ) ;
            $this->afterModelCall( $request , $modelInit , $documents ) ;

            $total = count( $documents ) ;

            if( $limit > 0 && $this->model instanceof Documents && !$this->model->mock )
            {
                $total = $this->model->foundRows() ;
            }

            $options = [ Output::TOTAL => $total ] ;

            // Per-value facet counts alongside the list (faceted-search sidebar).
            if( !empty( $facetCounts ) && $this->model instanceof Documents && !$this->model->mock )
            {
                $options[ Arango::FACETS ] = $this->model->facetCounts( [ ...$modelInit , Arango::FACET_COUNTS => $facetCounts ] ) ;
            }

            $this->endBench( $timestamp , $options ) ;

            return $this->outputDocuments( $request , $response , $documents , $params , $options ) ;
        }
        catch( Exception $e )
        {
            $this->warning( json_encode( $e->getTrace() , JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ;
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