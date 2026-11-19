# Vector Databases & Similarity Search (HNSW, IVF-Flat)

## The problem

Search by *meaning*, not by exact match: "find products like this one", "find the paragraphs that answer this question". An embedding model turns each item into a **vector** (a list of a few hundred to a few thousand numbers) so that similar items end up close together. The query then becomes: *which stored vectors are nearest to this one?*

The exact answer needs the distance from the query to **every** vector — fine for ten thousand, hopeless for a hundred million. A B-tree doesn't help: there is no ordering of 768-dimensional points that keeps neighbours together.

## The idea

Give up a little accuracy for a lot of speed: **approximate** nearest neighbour (ANN) search. An index looks at a small fraction of the vectors and usually finds the true neighbours. Quality is measured as **recall**: the share of the true top-k that the index returned.

### IVF-Flat — partition the space

1. **Train:** run k-means to find `nlist` centroids. Each centroid owns the region of space closest to it.
2. **Add:** put every vector in the list of its nearest centroid (an *inverted file*).
3. **Search:** find the `nprobe` centroids closest to the query and scan only their lists, exactly ("flat").

`nprobe` is the dial: 1 is fastest, `nlist` is exact.

### HNSW — navigate a graph

A multi-layer graph where each vector is linked to its nearest neighbours. Upper layers are sparse with long links (an express network), the bottom layer contains everything.

Search starts at the top, greedily hops towards the query, drops a layer, and repeats — like zooming a map from country to street level. `efSearch` (how many candidates to keep at the bottom layer) is the dial.

| | IVF-Flat | HNSW |
| --- | --- | --- |
| Build | Needs training data; fast to add | No training; slower inserts |
| Memory | Low (just the lists) | Higher (the graph's links) |
| Query speed / recall | Good | **Best in class** |
| Typical use | Very large, memory-bound datasets | The default in most vector databases |

## In this folder

| File | Role |
| --- | --- |
| `Distance.php` | Euclidean distance and cosine similarity |
| `BruteForceIndex.php` | Exact search: the baseline, and the ground truth for measuring recall |
| `IvfFlatIndex.php` | k-means training, inverted lists, `nprobe` |
| `HnswIndex.php` | Layered proximity graph: insertion, greedy descent, `efSearch` |

Every index counts its distance computations, so the tests can show *how much work* each one avoided and *what recall* it paid for that.

Real systems: pgvector (both index types), FAISS, Qdrant, Milvus, Pinecone, Elasticsearch.

## Try it

```bash
php artisan test --filter=VectorSearchTest
```
