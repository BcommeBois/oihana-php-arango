<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of the Faiss Library params to use in the "params" option in the vector index definitions.
 *
 * @see https://docs.arango.ai/arangodb/stable/develop/http-api/indexes/vector/
 */
class FaithParam
{
    use ConstantsTrait ;
    
    /**
     * How many neighboring centroids to consider for the search results by default.
     *
     * The larger the number, the slower the search but the better the search results.
     *
     * The default is 1. You should generally use a higher value here or per query
     * via the nProbe option of the vector similarity functions.
     */
    public const string DEFAULT_N_PROBE = 'defaultNProbe' ;

    /**
     * The vector dimension.
     * The attribute to index needs to have this many elements in the array that stores the vector embedding.
     */
    public const string DIMENSION = 'dimension' ;

    /**
     * You can specify an index factory string that is forwarded to the underlying Faiss library,
     * allowing you to combine different advanced options.
     *
     * Examples:
     * - "IVF100_HNSW10,Flat"
     * - "IVF100,SQ4"
     * - "IVF10_HNSW5,Flat"
     * - "IVF100_HNSW5,PQ256x16"
     *
     * The base index must be an inverted file (IVF) to work with ArangoDB.
     * If you don’t specify an index factory, the value is equivalent to IVF<nLists>,Flat.
     *
     * For more information on how to create these custom indexes, see the Faiss Wiki.
     *
     * @see https://github.com/facebookresearch/faiss/wiki/The-index-factory
     */
    public const string FACTORY = 'factory' ;

    /**
     * Possible values: "cosine", "innerProduct", "l2"
     *
     * The measure for calculating the vector similarity:
     *
     * "cosine": Angular similarity. Vectors are automatically normalized before insertion and search.
     * "innerProduct" (introduced in v3.12.6): Similarity in terms of angle and magnitude.
     *
     * Vectors are not normalized, making it faster than cosine.
     * "l2": Euclidean distance.
     */
    public const string METRIC = 'metric' ;

    /**
     * The number of Voronoi cells to partition the vector space into, respectively the number of centroids in the index.
     *
     * What value to choose depends on the data distribution and chosen metric.
     *
     * According to The Faiss library paper , it should be around 15 * sqrt(N) where N
     * is the number of documents in the collection, respectively the number of documents
     * in the shard for cluster deployments.
     *
     * A bigger value produces more correct results but increases the training time and thus how long it takes
     * to build the index. It cannot be bigger than the number of documents.
     *
     * @see https://arxiv.org/abs/2401.08281
     */
    public const string N_LISTS = 'nLists' ;

    /**
     * The number of iterations in the training process. The default is 25.
     *
     * Smaller values lead to a faster index creation but may yield worse search results.
     */
    public const string TRAINING_ITERATIONS = 'trainingIterations' ;
}
