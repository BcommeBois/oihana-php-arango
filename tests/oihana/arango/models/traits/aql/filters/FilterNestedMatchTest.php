<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;
use RuntimeException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\enums\Boolean;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * A `match` on a list of objects means the same thing at every depth.
 *
 * `match` is a multi-field test on the **elements**. Behind an object it used to be
 * replaced by a bare cardinality — `LENGTH(doc.resolution.steps[*]) > 0` — so the
 * caller was not refused, they were **answered**, with someone else's question: the
 * tickets carrying *at least one* step instead of those carrying a step *satisfying
 * their conditions*. A wrong page is noticed far less than an empty one.
 *
 * 🔑 **Every case here is written as a PAIR**, the root spelling beside the nested
 * one. The defect was never a value: it was a **disagreement between two depths**,
 * and only an assertion that holds them together says anything about their accord.
 * The list-named-last batch found that this grammar has two seats reached by two
 * roads — a key with a dot and a key without — and wrote it down; the `match` guard
 * was then carried to one of them only.
 */
class FilterNestedMatchTest extends TestCase
{
    private Container $container;
    private array $binds;

    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
    }

    /**
     * `attachments[*]` is a list of objects at the root; `resolution.steps[*]` the same
     * list of objects behind an object. Same sub-fields on both, so a case can send the
     * same `match` to each and compare.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function model( array $fields = [] ): Documents
    {
        $elements =
        [
            'label'  => FilterType::STRING ,
            'secret' => FilterType::STRING ,
        ];

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'tickets' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'attachments' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => $elements ] ,
                'resolution'  =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS => [ 'steps' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => $elements ] ] ,
                ] ,
            ] ,
            ...( $fields === [] ? [] : [ AQL::FIELDS => $fields ] ) ,
        ]);
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function compile( array $init , ?Documents $model = null ) :?string
    {
        $binds = [] ;
        return ( $model ?? $this->model() )->prepareFilter( $init , $binds ) ;
    }

    // ========================================
    // THE PAIRS
    // ========================================

    /**
     * The same `match`, at both depths, compiles the same shape — the document
     * reference apart, which is the only thing depth is allowed to change.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheSameMatchCompilesTheSameShapeAtBothDepths(): void
    {
        $root   = $this->compile( [ 'key' => 'attachments[*]'      , 'match' => [ 'label' => 'x' ] ] ) ;
        $nested = $this->compile( [ 'key' => 'resolution.steps[*]' , 'match' => [ 'label' => 'x' ] ] ) ;

        $shape = fn( ?string $aql ) => preg_replace( [ '/@\w+/' , '/doc(\.\w+)*\.(attachments|steps)/' ] , [ '@v' , 'ARRAY' ] , (string) $aql ) ;

        $this->assertSame( 'LENGTH(ARRAY[* FILTER CURRENT.label == @v]) > 0' , $shape( $root ) ) ;
        $this->assertSame( $shape( $root ) , $shape( $nested ) ) ;

        // …and the nested one names the array through the reference the object walk
        // moved it to. Naming it from the key alone would aim at `doc.steps`, which
        // exists nowhere and answers zero rows without a word.
        $this->assertStringContainsString( 'doc.resolution.steps[*' , (string) $nested ) ;
    }

    /**
     * `match` combined with `quant` — "no element satisfies these conditions" — is the
     * question-mark operator at both depths.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testMatchCombinedWithQuantAgreesAtBothDepths(): void
    {
        $root   = $this->compile( [ 'key' => 'attachments[*]'      , 'quant' => 'none' , 'match' => [ 'label' => 'x' ] ] ) ;
        $nested = $this->compile( [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' , 'match' => [ 'label' => 'x' ] ] ) ;

        $this->assertStringContainsString( 'doc.attachments[? NONE FILTER CURRENT.label ==' , (string) $root ) ;
        $this->assertStringContainsString( 'doc.resolution.steps[? NONE FILTER CURRENT.label ==' , (string) $nested ) ;
    }

    /**
     * An `alt` chain reaches inside the inline condition at both depths.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnAltChainReachesTheElementsAtBothDepths(): void
    {
        $root   = $this->compile( [ 'key' => 'attachments[*]'      , 'match' => [ 'label' => 'X' ] , 'alt' => [ 'key' => 'lower' ] ] ) ;
        $nested = $this->compile( [ 'key' => 'resolution.steps[*]' , 'match' => [ 'label' => 'X' ] , 'alt' => [ 'key' => 'lower' ] ] ) ;

        $this->assertStringContainsString( 'LOWER(CURRENT.label)' , (string) $root ) ;
        $this->assertStringContainsString( 'LOWER(CURRENT.label)' , (string) $nested ) ;
    }

    // ========================================
    // PERMISSION — the case the root made red, and this seat never had
    // ========================================

    /**
     * 🚨 A `match` naming a locked sub-field neutralises to `false` at both depths.
     *
     * Never `null`, and never a cardinality: a `match` dropped under `quant: none`
     * turns into an existence oracle on a path the caller may not read. Nested, the
     * sub-field was not merely ungated — it was never looked at, so the caller got a
     * working predicate answering a different question.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testALockedSubFieldNeutralisesToFalseAtBothDepths(): void
    {
        $model = $this->model
        ([
            'attachments' => [ Field::FIELDS => [ 'label' => true , 'secret' => [ Field::REQUIRES => 'audit' ] ] ] ,
            'resolution'  => [ Field::FIELDS => [ 'steps' => [ Field::FIELDS => [ 'label' => true , 'secret' => [ Field::REQUIRES => 'audit' ] ] ] ] ] ,
        ]) ;

        foreach ( [ 'attachments[*]' , 'resolution.steps[*]' ] as $key )
        {
            $result = $this->compile
            (
                [ Arango::FILTER => [ 'key' => $key , 'match' => [ 'secret' => 'x' ] ] , Arango::AUTHORIZER => fn() => false ] ,
                $model
            ) ;

            $this->assertSame( Boolean::FALSE , $result , $key ) ;
            $this->assertNotSame( null , $result , $key ) ;
        }
    }

    /**
     * The same, with `quant: none` — the shape that would have been an oracle.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testALockedSubFieldUnderNoneIsNeutralisedRatherThanDropped(): void
    {
        $model = $this->model
        ([
            'resolution' => [ Field::FIELDS => [ 'steps' => [ Field::FIELDS => [ 'secret' => [ Field::REQUIRES => 'audit' ] ] ] ] ] ,
        ]) ;

        $result = $this->compile
        (
            [
                Arango::FILTER     => [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' , 'match' => [ 'secret' => 'x' ] ] ,
                Arango::AUTHORIZER => fn() => false ,
            ] ,
            $model
        ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
    }

    /**
     * A sub-field the model never declared is refused by name at both depths, and
     * refused rather than dropped.
     *
     * ⚠ The nested seat had to be built OUTSIDE the leaf's broad `catch`, which exists
     * for a consumer's own callable — a fault no URL can fix. This one is the caller's,
     * and swallowing it would drop the filter: the shape the whole batch closes.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnUndeclaredSubFieldIsRefusedAtBothDepths(): void
    {
        foreach ( [ 'attachments[*]' , 'resolution.steps[*]' ] as $key )
        {
            try
            {
                $this->compile( [ 'key' => $key , 'match' => [ 'zzz' => 'x' ] ] ) ;
                $this->fail( "an undeclared sub-field must be refused at $key" ) ;
            }
            catch ( RuntimeException $exception )
            {
                $this->assertStringContainsString( "Field 'zzz' is not allowed in match filter" , $exception->getMessage() ) ;
            }
        }
    }

    // ========================================
    // WHAT MUST NOT MOVE
    // ========================================

    /**
     * ⚠ `quant` WITHOUT a `match` keeps the cardinality the list-named-last batch just
     * shipped — the fix must not take back what that one gave.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testCardinalityWithoutAMatchIsUntouched(): void
    {
        $this->assertSame
        (
            'LENGTH(doc.resolution.steps[*]) == 0' ,
            $this->compile( [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' ] )
        ) ;

        $this->assertSame
        (
            'LENGTH(doc.resolution.steps[*]) > 0' ,
            $this->compile( [ 'key' => 'resolution.steps[*]' ] )
        ) ;
    }

    /**
     * The witness: a terminal sub-field behind an object always compiled correctly. It
     * is what said the walk itself was sound and only the `match` was being lost.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testATerminalSubFieldIsUntouched(): void
    {
        $this->assertStringContainsString
        (
            'doc.resolution.steps[* FILTER CURRENT.label LIKE' ,
            (string) $this->compile( [ 'key' => 'resolution.steps[*].label' , 'op' => 'like' , 'val' => 'x%' ] )
        ) ;
    }
}
