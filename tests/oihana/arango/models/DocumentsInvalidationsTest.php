<?php

namespace tests\oihana\arango\models;

use DI\Container;

use oihana\arango\cache\InvalidatesOnWriteTrait;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use tests\oihana\arango\cache\mocks\InvalidableSpy;

/**
 * Guards the constructor wiring of {@see InvalidatesOnWriteTrait} into {@see Documents}.
 *
 * The trait itself is covered by `InvalidatesOnWriteTraitTest`; what is asserted
 * here is that a plain `Documents` honours `Arango::INVALIDATES` with **no**
 * manual `initializeInvalidations()` call.
 */
#[CoversClass( Documents::class )]
final class DocumentsInvalidationsTest extends TestCase
{
    private Container $container ;

    private InvalidableSpy $spy ;

    protected function setUp() : void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->spy = new InvalidableSpy() ;
        $this->container->set( 'service.cache' , $this->spy ) ;
    }

    /**
     * Builds a Documents that touches no server, with the invalidation isolated.
     *
     * The constructor also connects `onUpdateRelations` to the insert / replace /
     * update signals, and that callback needs a real notice to run — it is
     * disconnected here so an argument-less `emit()` exercises the invalidation
     * alone.
     */
    private function makeModel( array $init = [] ) : Documents
    {
        $model = new Documents( $this->container , $init +
        [
            AQL::COLLECTION => 'terms' ,
            AQL::LAZY       => false  ,
        ]) ;

        $relations = [ $model , Documents::ON_UPDATE_RELATIONS ] ;

        $model->afterInsert?->disconnect( $relations ) ;
        $model->afterUpdate?->disconnect( $relations ) ;

        return $model ;
    }

    public function testTheConstructorWiresTheDeclaredServicesWithNoManualCall() : void
    {
        $model = $this->makeModel([ Arango::INVALIDATES => [ 'service.cache' ] ]) ;

        $model->afterInsert?->emit() ;

        $this->assertSame( 1 , $this->spy->calls , 'Expected the wiring without any manual initializeInvalidations() call.' ) ;
    }

    public function testEachWriteSignalInvalidatesTheDeclaredService() : void
    {
        $model = $this->makeModel([ Arango::INVALIDATES => 'service.cache' ]) ;

        $model->afterInsert?->emit() ;
        $this->assertSame( 1 , $this->spy->calls ) ;

        $model->afterUpdate?->emit() ;
        $this->assertSame( 2 , $this->spy->calls ) ;

        $model->afterDelete?->emit() ;
        $this->assertSame( 3 , $this->spy->calls ) ;
    }

    public function testNoDeclarationLeavesTheWriteSignalsUntouched() : void
    {
        $model = $this->makeModel() ;

        $this->assertFalse( $model->afterDelete?->connected() , 'afterDelete carries no other receiver: nothing must be connected to it.' ) ;

        $model->afterInsert?->emit() ;
        $model->afterUpdate?->emit() ;
        $model->afterDelete?->emit() ;

        $this->assertSame( 0 , $this->spy->calls ) ;
    }
}
