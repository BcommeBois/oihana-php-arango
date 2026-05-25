<?php

namespace tests\oihana\arango\db\binds;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\formatBindVariable;

class FormatBindVariableTest extends TestCase
{
    public function testSimpleBindVariable()
    {
        $this->assertSame('@userId', formatBindVariable('userId'));
        $this->assertSame('@foo', formatBindVariable('foo'));
        $this->assertSame('@_bar', formatBindVariable('_bar'));
    }

    public function testBindVariableStartingWithAtSign()
    {
        $this->assertSame('@`@userId`', formatBindVariable('@userId'));
        $this->assertSame('@`@foo`', formatBindVariable('@foo'));
    }

    public function testCollectionBindVariable()
    {
        $this->assertSame('@@users', formatBindVariable('users', true));
        $this->assertSame('@@foo', formatBindVariable('foo', true));
        $this->assertSame('@@_bar', formatBindVariable('_bar', true));
    }

    public function testCollectionBindVariableWithAtSign()
    {
        $this->assertSame('@@`@users`', formatBindVariable('@users', true));
        $this->assertSame('@@`@foo`', formatBindVariable('@foo', true));
    }

    public function testCombinationCases()
    {
        // non-collection normal
        $this->assertSame('@abc', formatBindVariable('abc'));
        // collection
        $this->assertSame('@@abc', formatBindVariable('abc', true));
        // non-collection avec @ initial
        $this->assertSame('@`@abc`', formatBindVariable('@abc'));
        // collection avec @ initial
        $this->assertSame('@@`@abc`', formatBindVariable('@abc', true));
    }
}