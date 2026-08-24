<?php

namespace oihana\arango\controllers\traits\properties;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\arango\controllers\enums\ModelOperation;
use oihana\controllers\traits\CheckOwnerArgumentsTrait;
use oihana\controllers\traits\HttpCacheTrait;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\prepare\PrepareLang;
use oihana\controllers\traits\prepare\PrepareSkin;
use oihana\controllers\traits\StatusTrait;
use oihana\models\traits\ModelTrait;
use oihana\models\traits\PropertyTrait;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;

use org\schema\constants\Prop;

use function oihana\core\arrays\isIndexed;

trait PropertyControllerGetTrait
{
    use CheckOwnerArgumentsTrait ,
        HttpCacheTrait ,
        ModelTrait ,
        OutputDocumentsTrait ,
        PrepareLang ,
        PrepareSkin ,
        PropertyTrait ,
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
            $this->assertProperty();
            $this->checkOwnerArguments( $args ) ;

            $options = $init[ Arango::OPTIONS ] ?? [] ;
            $params  = $init[ Arango::PARAMS  ] ?? [] ;

            $modelInit =
            [
                Arango::ARGS       => $args ,
                Arango::CACHEABLE  => $init[ Arango::CACHEABLE  ] ?? null ,
                Arango::VALUE      => $args[ Arango::ID         ] ?? null ,
                Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? [] ,
                Arango::KEY        => $init[ Arango::KEY        ] ?? Prop::_KEY  ,
                Arango::IN         => $this->property , // returns only the specific property field
                Arango::LANG       => $this->prepareLang( $request , $init , $params )  ,
                Arango::OPERATION  => ModelOperation::GET ,
                Arango::SKIN       => $this->prepareSkin( $request , $init , $params , HttpMethod::get ) ,
            ] ;

            $this->beforeModelCall( $request , $modelInit ) ;

            $document = $this->model->get( $modelInit ) ;

            $this->afterModelCall( $request , $modelInit , $document ) ;

            // A document filtered out by the scope and an unknown identifier are
            // indistinguishable here — both answer 200 with a null result, exactly
            // like a visible document whose property is simply absent. Saying more
            // (a 404 on the first two) would tell a caller which of the three it hit.
            $data = $document->{ $this->property } ?? null ;

            return $this->success( $request , $response , $data  ,
            [
                Output::COUNT   => is_array( $data ) && isIndexed( $data ) ? count( $data ) : null ,
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