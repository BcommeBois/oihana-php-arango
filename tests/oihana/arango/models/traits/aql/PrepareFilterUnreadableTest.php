<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A filter {@see \oihana\arango\models\traits\aql\FilterTrait::prepareFilter()} cannot read
 * is refused — it used to be dropped without a word, and the whole collection came back.
 *
 * Measured before the fix : a JSON string, a Mongo-style `{"age":{"$gte":30}}`, a mistyped
 * `{"agee":1}` or the `{"logic":…,"conditions":[…]}` form the wiki taught all compiled to
 * no clause at all ; inside an `and`, such a child vanished and LOOSENED the group ;
 * `["not", a, b]` and `["not", {"agee":1}]` compiled to invalid AQL ; a scalar child or an
 * unknown group operator raised a TypeError no controller catches.
 *
 * Every faulty shape is tried at the root AND as the child of a group, because a child
 * that vanishes widens an `and` — the worse of the two.
 */
final class PrepareFilterUnreadableTest extends TestCase
{
    private Documents $model ;

    private array $binds = [] ;

    private const array AGE  = [ 'key' => 'age'  , 'op' => 'ge' , 'val' => 30 ] ;
    private const array NAME = [ 'key' => 'name' , 'val' => 'Ana' ] ;

    protected function setUp() :void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'users' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'age'     => FilterType::NUMBER ,
                'name'    => FilterType::STRING ,
                'virtual' => FilterType::VIRTUAL ,
            ] ,
        ]) ;
    }

    private function filter( mixed $filter ) :?string
    {
        $this->binds = [] ;
        return $this->model->prepareFilter( [ Arango::FILTER => $filter , Arango::LIMIT => 5 ] , $this->binds ) ;
    }

    // ---- what is no filter at all -----------------------------------------------

    /**
     * 🚨 The unfiltered read stays unfiltered : the init of a list carries `limit`,
     * `offset`… and the raw `conditions` of AQL::CONDITIONS — none of them is a filter.
     */
    public function testWhatIsNoFilterAtAllStaysSo() :void
    {
        $this->assertNull( $this->model->prepareFilter( [ Arango::LIMIT => 5 , AQL::CONDITIONS => [ 'doc.a == 1' ] ] , $this->binds ) ) ;
        $this->assertNull( $this->filter( null ) ) ;
        $this->assertNull( $this->filter( [] ) ) ;
    }

    // ---- what still compiles ----------------------------------------------------

    public static function readableFilters() :array
    {
        return
        [
            'a segment'           => [ self::AGE ] ,
            'an and group'        => [ [ 'and' , self::AGE , self::NAME ] ] ,
            'an or group'         => [ [ 'or'  , self::AGE , self::NAME ] ] ,
            'a bare list'         => [ [ self::AGE , self::NAME ] ] ,
            'a not'               => [ [ 'not' , self::AGE ] ] ,
            'a not of a group'    => [ [ 'not' , [ 'or' , self::AGE , self::NAME ] ] ] ,
            'a nested group'      => [ [ 'and' , self::AGE , [ 'or' , self::AGE , self::NAME ] ] ] ,
        ] ;
    }

    #[DataProvider( 'readableFilters' )]
    public function testAReadableFilterStillCompiles( array $filter ) :void
    {
        $this->assertStringContainsString( 'doc.age' , (string) $this->filter( $filter ) ) ;
    }

    /**
     * A declared filter may emit no clause (FilterType::VIRTUAL) : negating it used to
     * compile `!()`, which the server refuses. Its negation constrains nothing either.
     */
    public function testANotOfAClauselessFilterIsNoClauseNotInvalidAql() :void
    {
        $this->assertNull( $this->filter( [ 'not' , [ 'key' => 'virtual' , 'val' => 1 ] ] ) ) ;
        $this->assertSame( '(doc.age >= @' , substr( (string) $this->filter( [ 'and' , self::AGE , [ 'not' , [ 'key' => 'virtual' , 'val' => 1 ] ] ] ) , 0 , 13 ) ) ;
    }

    // ---- what is refused --------------------------------------------------------

    public static function unreadableFilters() :array
    {
        $grammar = 'name the field with "key" — {"key":…,"op":…,"val":…} — or write a group as a list — ["and"|"or"|"not", …].' ;

        return
        [
            // at the root
            'a JSON string'                   => [ json_encode( self::AGE )                         , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : string given.' ] ,
            'a number'                        => [ 42                                               , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : int given.' ] ,
            'a Mongo-style object'            => [ [ 'age' => [ '$gte' => 30 ] ]                    , 'The filter {"age"} speaks no filter grammar : ' . $grammar ] ,
            'a mistyped key'                  => [ [ 'agee' => 1 ]                                  , 'The filter {"agee"} speaks no filter grammar : ' . $grammar ] ,
            'the logic / conditions form'     => [ [ 'logic' => 'or' , 'conditions' => [ self::AGE , self::NAME ] ] , 'The filter {"logic","conditions"} speaks no filter grammar : ' . $grammar ] ,
            'an empty and'                    => [ [ 'and' ]                                        , 'The filter group "and" holds no condition.' ] ,
            'an empty or'                     => [ [ 'or' ]                                         , 'The filter group "or" holds no condition.' ] ,
            'an empty not'                    => [ [ 'not' ]                                        , 'The filter group "not" holds no condition.' ] ,
            'an unknown operator'             => [ [ 'xor' , self::AGE , self::NAME ]               , 'Unknown filter group operator "xor" — expected one of: and, not, or.' ] ,
            'a not of two conditions'         => [ [ 'not' , self::AGE , self::NAME ]               , 'The filter group "not" takes a single condition : negate several with ["not", ["and", …]] or ["not", ["or", …]].' ] ,

            // as the child of a group
            'a mistyped child of an and'      => [ [ 'and' , self::AGE , [ 'agee' => 1 ] ]          , 'The filter {"agee"} speaks no filter grammar : ' . $grammar ] ,
            'a mistyped child of an or'       => [ [ 'or'  , self::AGE , [ 'agee' => 1 ] ]          , 'The filter {"agee"} speaks no filter grammar : ' . $grammar ] ,
            'a mistyped operand of a not'     => [ [ 'not' , [ 'agee' => 1 ] ]                      , 'The filter {"agee"} speaks no filter grammar : ' . $grammar ] ,
            'a logic child of an and'         => [ [ 'and' , self::AGE , [ 'logic' => 'or' , 'conditions' => [ self::NAME ] ] ] , 'The filter {"logic","conditions"} speaks no filter grammar : ' . $grammar ] ,
            'a JSON string child'             => [ [ self::AGE , json_encode( self::NAME ) ]        , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : string given.' ] ,
            'a number child'                  => [ [ 'and' , self::AGE , 42 ]                       , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : int given.' ] ,
            'a null child'                    => [ [ 'and' , self::AGE , null ]                     , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : null given.' ] ,
            'a null operand of a not'         => [ [ 'not' , null ]                                 , 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : null given.' ] ,
            'an empty child'                  => [ [ 'and' , self::AGE , [] ]                       , 'A filter group holds no condition.' ] ,
            'an empty child group'            => [ [ 'and' , self::AGE , [ 'or' ] ]                 , 'The filter group "or" holds no condition.' ] ,

            // two levels down
            'a mistyped grandchild'           => [ [ 'and' , self::AGE , [ 'or' , self::NAME , [ 'agee' => 1 ] ] ] , 'The filter {"agee"} speaks no filter grammar : ' . $grammar ] ,
            'an unknown operator below'       => [ [ 'and' , self::AGE , [ 'xor' , self::NAME ] ]   , 'Unknown filter group operator "xor" — expected one of: and, not, or.' ] ,
        ] ;
    }

    #[DataProvider( 'unreadableFilters' )]
    public function testAnUnreadableFilterIsRefused( mixed $filter , string $message ) :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( $message ) ;

        $this->filter( $filter ) ;
    }

    /**
     * A filter handed bare — without the Arango::FILTER wrapper — is held to the same
     * reading when it speaks the grammar or is a list.
     */
    public function testABareGroupIsHeldToTheSameReading() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter {"agee"} speaks no filter grammar' ) ;

        $this->model->prepareFilter( [ 'and' , self::AGE , [ 'agee' => 1 ] ] , $this->binds ) ;
    }

    /**
     * The traversal controller wraps its predicate with the authorizer : the wrapper is
     * still read, the predicate held to the same rule.
     */
    public function testTheWrapperOfAnAuthorizedPredicateIsStillRead() :void
    {
        $this->assertStringContainsString( 'doc.age' , (string) $this->model->prepareFilter( [ Arango::FILTER => self::AGE , Arango::AUTHORIZER => null ] , $this->binds ) ) ;

        $this->expectException( RequestValidationException::class ) ;
        $this->model->prepareFilter( [ Arango::FILTER => [ 'agee' => 1 ] , Arango::AUTHORIZER => null ] , $this->binds ) ;
    }
}
