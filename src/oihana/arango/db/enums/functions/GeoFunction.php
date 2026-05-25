<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

class GeoFunction
{
    use FunctionCallTrait ;

    public const string NEAR             = 'NEAR' ;
    public const string WITHIN           = 'WITHIN' ;
    public const string WITHIN_RECTANGLE = 'WITHIN_RECTANGLE' ;

    // ---- Utilities

    public const string DISTANCE       = 'DISTANCE' ;
    public const string GEO_AREA       = 'GEO_AREA' ;
    public const string GEO_CONTAINS   = 'GEO_CONTAINS' ;
    public const string GEO_DISTANCE   = 'GEO_DISTANCE' ;
    public const string GEO_EQUALS     = 'GEO_EQUALS' ;
    public const string GEO_INTERSECTS = 'GEO_INTERSECTS' ;
    public const string GEO_IN_RANGE   = 'GEO_IN_RANGE' ;
    public const string IS_IN_POLYGON  = 'IS_IN_POLYGON' ;

    // ---- Utilities

    public const string GEO_LINESTRING       = 'GEO_LINESTRING' ;
    public const string GEO_MULTILINESTRING  = 'GEO_MULTILINESTRING ' ;
    public const string GEO_MULTIPOINT       = 'GEO_MULTIPOINT ' ;
    public const string GEO_MULTIPOLYGON     = 'GEO_MULTIPOLYGON ' ;
    public const string GEO_POINT            = 'GEO_POINT ' ;
    public const string GEO_POLYGON          = 'GEO_POLYGON ' ;
}
