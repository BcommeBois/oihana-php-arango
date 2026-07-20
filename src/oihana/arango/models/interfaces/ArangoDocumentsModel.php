<?php

namespace oihana\arango\models\interfaces;

use oihana\models\interfaces\DocumentsModel;

/**
 * ArangoDB-specific documents model contract.
 *
 * Extends the generic {@see DocumentsModel} with the extra operations the
 * ArangoDB {@see \oihana\arango\models\Documents} model provides on top of the
 * standard CRUD/list surface — total-rows accounting, per-value facet counts
 * and numeric bounds — which the ArangoDB controllers rely on. Keeping them on
 * a dedicated interface avoids polluting the generic model contract with
 * ArangoDB/search-specific methods.
 *
 * @package oihana\arango\models\interfaces
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.6.0
 */
interface ArangoDocumentsModel extends DocumentsModel
{
    /**
     * Returns the numeric `{ min, max }` bounds requested alongside a list query.
     *
     * @param array $init The init array forwarded to the model.
     *
     * @return array
     */
    public function bounds( array $init = [] ) : array ;

    /**
     * Returns the per-value facet counts requested alongside a list query.
     *
     * @param array $init The init array forwarded to the model.
     *
     * @return array
     */
    public function facetCounts( array $init = [] ) : array ;

    /**
     * Returns the total number of rows the last list query would have returned
     * without its `LIMIT` clause (the `fullCount` figure), for pagination.
     *
     * @return int
     */
    public function foundRows() : int ;
}
