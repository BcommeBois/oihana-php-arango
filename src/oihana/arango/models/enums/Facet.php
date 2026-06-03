<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

class Facet
{
    use ConstantsTrait ;

    public const string ARRAY_COMPLEX     = 'facet_array_complex' ;
    public const string EDGE              = 'facet_edge' ;
    public const string EDGE_COMPLEX      = 'facet_edge_complex' ;
    public const string FIELD             = 'facet_field' ;
    public const string IN                = 'facet_in' ;
    public const string LOGIC             = 'facet_logic' ;
    public const string LIST              = 'facet_list' ;
    public const string LIST_FIELD        = 'facet_list_field' ;
    public const string LIST_FIELD_SORTED = 'facet_list_field_sorted' ;
    public const string OP                = 'facet_op' ;
    public const string PROPERTY          = 'facet_property' ;
    public const string THESAURUS         = 'facet_thesaurus' ;
    public const string TYPE              = 'facet_type' ;

}