<?php

namespace oihana\arango\models\traits\documents;

use oihana\arango\models\traits\documents\callbacks\OnUpdateRelations;

trait DocumentsMethodsTrait
{
    use DocumentsCountTrait    ,
        DocumentsDeleteTrait   ,
        DocumentsExistTrait    ,
        DocumentsGetTrait      ,
        DocumentsInsertTrait   ,
        DocumentsLastTrait     ,
        DocumentsListTrait     ,
        DocumentsReplaceTrait  ,
        DocumentsRepsertTrait  ,
        DocumentsStreamTrait   ,
        DocumentsTruncateTrait ,
        DocumentsUpdateTrait   ,
        DocumentsUpsertTrait   ,
        OnUpdateRelations      ;

    /**
     * Initialize the Documents HTTP methods signals.
     * @return static
     */
    public function initializeDocumentsMethods() :static
    {
        return $this->initializeDeleteSignals  ()
                    ->initializeInsertSignals  ()
                    ->initializeReplaceSignals ()
                    ->initializeUpdateSignals  ()
                    // insert/replace/update -> auto update the edges relations
                    ->registerUpdateRelations  () ;
    }
}