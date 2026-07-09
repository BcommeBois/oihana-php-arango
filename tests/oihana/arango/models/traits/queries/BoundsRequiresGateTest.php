<?php

namespace tests\oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\Bound;
use oihana\arango\models\traits\queries\BoundsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

/**
 * Self-contained host for the bounds permission gate.
 */
class BoundsGateStub
{
    use ListQueryTrait , BoundsQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'products' ;
        $this->bounds     = [ 'width' => true ] ;
    }
}

/**
 * Permission gate on `?bounds=`: a boundable field hidden from the projection is
 * dropped — its `{ min, max }` would otherwise leak a real value of the hidden
 * field (a bound oracle), a leak sharper than a facet count. The gate mirrors
 * the facet-counts gate but walks the whole path ({@see isPathAuthorized()}), so
 * a locked sub-field of a nested measure is caught too.
 */
class BoundsRequiresGateTest extends TestCase
{
    private function stub() :BoundsGateStub
    {
        return new BoundsGateStub() ;
    }

    /**
     * @return void
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     */
    public function testRefusedFieldIsDropped() :void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'width' => [ Field::REQUIRES => 'dim:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'width' , Arango::AUTHORIZER => fn() => false ] ;

        // The only requested field is refused → no aggregate, empty query.
        $this->assertSame( '' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }

    public function testGrantedFieldIsBounded() :void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'width' => [ Field::REQUIRES => 'dim:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'width' , Arango::AUTHORIZER => fn( string $s ) => $s === 'dim:read' ] ;

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }

    public function testUngatedFieldIsUnaffected() :void
    {
        $stub  = $this->stub() ; // no $fields REQUIRES on 'width'
        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'width' , Arango::AUTHORIZER => fn() => false ] ;

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }

    public function testExplicitRequiresOnTheBoundDefinitionDropsTheField() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'secret' => [ Bound::PROPERTY => 'secret' , Bound::REQUIRES => 'ops:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'secret' , Arango::AUTHORIZER => fn() => false ] ;

        $this->assertSame( '' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }

    public function testDeepSubFieldIsGatedAtTheExactLeaf() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'dimWidth' => [ Bound::PROPERTY => 'dimensions.width' ] ] ;
        $stub->fields = [ 'dimensions' => [ Field::FIELDS => [ 'width' => [ Field::REQUIRES => 'dim:read' ] ] ] ] ;

        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'dimWidth' , Arango::AUTHORIZER => fn() => false ] ;

        // The leaf `dimensions.width` is locked → the whole field is dropped.
        $this->assertSame( '' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }

    public function testGatedFieldFailsOpenWithoutAnAuthorizer() :void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'width' => [ Field::REQUIRES => 'dim:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::BOUNDS => 'width' ] ; // no authorizer injected

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $stub->buildBoundsQuery( $init , $binds ) ) ;
    }
}
