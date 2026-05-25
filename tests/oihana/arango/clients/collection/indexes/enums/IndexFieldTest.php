<?php

namespace tests\oihana\arango\clients\collection\indexes\enums ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see IndexField} — canonical JSON field names of the
 * ArangoDB index API.
 */
#[CoversClass( IndexField::class )]
class IndexFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'analyzer'                  , IndexField::ANALYZER                    ) ;
        $this->assertSame( 'cache'                     , IndexField::CACHE                       ) ;
        $this->assertSame( 'cacheEnabled'              , IndexField::CACHE_ENABLED               ) ;
        $this->assertSame( 'cleanupIntervalStep'       , IndexField::CLEANUP_INTERVAL_STEP       ) ;
        $this->assertSame( 'commitIntervalMsec'        , IndexField::COMMIT_INTERVAL_MSEC        ) ;
        $this->assertSame( 'consolidationIntervalMsec' , IndexField::CONSOLIDATION_INTERVAL_MSEC ) ;
        $this->assertSame( 'deduplicate'               , IndexField::DEDUPLICATE                 ) ;
        $this->assertSame( 'estimates'                 , IndexField::ESTIMATES                   ) ;
        $this->assertSame( 'expireAfter'               , IndexField::EXPIRE_AFTER                ) ;
        $this->assertSame( 'features'                  , IndexField::FEATURES                    ) ;
        $this->assertSame( 'fields'                    , IndexField::FIELDS                      ) ;
        $this->assertSame( 'fieldValueTypes'           , IndexField::FIELD_VALUE_TYPES           ) ;
        $this->assertSame( 'geoJson'                   , IndexField::GEO_JSON                    ) ;
        $this->assertSame( 'id'                        , IndexField::ID                          ) ;
        $this->assertSame( 'includeAllFields'          , IndexField::INCLUDE_ALL_FIELDS          ) ;
        $this->assertSame( 'inBackground'              , IndexField::IN_BACKGROUND               ) ;
        $this->assertSame( 'indexes'                   , IndexField::INDEXES                     ) ;
        $this->assertSame( 'minLength'                 , IndexField::MIN_LENGTH                  ) ;
        $this->assertSame( 'name'                      , IndexField::NAME                        ) ;
        $this->assertSame( 'parallelism'               , IndexField::PARALLELISM                 ) ;
        $this->assertSame( 'params'                    , IndexField::PARAMS                      ) ;
        $this->assertSame( 'prefixFields'              , IndexField::PREFIX_FIELDS               ) ;
        $this->assertSame( 'primaryKeyCache'           , IndexField::PRIMARY_KEY_CACHE           ) ;
        $this->assertSame( 'primarySort'               , IndexField::PRIMARY_SORT                ) ;
        $this->assertSame( 'searchField'               , IndexField::SEARCH_FIELD                ) ;
        $this->assertSame( 'sparse'                    , IndexField::SPARSE                      ) ;
        $this->assertSame( 'storedValues'              , IndexField::STORED_VALUES               ) ;
        $this->assertSame( 'trackListPositions'        , IndexField::TRACK_LIST_POSITIONS        ) ;
        $this->assertSame( 'type'                      , IndexField::TYPE                        ) ;
        $this->assertSame( 'unique'                    , IndexField::UNIQUE                      ) ;
    }
}
