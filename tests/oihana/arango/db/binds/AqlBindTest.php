<?php

namespace tests\oihana\arango\db\binds;

use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\binds\aqlBind;
use function oihana\arango\db\binds\aqlBindCollection;

class AqlBindTest extends TestCase
{
    /**
     * @throws BindException
     */
    public function testAqlBind(): void
    {
        $binds = [];
        $result = aqlBind('foo', $binds, 'my_var');
        $this->assertSame('@my_var', $result);
        $this->assertSame(['my_var' => 'foo'], $binds);
    }

    /**
     * @throws BindException
     */
    public function testAqlBindWithNullToAndPrefix(): void
    {
        $binds = [];
        $result = aqlBind('foo', $binds, null , 'var' ) ;
        $this->assertMatchesRegularExpression('/^@var_[0-9]{6}$/', $result);

        $key = ltrim($result,'@') ;

        $this->assertArrayHasKey( $key , $binds ) ;
        $this->assertSame('foo', $binds[$key]);
    }

    /**
     * @throws BindException
     */
    public function testAqlBindWithNullToAndNullPrefix(): void
    {
        $binds = [];
        $result = aqlBind('foo', $binds ) ;
        $this->assertMatchesRegularExpression('/^@q_[0-9]{6}$/', $result);

        $key = ltrim($result,'@') ;

        $this->assertArrayHasKey( $key , $binds ) ;
        $this->assertSame('foo', $binds[$key]);
    }

    /**
     * @throws BindException
     */
    public function testAqlBindCollection(): void
    {
        $binds = [];
        $result = aqlBindCollection('my_collection', $binds, 'my_coll');
        $this->assertSame('@@my_coll', $result);
        $this->assertSame(['@my_coll' => 'my_collection'], $binds);
    }

    /**
     * @throws BindException
     */
    public function testBindAqlCollectionWithNullToAndWithNullPrefix(): void
    {
        $binds = [];
        $result = aqlBindCollection('my_collection', $binds );
        $this->assertMatchesRegularExpression('/^@@c_[0-9]{6}$/', $result);

        $key = substr($result, 1);

        $this->assertArrayHasKey( $key , $binds ) ;
        $this->assertSame('my_collection', $binds[$key]);
    }
}