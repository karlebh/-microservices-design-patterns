<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\VectorSearch;

/** The two usual ways to say how close two vectors are. */
final class Distance
{
    /**
     * Straight-line distance. Smaller means more similar.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function euclidean(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $difference = $value - $b[$i];
            $sum += $difference * $difference;
        }

        return sqrt($sum);
    }

    /**
     * The cosine of the angle between two vectors: 1 = same direction,
     * 0 = unrelated, -1 = opposite. It ignores length, which suits text
     * embeddings where direction carries the meaning.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $normA === 0.0 || $normB === 0.0 ? 0.0 : $dot / (sqrt($normA) * sqrt($normB));
    }
}
