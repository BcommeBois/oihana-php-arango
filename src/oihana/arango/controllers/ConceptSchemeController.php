<?php

namespace oihana\arango\controllers;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterQuantifier;

use oihana\controllers\Controller;
use oihana\controllers\enums\Skin;
use oihana\controllers\traits\prepare\PrepareSearch;
use oihana\controllers\traits\prepare\PrepareSort;

use oihana\enums\Char;
use oihana\enums\http\HttpStatusCode;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;

use org\schema\constants\Schema;

use xyz\oihana\schema\constants\Oihana;
use xyz\oihana\schema\thesaurus\ConceptScheme;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use ReflectionException;

use function oihana\core\container\resolveDependency;

/**
 * Exposes a hierarchical thesaurus as a SKOS {@see ConceptScheme} : its
 * `hasTopConcept` is the set of **roots** (the concepts that have no broader
 * parent), assembled on the fly from the underlying {@see Documents} model.
 *
 * It is generic and read-only : a consumer wires one instance (a plain
 * `GetRoute` is enough — there is a single entry point), configured with the
 * thesaurus model ({@see self::MODEL}), a display title ({@see self::TITLE}), the
 * "broader" relation key whose absence marks a root ({@see self::RELATION},
 * defaulting to {@see Oihana::BROADER}) and the skin used to project the roots
 * ({@see self::SKIN}, defaulting to {@see Skin::FULL}). It honours `?sort` and
 * `?search` on the roots — the model applies its own `SORTABLE`/`SEARCHABLE`
 * whitelist. Nothing is persisted.
 *
 * @package oihana\arango\controllers
 * @author  Marc Alcaraz
 */
class ConceptSchemeController extends Controller
{
    use PrepareSearch ,
        PrepareSort   ;

    /**
     * Creates a new ConceptSchemeController instance.
     *
     * @param Container $container The DI container reference.
     * @param array $init Supports:
     *                        - {@see self::MODEL}    : the thesaurus `Documents` model (service id or instance),
     *                        - {@see self::TITLE}    : the human-readable scheme name,
     *                        - {@see self::RELATION} : the broader relation key (default {@see Oihana::BROADER}),
     *                        - {@see self::SKIN}     : the skin used to project the roots (default `full`).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;

        $model = resolveDependency( $init[ self::MODEL ] ?? null , $container ) ;

        $this->model    = $model instanceof Documents ? $model : null ;
        $this->title    = (string) ( $init[ self::TITLE    ] ?? Char::EMPTY ) ;
        $this->relation = (string) ( $init[ self::RELATION ] ?? Oihana::BROADER ) ;
        $this->skin     = (string) ( $init[ self::SKIN     ] ?? Skin::FULL ) ;
    }

    /**
     * Initialization key for the thesaurus model.
     */
    public const string MODEL = 'model' ;

    /**
     * Initialization key for the broader relation key (its absence marks a root).
     */
    public const string RELATION = 'relation' ;

    /**
     * Initialization key for the skin used to project the root concepts.
     */
    public const string SKIN = 'skin' ;

    /**
     * Initialization key for the scheme display title.
     */
    public const string TITLE = 'title' ;

    /**
     * The thesaurus model whose roots become the scheme's top concepts.
     */
    protected ?Documents $model = null ;

    /**
     * The broader relation key (a concept with no such relation is a root).
     */
    protected string $relation = Oihana::BROADER ;

    /**
     * The skin used to project the root concepts.
     */
    protected string $skin = Skin::FULL ;

    /**
     * The human-readable scheme name.
     */
    protected string $title = Char::EMPTY ;

    /**
     * Returns the thesaurus as a {@see ConceptScheme} whose `hasTopConcept` is the
     * list of root concepts (those with no broader relation).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function get( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        if ( !$this->model )
        {
            return $this->fail( $request , $response , HttpStatusCode::INTERNAL_SERVER_ERROR , 'Concept scheme model not configured' ) ;
        }

        // SKOS top concepts = the roots : the concepts that have no broader relation.
        // ?sort / ?search are honoured through the framework helpers ; the model
        // applies its own SORTABLE/SEARCHABLE whitelist.
        $params = [] ;

        $roots = $this->model->list
        ([
            Arango::SEARCH => $this->prepareSearch( $request , [] , $params ) ,
            Arango::SKIN   => $this->skin ,
            Arango::SORT   => $this->prepareSort( $request , [] , $params , Schema::NAME ) ,
            Arango::FILTER =>
            [
                FilterParam::KEY   => $this->relation ,
                FilterParam::QUANT => FilterQuantifier::NONE ,
            ],
        ]) ;

        $scheme = new ConceptScheme
        ([
            Schema::NAME                   => $this->title ,
            ConceptScheme::HAS_TOP_CONCEPT => $roots ,
        ]) ;

        return $this->success( $request , $response , $scheme ) ;
    }
}
