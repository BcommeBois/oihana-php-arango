<?php

namespace tests\oihana\arango\db\operations;

use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlTraversalRange;

class AqlTraversableRangeTest extends TestCase
{
    /**
     * @throws BindException
     */
    public function testReturnsDefaultRangeWhenBothAreNull(): void
    {
        $this->assertSame('', aqlTraversalRange(null, null));
    }

    /**
     * @throws BindException
     */
    public function testReturnsDefaultCustomRangeWhenBothAreNull(): void
    {
        $this->assertSame('1..1', aqlTraversalRange( defaultRange: '1..1' ) );
    }


    /**
     * @throws BindException
     */
    public function testReturnsFixedRangeWithMinAndMax(): void
    {
        $this->assertSame('1..5', aqlTraversalRange(1, 5));
    }

    /**
     * @throws BindException
     */
    public function testReturnsFixedRangeWithSameMinAndMax(): void
    {
        $this->assertSame('2..2', aqlTraversalRange(2, 2));
    }

    /**
     * @throws BindException
     */
    public function testReturnsOpenEndedMaxRangeWithOnlyMin(): void
    {
        $this->assertSame('3..', aqlTraversalRange(3, null));
    }

    /**
     * @throws BindException
     */
    public function testReturnsOpenEndedMinRangeWithOnlyMax(): void
    {
        $this->assertSame('..7', aqlTraversalRange(null, 7));
    }

    /**
     * @throws BindException
     */
    public function testHandlesZeroAsMin(): void
    {
        $this->assertSame('0..', aqlTraversalRange(0, null));
    }

    /**
     * @throws BindException
     */
    public function testHandlesZeroAsMax(): void
    {
        $this->assertSame('..0', aqlTraversalRange(null, 0));
    }

    /**
     * @throws BindException
     */
    public function testHandlesZeroAsMinAndMax(): void
    {
        $this->assertSame('0..0', aqlTraversalRange(0, 0));
    }

    /**
     * @throws BindException
     */
    public function testReturnsDefaultRangeWhenBothAreNullWithBinds(): void
    {
        $binds = [];
        $result = aqlTraversalRange(null, null, $binds);

        $this->assertSame('', $result);
        $this->assertEmpty($binds, 'Binds array should remain empty');
    }

    /**
     * @throws BindException
     */
    public function testReturnsBoundRangeWithMinAndMax(): void
    {
        $binds = [];
        $result = aqlTraversalRange(1, 5, $binds);

        // Vérifie la chaîne retournée
        $this->assertSame('@minDepth..@maxDepth', $result);

        // Vérifie que le tableau $binds a été modifié par référence
        $expectedBinds = [
            'minDepth' => 1,
            'maxDepth' => 5
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * @throws BindException
     */
    public function testReturnsBoundRangeWithOnlyMin(): void
    {
        $binds = [];
        $result = aqlTraversalRange(3, null, $binds);

        $this->assertSame('@minDepth..', $result);
        $this->assertSame(['minDepth' => 3], $binds);
    }

    /**
     * @throws BindException
     */
    public function testReturnsBoundRangeWithOnlyMax(): void
    {
        $binds = [];
        $result = aqlTraversalRange(null, 7, $binds);

        $this->assertSame('..@maxDepth', $result);
        $this->assertSame(['maxDepth' => 7], $binds);
    }

    /**
     * @throws BindException
     */
    public function testReturnsBoundRangeWithZeroValues(): void
    {
        $binds = [];
        $result = aqlTraversalRange(0, 0, $binds);

        $this->assertSame('@minDepth..@maxDepth', $result);
        $expectedBinds = [
            'minDepth' => 0,
            'maxDepth' => 0
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * @throws BindException
     */
    public function testModifiesExistingBindsArray(): void
    {
        // Vérifie que la fonction ajoute aux clés existantes
        $binds = ['existingKey' => 'value'];
        aqlTraversalRange(1, 2, $binds);

        $expectedBinds = [
            'existingKey' => 'value',
            'minDepth' => 1,
            'maxDepth' => 2
        ];
        $this->assertSame($expectedBinds, $binds);
    }

    /**
     * 🔑 Two traversals of one query with different depths : the second draws names of
     * its own instead of refusing the first ones.
     *
     * @throws BindException
     */
    public function testASecondRangeWithOtherDepthsDrawsItsOwnNames(): void
    {
        $binds  = [] ;
        $first  = aqlTraversalRange( 1 , 3 , $binds ) ;
        $second = aqlTraversalRange( 1 , 5 , $binds ) ;

        $this->assertSame( '@minDepth..@maxDepth' , $first ) ;
        $this->assertMatchesRegularExpression( '/^@minDepth\.\.@maxDepth_\d+$/' , $second ) ;
        $this->assertSame( 1 , $binds[ 'minDepth' ] ) ;
        $this->assertSame( 3 , $binds[ 'maxDepth' ] ) ;
        $this->assertContains( 5 , $binds ) ;
        $this->assertCount( 3 , $binds ) ;
    }

    /**
     * The same depth again reuses the fixed name : nothing changes for one traversal,
     * nor for two that agree.
     *
     * @throws BindException
     */
    public function testTheSameDepthsReuseTheFixedNames(): void
    {
        $binds = [] ;
        aqlTraversalRange( 2 , 4 , $binds ) ;

        $this->assertSame( '@minDepth..@maxDepth' , aqlTraversalRange( 2 , 4 , $binds ) ) ;
        $this->assertSame( [ 'minDepth' => 2 , 'maxDepth' => 4 ] , $binds ) ;
    }
}