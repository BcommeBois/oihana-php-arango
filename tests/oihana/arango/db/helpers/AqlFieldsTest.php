<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function oihana\arango\db\helpers\aqlFields;

final class AqlFieldsTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     */
    public function testFieldsWithFilterArray(): void
    {
        $fields = [ 'tags' => [ Field::FILTER => Filter::ARRAY ] ] ;

        $result = aqlFields( $fields ) ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(doc.tags) ? doc.tags : []' ,
            $result
        );

        $fields = [ 'tags' => [ Field::FILTER => Filter::ARRAY , Field::DEFAULT => AQL::NULL ] ] ;

        $result = aqlFields( $fields , 'edge') ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(edge.tags) ? edge.tags : null' ,
            $result
        );
    }
}
