<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AnalyzerField} — the catalogue of wire field
 * names exchanged with `/_api/analyzer`.
 */
#[CoversClass( AnalyzerField::class )]
class AnalyzerFieldTest extends TestCase
{
    public function testTopLevelFieldValues() :void
    {
        $this->assertSame( 'name'       , AnalyzerField::NAME       ) ;
        $this->assertSame( 'type'       , AnalyzerField::TYPE       ) ;
        $this->assertSame( 'features'   , AnalyzerField::FEATURES   ) ;
        $this->assertSame( 'properties' , AnalyzerField::PROPERTIES ) ;
        $this->assertSame( 'result'     , AnalyzerField::RESULT     ) ;
    }

    public function testTypeSpecificPropertyFieldValues() :void
    {
        $this->assertSame( 'locale'           , AnalyzerField::LOCALE            ) ;
        $this->assertSame( 'case'             , AnalyzerField::CASE              ) ;
        $this->assertSame( 'accent'           , AnalyzerField::ACCENT            ) ;
        $this->assertSame( 'stemming'         , AnalyzerField::STEMMING          ) ;
        $this->assertSame( 'stopwords'        , AnalyzerField::STOPWORDS         ) ;
        $this->assertSame( 'stopwordsPath'    , AnalyzerField::STOPWORDS_PATH    ) ;
        $this->assertSame( 'edgeNgram'        , AnalyzerField::EDGE_NGRAM        ) ;
        $this->assertSame( 'min'              , AnalyzerField::MIN               ) ;
        $this->assertSame( 'max'              , AnalyzerField::MAX               ) ;
        $this->assertSame( 'preserveOriginal' , AnalyzerField::PRESERVE_ORIGINAL ) ;
        $this->assertSame( 'startMarker'      , AnalyzerField::START_MARKER      ) ;
        $this->assertSame( 'endMarker'        , AnalyzerField::END_MARKER        ) ;
        $this->assertSame( 'streamType'       , AnalyzerField::STREAM_TYPE       ) ;
    }

    public function testFieldsAreUnique() :void
    {
        $values     = array_values( AnalyzerField::getAll() ) ;
        $duplicates = array_diff_assoc( $values , array_unique( $values ) ) ;

        $this->assertSame
        (
            [] ,
            $duplicates ,
            'AnalyzerField must not contain duplicate wire values: ' . implode( ', ' , $duplicates ) ,
        ) ;
    }
}
