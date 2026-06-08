<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\enums\GroupParam;
use oihana\arango\controllers\traits\PrepareGroupTrait;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

/**
 * Host exposing the protected {@see PrepareGroupTrait::prepareGroup()}.
 */
class PrepareGroupHost
{
    use PrepareGroupTrait ;

    public function call( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        return $this->prepareGroup( $request , $args , $params ) ;
    }
}

/**
 * Unit coverage for {@see PrepareGroupTrait}: it maps the `?groupBy=` CSV shortcut
 * and the `?group=` JSON spec (short {@see GroupParam} keys) onto the model-side
 * {@see Group} vocabulary.
 */
class PrepareGroupTraitTest extends TestCase
{
    private function host() :PrepareGroupHost
    {
        return new PrepareGroupHost() ;
    }

    private function request( array $query ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( 'GET' , '/' )->withQueryParams( $query ) ;
    }

    public function testNullWhenNothingRequested() :void
    {
        $this->assertNull( $this->host()->call( null ) ) ;
        $this->assertNull( $this->host()->call( $this->request( [] ) ) ) ;
    }

    public function testGroupByCsvImpliesCount() :void
    {
        $params  = [] ;
        $request = $this->request( [ 'groupBy' => 'category' ] ) ;

        $this->assertSame(
            [ Group::BY => 'category' , Group::COUNT => true ] ,
            $this->host()->call( $request , [] , $params )
        ) ;
        $this->assertSame( 'category' , $params[ 'groupBy' ] ) ;
    }

    public function testGroupJsonFullSpecIsMapped() :void
    {
        $json    = '{"by":{"year":"created"},"agg":{"total":"sum:amount"},"sort":"-total","alt":{"year":"dateYear"}}' ;
        $request = $this->request( [ GroupParam::GROUP => $json ] ) ;

        $this->assertSame(
            [
                Group::BY   => [ 'year' => 'created' ] ,
                Group::AGG  => [ 'total' => 'sum:amount' ] ,
                Group::SORT => '-total' ,
                Group::ALT  => [ 'year' => 'dateYear' ] ,
            ] ,
            $this->host()->call( $request )
        ) ;
    }

    public function testGroupByDoesNotForceCountWhenJsonAlreadyCounts() :void
    {
        // ?group sets count, ?groupBy overrides the field but must not add a default count.
        $request = $this->request(
        [
            GroupParam::GROUP => '{"by":"old","count":"n"}' ,
            'groupBy'         => 'category' ,
        ]) ;

        $this->assertSame(
            [ Group::BY => 'category' , Group::COUNT => 'n' ] ,
            $this->host()->call( $request )
        ) ;
    }

    public function testInvalidJsonIsIgnored() :void
    {
        $this->assertNull( $this->host()->call( $this->request( [ GroupParam::GROUP => 'not-json' ] ) ) ) ;
    }

    public function testUnknownJsonKeysAreDropped() :void
    {
        $request = $this->request( [ GroupParam::GROUP => '{"by":"category","danger":"x"}' ] ) ;
        $this->assertSame( [ Group::BY => 'category' ] , $this->host()->call( $request ) ) ;
    }

    public function testPredefinedArgsSpecIsUsedAsBase() :void
    {
        $this->assertSame(
            [ Group::BY => 'category' ] ,
            $this->host()->call( null , [ Arango::GROUP => [ Group::BY => 'category' ] ] )
        ) ;
    }

    public function testNonArrayArgsSpecIsIgnored() :void
    {
        $this->assertNull( $this->host()->call( null , [ Arango::GROUP => 'nope' ] ) ) ;
    }
}
