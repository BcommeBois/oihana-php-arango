<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\exceptions\UnsupportedOperationException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\joins\joinKeyPaths;

/**
 * Where a list join reads its keys — {@see joinKeyPaths()}.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class JoinKeyPathsTest extends TestCase
{
    /**
     * @return array<string,array{0:null|string|array<int,string>,1:array{0:string,1:string,2:string|null}}>
     */
    public static function properties() :array
    {
        return
        [
            'no property : the elements are the keys' => [ null           , [ 'doc.refs'              , 'doc.refs'       , null   ] ] ,
            'a plain property is appended'            => [ 'ids'          , [ 'doc.refs.ids'          , 'doc.refs.ids'   , null   ] ] ,
            'a dotted property is appended'           => [ 'a.ids'        , [ 'doc.refs.a.ids'        , 'doc.refs.a.ids' , null   ] ] ,
            'a list of objects, keyed on _key'        => [ '[*]._key'     , [ 'doc.refs[*]._key'      , 'doc.refs'       , '_key' ] ] ,
            'a list of objects, keyed deeper'         => [ '[*].ref.id'   , [ 'doc.refs[*].ref.id'    , 'doc.refs'       , 'ref.id' ] ] ,
            'a list inside the field'                 => [ 'items[*].ref' , [ 'doc.refs.items[*].ref' , 'doc.refs.items' , 'ref'  ] ] ,
            'the bare expansion : the list itself'    => [ '[*]'          , [ 'doc.refs[*]'           , 'doc.refs'       , null   ] ] ,
        ] ;
    }

    /**
     * @param null|string|array<int,string>                $property
     * @param array{0:string,1:string,2:string|null} $expected
     *
     * @throws UnsupportedOperationException
     */
    #[DataProvider( 'properties' )]
    public function testThePathsOfAProperty( null|string|array $property , array $expected ) :void
    {
        $this->assertSame( $expected , joinKeyPaths( 'doc.refs' , $property ) ) ;
    }

    /**
     * 🚨 The path the library wrote before : a dot in front of the expansion, which
     * ArangoDB refuses as a syntax error.
     *
     * @throws UnsupportedOperationException
     */
    public function testAnExpansionIsGluedWithoutADot() :void
    {
        [ $in ] = joinKeyPaths( 'doc.refs' , '[*]._key' ) ;

        $this->assertStringNotContainsString( '.[*]' , $in ) ;
    }

    /**
     * A property with no expansion keeps the path `key()` always gave it — the
     * historical behaviour, unchanged.
     *
     * @throws UnsupportedOperationException
     */
    public function testAPropertyWithoutExpansionKeepsItsHistoricalPath() :void
    {
        $this->assertSame( [ 'doc.refs.ref' , 'doc.refs.ref' , null ] , joinKeyPaths( 'doc.refs' , 'ref' ) ) ;
    }

    /**
     * An array property is appended as it always was, and carries no expansion.
     *
     * @throws UnsupportedOperationException
     */
    public function testAnArrayPropertyIsAppendedAsItAlwaysWas() :void
    {
        [ $in , $list , $item ] = joinKeyPaths( 'doc.refs' , [ 'a' , 'b' ] ) ;

        $this->assertSame( $in , $list ) ;
        $this->assertNull( $item ) ;
    }

    public function testTwoExpansionsAreRefused() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( "'[*].tags[*]._key'" ) ;

        joinKeyPaths( 'doc.refs' , '[*].tags[*]._key' ) ;
    }
}
