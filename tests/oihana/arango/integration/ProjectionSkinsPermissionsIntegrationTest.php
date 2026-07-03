<?php

namespace tests\oihana\arango\integration;

use Devium\Toml\TomlError;
use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\Group;

use Throwable;
use function oihana\init\initConfig;

/**
 * Live validation of the 2026-07 projection series — the three features whose
 * unit suites freeze AQL strings but cannot prove the server accepts them:
 *
 * 1. **Nested `Field::SKINS`** (deep skin filtering): a marker on a sub-field
 *    of a MAP/DOCUMENT projection varies the real response with the skin, and
 *    an emptied parent is DROPPED (never the raw sub-document — the leak the
 *    drop rule exists to prevent).
 * 2. **Definition-level `AQL::REQUIRES`**: a denied edge definition drops the
 *    relation from BOTH walks — the projected key AND its `LET` — so the query
 *    still parses and runs (a one-sided drop would raise `unknown variable`
 *    at runtime, invisible to the string-freezing unit tests). Covered on the
 *    prepared branch and on the whole-document (`*`) branch.
 * 3. **Root/structural `AQL::SKIN_FIELDS`**: a model-level bucket table drives
 *    the projection per skin, is inherited by an edge that declares no
 *    projection of its own, and gives one nested key two shapes per skin.
 *
 * Neutral graph:
 *
 * ```
 * products                                  (embedded offers[] + pricing{})
 * users --[user_has_roles]--> roles         (the gated / inherited relation)
 * users --[user_has_teams]--> teams         (an ungated sibling relation)
 * ```
 *
 * Real `Documents::list()` / models are driven against a seeded, disposable
 * database. Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ProjectionSkinsPermissionsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_projection_skins_it' ;

    private const string PRODUCTS = 'products' ;
    private const string USERS    = 'users' ;
    private const string ROLES    = 'roles' ;
    private const string TEAMS    = 'teams' ;

    private const string USER_HAS_ROLES = 'user_has_roles' ;
    private const string USER_HAS_TEAMS = 'user_has_teams' ;

    /**
     * Seeds one product (embedded pricing + offers), one user linked to one
     * role and one team.
     *
     * @throws Throwable
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::PRODUCTS )->create() ;
        $db->collection( self::USERS    )->create() ;
        $db->collection( self::ROLES    )->create() ;
        $db->collection( self::TEAMS    )->create() ;
        $db->edgeCollection( self::USER_HAS_ROLES )->create() ;
        $db->edgeCollection( self::USER_HAS_TEAMS )->create() ;

        $db->collection( self::PRODUCTS )->insert(
        [
            '_key'    => 'p1' ,
            'name'    => 'Widget' ,
            'pricing' => [ 'publicPrice' => 100 , 'internalCost' => 62 ] ,
            'offers'  =>
            [
                [ 'price' => 100 , 'priceSpecification' => [ 'basePrice' => 80 , 'taxes' => 20 ] ] ,
                [ 'price' => 90  , 'priceSpecification' => [ 'basePrice' => 72 , 'taxes' => 18 ] ] ,
            ] ,
        ]) ;

        $db->collection( self::USERS )->insert( [ '_key' => 'u1' , 'name' => 'Ada' ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'r1' , 'name' => 'manager' , 'scope' => 'apps' ] ) ;
        $db->collection( self::TEAMS )->insert( [ '_key' => 't1' , 'name' => 'Core' ] ) ;

        $db->edgeCollection( self::USER_HAS_ROLES )->insert( [ '_from' => 'users/u1' , '_to' => 'roles/r1' ] ) ;
        $db->edgeCollection( self::USER_HAS_TEAMS )->insert( [ '_from' => 'users/u1' , '_to' => 'teams/t1' ] ) ;
    }

    /**
     * A live ArangoDB façade + container wired to the disposable database.
     *
     * @return array{ 0: ArangoDB , 1: Container }
     * @throws TomlError
     * @throws Throwable
     */
    private function context() :array
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return [ $arangodb , $container ] ;
    }

    /**
     * A live `Documents` model bound to a collection on the disposable
     * database, extended with the given definition keys.
     *
     * @throws DependencyException | NotFoundException
     * @throws ContainerExceptionInterface | NotFoundExceptionInterface
     */
    private function model( ArangoDB $arangodb , Container $container , string $collection , array $init = [] ) :Documents
    {
        return new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => $collection , AQL::LAZY => false , ...$init ] ) ;
    }

    /**
     * The `user_has_roles` edge model (users → roles), its target built with
     * the given definition keys (e.g. a root `AQL::SKIN_FIELDS` registry).
     *
     * @throws DependencyException | NotFoundException
     * @throws ContainerExceptionInterface | NotFoundExceptionInterface
     */
    private function userHasRoles( ArangoDB $arangodb , Container $container , array $rolesInit = [] ) :Edges
    {
        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::USER_HAS_ROLES ,
            AQL::FROM        => $this->model( $arangodb , $container , self::USERS ) ,
            AQL::TO          => $this->model( $arangodb , $container , self::ROLES , $rolesInit ) ,
            AQL::LAZY        => false ,
        ]) ;
    }

    /**
     * The `user_has_teams` edge model (users → teams), no gating.
     *
     * @throws DependencyException | NotFoundException
     * @throws ContainerExceptionInterface | NotFoundExceptionInterface
     */
    private function userHasTeams( ArangoDB $arangodb , Container $container ) :Edges
    {
        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::USER_HAS_TEAMS ,
            AQL::FROM        => $this->model( $arangodb , $container , self::USERS ) ,
            AQL::TO          => $this->model( $arangodb , $container , self::TEAMS , [ AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ] ) ,
            AQL::LAZY        => false ,
        ]) ;
    }

    private function rows( Documents $model , array $init ) :array
    {
        return json_decode( json_encode( $model->list( $init ) ) , true ) ;
    }

    // ---------------------------------------------------------------- 1. nested Field::SKINS

    /**
     * A `Field::SKINS` marker on a nested sub-field (a DOCUMENT inside the
     * `pricing` object, a DOCUMENT inside each MAP `offers` element) varies
     * the REAL response with the requested skin, at every depth.
     */
    public function testNestedSkinsFilterTheResponseAtEveryDepth() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $products = $this->model( $arangodb , $container , self::PRODUCTS ,
        [
            AQL::FIELDS =>
            [
                '_key'    => [] ,
                'name'    => [] ,
                'pricing' =>
                [
                    Field::FILTER => Filter::DOCUMENT ,
                    Field::FIELDS =>
                    [
                        'publicPrice'  => [] ,
                        'internalCost' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                    ] ,
                ] ,
                'offers' =>
                [
                    Field::FILTER => Filter::MAP ,
                    Field::FIELDS =>
                    [
                        'price'              => [] ,
                        'priceSpecification' =>
                        [
                            Field::FILTER => Filter::DOCUMENT ,
                            Field::FIELDS => [ 'basePrice' => [] , 'taxes' => [] ] ,
                            Field::SKINS  => [ 'full' ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        $full = $this->rows( $products , [ Arango::SKIN => 'full' ] )[ 0 ] ;

        $this->assertSame( [ 'publicPrice' => 100 , 'internalCost' => 62 ] , $full[ 'pricing' ] ) ;
        $this->assertSame( [ 'basePrice' => 80 , 'taxes' => 20 ] , $full[ 'offers' ][ 0 ][ 'priceSpecification' ] ) ;

        $default = $this->rows( $products , [ Arango::SKIN => 'default' ] )[ 0 ] ;

        $this->assertSame( [ 'publicPrice' => 100 ] , $default[ 'pricing' ] ) ;                      // internalCost filtered
        $this->assertSame( [ [ 'price' => 100 ] , [ 'price' => 90 ] ] , $default[ 'offers' ] ) ;     // priceSpecification filtered at depth 2
    }

    /**
     * The emptied-parent rule at runtime: a DOCUMENT whose ONLY declared
     * sub-field is out of the requested skin is DROPPED from the response —
     * the key is absent, the raw stored object (with its sensitive values)
     * never leaks, and the query executes without error.
     */
    public function testEmptiedParentIsDroppedNotLeaked() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $products = $this->model( $arangodb , $container , self::PRODUCTS ,
        [
            AQL::FIELDS =>
            [
                '_key'    => [] ,
                'pricing' =>
                [
                    Field::FILTER => Filter::DOCUMENT ,
                    Field::FIELDS =>
                    [
                        'internalCost' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        $default = $this->rows( $products , [ Arango::SKIN => 'default' ] )[ 0 ] ;

        $this->assertArrayNotHasKey( 'pricing' , $default ) ; // dropped — NOT the raw { publicPrice, internalCost } object

        $full = $this->rows( $products , [ Arango::SKIN => 'full' ] )[ 0 ] ;

        $this->assertSame( [ 'internalCost' => 62 ] , $full[ 'pricing' ] ) ;
    }

    // ---------------------------------------------------------------- 2. definition-level AQL::REQUIRES

    /**
     * Prepared branch: a denied `AQL::REQUIRES` on the edge definition drops
     * the relation from the real response — the query still parses and runs
     * (the projected key and its `LET` are dropped together; a one-sided drop
     * would raise `unknown variable` here).
     */
    public function testDefinitionRequiresDropsTheRelationAtRuntime() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $users = $this->model( $arangodb , $container , self::USERS ,
        [
            AQL::FIELDS => [ '_key' => [] , 'name' => [] , 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            AQL::EDGES  =>
            [
                'roles' =>
                [
                    AQL::MODEL    => $this->userHasRoles( $arangodb , $container , [ AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ] ) ,
                    AQL::REQUIRES => 'users.roles:list' ,
                ] ,
            ] ,
        ]) ;

        $denied = $this->rows( $users , [ Arango::AUTHORIZER => fn() :bool => false ] )[ 0 ] ;

        $this->assertSame( 'Ada' , $denied[ 'name' ] ) ;
        $this->assertArrayNotHasKey( 'roles' , $denied ) ; // key absent — not null, not []

        $granted = $this->rows( $users , [ Arango::AUTHORIZER => fn() :bool => true ] )[ 0 ] ;

        $this->assertSame( [ [ '_key' => 'r1' , 'name' => 'manager' ] ] , $granted[ 'roles' ] ) ;
    }

    /**
     * Whole-document branch (`*`, no field list on the model): the denied
     * definition is dropped from both the `LET`s and the RETURN merge, while
     * the ungated sibling relation stays — on a real server.
     */
    public function testDefinitionRequiresGatesTheWholeDocumentBranch() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $users = $this->model( $arangodb , $container , self::USERS ,
        [
            // no AQL::FIELDS → the '*' whole-document branch
            AQL::EDGES =>
            [
                'roles' =>
                [
                    AQL::MODEL    => $this->userHasRoles( $arangodb , $container , [ AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ] ) ,
                    AQL::REQUIRES => 'users.roles:list' ,
                ] ,
                'teams' => [ AQL::MODEL => $this->userHasTeams( $arangodb , $container ) ] , // ungated
            ] ,
        ]) ;

        $denied = $this->rows( $users , [ Arango::AUTHORIZER => fn() :bool => false ] )[ 0 ] ;

        $this->assertSame( 'Ada' , $denied[ 'name' ] ) ;                                       // the whole document is there
        $this->assertArrayNotHasKey( 'roles' , $denied ) ;                                     // the gated relation is not
        $this->assertSame( [ [ '_key' => 't1' , 'name' => 'Core' ] ] , $denied[ 'teams' ] ) ;  // the ungated sibling is

        $granted = $this->rows( $users , [ Arango::AUTHORIZER => fn() :bool => true ] )[ 0 ] ;

        $this->assertSame( [ [ '_key' => 'r1' , 'name' => 'manager' ] ] , $granted[ 'roles' ] ) ;
        $this->assertSame( [ [ '_key' => 't1' , 'name' => 'Core' ] ] , $granted[ 'teams' ] ) ;
    }

    // ---------------------------------------------------------------- 3. AQL::SKIN_FIELDS (root + structural)

    /**
     * Root registry + inheritance: the target model (`roles`) declares one
     * projection per skin in its own `AQL::SKIN_FIELDS`; the edge declares NO
     * projection and inherits the bucket of the requested skin — end to end
     * on a real server.
     */
    public function testRootSkinFieldsDriveTheModelAndInheritThroughAnEdge() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $rolesInit =
        [
            AQL::SKIN_FIELDS =>
            [
                'default' => [ '_key' => [] , 'name' => [] ] ,
                'full'    => [ '_key' => [] , 'name' => [] , 'scope' => [] ] ,
            ] ,
        ] ;

        $users = $this->model( $arangodb , $container , self::USERS ,
        [
            AQL::FIELDS => [ '_key' => [] , 'name' => [] , 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            AQL::EDGES  => [ 'roles' => [ AQL::MODEL => $this->userHasRoles( $arangodb , $container , $rolesInit ) ] ] , // no projection → inherits
        ]) ;

        $full = $this->rows( $users , [ Arango::SKIN => 'full' ] )[ 0 ] ;

        $this->assertSame( [ [ '_key' => 'r1' , 'name' => 'manager' , 'scope' => 'apps' ] ] , $full[ 'roles' ] ) ;

        $default = $this->rows( $users , [ Arango::SKIN => 'default' ] )[ 0 ] ;

        $this->assertSame( [ [ '_key' => 'r1' , 'name' => 'manager' ] ] , $default[ 'roles' ] ) ; // the flat bucket, no scope
    }

    /**
     * Structural table: the SAME nested key (`offers`) gets two shapes per
     * skin through `AQL::SKIN_FIELDS` on the MAP sub-field, and an unresolved
     * table (a skin with no bucket, no '*', no Field::FIELDS) drops the field
     * — on the real response.
     */
    public function testStructuralSkinFieldsGiveTwoShapesToTheSameKey() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $products = $this->model( $arangodb , $container , self::PRODUCTS ,
        [
            AQL::FIELDS =>
            [
                '_key'   => [] ,
                'offers' =>
                [
                    Field::FILTER    => Filter::MAP ,
                    AQL::SKIN_FIELDS =>
                    [
                        'default' => [ 'price' => [] ] ,
                        'full'    =>
                        [
                            'price'              => [] ,
                            'priceSpecification' =>
                            [
                                Field::FILTER => Filter::DOCUMENT ,
                                Field::FIELDS => [ 'basePrice' => [] ] ,
                            ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        $default = $this->rows( $products , [ Arango::SKIN => 'default' ] )[ 0 ] ;

        $this->assertSame( [ [ 'price' => 100 ] , [ 'price' => 90 ] ] , $default[ 'offers' ] ) ;

        $full = $this->rows( $products , [ Arango::SKIN => 'full' ] )[ 0 ] ;

        $this->assertSame( [ 'price' => 100 , 'priceSpecification' => [ 'basePrice' => 80 ] ] , $full[ 'offers' ][ 0 ] ) ;

        // an unlisted skin resolves nothing (no '*', no Field::FIELDS) → the key is dropped
        $other = $this->rows( $products , [ Arango::SKIN => 'compact' ] )[ 0 ] ;

        $this->assertArrayNotHasKey( 'offers' , $other ) ;
    }

    // ---------------------------------------------------------------- 4. composition

    /**
     * The mechanisms compose in order — view first, security second: a
     * relation marked full-only AND permission-gated only comes out when the
     * skin matches AND the permission is granted.
     */
    public function testSkinAndPermissionCompose() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $users = $this->model( $arangodb , $container , self::USERS ,
        [
            AQL::FIELDS =>
            [
                '_key'  => [] ,
                'name'  => [] ,
                'roles' => [ Field::FILTER => Filter::EDGES , Field::SKINS => [ 'full' ] ] ,
            ] ,
            AQL::EDGES =>
            [
                'roles' =>
                [
                    AQL::MODEL    => $this->userHasRoles( $arangodb , $container , [ AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ] ) ,
                    AQL::REQUIRES => 'users.roles:list' ,
                ] ,
            ] ,
        ]) ;

        $grant = fn() :bool => true ;
        $deny  = fn() :bool => false ;

        // skin out → dropped by the view, whatever the permission
        $row = $this->rows( $users , [ Arango::SKIN => 'default' , Arango::AUTHORIZER => $grant ] )[ 0 ] ;
        $this->assertArrayNotHasKey( 'roles' , $row ) ;

        // skin in, permission denied → dropped by the lock
        $row = $this->rows( $users , [ Arango::SKIN => 'full' , Arango::AUTHORIZER => $deny ] )[ 0 ] ;
        $this->assertArrayNotHasKey( 'roles' , $row ) ;

        // skin in AND permission granted → projected
        $row = $this->rows( $users , [ Arango::SKIN => 'full' , Arango::AUTHORIZER => $grant ] )[ 0 ] ;
        $this->assertSame( [ [ '_key' => 'r1' , 'name' => 'manager' ] ] , $row[ 'roles' ] ) ;
    }
}
