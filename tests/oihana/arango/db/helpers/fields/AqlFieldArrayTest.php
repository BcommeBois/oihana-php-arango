<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldArray;
use function oihana\arango\db\helpers\fields\aqlFieldArrayCount;
use function oihana\arango\db\helpers\fields\aqlFieldArrayFirst;

final class AqlFieldArrayTest extends TestCase
{
    // ------ aqlFieldArray

    public function testFieldArrayBasic(): void
    {
        $result = aqlFieldArray('tags') ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(doc.tags) ? doc.tags : []' ,
            $result
        );
    }

    public function testFieldArrayWithCustomDocRef(): void
    {
        $result = aqlFieldArray('tags', 'edge' ) ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(edge.tags) ? edge.tags : []' ,
            $result
        );
    }

    public function testFieldArrayWithCustomDocRefAndCustomDefault(): void
    {
        $result = aqlFieldArray('tags' , 'edge' , AQL::NULL ) ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(edge.tags) ? edge.tags : null' ,
            $result
        );
    }

    // ------ aqlFieldArrayCount

    public function testFieldArrayCountBasic(): void
    {
        $result = aqlFieldArrayCount('tagCount') ;
        $this->assertEquals
        (
            'tagCount:IS_ARRAY(doc.tagCount) ? LENGTH(doc.tagCount) : 0' ,
            $result
        );
    }

    public function testFieldArrayCountWithCustomDocRef(): void
    {
        $result = aqlFieldArrayCount('tagCount', 'tags') ;
        $this->assertEquals
        (
            'tagCount:IS_ARRAY(tags.tagCount) ? LENGTH(tags.tagCount) : 0' ,
            $result
        );
    }

    public function testFieldArrayCountWithCustomDocAndAlias(): void
    {
        $result = aqlFieldArrayCount('authorCount', 'authors', 'userDoc') ;
        $this->assertEquals
        (
            'authorCount:IS_ARRAY(authors.userDoc) ? LENGTH(authors.userDoc) : 0' ,
            $result
        );
    }

    // ------ aqlFieldArrayFirst

    public function testFieldArrayExtractsFirstElement(): void
    {
        $result = aqlFieldArrayFirst('firstTag', 'tagsList');
        $this->assertEquals
        (
            'firstTag:IS_ARRAY(tagsList) ? FIRST(tagsList) : null',
            $result
        );
    }
}
