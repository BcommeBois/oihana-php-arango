<?php

namespace tests\oihana\arango\db\operations;

use oihana\arango\db\enums\options\TraversalOption;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\operations\aqlTraversal;

class AqlTraversalTest extends TestCase
{
    /**
     * @throws ReflectionException
     * @throws BindException
     * @throws ConstantException
     */
    public function testReturnsEmptyWhenGraphIsMissing(): void
    {
        $init = [AQL::START_VERTEX => 'users/1'];
        $this->assertSame('', aqlTraversal($init));
    }

    /**
     * @throws ReflectionException
     * @throws BindException
     * @throws ConstantException
     */
    public function testReturnsEmptyWhenStartVertexIsMissing(): void
    {
        $init = [AQL::GRAPH => 'myGraph'];
        $this->assertSame('', aqlTraversal($init));
    }

    /**
     * @throws ReflectionException
     * @throws BindException
     * @throws ConstantException
     */
    public function testReturnsEmptyWhenBothAreMissing(): void
    {
        $this->assertSame('', aqlTraversal());
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testBasicQueryWithDefaultsNoBinds(): void
    {
        $init = [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1'
        ];
        $result = aqlTraversal($init);
        $expected = "FOR vertex IN OUTBOUND 'users/1' GRAPH 'myGraph'";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testBasicQueryWithCustomEdgeRef(): void
    {
        $init = [
            AQL::VERTEX_REF   => AQL::GRAPH_DEFAULT ,
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1'
        ];
        $result = aqlTraversal($init);
        $expected = "FOR vertex, edge, path IN OUTBOUND 'users/1' GRAPH 'myGraph'";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithCustomVarsNoBinds(): void
    {
        $init =
        [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::VERTEX_REF   => 'v',
            AQL::EDGE_REF     => 'e',
            AQL::PATH_REF     => 'p',
        ];
        $result = aqlTraversal($init);
        $expected = "FOR v, e, p IN OUTBOUND 'users/1' GRAPH 'myGraph'";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithCustomDirectionNoBinds(): void
    {
        $init = [
            AQL::GRAPH => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::DIRECTION => Traversal::INBOUND
        ];
        $result = aqlTraversal($init);
        $this->assertStringContainsString('INBOUND', $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithCustomDepthNoBinds(): void
    {
        $init = [
            AQL::GRAPH => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::MIN_DEPTH => 2,
            AQL::MAX_DEPTH => 5
        ];
        $result = aqlTraversal($init);
        // Uses the simple literal mock of aqlTraversalRange
        $this->assertStringContainsString('IN 2..5', $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithPruneNoBinds(): void
    {
        $init = [
            AQL::GRAPH => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::PRUNE => 'v.age > 20'
        ];
        $result = aqlTraversal($init);
        // Uses the mock of aqlPrune
        $this->assertStringContainsString('PRUNE v.age > 20', $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithOptionsNoBinds(): void
    {
        $init =
        [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::OPTIONS      => ['bfs' => true] // bfs is deprecated, use order:"bfs" instead.
        ];

        $result = aqlTraversal( $init ) ;
        $this->assertEquals
        (
            "FOR vertex IN OUTBOUND 'users/1' GRAPH 'myGraph'",
            $result
    );
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithAllFeaturesNoBinds(): void
    {
        $init = [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::VERTEX_REF   => 'v',
            AQL::EDGE_REF     => 'e',
            AQL::PATH_REF     => 'p',
            AQL::DIRECTION    => Traversal::ANY,
            AQL::MIN_DEPTH    => 0,
            AQL::MAX_DEPTH    => 3,
            AQL::PRUNE        => 'v.stop == true',
            AQL::OPTIONS      => [ TraversalOption::UNIQUE_VERTICES => 'global']
        ];
        $result = aqlTraversal($init);
        $expected = "FOR v, e, p IN 0..3 ANY 'users/1' GRAPH 'myGraph' PRUNE v.stop == true OPTIONS {\"uniqueVertices\":\"global\"}";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testBasicQueryWithBinds(): void
    {
        $binds = [];
        $init  =
        [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1'
        ];
        $result = aqlTraversal( $init , $binds ) ;

        $expected = "FOR vertex IN OUTBOUND @startVertex GRAPH @graph";
        $this->assertSame($expected, $result);

        $expectedBinds =
        [
            'startVertex'  => 'users/1',
            'graph'        => 'myGraph'
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testFullQueryWithBinds(): void
    {
        $binds = ['existingKey' => 'value']; // Test that it appends to existing binds
        $init  =
        [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::VERTEX_REF   => 'v',
            AQL::EDGE_REF     => 'e',
            AQL::PATH_REF     => 'p',
            AQL::DIRECTION    => Traversal::INBOUND,
            AQL::MIN_DEPTH    => 2,
            AQL::MAX_DEPTH    => 5,
            AQL::PRUNE        => 'v.stop == true',
            AQL::OPTIONS      => ['uniqueVertices' => 'global']
        ];
        $result = aqlTraversal($init, $binds);

        $expected = "FOR v, e, p IN @minDepth..@maxDepth INBOUND @startVertex GRAPH @graph PRUNE v.stop == true OPTIONS {\"uniqueVertices\":\"global\"}";
        $this->assertSame($expected, $result);

        $expectedBinds =
        [
            'existingKey' => 'value',
            'startVertex' => 'users/1',
            'graph'       => 'myGraph' ,
            'minDepth'    => 2 ,
            'maxDepth'    => 5 ,
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testThrowsExceptionOnInvalidDirection(): void
    {
        $this->expectException(ConstantException::class);

        $init =
        [
            AQL::GRAPH        => 'myGraph',
            AQL::START_VERTEX => 'users/1',
            AQL::DIRECTION    => 'SIDEWAYS' // Invalid direction
        ];

        // The mock for Traversal::validate will throw the exception
        aqlTraversal($init);
    }
    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithEdgeCollectionNoBinds(): void
    {
        $init = [
            AQL::EDGE_COLLECTION => 'myEdges',
            AQL::START_VERTEX    => 'users/1'
        ];

        $result = aqlTraversal($init);
        $expected = "FOR vertex IN OUTBOUND 'users/1' myEdges";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithMultipleEdgeCollectionsAndNoBinds(): void
    {
        $init = [
            AQL::EDGE_COLLECTION => ['edgesA', 'edgesB'],
            AQL::START_VERTEX    => 'users/1'
        ];

        $result = aqlTraversal($init);
        $expected = "FOR vertex IN OUTBOUND 'users/1' edgesA, edgesB";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithMultipleEdgeCollectionsAndWithBinds(): void
    {
        $binds = [] ;
        $init  =
        [
            AQL::EDGE_COLLECTION => [ 'edgesA', 'edgesB'],
            AQL::START_VERTEX    => 'users/1'
        ];

        $result = aqlTraversal($init, $binds);

        $this->assertStringContainsString('FOR vertex IN OUTBOUND @startVertex', $result);
        $this->assertStringContainsString('@@', $result); // Au moins une collection bind


        $this->assertArrayHasKey('startVertex', $binds);
        $this->assertEquals('users/1', $binds['startVertex']);

        $collectionBinds = array_filter($binds, fn($key) => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(2, $collectionBinds);
        $this->assertContains('edgesA', $collectionBinds);
        $this->assertContains('edgesB', $collectionBinds);

    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithEdgeCollectionAndBinds(): void
    {
        $binds = [];
        $init = [
            AQL::EDGE_COLLECTION => 'myEdges',
            AQL::START_VERTEX    => 'users/1'
        ];

        $result = aqlTraversal($init, $binds);

        $this->assertStringContainsString('FOR vertex IN OUTBOUND @startVertex', $result);
        $this->assertStringContainsString('@@', $result); // Collection bind présent

        $this->assertArrayHasKey('startVertex', $binds);
        $this->assertEquals('users/1', $binds['startVertex']);

        $collectionBinds = array_filter($binds, fn($key) => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $collectionBinds);
        $this->assertContains('myEdges', $collectionBinds);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithEdgeCollectionIgnoredWithGraphOption(): void
    {
        $binds = [];
        $init =
        [
            AQL::GRAPH           => 'myGraph',
            AQL::EDGE_COLLECTION => 'myEdges',
            AQL::START_VERTEX    => 'users/1'
        ];

        $result = aqlTraversal($init, $binds);
        $expected = "FOR vertex IN OUTBOUND @startVertex GRAPH @graph";
        $this->assertSame($expected, $result);

        $expectedBinds = [
            'startVertex' => 'users/1',
            'graph'       => 'myGraph'
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * @throws ReflectionException|BindException|ConstantException
     */
    public function testQueryWithEdgeCollectionAndDepth(): void
    {
        $binds = [];
        $init = [
            AQL::EDGE_COLLECTION => 'myEdges',
            AQL::START_VERTEX    => 'users/1',
            AQL::MIN_DEPTH       => 2,
            AQL::MAX_DEPTH       => 4
        ];

        $result = aqlTraversal($init, $binds);

        $this->assertStringContainsString('FOR vertex IN @minDepth..@maxDepth OUTBOUND @startVertex', $result);
        $this->assertStringContainsString('@@', $result); // Collection bind présent

        $expectedBinds = [
            'startVertex' => 'users/1',
            'minDepth'    => 2,
            'maxDepth'    => 4
        ];

        foreach ($expectedBinds as $key => $value) {
            $this->assertArrayHasKey($key, $binds);
            $this->assertEquals($value, $binds[$key]);
        }

        $collectionBinds = array_filter($binds, fn($key) => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $collectionBinds);
        $this->assertContains('myEdges', $collectionBinds);
    }
}