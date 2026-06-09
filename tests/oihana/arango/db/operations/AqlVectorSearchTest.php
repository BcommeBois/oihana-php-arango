<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlVectorSearch;

class AqlVectorSearchTest extends TestCase
{
    public function testDefaultCosineSearch(): void
    {
        $this->assertSame
        (
            'FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc' ,
            aqlVectorSearch
            (
                collection : 'items' ,
                attribute  : 'embedding' ,
                vector     : '@query' ,
                limit      : 10 ,
            )
        ) ;
    }

    public function testL2SearchSortsAscending(): void
    {
        $this->assertSame
        (
            'FOR doc IN items SORT APPROX_NEAR_L2(doc.embedding,@query) ASC LIMIT 5 RETURN doc' ,
            aqlVectorSearch
            (
                collection : 'items' ,
                attribute  : 'embedding' ,
                vector     : '@query' ,
                limit      : 5 ,
                metric     : 'l2' ,
            )
        ) ;
    }

    public function testFullCustomisation(): void
    {
        $this->assertSame
        (
            'FOR d IN items SORT APPROX_NEAR_L2(d.embedding,@query,{"nProbe":20}) ASC LIMIT 5 RETURN { key: d._key }' ,
            aqlVectorSearch
            (
                collection : 'items' ,
                attribute  : 'embedding' ,
                vector     : '@query' ,
                limit      : 5 ,
                metric     : 'l2' ,
                nProbe     : 20 ,
                docRef     : 'd' ,
                return     : '{ key: d._key }' ,
            )
        ) ;
    }

    public function testCosineWithNProbe(): void
    {
        $this->assertSame
        (
            'FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query,{"nProbe":7}) DESC LIMIT 3 RETURN doc' ,
            aqlVectorSearch
            (
                collection : 'items' ,
                attribute  : 'embedding' ,
                vector     : '@query' ,
                limit      : 3 ,
                nProbe     : 7 ,
            )
        ) ;
    }

    public function testUnsupportedMetricThrows(): void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( "unsupported metric 'hamming'" ) ;
        aqlVectorSearch
        (
            collection : 'items' ,
            attribute  : 'embedding' ,
            vector     : '@query' ,
            limit      : 5 ,
            metric     : 'hamming' ,
        ) ;
    }
}
