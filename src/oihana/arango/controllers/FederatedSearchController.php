<?php

namespace oihana\arango\controllers;

use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\AuthorizationContextTrait;
use oihana\arango\enums\Arango;
use oihana\arango\search\FederatedSearch;

use oihana\auth\controllers\traits\DocumentsControllerCapabilitiesTrait;
use oihana\auth\controllers\traits\PermissionAuthorizerTrait;

use oihana\controllers\Controller;
use oihana\controllers\traits\OutputDocumentsTrait;
use oihana\controllers\traits\PrepareParamTrait;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;

/**
 * The read-only HTTP entry point of the federated multi-collection search.
 *
 * Where {@see DocumentsController} exposes one model over a single collection,
 * this controller exposes a whole {@see FederatedSearch} engine — one search
 * bar over several collections at once (customers, products, sellers, places,
 * …), returning a single list ranked by relevance. It is the *plug* that turns
 * an HTTP request into the engine `$init`, runs the engine, and renders the
 * JSON; the engine itself does the work (find + rebuild + per-collection
 * permission gate, lots C1–C4).
 *
 * It mirrors `DocumentsController` exactly, only it holds a `FederatedSearch`
 * (resolved as a DI service) instead of a `Documents` model, and exposes the
 * single read action {@see search()}:
 *
 * ```
 * GET /search?search=dupont&limit=25&offset=0&skin=compact
 * → { data: [ { collection, score, document }, … ], count, options: { total }, url }
 * ```
 *
 * **Security is already wired by the engine (lot C4).** As `DocumentsController`
 * does, this controller resolves the request enforcer + subject resolver from
 * the container, builds a request-scoped `Closure(string $subject): bool`
 * ({@see PermissionAuthorizerTrait::buildPermissionAuthorizer()}) and poses it
 * under `Arango::AUTHORIZER` in the engine `$init`; the engine consults it on
 * its own to gate the searchable collections per request. With no enforcer /
 * resolver (tests, CLI, auth disabled) the authorizer is null and the gate
 * falls open — behaviour unchanged. The query-param capability gating (search /
 * skin) is reused verbatim from {@see DocumentsControllerCapabilitiesTrait}.
 *
 * @package oihana\arango\controllers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
class FederatedSearchController extends Controller
{
    /**
     * Creates a new FederatedSearchController instance.
     *
     * @param Container           $container The DI Container reference.
     * @param array<string,mixed> $init      The optional properties to initialize the object,
     *                                        including {@see self::ENGINE} (the {@see FederatedSearch}
     *                                        service id or instance).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;

        $this->initializeLimit                ( $init )
             ->initializeSkins                ( $init )
             ->initializeAuthorizationContext ( $init )
             ->initializeEngine               ( $init ) ;
    }

    use AuthorizationContextTrait            ,
        DocumentsControllerCapabilitiesTrait ,
        OutputDocumentsTrait                 ,
        PermissionAuthorizerTrait            ,
        PrepareParamTrait
    {
        DocumentsControllerCapabilitiesTrait::prepareFilter insteadof PrepareParamTrait ;
        DocumentsControllerCapabilitiesTrait::prepareSearch insteadof PrepareParamTrait ;
        DocumentsControllerCapabilitiesTrait::prepareSkin   insteadof PrepareParamTrait ;
    }

    /**
     * Initialization key carrying the {@see FederatedSearch} engine — a service
     * id resolved from the container, or an instance passed verbatim.
     */
    public const string ENGINE = 'engine' ;

    /**
     * The controller method bound to the federated search route.
     */
    public const string SEARCH = 'search' ;

    /**
     * The federated search engine this controller exposes, or null when none
     * was configured (the action then returns an empty result set).
     *
     * @var FederatedSearch|null
     */
    protected ?FederatedSearch $engine = null ;

    /**
     * Runs a federated search across every authorized collection and renders
     * the ranked result page.
     *
     * The query term, pagination and skin are read from the request (the
     * capability-aware overrides clear a forbidden search / downgrade a
     * forbidden skin); the request authorizer is posed under
     * `Arango::AUTHORIZER` so the engine gates the searchable collections; the
     * engine then returns the page (`{ collection, score, document }` rows) and
     * the total count for pagination.
     *
     * Ex: `../search?search=dupont&limit=25&offset=0&skin=compact`
     *
     * @param ?Request $request The PSR-7 request.
     * @param ?Response $response The PSR-7 response.
     * @param array<string,mixed> $args The route arguments.
     * @param array<string,mixed> $init The optional call overrides.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function search( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        try
        {
            $params = $init[ Arango::PARAMS ] ?? [] ;

            $engineInit =
            [
                Arango::LIMIT  => $this->prepareLimit ( $request , $init , $params ) ,
                Arango::OFFSET => $this->prepareOffset( $request , $init , $params ) ,
                Arango::SEARCH => $this->prepareSearch( $request , $init , $params ) ,
                Arango::SKIN   => $this->prepareSkin  ( $request , $init , $params ) ,
            ] ;

            $authorizer = $this->buildPermissionAuthorizer( $request ) ;

            if ( $authorizer !== null )
            {
                $engineInit[ Arango::AUTHORIZER ] = $authorizer ;
            }

            $documents = $this->engine?->search( $engineInit ) ?? [] ;
            $total     = $this->engine?->foundRows() ?? 0 ;

            $options = [ Output::TOTAL => $total ] ;

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

    /**
     * Resolves the {@see FederatedSearch} engine: an instance passed verbatim,
     * or a container service id resolved through the container. Any other value
     * leaves the controller without an engine.
     *
     * @param array<string,mixed> $init
     *
     * @return static
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function initializeEngine( array $init = [] ) :static
    {
        $engine = $init[ self::ENGINE ] ?? null ;

        if ( is_string( $engine ) && $engine !== '' && $this->container->has( $engine ) )
        {
            $engine = $this->container->get( $engine ) ;
        }

        $this->engine = $engine instanceof FederatedSearch ? $engine : null ;

        return $this ;
    }
}
