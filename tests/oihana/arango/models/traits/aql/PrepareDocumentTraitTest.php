<?php

namespace tests\oihana\arango\models\traits\aql;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use oihana\arango\db\enums\Operation;
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
    )
    : string
    {
        return $this->prepareDocumentClause( $doc , $operation , $binds , $removeKeys , $conditions , $ensure ) ;
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
