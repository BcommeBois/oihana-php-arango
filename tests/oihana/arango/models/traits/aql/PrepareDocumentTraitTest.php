<?php

namespace tests\oihana\arango\models\traits\aql;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use oihana\arango\db\enums\Operation;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\PrepareDocumentTrait;

use InvalidArgumentException;
use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Minimal PSR logger spy capturing the rendered messages it receives.
 */
class PrepareDocumentSpyLogger extends AbstractLogger
{
    public array $messages = [] ;

    public function log( $level , \Stringable|string $message , array $context = [] ) :void
    {
        $this->messages[] = (string) $message ;
    }
}

/**
 * Bare host exposing {@see PrepareDocumentTrait} (and the BindTrait it relies on).
 * Bind names are explicit, so the deterministic paths emit stable AQL / binds.
 */
class PrepareDocumentTraitStub
{
    use PrepareDocumentTrait ;

    public mixed $logger = null ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }

    /**
     * @param mixed $doc
     * @param string $operation
     * @param array $binds
     * @param array|null $removeKeys
     * @param array|null $conditions
     * @param Closure|null $ensure
     * @return string
     * @throws BindException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    public function callPrepareDocumentClause
    (
        mixed    $doc ,
        string   $operation ,
        array    &$binds ,
        ?array   $removeKeys = null ,
        ?array   $conditions = null ,
        ?Closure $ensure     = null ,
        bool     $touch      = true ,
    )
    : string
    {
        return $this->prepareDocumentClause( $doc , $operation , $binds , $removeKeys , $conditions , $ensure , $touch ) ;
    }

    /**
     * Public proxy over the protected resolveAqlConditions().
     *
     * @param array $init
     *
     * @return array
     */
    public function callResolveAqlConditions( array $init ) :array
    {
        return $this->resolveAqlConditions( $init ) ;
    }

    /**
     * Public proxy over the protected resolveOmitWhen().
     *
     * @param array $init
     * @param mixed $default
     *
     * @return mixed
     */
    public function callResolveOmitWhen( array $init , mixed $default = null ) :mixed
    {
        return $this->resolveOmitWhen( $init , $default ) ;
    }
}

/**
 * Characterization coverage for {@see PrepareDocumentTrait}: initializeFillable,
 * prepareDocument (key:@bind pairs with fillable gating) and
 * prepareDocumentClause (the INSERT/UPDATE/REPLACE write clause, with the
 * automatic created/modified stamping).
 *
 * Date-stamping uses two distinct mechanisms: the string-doc path emits the AQL
 * expression `DATE_ISO8601(DATE_NOW())` (deterministic, asserted exactly), while
 * the array/object path binds a real now() timestamp (non-deterministic — only
 * the presence of the created/modified keys is asserted there).
 */
class PrepareDocumentTraitTest extends TestCase
{
    private function stub() :PrepareDocumentTraitStub
    {
        return new PrepareDocumentTraitStub() ;
    }

    // ---------------------------------------------------------------- resolveOmitWhen

    public function testResolveOmitWhenReturnsTheNewKeyWhenPresent() :void
    {
        $stub      = $this->stub() ;
        $predicate = static fn( mixed $value ) :bool => $value === '' ;

        $this->assertSame( [ $predicate ] , $stub->callResolveOmitWhen( [ Arango::OMIT_WHEN => [ $predicate ] ] ) ) ;
    }

    /**
     * An explicit null under the new key means "restore the default null-compression",
     * and must not fall through to the deprecated key — hence `array_key_exists`
     * rather than the null-coalescing operator.
     */
    public function testResolveOmitWhenHonoursAnExplicitNullUnderTheNewKey() :void
    {
        $stub = $this->stub() ;

        $init = [ Arango::OMIT_WHEN => null , Arango::CONDITIONS => [ static fn( $v ) => false ] ] ;

        $this->assertNull( $stub->callResolveOmitWhen( $init , [] ) ) ;
    }

