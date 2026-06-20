<?php

namespace tests\oihana\arango\integration ;

use Psr\Log\LoggerInterface ;
use Psr\Log\NullLogger ;

use DI\Container ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\ArangoConfig ;
use oihana\arango\db\enums\Traversal ;
use oihana\arango\enums\Arango ;
use oihana\arango\enums\Filter ;
use oihana\arango\models\Documents ;
use oihana\arango\models\Edges ;
use oihana\arango\models\enums\filters\FilterType ;

use PHPUnit\Framework\Attributes\Group ;

use function oihana\init\initConfig ;

/**
 * Live validation of the `quant` quantifier generalized to edge & join
 * traversals: the `?filter=` object is built by the real
 * {@see Documents::prepareFilter()}, embedded in a `FOR doc IN <collection>
 * FILTER ..` query and executed against a seeded, disposable ArangoDB database.
 *
 * This proves the generated existence checks (`LENGTH(FOR … RETURN 1) <cmp> n`)
 * actually parse AND select the right documents on a real server — not just that
 * the AQL string matches — for `any` / `none` / `n` / `all` and for the pure
 * existence/absence forms (no leaf condition), on both an edge and a join.
 *
 * Edge graph (organizations → members, OUTBOUND), with member `active` flags:
 *   o1: m1(active), m2(active)      — all active
 *   o2: m3(active), m4(inactive)    — mixed
 *   o3: m5(inactive)                — none active
 *   o0: (no members)
 *
 * Join graph (customers → companies by `_key`):
 *   cu1 → c1 (Acme) ; cu2 → c2 (Globex) ; cu3 → 'missing' (dangling) ; cu4 (none)
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class FilterQuantRelationsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_filter_quant_relations_it' ;

    private const string ORGANIZATIONS = 'organizations' ;
    private const string MEMBERS       = 'members' ;
    private const string ORG_MEMBERS   = 'org_has_member' ;

    private const string CUSTOMERS = 'customers' ;
    private const string COMPANIES = 'companies' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        // --- edge graph ---
        $db->collection( self::ORGANIZATIONS )->create() ;
        $db->collection( self::MEMBERS )->create() ;
        $db->edgeCollection( self::ORG_MEMBERS )->create() ;

        foreach ( [ 'o0' , 'o1' , 'o2' , 'o3' ] as $org )
        {
            $db->collection( self::ORGANIZATIONS )->insert( [ '_key' => $org ] ) ;
        }

        $db->collection( self::MEMBERS )->insert( [ '_key' => 'm1' , 'active' => true  ] ) ;
        $db->collection( self::MEMBERS )->insert( [ '_key' => 'm2' , 'active' => true  ] ) ;
        $db->collection( self::MEMBERS )->insert( [ '_key' => 'm3' , 'active' => true  ] ) ;
        $db->collection( self::MEMBERS )->insert( [ '_key' => 'm4' , 'active' => false ] ) ;
        $db->collection( self::MEMBERS )->insert( [ '_key' => 'm5' , 'active' => false ] ) ;

        $links =
        [
            [ 'o1' , 'm1' ] , [ 'o1' , 'm2' ] ,
            [ 'o2' , 'm3' ] , [ 'o2' , 'm4' ] ,
            [ 'o3' , 'm5' ] ,
        ] ;

        foreach ( $links as [ $org , $member ] )
        {
            $db->edgeCollection( self::ORG_MEMBERS )->insert
            ([
                '_from' => self::ORGANIZATIONS . '/' . $org ,
                '_to'   => self::MEMBERS . '/' . $member ,
            ]) ;
        }

        // --- join graph ---
        $db->collection( self::COMPANIES )->create() ;
        $db->collection( self::CUSTOMERS )->create() ;

        $db->collection( self::COMPANIES )->insert( [ '_key' => 'c1' , 'name' => 'Acme'   ] ) ;
        $db->collection( self::COMPANIES )->insert( [ '_key' => 'c2' , 'name' => 'Globex' ] ) ;

        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'cu1' , 'company' => 'c1'      ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'cu2' , 'company' => 'c2'      ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'cu3' , 'company' => 'missing' ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'cu4' ] ) ;
    }

    /**
     * Returns the sorted `_key`s of the documents of `$collection` matching the
     * compiled `$filter` (with its `$binds`).
     */
    private function keys( string $collection , string $filter , array $binds ) :array
    {
        $aql    = 'FOR doc IN ' . $collection . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $cursor = self::$db->query( $aql , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    /**
     * The `organizations` model with a filterable `members` edge.
     */
    private function organizationsModel() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;
        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $organizations = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::ORGANIZATIONS , AQL::LAZY => false ] ) ;
        $members       = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::MEMBERS       , AQL::LAZY => false ] ) ;

        $container->set( 'MemberEdge' , new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ORG_MEMBERS ,
            AQL::FROM        => $organizations ,
            AQL::TO          => $members ,
            AQL::LAZY        => false ,
        ]) ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ORGANIZATIONS ,
            AQL::LAZY        => false ,
            AQL::FILTERS     =>
            [
                'members' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'active' => FilterType::BOOL ] ,
                ] ,
            ] ,
            AQL::EDGES =>
            [
                'members' => [ AQL::MODEL => 'MemberEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            ] ,
        ]) ;
    }

    /**
     * The `customers` model with a filterable `company` join.
     */
    private function customersModel() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;
        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $container->set( 'CompanyModel' , new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::COMPANIES , AQL::LAZY => false ] ) ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::CUSTOMERS ,
            AQL::LAZY        => false ,
            AQL::FILTERS     =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ] ,
            ] ,
            AQL::JOINS =>
            [
                'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' ] ,
            ] ,
        ]) ;
    }

    // ----- edges -----

    public function testEdgeAnyDefaultIsExistential() :void
    {
        // at least one active member (legacy default) → o1, o2.
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true ] , $binds ) ;
        $this->assertSame( [ 'o1' , 'o2' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    public function testEdgeNoneActiveMember() :void
    {
        // no active member → o0 (no member at all) and o3 (only inactive).
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'o0' , 'o3' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    public function testEdgeAllMembersActive() :void
    {
        // every member active → o1 ; o0 is vacuously true (no member).
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'all' ] , $binds ) ;
        $this->assertSame( [ 'o0' , 'o1' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    public function testEdgeAtLeastTwoActiveMembers() :void
    {
        // at least 2 active members → only o1.
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 2 ] , $binds ) ;
        $this->assertSame( [ 'o1' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    public function testEdgePureExistence() :void
    {
        // has at least one member (no leaf condition) → o1, o2, o3.
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*]' ] , $binds ) ;
        $this->assertSame( [ 'o1' , 'o2' , 'o3' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    public function testEdgePureAbsence() :void
    {
        // has no member at all → only o0.
        $binds  = [] ;
        $filter = $this->organizationsModel()->prepareFilter( [ 'key' => 'members[*]' , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'o0' ] , $this->keys( self::ORGANIZATIONS , $filter , $binds ) ) ;
    }

    // ----- joins -----

    public function testJoinAnyLeaf() :void
    {
        // linked to a company named Acme → cu1.
        $binds  = [] ;
        $filter = $this->customersModel()->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $binds ) ;
        $this->assertSame( [ 'cu1' ] , $this->keys( self::CUSTOMERS , $filter , $binds ) ) ;
    }

    public function testJoinNoneLeaf() :void
    {
        // not linked to an Acme company → cu2 (Globex), cu3 (dangling), cu4 (none).
        $binds  = [] ;
        $filter = $this->customersModel()->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'cu2' , 'cu3' , 'cu4' ] , $this->keys( self::CUSTOMERS , $filter , $binds ) ) ;
    }

    public function testJoinPureExistence() :void
    {
        // linked to an existing company (no leaf) → cu1, cu2.
        $binds  = [] ;
        $filter = $this->customersModel()->prepareFilter( [ 'key' => 'company' ] , $binds ) ;
        $this->assertSame( [ 'cu1' , 'cu2' ] , $this->keys( self::CUSTOMERS , $filter , $binds ) ) ;
    }

    public function testJoinPureAbsence() :void
    {
        // linked to no existing company → cu3 (dangling), cu4 (none).
        $binds  = [] ;
        $filter = $this->customersModel()->prepareFilter( [ 'key' => 'company' , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'cu3' , 'cu4' ] , $this->keys( self::CUSTOMERS , $filter , $binds ) ) ;
    }
}
