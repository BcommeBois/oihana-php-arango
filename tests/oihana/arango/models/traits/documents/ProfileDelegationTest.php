<?php

namespace tests\oihana\arango\models\traits\documents;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\ArangoDB;
use oihana\arango\db\results\ExecutionStats;
use oihana\arango\db\results\ProfileResult;
use oihana\arango\models\traits\documents\DocumentsListTrait;

/**
 * Bare host exposing {@see DocumentsListTrait} (→ ArangoTrait) with an injectable
 * mock {@see ArangoDB} façade.
 */
class DocumentsProfileStub
{
    use DocumentsListTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }

    public function setArango( ArangoDB $arango ) : void
    {
        $this->arangodb = $arango ;
    }
}

/**
 * Coverage for {@see \oihana\arango\models\traits\ArangoTrait::getStats()} and
 * {@see \oihana\arango\models\traits\ArangoTrait::getProfile()} — both delegate to
 * the façade.
 */
class ProfileDelegationTest extends TestCase
{
    public function testGetStatsDelegatesToFacade() : void
    {
        $sentinel = new ExecutionStats( [ 'scannedFull' => 7 ] );

        $arango = $this->createMock( ArangoDB::class );
        $arango->expects( $this->once() )->method( 'getStats' )->willReturn( $sentinel );

        $stub = new DocumentsProfileStub() ;
        $stub->setArango( $arango ) ;

        $this->assertSame( $sentinel , $stub->getStats() );
    }

    public function testGetProfileDelegatesToFacade() : void
    {
        $sentinel = new ProfileResult( [ 'stats' => [ 'filtered' => 3 ] ] );

        $arango = $this->createMock( ArangoDB::class );
        $arango->expects( $this->once() )->method( 'getProfile' )->willReturn( $sentinel );

        $stub = new DocumentsProfileStub() ;
        $stub->setArango( $arango ) ;

        $this->assertSame( $sentinel , $stub->getProfile() );
    }
}