    public function testResolveOmitWhenHonoursAnExplicitEmptyArrayUnderTheNewKey() :void
    {
        $stub = $this->stub() ;

        $this->assertSame( [] , $stub->callResolveOmitWhen( [ Arango::OMIT_WHEN => [] ] ) ) ;
    }

    public function testResolveOmitWhenTakesTheNewKeyOverTheDeprecatedOne() :void
    {
        $stub    = $this->stub() ;
        $winner  = static fn( mixed $value ) :bool => true ;
        $ignored = static fn( mixed $value ) :bool => false ;

        $resolved = $stub->callResolveOmitWhen
        ([
            Arango::OMIT_WHEN  => [ $winner  ] ,
            Arango::CONDITIONS => [ $ignored ] ,
        ]) ;

        $this->assertSame( [ $winner ] , $resolved ) ;
    }

    /**
     * The caller's own default is returned untouched — `null` on insert (the
     * null-compression applies) and `[]` on update / upsert (it is off).
     */
    public function testResolveOmitWhenFallsBackOnTheCallerDefault() :void
    {
        $stub = $this->stub() ;

        $this->assertNull  ( $stub->callResolveOmitWhen( [] , null ) ) ;
        $this->assertSame  ( [] , $stub->callResolveOmitWhen( [] , [] ) ) ;
    }

    public function testResolveOmitWhenStillHonoursTheDeprecatedKeyAndLogsIt() :void
    {
        $stub         = $this->stub() ;
        $stub->logger = $logger = new PrepareDocumentSpyLogger() ;

        $predicate = static fn( mixed $value ) :bool => $value === null ;

        $resolved = $stub->callResolveOmitWhen( [ Arango::CONDITIONS => [ $predicate ] ] , [] ) ;

        $this->assertSame( [ $predicate ] , $resolved ) ;
        // through the consuming class: PHP 8.4 forbids reaching a trait constant directly
        $this->assertContains( PrepareDocumentTraitStub::OMIT_WHEN_DEPRECATION , $logger->messages ) ;
    }

    /**
     * AQL predicate strings are the **read** meaning of the shared key, and the
     * write's FILTER now consumes them through {@see resolveAqlConditions()}. There
     * is nothing to compress here, so the caller's default applies — and no
     * deprecation is logged, since the caller is not using the legacy write form.
     */
    public function testResolveOmitWhenLeavesAqlStringsToTheFilter() :void
    {
        $stub         = $this->stub() ;
        $stub->logger = $logger = new PrepareDocumentSpyLogger() ;

        $resolved = $stub->callResolveOmitWhen( [ Arango::CONDITIONS => [ 'doc.published == @published' ] ] , [] ) ;

        $this->assertSame( [] , $resolved ) ;
        $this->assertSame( [] , $logger->messages ) ;
    }

    /**
     * An explicit empty array means "compress nothing". It must survive as-is
     * rather than fall back on a default that would switch the compression back on
     * — which is exactly what `insert()` would do, its own default being null.
     */
    public function testResolveOmitWhenKeepsAnExplicitEmptyDeprecatedKey() :void
    {
        $stub = $this->stub() ;

        $this->assertSame( [] , $stub->callResolveOmitWhen( [ Arango::CONDITIONS => [] ] , null ) ) ;
    }

    /**
     * A mixed array is split rather than refused: the callables compress the
     * payload, the strings go to the FILTER.
     */
    public function testResolveOmitWhenKeepsOnlyTheCallablesOfAMixedDeprecatedKey() :void
    {
        $stub         = $this->stub() ;
        $stub->logger = $logger = new PrepareDocumentSpyLogger() ;

        $predicate = static fn( mixed $value ) :bool => $value === null ;

        $resolved = $stub->callResolveOmitWhen
        (
            [ Arango::CONDITIONS => [ 'doc.published == @published' , $predicate ] ] ,
            []
        ) ;

        $this->assertSame( [ $predicate ] , $resolved ) ;
        $this->assertContains( PrepareDocumentTraitStub::OMIT_WHEN_DEPRECATION , $logger->messages ) ;
    }

