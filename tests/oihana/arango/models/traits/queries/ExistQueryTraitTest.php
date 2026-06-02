<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\ExistQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see ExistQueryTrait}. A fixed query id keeps the
 * auto-generated value-bind prefix stable (`q_<random>`); the random numeric
 * suffix is normalised away in assertions (see {@see normalize()}).
 */
class ExistQueryTraitStub
{
    use ExistQueryTrait ;

    public ?string $collection = 'users' ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }
}

/**
 * Characterization coverage for {@see ExistQueryTrait::buildExistQuery()} — the
 * pure `RETURN LENGTH( FOR ... FILTER <key> IN [...] RETURN 1 )` existence probe.
 * The fetch side (exist()) is out of scope here and belongs to the Tier-2
 * mock-transport suite.
 */
class ExistQueryTraitTest extends TestCase
{
    private function stub() :ExistQueryTraitStub
    {
        return new ExistQueryTraitStub() ;
    }

    /**
     * Renames the non-deterministic value-bind variables (`q_<random>`) to a
     * stable sequence (`q_0`, `q_1`, ...) in BOTH the query string and the bind
     * keys, in order of first appearance in the query — so an exact assertion
     * still proves the query references exactly the binds that were created.
     *
     * @return array{0:string,1:array} The normalised [query, binds] pair.
     */
    private function normalize( string $query , array $binds ) :array
    {
        preg_match_all( '/q_\d+/' , $query , $matches ) ;

        $map = [] ;
        $i   = 0 ;
        foreach ( $matches[ 0 ] as $token )
        {
            if ( !isset( $map[ $token ] ) )
            {
                $map[ $token ] = 'q_' . $i++ ;
            }
        }

        $normBinds = [] ;
        foreach ( $binds as $key => $value )
        {
            $normBinds[ $map[ $key ] ?? $key ] = $value ;
        }

        return [ strtr( $query , $map ) , $normBinds ] ;
    }

    public function testSingleValueDefaultKeyAndPrefix() :void
    {
        $binds = [] ;
        [ $query , $binds ] = $this->normalize( $this->stub()->buildExistQuery( [ Arango::VALUE => 'abc' ] , $binds ) , $binds ) ;

        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key IN [@q_0] RETURN 1)' , $query ) ;
        $this->assertSame( [ 'q_0' => 'abc' , '@collection' => 'users' ] , $binds ) ;
    }

    public function testMultipleValuesProduceOneBindEach() :void
    {
        $binds = [] ;
        [ $query , $binds ] = $this->normalize( $this->stub()->buildExistQuery( [ Arango::VALUE => [ 'a' , 'b' ] ] , $binds ) , $binds ) ;

        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key IN [@q_0,@q_1] RETURN 1)' , $query ) ;
        $this->assertSame( [ 'q_0' => 'a' , 'q_1' => 'b' , '@collection' => 'users' ] , $binds ) ;
    }

    public function testDuplicateValuesAreDeduplicated() :void
    {
        $binds = [] ;
        [ $query , $binds ] = $this->normalize( $this->stub()->buildExistQuery( [ Arango::VALUE => [ 'a' , 'a' , 'b' ] ] , $binds ) , $binds ) ;

        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key IN [@q_0,@q_1] RETURN 1)' , $query ) ;
        $this->assertSame( [ 'q_0' => 'a' , 'q_1' => 'b' , '@collection' => 'users' ] , $binds ) ;
    }

    public function testCustomKeyPrefixAndExtraConditions() :void
    {
        $binds = [] ;
        [ $query ] = $this->normalize
        (
            $this->stub()->buildExistQuery
            (
                [
                    Arango::VALUE      => [ 'x' ] ,
                    Arango::KEY        => 'email' ,
                    Arango::PREFIX     => 'u' ,
                    Arango::CONDITIONS => [ 'u.active == 1' ] ,
                ] ,
                $binds ,
            ) ,
            $binds ,
        ) ;

        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER u.email IN [@q_0] && u.active == 1 RETURN 1)' ,
            $query ,
        ) ;
    }

    public function testInstanceConditionsArePrepended() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.deleted == false' ] ;

        $binds = [] ;
        [ $query ] = $this->normalize( $stub->buildExistQuery( [ Arango::VALUE => 'z' ] , $binds ) , $binds ) ;

        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc.deleted == false && doc._key IN [@q_0] RETURN 1)' ,
            $query ,
        ) ;
    }

    public function testEmptyValuesProduceEmptyInList() :void
    {
        $binds = [] ;
        $query = $this->stub()->buildExistQuery( [] , $binds ) ;

        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key IN [] RETURN 1)' , $query ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }
}
