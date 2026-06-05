<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Tests for HasFilterString trait.
 */
class HasFilterStringTest extends TestCase
{
    private Documents $model;
    private array $binds;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        $container = new Container() ;

        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'  => FilterType::STRING ,
                'email' => FilterType::STRING ,
                'title' => FilterType::STRING ,
                'code'  => FilterType::STRING ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // BASIC STRING FILTERS
    // ========================================

    public function testStringFilterEquals(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
        $this->assertNotEmpty( $this->binds ) ;
        $this->assertContains( 'John' , $this->binds ) ;
    }

    public function testStringFilterNotEquals(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' , 'op' => 'ne' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( '!=' , $result ) ;
    }

    public function testStringFilterGreaterThan(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'A' , 'op' => 'gt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>' , $result ) ;
        $this->assertStringNotContainsString( '>=' , $result ) ;
    }

    public function testStringFilterGreaterThanOrEquals(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'A' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
    }

    public function testStringFilterLessThan(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'Z' , 'op' => 'lt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<' , $result ) ;
        $this->assertStringNotContainsString( '<=' , $result ) ;
    }

    public function testStringFilterLessThanOrEquals(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'Z' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<=' , $result ) ;
    }

    // ========================================
    // LIKE OPERATORS
    // ========================================

    public function testStringFilterLike(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John%' , 'op' => 'like' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LIKE' , $result ) ;
        $this->assertContains( 'John%' , $this->binds ) ;
    }

    public function testStringFilterNotLike(): void
    {
        $init = [ 'key' => 'name' , 'val' => '%admin%' , 'op' => 'nlike' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NOT LIKE' , $result ) ;
    }

    // ========================================
    // REGEX MATCH OPERATORS
    // ========================================

    public function testStringFilterMatch(): void
    {
        $init = [ 'key' => 'email' , 'val' => '^[a-z]+@' , 'op' => 'match' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '=~' , $result ) ;
    }

    public function testStringFilterNotMatch(): void
    {
        $init = [ 'key' => 'email' , 'val' => '^admin' , 'op' => 'nmatch' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!~' , $result ) ;
    }

    // ========================================
    // IN / NOT IN OPERATORS
    // ========================================

    public function testStringFilterIn(): void
    {
        $init = [ 'key' => 'name' , 'val' => [ 'John' , 'Jane' , 'Bob' ] , 'op' => 'in' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'IN' , $result ) ;
    }

    public function testStringFilterNotIn(): void
    {
        $init = [ 'key' => 'name' , 'val' => [ 'admin' , 'root' ] , 'op' => 'nin' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NOT IN' , $result ) ;
    }

    // ========================================
    // FUNCTION TRANSFORMATIONS - SINGLE
    // ========================================

    public function testStringFilterWithLower(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'john' , 'alt' => 'lower' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LOWER(doc.name)' , $result ) ;
    }

    public function testStringFilterWithUpper(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'JOHN' , 'alt' => 'upper' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'UPPER(doc.name)' , $result ) ;
    }

    public function testStringFilterWithTrim(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' , 'alt' => 'trim' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'TRIM(doc.name)' , $result ) ;
    }

    public function testStringFilterWithLtrim(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' , 'alt' => 'ltrim' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LTRIM(doc.name)' , $result ) ;
    }

    public function testStringFilterWithRtrim(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' , 'alt' => 'rtrim' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'RTRIM(doc.name)' , $result ) ;
    }

    public function testStringFilterWithLength(): void
    {
        $init = [ 'key' => 'name' , 'val' => 10 , 'alt' => 'length' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(doc.name)' , $result ) ;
        $this->assertStringContainsString( '>=' , $result ) ;
    }

    // ========================================
    // FUNCTION TRANSFORMATIONS - WITH PARAMS
    // ========================================

    public function testStringFilterWithSubstring(): void
    {
        $init = [ 'key' => 'code' , 'val' => 'ABC' , 'alt' => [ 'substring' , 0 , 3 ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/SUBSTRING\(doc\.code,\s*0,\s*3\)/' , $result ) ;
    }

    public function testStringFilterWithLeft(): void
    {
        $init = [ 'key' => 'code' , 'val' => 'AB' , 'alt' => [ 'left' , 2 ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/LEFT\(doc\.code,\s*2\)/' , $result ) ;
    }

    public function testStringFilterWithRight(): void
    {
        $init = [ 'key' => 'code' , 'val' => 'XY' , 'alt' => [ 'right' , 2 ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/RIGHT\(doc\.code,\s*2\)/' , $result ) ;
    }

    // ========================================
    // FUNCTION CHAINING
    // ========================================

    public function testStringFilterWithFunctionChain(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'john' , 'alt' => [ 'trim' , 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LOWER(TRIM(doc.name))' , $result ) ;
    }

    public function testStringFilterWithMixedChain(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'joh' , 'alt' => [ 'trim' , [ 'substring' , 0 , 3 ] , 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/LOWER\(SUBSTRING\(TRIM\(doc\.name\),\s*0,\s*3\)\)/' , $result ) ;
    }

    public function testStringFilterWithExplicitParameterFormat(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'Joh' , 'alt' => [ [ 'substring' , 0 , 3 ] ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/SUBSTRING\(doc\.name,\s*0,\s*3\)/' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    public function testStringFilterWithCustomDocRef(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'John' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds , 'v1' ) ;

        $this->assertStringContainsString( 'v1.name' , $result ) ;
        $this->assertStringNotContainsString( 'doc.name' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testStringFilterWithEmptyString(): void
    {
        $init = [ 'key' => 'name' , 'val' => '' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertContains( '' , $this->binds ) ;
    }

    public function testStringFilterWithNullValue(): void
    {
        $init = [ 'key' => 'name' , 'val' => null ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
    }

    public function testStringFilterWithSpecialCharacters(): void
    {
        $init = [ 'key' => 'name' , 'val' => "O'Brien" ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertContains( "O'Brien" , $this->binds ) ;
    }

    public function testStringFilterWithUnicodeCharacters(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'Müller' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertContains( 'Müller' , $this->binds ) ;
    }

    // ========================================
    // STARTS WITH (`sw`) — STARTS_WITH(key, value)
    // ========================================

    public function testStringFilterStartsWith(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'ekam' , 'op' => 'sw' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^STARTS_WITH\(doc\.name,\s*@\S+\)$/' , $result ) ;
        $this->assertContains( 'ekam' , $this->binds ) ;
    }

    public function testStringFilterStartsWithCaseInsensitiveMirror(): void
    {
        // The alt {key:lower, val:true} mirror wraps both sides → case-insensitive.
        $init = [ 'key' => 'name' , 'val' => 'EKAM' , 'op' => 'sw' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^STARTS_WITH\(LOWER\(doc\.name\),\s*LOWER\(@\S+\)\)$/' , $result ) ;
        $this->assertContains( 'EKAM' , $this->binds ) ;
    }

    public function testStringFilterStartsWithBindsValueLiterally(): void
    {
        // STARTS_WITH matches the prefix literally: wildcard chars are NOT special,
        // the value is bound as-is (no LIKE-style escaping).
        $init = [ 'key' => 'name' , 'val' => '50%_off' , 'op' => 'sw' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringStartsWith( 'STARTS_WITH(doc.name,' , $result ) ;
        $this->assertContains( '50%_off' , $this->binds ) ;
    }

    // ========================================
    // ENDS WITH (`ew`) — RIGHT(key, CHAR_LENGTH(value)) == value
    // ========================================

    public function testStringFilterEndsWith(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'leon' , 'op' => 'ew' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        // RIGHT(doc.name, CHAR_LENGTH(@v)) == @v — the same bind is reused twice.
        $this->assertMatchesRegularExpression
        (
            '/^RIGHT\(doc\.name,\s*CHAR_LENGTH\((@\S+)\)\)\s*==\s*\1$/' ,
            $result
        ) ;
        $this->assertContains( 'leon' , $this->binds ) ;
    }

    public function testStringFilterEndsWithCaseInsensitiveMirror(): void
    {
        // The alt {key:lower, val:true} mirror wraps both sides → case-insensitive.
        $init = [ 'key' => 'name' , 'val' => 'LEON' , 'op' => 'ew' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression
        (
            '/^RIGHT\(LOWER\(doc\.name\),\s*CHAR_LENGTH\(LOWER\((@\S+)\)\)\)\s*==\s*LOWER\(\1\)$/' ,
            $result
        ) ;
        $this->assertContains( 'LEON' , $this->binds ) ;
    }

    public function testStringFilterEndsWithBindsValueLiterally(): void
    {
        // Literal match (no LIKE pattern): wildcard chars are bound as-is.
        $init = [ 'key' => 'name' , 'val' => '%_x' , 'op' => 'ew' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringStartsWith( 'RIGHT(doc.name,' , $result ) ;
        $this->assertContains( '%_x' , $this->binds ) ;
    }

    // ========================================
    // CONTAINS (`contains`) — CONTAINS(key, value)
    // ========================================

    public function testStringFilterContains(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'mele' , 'op' => 'contains' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^CONTAINS\(doc\.name,\s*@\S+\)$/' , $result ) ;
        $this->assertContains( 'mele' , $this->binds ) ;
    }

    public function testStringFilterContainsCaseInsensitiveMirror(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'MELE' , 'op' => 'contains' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^CONTAINS\(LOWER\(doc\.name\),\s*LOWER\(@\S+\)\)$/' , $result ) ;
        $this->assertContains( 'MELE' , $this->binds ) ;
    }

    // ========================================
    // REGEX (`regex`) — REGEX_TEST(key, value)
    // ========================================

    public function testStringFilterRegex(): void
    {
        $init = [ 'key' => 'name' , 'val' => '^eka.*on$' , 'op' => 'regex' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^REGEX_TEST\(doc\.name,\s*@\S+\)$/' , $result ) ;
        // The pattern is bound (no AQL injection); it reaches AQL untouched.
        $this->assertContains( '^eka.*on$' , $this->binds ) ;
    }

    // ========================================
    // NEGATED FUNCTION-FORM OPERATORS — !( … )
    // ========================================

    public function testStringFilterNotStartsWith(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'ekam' , 'op' => 'nsw' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^!\(STARTS_WITH\(doc\.name,\s*@\S+\)\)$/' , $result ) ;
        $this->assertContains( 'ekam' , $this->binds ) ;
    }

    public function testStringFilterNotEndsWith(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'leon' , 'op' => 'new' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression
        (
            '/^!\(RIGHT\(doc\.name,\s*CHAR_LENGTH\((@\S+)\)\)\s*==\s*\1\)$/' ,
            $result
        ) ;
        $this->assertContains( 'leon' , $this->binds ) ;
    }

    public function testStringFilterNotContains(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'mele' , 'op' => 'ncontains' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^!\(CONTAINS\(doc\.name,\s*@\S+\)\)$/' , $result ) ;
        $this->assertContains( 'mele' , $this->binds ) ;
    }

    public function testStringFilterNotRegex(): void
    {
        $init = [ 'key' => 'name' , 'val' => '^x' , 'op' => 'nregex' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^!\(REGEX_TEST\(doc\.name,\s*@\S+\)\)$/' , $result ) ;
        $this->assertContains( '^x' , $this->binds ) ;
    }

    public function testStringFilterNotStartsWithCaseInsensitiveMirror(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'EKAM' , 'op' => 'nsw' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^!\(STARTS_WITH\(LOWER\(doc\.name\),\s*LOWER\(@\S+\)\)\)$/' , $result ) ;
        $this->assertContains( 'EKAM' , $this->binds ) ;
    }
}
