<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\Group;

use Throwable;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\init\initConfig;

/**
 * Live validation that a FILTER through a relation reads the relation the PROJECTION
 * reads : the list of keys of a list join (`IN`, not `==`), the conditions of a join,
 * its anchor (`Arango::SOURCE`, `Arango::PROPERTY`), the scope of an edge (`AQL::WHERE`,
 * `AQL::PRUNE`), its depth, its start — each case read on the answer of a real server.
 *
 * Neutral data :
 *
 * ```
 * tracks  t1 « Intro » (audio) · t2 « Outro » (audio) · t4 « Bonus » (hidden)
 * albums  a1 tracks [t1,t2] · a2 tracks [t4] , refs [{_key:t4}] · a3 selector.trackId t2 · a4 pick {ref:t2}
 *
 * people     p1 Bob (active) · p2 Ann (inactive) · m1 Max (active) · m2 Mia (inactive) · m3 Leo (active)
 * companies  c1 ─employs→ p1 · c2 ─employs→ p2 · c5 ─employs→ p1, p2 · c4 { parentRef: companies/c1 }
 *            c3 ─employs→ m1 ─employs→ m2 ─employs→ m3      (a chain, three levels deep)
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class FilterReadsTheRelationIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_filter_relation_it' ;

    private const string ALBUMS    = 'albums' ;
    private const string COMPANIES = 'companies' ;
    private const string EMPLOYS   = 'employs' ;
    private const string PEOPLE    = 'people' ;
    private const string TRACKS    = 'tracks' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        foreach ( [ self::ALBUMS , self::COMPANIES , self::PEOPLE , self::TRACKS ] as $collection )
        {
            $db->collection( $collection )->create() ;
        }
        $db->edgeCollection( self::EMPLOYS )->create() ;

        $tracks = $db->collection( self::TRACKS ) ;
        $tracks->insert( [ '_key' => 't1' , 'name' => 'Intro' , 'kind' => 'audio'  ] ) ;
        $tracks->insert( [ '_key' => 't2' , 'name' => 'Outro' , 'kind' => 'audio'  ] ) ;
        $tracks->insert( [ '_key' => 't4' , 'name' => 'Bonus' , 'kind' => 'hidden' ] ) ;

        $albums = $db->collection( self::ALBUMS ) ;
        $albums->insert( [ '_key' => 'a1' , 'tracks' => [ 't1' , 't2' ] ] ) ;
        $albums->insert( [ '_key' => 'a2' , 'tracks' => [ 't4' ] , 'refs' => [ [ '_key' => 't4' ] ] ] ) ;
        $albums->insert( [ '_key' => 'a3' , 'selector' => [ 'trackId' => 't2' ] ] ) ;
        $albums->insert( [ '_key' => 'a4' , 'pick' => [ 'ref' => 't2' ] ] ) ;

        $people = $db->collection( self::PEOPLE ) ;
        foreach ( [ 'p1' => [ 'Bob' , true ] , 'p2' => [ 'Ann' , false ] , 'm1' => [ 'Max' , true ] , 'm2' => [ 'Mia' , false ] , 'm3' => [ 'Leo' , true ] ] as $key => [ $name , $active ] )
        {
            $people->insert( [ '_key' => $key , 'name' => $name , 'active' => $active ] ) ;
        }

        $companies = $db->collection( self::COMPANIES ) ;
        foreach ( [ 'c1' , 'c2' , 'c3' , 'c5' ] as $key )
        {
            $companies->insert( [ '_key' => $key ] ) ;
        }
        $companies->insert( [ '_key' => 'c4' , 'parentRef' => 'companies/c1' ] ) ;

        $employs = $db->edgeCollection( self::EMPLOYS ) ;
        foreach ( [ [ 'companies/c1' , 'people/p1' ] , [ 'companies/c2' , 'people/p2' ] , [ 'companies/c5' , 'people/p1' ] , [ 'companies/c5' , 'people/p2' ] ,
                    [ 'companies/c3' , 'people/m1' ] , [ 'people/m1' , 'people/m2' ] , [ 'people/m2' , 'people/m3' ] ] as [ $from , $to ] )
        {
            $employs->insert( [ '_from' => $from , '_to' => $to ] ) ;
        }
    }

    // ---- R1 : a list join -------------------------------------------------

    /**
     * 🚨 A filter through a list of keys finds the album that has the track — it found
     * nothing before (a key compared with a list).
     *
     * @throws Throwable
     */
    public function testAListJoinFilterFindsTheAlbumThatHasTheTrack() :void
    {
        $this->assertSame( [ 'a1' ] , $this->albums( 'tracks' , Filter::JOINS , [] , [ 'key' => 'tracks[*].name' , 'val' => 'Intro' ] ) ) ;
    }

    /**
     * And through a list of objects, on its expanded key.
     *
     * @throws Throwable
     */
    public function testAListOfObjectsFilterFindsTheAlbum() :void
    {
        $this->assertSame( [ 'a2' ] , $this->albums( 'refs' , Filter::JOINS , [ Arango::PROPERTY => '[*]._key' ] , [ 'key' => 'refs[*].name' , 'val' => 'Bonus' ] ) ) ;
    }

    // ---- R2 : the conditions of a join ------------------------------------

    /**
     * 🚨 A track the conditions mask does not make its album retained.
     *
     * @throws Throwable
     */
    public function testAMaskedTrackDoesNotRetainItsAlbum() :void
    {
        $audio = [ Arango::CONDITIONS => fn( string $join ) => [ $join . '.kind == "audio"' ] ] ;

        $this->assertSame( []       , $this->albums( 'tracks' , Filter::JOINS , $audio , [ 'key' => 'tracks[*].name' , 'val' => 'Bonus' ] ) ) ;
        $this->assertSame( [ 'a1' ] , $this->albums( 'tracks' , Filter::JOINS , $audio , [ 'key' => 'tracks[*].name' , 'val' => 'Intro' ] ) ) ;
    }

    // ---- R3 : the anchor of a join -------------------------------------------

    /**
     * 🚨 Arango::SOURCE anchors the key the filter compares.
     *
     * @throws Throwable
     */
    public function testTheSourceOfAJoinAnchorsTheFilter() :void
    {
        $this->assertSame( [ 'a3' ] , $this->albums( 'track' , Filter::JOIN , [ Arango::SOURCE => 'selector.trackId' ] , [ 'key' => 'track.name' , 'val' => 'Outro' ] ) ) ;
    }

    /**
     * And Arango::PROPERTY is appended to it.
     *
     * @throws Throwable
     */
    public function testThePropertyOfAJoinIsReadByTheFilter() :void
    {
        $this->assertSame( [ 'a4' ] , $this->albums( 'pick' , Filter::JOIN , [ Arango::PROPERTY => 'ref' ] , [ 'key' => 'pick.name' , 'val' => 'Outro' ] ) ) ;
    }

    // ---- R4 : the scope of an edge ------------------------------------------

    /**
     * 🚨 An employee out of the scope does not make the company retained.
     *
     * @throws Throwable
     */
    public function testAnEmployeeOutOfTheScopeDoesNotRetainTheCompany() :void
    {
        $active = [ AQL::WHERE => [ 'active' , 'eq' , true ] ] ;

        $this->assertSame( []                   , $this->companies( $active , [ 'key' => 'employee[*].name' , 'val' => 'Ann' ] ) ) ;
        $this->assertSame( [ 'c1' , 'c5' ]       , $this->companies( $active , [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] ) ) ;
    }

    /**
     * A scope decided at query time (aqlBindRef) holds too.
     *
     * @throws Throwable
     */
    public function testAScopeBoundAtQueryTimeHolds() :void
    {
        $hidden = [ AQL::WHERE => [ 'name' , 'nin' , aqlBindRef( 'hidden' ) ] ] ;

        $this->assertSame( [] , $this->companies( $hidden , [ 'key' => 'employee[*].name' , 'val' => 'Ann' ] , [ 'hidden' => [ 'Ann' ] ] ) ) ;
    }

    /**
     * Under `all`, the scope stays positive : every employee IN THE SCOPE is Bob — c5
     * employs Ann too, but Ann is out of the scope.
     *
     * @throws Throwable
     */
    public function testUnderAllOnlyTheScopeIsAsked() :void
    {
        $this->assertContains( 'c5' , $this->companies( [ AQL::WHERE => [ 'active' , 'eq' , true ] ] , [ 'key' => 'employee[*].name' , 'val' => 'Bob' , 'quant' => 'all' ] ) ) ;
        $this->assertNotContains( 'c5' , $this->companies( [] , [ 'key' => 'employee[*].name' , 'val' => 'Bob' , 'quant' => 'all' ] ) ) ;
    }

    /**
     * AQL::PRUNE stops the walk of the filter : Mia is out of the scope, and Leo, under
     * her, is not reached — as the projection does not show him.
     *
     * @throws Throwable
     */
    public function testPruneStopsTheWalkOfTheFilter() :void
    {
        $pruned = [ AQL::MAX_DEPTH => 3 , AQL::WHERE => [ 'active' , 'eq' , true ] , AQL::PRUNE => true ] ;
        $scoped = [ AQL::MAX_DEPTH => 3 , AQL::WHERE => [ 'active' , 'eq' , true ] ] ;

        $this->assertSame( []       , $this->companies( $pruned , [ 'key' => 'employee[*].name' , 'val' => 'Leo' ] ) ) ;
        $this->assertSame( [ 'c3' ] , $this->companies( $scoped , [ 'key' => 'employee[*].name' , 'val' => 'Leo' ] ) ) ;
    }

    // ---- R6 : the depth of an edge ------------------------------------------

    /**
     * 🚨 A filter on the third level retains the root — it walked one level before.
     *
     * @throws Throwable
     */
    public function testTheFilterWalksTheDepthOfTheEdge() :void
    {
        $this->assertSame( [ 'c3' ] , $this->companies( [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => 3 ] , [ 'key' => 'employee[*].name' , 'val' => 'Leo' ] ) ) ;
        $this->assertSame( []       , $this->companies( []                                           , [ 'key' => 'employee[*].name' , 'val' => 'Leo' ] ) ) ;
    }

    /**
     * Two ranged edges filtered in one query keep their own depths.
     *
     * @throws Throwable
     */
    public function testTwoRangedEdgesInOneQueryKeepTheirDepths() :void
    {
        [ $model ] = $this->companyModel(
        [
            'employee' => [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => 3 ] ,
            'staff'    => [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => 1 ] ,
        ] ) ;

        $binds  = [] ;
        $filter = $model->prepareFilter( [ [ 'key' => 'employee[*].name' , 'val' => 'Leo' ] , [ 'key' => 'staff[*].name' , 'val' => 'Max' ] ] , $binds ) ;

        $this->assertSame( [ 'c3' ] , $this->keys( self::COMPANIES , $filter , $binds ) ) ;
    }

    // ---- the start of an edge -----------------------------------------------

    /**
     * Arango::SOURCE moves the start of the filter as it moves the projection's.
     *
     * @throws Throwable
     */
    public function testTheSourceOfAnEdgeMovesTheStartOfTheFilter() :void
    {
        $this->assertSame( [ 'c4' ] , $this->companies( [ Arango::SOURCE => 'parentRef' ] , [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] ) ) ;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * The keys of the albums a filter retains through a join on the tracks.
     *
     * @param array<string,mixed> $definition Extra keys of the join definition.
     * @param array<string,mixed> $filter     The filter.
     *
     * @return array<int,string>
     *
     * @throws Throwable
     */
    private function albums( string $field , string $type , array $definition , array $filter ) :array
    {
        [ $arangodb , $container ] = $this->context() ;

        $container->set( 'TrackModel' , new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::TRACKS , AQL::LAZY => false ] ) ) ;

        $model = new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ALBUMS ,
            AQL::LAZY        => false ,
            AQL::FILTERS     => [ $field => [ AQL::TYPE => $type , AQL::FILTERS => [ 'name' => FilterType::STRING ] ] ] ,
            AQL::JOINS       => [ $field => [ AQL::MODEL => 'TrackModel' , AQL::KEY => '_key' , ...$definition ] ] ,
        ]) ;

        $binds = [] ;

        return $this->keys( self::ALBUMS , $model->prepareFilter( $filter , $binds ) , $binds ) ;
    }

    /**
     * The keys of the companies a filter retains through the `employee` edge.
     *
     * @param array<string,mixed> $definition Extra keys of the edge definition.
     * @param array<string,mixed> $filter     The filter.
     * @param array<string,mixed> $values     The values bound at query time.
     *
     * @return array<int,string>
     *
     * @throws Throwable
     */
    private function companies( array $definition , array $filter , array $values = [] ) :array
    {
        [ $model ] = $this->companyModel( [ 'employee' => $definition ] ) ;

        $binds = [] ;
        $aql   = $model->prepareFilter( $filter , $binds ) ;

        return $this->keys( self::COMPANIES , $aql , [ ...$binds , ...$values ] ) ;
    }

    /**
     * A companies model whose edges, each on `employs`, are filterable by name.
     *
     * @param array<string,array<string,mixed>> $edges The extra keys of each edge definition, by edge name.
     *
     * @return array{0:Documents}
     *
     * @throws Throwable
     */
    private function companyModel( array $edges ) :array
    {
        [ $arangodb , $container ] = $this->context() ;

        $companies = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::COMPANIES , AQL::LAZY => false ] ) ;
        $people    = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::PEOPLE    , AQL::LAZY => false , AQL::FILTERS => [ 'name' => FilterType::STRING ] ] ) ;

        $container->set( 'EmploysEdge' , new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::EMPLOYS ,
            AQL::FROM        => $companies ,
            AQL::TO          => $people ,
            AQL::LAZY        => false ,
        ]) ) ;

        $filters     = [] ;
        $definitions = [] ;

        foreach ( $edges as $name => $definition )
        {
            $filters[ $name ]     = [ AQL::TYPE => Filter::EDGES , AQL::FILTERS => [ 'name' => FilterType::STRING ] ] ;
            $definitions[ $name ] = [ AQL::MODEL => 'EmploysEdge' , AQL::DIRECTION => Traversal::OUTBOUND , ...$definition ] ;
        }

        return
        [
            new Documents( $container ,
            [
                Arango::DATABASE => $arangodb ,
                AQL::COLLECTION  => self::COMPANIES ,
                AQL::LAZY        => false ,
                AQL::FILTERS     => $filters ,
                AQL::EDGES       => $definitions ,
            ]) ,
        ] ;
    }

    /**
     * The sorted keys of the documents of a collection a compiled filter retains.
     *
     * @param array<string,mixed> $binds
     *
     * @return array<int,string>
     *
     * @throws Throwable
     */
    private function keys( string $collection , string $filter , array $binds ) :array
    {
        $cursor = self::$db->query( 'FOR doc IN ' . $collection . ' FILTER ' . $filter . ' RETURN doc._key' , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;

        return $keys ;
    }

    /**
     * A live ArangoDB façade + container wired to the disposable database.
     *
     * @return array{ 0: ArangoDB , 1: Container }
     *
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
}
