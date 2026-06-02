<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\GetQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see GetQueryTrait}. It composes FieldsTrait → ArangoTrait,
 * which already declares `$collection`, so the property is set in the constructor
 * rather than redeclared.
 */
class GetQueryTraitStub
{
    use GetQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }
}

/**
 * Characterization coverage for {@see GetQueryTrait::buildGetQuery()} — the pure
 * `FOR ... FILTER <key> == <value> RETURN { fields }` single-document lookup.
 * The value bind has an auto-generated name, normalised to a stable sequence.
 */
class GetQueryTraitTest extends TestCase
{
    private function stub() :GetQueryTraitStub
    {
        return new GetQueryTraitStub() ;
    }

    /** @return array{0:string,1:array} normalised [query, binds] (see ExistQueryTraitTest). */
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

    public function testDefaultKeyLookup() :void
    {
        $binds = [] ;
        [ $query , $binds ] = $this->normalize( $this->stub()->buildGetQuery( [ Arango::VALUE => 'k1' ] , $binds ) , $binds ) ;

        $this->assertSame( 'FOR doc IN @@collection FILTER doc._key == @q_0 RETURN doc' , $query ) ;
        $this->assertSame( [ '@collection' => 'users' , 'q_0' => 'k1' ] , $binds ) ;
    }

    public function testCustomKeyPrefixAndExtraConditions() :void
    {
        $binds = [] ;
        [ $query ] = $this->normalize
        (
            $this->stub()->buildGetQuery
            (
                [
                    Arango::VALUE      => 'k1' ,
                    Arango::KEY        => 'slug' ,
                    Arango::PREFIX     => 'u' ,
                    Arango::CONDITIONS => [ 'u.x==1' ] ,
                ] ,
                $binds ,
            ) ,
            $binds ,
        ) ;

        $this->assertSame( 'FOR doc IN @@collection FILTER u.slug == @q_0 && u.x==1 RETURN doc' , $query ) ;
    }

    public function testDebugFlagDoesNotAlterTheQuery() :void
    {
        $binds = [] ;
        [ $query ] = $this->normalize
        (
            $this->stub()->buildGetQuery( [ Arango::VALUE => 'k1' , Arango::DEBUG => true ] , $binds ) ,
            $binds ,
        ) ;
        $this->assertSame( 'FOR doc IN @@collection FILTER doc._key == @q_0 RETURN doc' , $query ) ;
    }

    public function testActiveFilterIsAppendedWhenActivable() :void
    {
        $stub = $this->stub() ;
        $stub->activable = true ;

        $binds = [] ;
        [ $query , $binds ] = $this->normalize
        (
            $stub->buildGetQuery( [ Arango::VALUE => 'k1' , Arango::ACTIVE => true ] , $binds ) ,
            $binds ,
        ) ;

        $this->assertSame( 'FOR doc IN @@collection FILTER doc._key == @q_0 && doc.active == @active RETURN doc' , $query ) ;
        $this->assertSame( 1 , $binds[ 'active' ] ) ;
    }
}
