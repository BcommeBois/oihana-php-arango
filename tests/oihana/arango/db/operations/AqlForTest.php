<?php

namespace tests\oihana\arango\db\operations;

use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;

use oihana\enums\Char;

use function oihana\arango\db\operations\aqlFor;

final class AqlForTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testFor(): void
    {
        $in = 'my_collection';
        $opts = [AQL::IN => $in];
        $result = aqlFor($opts);
        $this->assertSame(Operation::FOR . Char::SPACE . AQL::DOC . Char::SPACE . Comparator::IN . Char::SPACE . $in, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testForWithVariableName(): void
    {
        $in = 'my_collection';
        $var = 'my_var';
        $opts = [AQL::IN => $in, AQL::DOC_REF => $var];
        $result = aqlFor($opts);
        $this->assertSame(Operation::FOR . Char::SPACE . $var . Char::SPACE . Comparator::IN . Char::SPACE . $in, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testForWithSearch(): void
    {
        $in     = 'my_collection' ;
        $search = 'foo.bar > 10' ;
        $opts   = [AQL::IN => $in, AQL::SEARCH => $search ] ;
        $result = aqlFor( $opts);
        $this->assertSame
        (
            'FOR doc IN my_collection SEARCH foo.bar > 10' ,
            $result
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testForWithOptions(): void
    {
        $in = 'my_collection';
        $options = ['useCache' => true];
        $opts = [AQL::IN => $in, AQL::OPTIONS => $options];
        $result = aqlFor( $opts ) ;
        $this->assertSame
        (
             'FOR doc IN my_collection OPTIONS {"useCache":true}' ,
            $result
        );
    }
}