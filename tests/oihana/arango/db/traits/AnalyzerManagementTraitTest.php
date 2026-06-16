<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\analyzer\Analyzer;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\clients\analyzer\IdentityAnalyzer;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\View;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\traits\AnalyzerManagementTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;

use tests\oihana\arango\db\ArangoDBTestCase;

/**
 * Characterization coverage for {@see AnalyzerManagementTrait} — the
 * analyzer management surface delegated to the `clients/Database` +
 * `clients/Analyzer` layer, mirroring {@see ViewManagementTraitTest}.
 *
 * Covers `analyzerExists()` and the Lot A1a read & safe-sync surface:
 * `analyzerDiff()`, `analyzerSync()` (missing → create, drift → signal
 * only) and `analyzerDependentViews()`.
 *
 * @package tests\oihana\arango\db\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( AnalyzerManagementTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class AnalyzerManagementTraitTest extends ArangoDBTestCase
{
    /**
     * An Analyzer double reporting the given existence and `get()` payload.
     *
     * @param bool                 $exists
     * @param array<string, mixed> $get
     *
     * @return Analyzer
     */
    private function analyzer( bool $exists , array $get = [] ) :Analyzer
    {
        $analyzer = $this->createMock( Analyzer::class ) ;
        $analyzer->method( 'exists' )->willReturn( $exists ) ;
        $analyzer->method( 'get' )->willReturn( $get ) ;
        return $analyzer ;
    }

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

    /**
     * A View double bound to the given name and links payload.
     *
     * @param string               $name
     * @param array<string, mixed> $links
     *
     * @return View
     */
    private function view( string $name , array $links ) :View
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'getName' )->willReturn( $name ) ;
        $view->method( 'properties' )->willReturn( [ 'links' => $links ] ) ;
        return $view ;
    }

    /**
     * The canonical declared analyzer of the fixtures : `text`, en, stemmed,
     * with the frequency + position features.
     *
     * @return AnalyzerDefinition
     */
    private function definition() :AnalyzerDefinition
    {
        return new AnalyzerDefinition
        (
            'myz' ,
            new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: true ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ;
    }

    /**
     * A server `get()` payload matching {@see definition()} (plus an extra
     * server-default property the declaration does not mention, and the
     * features in a different order).
     *
     * @param array<string, mixed> $overrides Properties to override / add.
     * @param ?string              $type      The server analyzer type.
     * @param ?array               $features  The server features (default : a reordered match).
     *
     * @return array<string, mixed>
     */
    private function serverGet( array $overrides = [] , ?string $type = 'text' , ?array $features = null ) :array
    {
        return
        [
            'name'       => 'unit::myz' ,
            'type'       => $type ,
            'properties' => array_merge( [ 'locale' => 'en' , 'case' => 'lower' , 'accent' => false , 'stemming' => true , 'streamType' => 'utf8' ] , $overrides ) ,
            'features'   => $features ?? [ AnalyzerFeature::POSITION , AnalyzerFeature::FREQUENCY ] ,
        ] ;
    }

    // ---- analyzerExists ---------------------------------------------------

    public function testAnalyzerExistsForwardsTheBoolean() :void
    {
        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $this->analyzer( true ) ) )->analyzerExists( 'text_fr' ) ) ;
    }

    public function testAnalyzerExistsForwardsAMissingAnalyzer() :void
    {
        $this->assertFalse( $this->newArangoDB( $this->databaseReturning( $this->analyzer( false ) ) )->analyzerExists( 'unknown' ) ) ;
    }

    public function testAnalyzerExistsReturnsFalseOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->analyzerExists( 'text_fr' ) ) ;
    }

    // ---- analyzerDiff -----------------------------------------------------

    public function testDiffEmptyNameIsInvalid() :void
    {
        $report = $this->newArangoDB()->analyzerDiff( new AnalyzerDefinition( '' , new IdentityAnalyzer() ) ) ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertSame( DiffKind::ANALYZER , $report->kind ) ;
    }

    public function testDiffMissingWhenAbsent() :void
    {
        $report = $this->newArangoDB( $this->databaseReturning( $this->analyzer( false ) ) )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testDiffInSyncIgnoresServerDefaultsAndFeatureOrder() :void
    {
        $report = $this->newArangoDB( $this->databaseReturning( $this->analyzer( true , $this->serverGet() ) ) )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testDiffDriftsOnType() :void
    {
        $report = $this->newArangoDB( $this->databaseReturning( $this->analyzer( true , $this->serverGet( type: 'norm' ) ) ) )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'myz.type : server "norm" ≠ declared "text"' , $report->changes ) ;
        $this->assertContains( 'myz : drop + recreate required (an analyzer is immutable)' , $report->changes ) ;
    }

    public function testDiffDriftsOnProperty() :void
    {
        $report = $this->newArangoDB( $this->databaseReturning( $this->analyzer( true , $this->serverGet( [ 'stemming' => false ] ) ) ) )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'myz.properties.stemming : server false ≠ declared true' , $report->changes ) ;
    }

    public function testDiffDriftsOnFeatures() :void
    {
        $report = $this->newArangoDB( $this->databaseReturning( $this->analyzer( true , $this->serverGet( features: [ AnalyzerFeature::FREQUENCY ] ) ) ) )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'myz.features : server ["frequency"] ≠ declared ["frequency","position"]' , $report->changes ) ;
    }

    public function testDiffListsDependentViews() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $this->analyzer( true , $this->serverGet( type: 'norm' ) ) ) ;
        $database->method( 'views' )->willReturn(
        [
            $this->view( 'placesView' , [ 'places' => [ 'fields' => [ 'name' => [ 'analyzers' => [ 'myz' ] ] ] ] ] ) ,
            $this->view( 'otherView'  , [ 'docs'   => [ 'fields' => [ 'x'    => [ 'analyzers' => [ 'text_en' ] ] ] ] ] ) ,
        ]) ;

        $report = $this->newArangoDB( $database )->analyzerDiff( $this->definition() ) ;

        $this->assertContains( 'myz : referenced by view(s) placesView — they must be rebuilt after the recreate' , $report->changes ) ;
    }

    public function testDiffUnreachableOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $report = $this->newArangoDB( $database )->analyzerDiff( $this->definition() ) ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'boom' ] , $report->changes ) ;
    }

    // ---- analyzerDependentViews -------------------------------------------

    public function testDependentViewsMatchesLinkLevelAndStripsPrefix() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'views' )->willReturn(
        [
            $this->view( 'linkLevel' , [ 'c' => [ 'analyzers' => [ 'myz' ] , 'fields' => [ 'f' => [] ] ] ] ) ,
            $this->view( 'none'      , [ 'c' => [ 'fields' => [ 'f' => [ 'analyzers' => [ 'identity' ] ] ] ] ] ) ,
        ]) ;

        $this->assertSame( [ 'linkLevel' ] , $this->newArangoDB( $database )->analyzerDependentViews( 'app::myz' ) ) ;
    }

    public function testDependentViewsEmptyOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'views' )->willThrowException( new ArangoException() ) ;

        $this->assertSame( [] , $this->newArangoDB( $database )->analyzerDependentViews( 'myz' ) ) ;
    }

    // ---- analyzerSync -----------------------------------------------------

    public function testSyncCreatesWhenMissing() :void
    {
        $definition = $this->definition() ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $this->analyzer( false ) ) ;
        $database->expects( $this->once() )
                 ->method( 'createAnalyzer' )
                 ->with( 'myz' , $definition->options , $definition->features ) ;

        $report = $this->newArangoDB( $database )->analyzerSync( $definition ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    public function testSyncLeavesDriftUntouched() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $this->analyzer( true , $this->serverGet( type: 'norm' ) ) ) ;
        $database->expects( $this->never() )->method( 'createAnalyzer' ) ;

        $report = $this->newArangoDB( $database )->analyzerSync( $this->definition() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testSyncLeavesInSyncUntouched() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $this->analyzer( true , $this->serverGet() ) ) ;
        $database->expects( $this->never() )->method( 'createAnalyzer' ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $this->newArangoDB( $database )->analyzerSync( $this->definition() )->status ) ;
    }

    public function testSyncReportsCreateFailure() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'analyzer' )->willReturn( $this->analyzer( false ) ) ;
        $database->method( 'createAnalyzer' )->willThrowException( new ArangoException( 'denied' ) ) ;

        $report = $this->newArangoDB( $database )->analyzerSync( $this->definition() ) ;

        $this->assertFalse( $report->applied ) ;
        $this->assertContains( 'sync failed : denied' , $report->changes ) ;
    }
}