    // ---------------------------------------------------------------- resolveAqlConditions

    public function testResolveAqlConditionsKeepsThePredicateStrings() :void
    {
        $stub = $this->stub() ;

        $resolved = $stub->callResolveAqlConditions( [ Arango::CONDITIONS => [ 'doc.a == 1' , 'doc.b == @b' ] ] ) ;

        $this->assertSame( [ 'doc.a == 1' , 'doc.b == @b' ] , $resolved ) ;
    }

    public function testResolveAqlConditionsReturnsAnEmptyListWhenTheKeyIsAbsent() :void
    {
        $this->assertSame( [] , $this->stub()->callResolveAqlConditions( [] ) ) ;
    }

    /**
     * The write-side callables belong to {@see resolveOmitWhen()}: reaching the
     * FILTER they would break `predicates()`, which joins strings.
     */
    public function testResolveAqlConditionsDropsTheDeprecatedCallables() :void
    {
        $stub = $this->stub() ;

        $resolved = $stub->callResolveAqlConditions
        ([
            Arango::CONDITIONS => [ 'doc.a == 1' , static fn( mixed $value ) :bool => true ] ,
        ]) ;

        $this->assertSame( [ 'doc.a == 1' ] , $resolved ) ;
    }

    /**
     * The surviving strings are re-indexed, so a spread into `aqlFilter()` yields a
     * list and not an object.
     */
    public function testResolveAqlConditionsReindexesTheSurvivors() :void
    {
        $stub = $this->stub() ;

        $resolved = $stub->callResolveAqlConditions
        ([
            Arango::CONDITIONS => [ static fn( mixed $value ) :bool => true , 'doc.a == 1' ] ,
        ]) ;

        $this->assertSame( [ 0 => 'doc.a == 1' ] , $resolved ) ;
    }

    public function testResolveAqlConditionsIgnoresANonArrayKey() :void
    {
        $this->assertSame( [] , $this->stub()->callResolveAqlConditions( [ Arango::CONDITIONS => 'doc.a == 1' ] ) ) ;
    }

    public function testResolveOmitWhenLogsNothingWhenNoKeyIsGiven() :void
    {
        $stub         = $this->stub() ;
        $stub->logger = $logger = new PrepareDocumentSpyLogger() ;

        $stub->callResolveOmitWhen( [] , [] ) ;

        $this->assertSame( [] , $logger->messages ) ;
    }

    /**
     * A model wired without a logger must still write — the deprecation is a
     * notice, not a reason to fail.
     */
    public function testResolveOmitWhenSurvivesWithoutALogger() :void
    {
        $stub         = $this->stub() ;
        $stub->logger = null ;

        $predicate = static fn( mixed $value ) :bool => true ;

        $this->assertSame( [ $predicate ] , $stub->callResolveOmitWhen( [ Arango::CONDITIONS => [ $predicate ] ] ) ) ;
    }

    // ---------------------------------------------------------------- initializeFillable

