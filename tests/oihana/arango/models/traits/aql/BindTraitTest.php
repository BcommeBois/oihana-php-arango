<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\BindTrait;

use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see BindTrait}. A fixed query id makes the
 * auto-generated bind-name prefix deterministic (`q_<random>`).
 */
class BindTraitStub
{
    use BindTrait ;

    public ?string $collection = 'users' ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }
}

/**
 * Characterization coverage for {@see BindTrait}: bind() (value binding, with an
 * explicit or auto-generated variable name) and bindCollection() (the `@@`
 * collection binding, defaulting to `$this->collection`).
 */
class BindTraitTest extends TestCase
{
    private function stub() :BindTraitStub
    {
        return new BindTraitStub() ;
    }

    // ---------------------------------------------------------------- bind

    public function testBindWithExplicitNameRegistersValue() :void
    {
        $binds = [] ;
        $this->assertSame( '@name' , $this->stub()->bind( 'Marc' , $binds , 'name' ) ) ;
        $this->assertSame( [ 'name' => 'Marc' ] , $binds ) ;
    }

    public function testBindWithoutNameGeneratesAQueryPrefixedVariable() :void
    {
        $binds = [] ;
        $var = $this->stub()->bind( 42 , $binds ) ;

        $this->assertMatchesRegularExpression( '/^@q_\d+$/' , $var ) ;
        $this->assertCount( 1 , $binds ) ;
        $this->assertSame( 42 , reset( $binds ) ) ;
        $this->assertMatchesRegularExpression( '/^q_\d+$/' , array_key_first( $binds ) ) ;
    }

    public function testBindWithInvalidNameThrows() :void
    {
        $this->expectException( BindException::class ) ;
        $binds = [] ;
        $this->stub()->bind( 'x' , $binds , '1bad' ) ;
    }

    // ---------------------------------------------------------------- bindCollection

    public function testBindCollectionDefaultsToInstanceCollection() :void
    {
        $binds = [] ;
        $this->assertSame( '@@collection' , $this->stub()->bindCollection( $binds ) ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testBindCollectionHonorsCollectionAndNameOptions() :void
    {
        $binds = [] ;
        $var = $this->stub()->bindCollection( $binds , [ Arango::COLLECTION => 'places' , Arango::NAME => 'col' ] ) ;

        $this->assertSame( '@@col' , $var ) ;
        $this->assertSame( [ '@col' => 'places' ] , $binds ) ;
    }
}
