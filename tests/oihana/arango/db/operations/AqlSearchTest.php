<?php

namespace tests\oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operations\aqlSearch;

final class AqlSearchTest extends TestCase
{
    public function testReturnsEmptyWhenNoSearchKey(): void
    {
        $this->assertSame(Char::EMPTY, aqlSearch([]));
        $this->assertSame(Char::EMPTY, aqlSearch([AQL::FILTER => 'foo']));
    }

    public function testWithValidSearchString(): void
    {
        $search = "ANALYZER(PHRASE(doc.text, 'search phrase'), 'text_en')" ;
        $result = aqlSearch( [ AQL::SEARCH => $search ] );
        $this->assertSame
        (
            "SEARCH $search" ,
            $result
        ) ;
    }

    public function testWithEmptySearchString(): void
    {
        $result = aqlSearch( [ AQL::SEARCH => Char::EMPTY ] );
        $this->assertSame('' , $result ) ;
    }
}
