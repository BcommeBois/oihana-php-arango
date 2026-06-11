<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\analyzer\Analyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\traits\AnalyzerManagementTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;

use tests\oihana\arango\db\ArangoDBTestCase;

/**
 * Characterization coverage for {@see AnalyzerManagementTrait} — the
 * analyzer management surface delegated to the `clients/Database` +
 * `clients/Analyzer` layer, mirroring {@see ViewManagementTraitTest}.
 *
 * @package tests\oihana\arango\db\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( AnalyzerManagementTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class AnalyzerManagementTraitTest extends ArangoDBTestCase
{
    /**
     * A Database double whose `analyzer()` always returns the given Analyzer.
     *
     * @param Analyzer $analyzer
     *
     * @return Database
     */
    private function databaseReturning( Analyzer $analyzer ) :Database
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $analyzer ) ;
        return $database ;
    }

    // ---- analyzerExists ---------------------------------------------------

    public function testAnalyzerExistsForwardsTheBoolean() :void
    {
        $analyzer = $this->createMock( Analyzer::class ) ;
        $analyzer->method( 'exists' )->willReturn( true ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $analyzer ) )->analyzerExists( 'text_fr' ) ) ;
    }

    public function testAnalyzerExistsForwardsAMissingAnalyzer() :void
    {
        $analyzer = $this->createMock( Analyzer::class ) ;
        $analyzer->method( 'exists' )->willReturn( false ) ;

        $this->assertFalse( $this->newArangoDB( $this->databaseReturning( $analyzer ) )->analyzerExists( 'unknown' ) ) ;
    }

    public function testAnalyzerExistsReturnsFalseOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->analyzerExists( 'text_fr' ) ) ;
    }
}