    public function testInitializeFillableSetsAndReturnsSelf() :void
    {
        $stub = $this->stub() ;
        $result = $stub->initializeFillable( [ 'fillable' => [ 'a' ] ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertSame( [ 'a' ] , $stub->fillable ) ;
    }

    public function testInitializeFillableKeepsExistingWhenKeyMissing() :void
    {
        $stub = $this->stub() ;
        $stub->fillable = [ 'a' ] ;
        $stub->initializeFillable( [] ) ;

        $this->assertSame( [ 'a' ] , $stub->fillable ) ;
    }

    // ---------------------------------------------------------------- prepareDocument

    public function testPrepareDocumentBuildsKeyBindPairs() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            [ 'name:@name' , 'age:@age' ] ,
            $this->stub()->prepareDocument( [ 'name' => 'Marc' , 'age' => 40 ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'name' => 'Marc' , 'age' => 40 ] , $binds ) ;
    }

    public function testPrepareDocumentDecodesJsonString() :void
    {
        $binds = [] ;
        $this->assertSame( [ 'a:@a' ] , $this->stub()->prepareDocument( '{"a":1}' , $binds ) ) ;
        $this->assertSame( [ 'a' => 1 ] , $binds ) ;
    }

    public function testPrepareDocumentAppliesExcludes() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            [ 'a:@a' ] ,
            $this->stub()->prepareDocument( [ 'a' => 1 , 'b' => 2 ] , $binds , [] , [ 'b' ] ) ,
        ) ;
    }

    public function testPrepareDocumentPrependsProvidedDocumentEntries() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            [ 'pre:doc.x' , 'a:@a' ] ,
            $this->stub()->prepareDocument( [ 'a' => 1 ] , $binds , [ 'pre:doc.x' ] ) ,
        ) ;
    }

    public function testPrepareDocumentNullDefinitionReturnsProvidedDocument() :void
    {
        $binds = [] ;
        $this->assertSame( [] , $this->stub()->prepareDocument( null , $binds ) ) ;
    }

    public function testPrepareDocumentJsonScalarYieldsNumericKeyAndThrowsBindException() :void
    {
        // A JSON string decoding to a non-object (here the scalar 5) is cast to an
        // array with a numeric key (0), which is not a valid bind variable name.
        $this->expectException( BindException::class ) ;
        $binds = [] ;
        $this->stub()->prepareDocument( '5' , $binds ) ;
    }

    public function testPrepareDocumentFiltersNonFillableAndLogsWarning() :void
    {
        $stub = $this->stub() ;
        $stub->logger   = new PrepareDocumentSpyLogger() ;
        $stub->fillable = [ 'name' ] ;

        $binds = [] ;
        $this->assertSame
        (
            [ 'name:@name' ] ,
            $stub->prepareDocument( [ 'name' => 'M' , 'secret' => 'x' ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'name' => 'M' ] , $binds ) ;
        $this->assertCount( 1 , $stub->logger->messages ) ;
        $this->assertStringContainsString( 'secret attribute is not a fillable property' , $stub->logger->messages[ 0 ] ) ;
    }

    // ---------------------------------------------------------------- prepareDocumentClause : string path

    public function testStringInsertMergesCreatedAndModified() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc,created:DATE_ISO8601(DATE_NOW()),modified:DATE_ISO8601(DATE_NOW()))' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::INSERT , $binds ) ,
        ) ;
        $this->assertSame( [] , $binds ) ;
    }

    public function testStringUpdateMergesModifiedOnly() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc,modified:DATE_ISO8601(DATE_NOW()))' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::UPDATE , $binds ) ,
        ) ;
    }

    public function testStringReplaceMergesModifiedOnly() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc,modified:DATE_ISO8601(DATE_NOW()))' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::REPLACE , $binds ) ,
        ) ;
    }

    public function testStringNonWriteOperationMergesNothing() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc)' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::SEARCH , $binds ) ,
        ) ;
    }

    // ---------------------------------------------------------------- prepareDocumentClause : array path

    public function testArrayNonWriteOperationBindsCompressedDocument() :void
    {
        // Default null conditions => null properties are stripped (the 'b' => null entry).
        $binds = [] ;
        $this->assertSame
        (
            '@search' ,
            $this->stub()->callPrepareDocumentClause( [ 'a' => 1 , 'b' => null ] , Operation::SEARCH , $binds ) ,
        ) ;
        $this->assertSame( [ 'search' => [ 'a' => 1 ] ] , $binds ) ;
    }

    public function testArrayWithEmptyConditionsKeepsNullProperties() :void
    {
        // conditions => [] disables compression, so the null 'b' survives.
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 , 'b' => null ] , Operation::SEARCH , $binds , null , [] ) ;
        $this->assertSame( [ 'search' => [ 'a' => 1 , 'b' => null ] ] , $binds ) ;
    }

    public function testArrayRemoveKeysDropsListedAttributes() :void
    {
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 , 'b' => 2 ] , Operation::SEARCH , $binds , [ 'b' ] ) ;
        $this->assertSame( [ 'search' => [ 'a' => 1 ] ] , $binds ) ;
    }

    public function testArrayInsertStampsCreatedAndModifiedKeys() :void
    {
        // now() makes the timestamp values non-deterministic; assert structure only.
        $binds = [] ;
        $this->assertSame
        (
            '@insert' ,
            $this->stub()->callPrepareDocumentClause( [ 'a' => 1 ] , Operation::INSERT , $binds ) ,
        ) ;
        $this->assertSame( [ 'a' , 'created' , 'modified' ] , array_keys( $binds[ 'insert' ] ) ) ;
        $this->assertSame( 1 , $binds[ 'insert' ][ 'a' ] ) ;
    }

    public function testArrayUpdateStampsModifiedButNotCreated() :void
    {
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 ] , Operation::UPDATE , $binds ) ;
        $this->assertSame( [ 'a' , 'modified' ] , array_keys( $binds[ 'update' ] ) ) ;
    }

    public function testEnsureClosureCanRewriteTheDocument() :void
    {
        $binds  = [] ;
        $ensure = fn( array $doc ) :array => $doc + [ 'forced' => true ] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 ] , Operation::SEARCH , $binds , null , null , $ensure ) ;
        $this->assertSame( [ 'a' => 1 , 'forced' => true ] , $binds[ 'search' ] ) ;
    }

    // ---------------------------------------------------------------- prepareDocumentClause : touch = false

    public function testStringInsertWithoutTouchMergesNothing() :void
    {
        // A replication carries its own dates : nothing is appended, on the very
        // branch whose stamp would otherwise overwrite them.
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc)' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::INSERT , $binds , null , null , null , false ) ,
        ) ;
    }

    public function testStringReplaceWithoutTouchMergesNothing() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'MERGE(doc)' ,
            $this->stub()->callPrepareDocumentClause( 'doc' , Operation::REPLACE , $binds , null , null , null , false ) ,
        ) ;
    }

    public function testArrayInsertWithoutTouchStampsNothing() :void
    {
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 , 'created' => '2024-05-12' ] , Operation::INSERT , $binds , null , null , null , false ) ;
        $this->assertSame( [ 'a' => 1 , 'created' => '2024-05-12' ] , $binds[ 'insert' ] ) ;
    }

    public function testArrayReplaceWithoutTouchKeepsTheSubmittedDates() :void
    {
        // The branch that motivated the flag : a replacement runs on every pass of
        // a replication, and the stamp used to land after the submitted document.
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 , 'modified' => '2025-11-03' ] , Operation::REPLACE , $binds , null , null , null , false ) ;
        $this->assertSame( [ 'a' => 1 , 'modified' => '2025-11-03' ] , $binds[ 'replace' ] ) ;
    }

    public function testEnsureStillRunsWithoutTouch() :void
    {
        // The two options are orthogonal : not stamping is no reason to skip the
        // caller's own guarantees.
        $binds  = [] ;
        $ensure = fn( array $doc ) :array => $doc + [ 'forced' => true ] ;
        $this->stub()->callPrepareDocumentClause( [ 'a' => 1 ] , Operation::INSERT , $binds , null , null , $ensure , false ) ;
        $this->assertSame( [ 'a' => 1 , 'forced' => true ] , $binds[ 'insert' ] ) ;
    }

    // ---------------------------------------------------------------- prepareDocumentClause : invalid input

    public function testEmptyStringThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( '' , Operation::INSERT , $binds ) ;
    }

    public function testScalarDocumentThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $binds = [] ;
        $this->stub()->callPrepareDocumentClause( 123 , Operation::INSERT , $binds ) ;
    }
}
