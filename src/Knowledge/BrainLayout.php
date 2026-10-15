<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/**
 * Places vault pages in stable topic clusters.
 *
 * Linked pages stay together. Unlinked pages with close embeddings form a
 * topic. What is left groups by kind. A saved coordinate is kept until the
 * page changes cluster, so adding a note does not move the rest of the map.
 */
final class BrainLayout
{
    public const SIMILAR = 0.82;

    private const MAX_EMBEDDINGS = 120;

    /**
     * @param list<array{id: int, title: string, kind: string}> $pages
     * @param list<array{from: int, to: int}> $edges
     * @param array<int, array{x: float, y: float, cluster_key: string}> $saved
     * @param array<int, list<float>> $embeddings
     * @return array{
     *   nodes: list<array{id: int, title: string, kind: string, x: float, y: float, cluster: string}>,
     *   clusters: list<array{key: string, label: string, x: float, y: float}>,
     *   dirty: list<array{id: int, cluster_key: string, x: float, y: float}>
     * }
     */
    public static function place(array $pages, array $edges, array $saved = [], array $embeddings = []): array
    {
        if (count($embeddings) > self::MAX_EMBEDDINGS) {
            $embeddings = [];
        }

        $byId = [];
        $parent = [];
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $byId[$id] = [
                'id'    => $id,
                'title' => (string) $page['title'],
                'kind'  => (string) $page['kind'],
            ];
            $parent[$id] = $id;
        }

        $linked = [];
        foreach ($edges as $edge) {
            $from = (int) $edge['from'];
            $to = (int) $edge['to'];
            if (!isset($parent[$from], $parent[$to]) || $from === $to) {
                continue;
            }
            $root = self::unite($parent, $from, $to);
            $linked[$root] = true;
        }

        $singles = self::singletonIds($parent);
        self::uniteSimilar($parent, $singles, $embeddings);

        $groups = [];
        foreach (array_keys($parent) as $id) {
            $root = self::find($parent, $id);
            $groups[$root][] = $id;
        }

        $degree = [];
        foreach ($edges as $edge) {
            $from = (int) $edge['from'];
            $to = (int) $edge['to'];
            if (!isset($byId[$from], $byId[$to]) || $from === $to) {
                continue;
            }
            $degree[$from] = ($degree[$from] ?? 0) + 1;
            $degree[$to] = ($degree[$to] ?? 0) + 1;
        }

        /** @var array<string, list<int>> $clusters */
        $clusters = [];
        $kindBuckets = [];
        foreach ($groups as $root => $ids) {
            sort($ids);
            $root = self::find($parent, $root);
            if (count($ids) > 1) {
                $key = isset($linked[$root]) ? 'link:' . $ids[0] : 'topic:' . $ids[0];
                $clusters[$key] = $ids;
                continue;
            }
            $kindBuckets[$byId[$ids[0]]['kind']][] = $ids[0];
        }
        foreach ($kindBuckets as $kind => $ids) {
            sort($ids);
            if (count($ids) >= 2) {
                $clusters['kind:' . $kind] = $ids;
                continue;
            }
            $clusters['page:' . $ids[0]] = $ids;
        }

        $centers = [];
        $locked = [];
        $kept = [];
        foreach ($clusters as $key => $ids) {
            $anchor = [];
            foreach ($ids as $id) {
                $prior = $saved[$id] ?? null;
                if ($prior !== null && (string) $prior['cluster_key'] === $key) {
                    $anchor[] = [(float) $prior['x'], (float) $prior['y']];
                    $kept[$id] = [(float) $prior['x'], (float) $prior['y']];
                }
            }
            if ($anchor !== []) {
                $locked[$key] = true;
                $centers[$key] = self::centroid($anchor);
            } else {
                $centers[$key] = self::seedCenter($key);
            }
        }
        $centers = self::relax($centers, $locked);

        $nodes = [];
        $dirty = [];
        $clusterMeta = [];
        foreach ($clusters as $key => $ids) {
            $center = $centers[$key];
            $placed = self::ring($ids, $center, $kept);
            $sumX = 0.0;
            $sumY = 0.0;
            foreach ($ids as $id) {
                $xy = $placed[$id];
                $sumX += $xy[0];
                $sumY += $xy[1];
                $nodes[] = [
                    'id'      => $id,
                    'title'   => $byId[$id]['title'],
                    'kind'    => $byId[$id]['kind'],
                    'x'       => $xy[0],
                    'y'       => $xy[1],
                    'cluster' => $key,
                ];
                if (!isset($kept[$id])) {
                    $dirty[] = [
                        'id'          => $id,
                        'cluster_key' => $key,
                        'x'           => $xy[0],
                        'y'           => $xy[1],
                    ];
                }
            }
            $n = max(1, count($ids));
            $clusterMeta[] = [
                'key'   => $key,
                'label' => self::label($key, $ids, $byId, $degree),
                'x'     => $sumX / $n,
                'y'     => $sumY / $n,
            ];
        }

        usort($nodes, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($clusterMeta, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return [
            'nodes'    => $nodes,
            'clusters' => $clusterMeta,
            'dirty'    => $dirty,
        ];
    }

    /**
     * @param array<int, int> $parent
     */
    private static function find(array &$parent, int $id): int
    {
        if ($parent[$id] !== $id) {
            $parent[$id] = self::find($parent, $parent[$id]);
        }
        return $parent[$id];
    }

    /**
     * Lower id stays the root so a cluster key does not depend on insert order.
     *
     * @param array<int, int> $parent
     */
    private static function unite(array &$parent, int $a, int $b): int
    {
        $ra = self::find($parent, $a);
        $rb = self::find($parent, $b);
        if ($ra === $rb) {
            return $ra;
        }
        if ($ra < $rb) {
            $parent[$rb] = $ra;
            return $ra;
        }
        $parent[$ra] = $rb;
        return $rb;
    }

    /**
     * @param array<int, int> $parent
     * @return list<int>
     */
    private static function singletonIds(array &$parent): array
    {
        $size = [];
        foreach (array_keys($parent) as $id) {
            $root = self::find($parent, $id);
            $size[$root] = ($size[$root] ?? 0) + 1;
        }
        $ids = [];
        foreach (array_keys($parent) as $id) {
            if ($size[self::find($parent, $id)] === 1) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param array<int, int> $parent
     * @param list<int> $singles
     * @param array<int, list<float>> $embeddings
     */
    private static function uniteSimilar(array &$parent, array $singles, array $embeddings): void
    {
        $pairs = [];
        $count = count($singles);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $singles[$i];
                $b = $singles[$j];
                if (!isset($embeddings[$a], $embeddings[$b])) {
                    continue;
                }
                $score = Vectors::cosine($embeddings[$a], $embeddings[$b]);
                if ($score >= self::SIMILAR) {
                    $pairs[] = [$score, $a, $b];
                }
            }
        }
        usort($pairs, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($pairs as $pair) {
            self::unite($parent, (int) $pair[1], (int) $pair[2]);
        }
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: float, 1: float}
     */
    private static function centroid(array $points): array
    {
        $x = 0.0;
        $y = 0.0;
        foreach ($points as $point) {
            $x += $point[0];
            $y += $point[1];
        }
        $n = max(1, count($points));
        return [$x / $n, $y / $n];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function seedCenter(string $key): array
    {
        $hash = crc32($key);
        if ($hash < 0) {
            $hash += 4294967296;
        }
        $angle = ($hash % 10000) / 10000 * 2 * M_PI;
        $radius = 0.38 + (($hash >> 9) % 1000) / 1000 * 0.5;
        return [cos($angle) * $radius, sin($angle) * $radius * 0.78];
    }

    /**
     * @param array<string, array{0: float, 1: float}> $centers
     * @param array<string, bool> $locked
     * @return array<string, array{0: float, 1: float}>
     */
    private static function relax(array $centers, array $locked): array
    {
        for ($step = 0; $step < 18; $step++) {
            $next = $centers;
            foreach ($centers as $key => $xy) {
                if (isset($locked[$key])) {
                    continue;
                }
                $x = $xy[0];
                $y = $xy[1];
                foreach ($centers as $other => $oxy) {
                    if ($other === $key) {
                        continue;
                    }
                    $dx = $x - $oxy[0];
                    $dy = $y - $oxy[1];
                    $dist = $dx * $dx + $dy * $dy;
                    if ($dist < 0.000001) {
                        $x += 0.06;
                        continue;
                    }
                    if ($dist < 0.28) {
                        $push = (0.28 - $dist) * 0.4;
                        $x += $dx * $push;
                        $y += $dy * $push;
                    }
                }
                $next[$key] = [self::clamp($x), self::clamp($y)];
            }
            $centers = $next;
        }
        return $centers;
    }

    /**
     * @param list<int> $ids
     * @param array{0: float, 1: float} $center
     * @param array<int, array{0: float, 1: float}> $kept
     * @return array<int, array{0: float, 1: float}>
     */
    private static function ring(array $ids, array $center, array $kept): array
    {
        $n = count($ids);
        $radius = min(0.22, 0.05 + max(0, $n - 1) * 0.03);
        $placed = [];
        $fresh = [];
        foreach ($ids as $id) {
            if (isset($kept[$id])) {
                $placed[$id] = $kept[$id];
            } else {
                $fresh[] = $id;
            }
        }
        if ($fresh === []) {
            return $placed;
        }
        if ($placed === []) {
            $i = 0;
            foreach ($fresh as $id) {
                $angle = (2 * M_PI * $i / $n) - M_PI / 2;
                $placed[$id] = [
                    self::clamp($center[0] + cos($angle) * $radius),
                    self::clamp($center[1] + sin($angle) * $radius),
                ];
                $i++;
            }
            return $placed;
        }
        foreach ($fresh as $id) {
            $placed[$id] = self::bestSlot($center, $radius, $placed);
        }
        return $placed;
    }

    /**
     * @param array{0: float, 1: float} $center
     * @param array<int, array{0: float, 1: float}> $placed
     * @return array{0: float, 1: float}
     */
    private static function bestSlot(array $center, float $radius, array $placed): array
    {
        $best = [$center[0], $center[1]];
        $bestScore = -1.0;
        for ($k = 0; $k < 20; $k++) {
            $angle = 2 * M_PI * $k / 20;
            $x = self::clamp($center[0] + cos($angle) * $radius);
            $y = self::clamp($center[1] + sin($angle) * $radius);
            $score = 10.0;
            foreach ($placed as $point) {
                $dx = $x - $point[0];
                $dy = $y - $point[1];
                $score = min($score, $dx * $dx + $dy * $dy);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$x, $y];
            }
        }
        return $best;
    }

    /**
     * @param list<int> $ids
     * @param array<int, array{id: int, title: string, kind: string}> $byId
     * @param array<int, int> $degree
     */
    private static function label(string $key, array $ids, array $byId, array $degree): string
    {
        if (str_starts_with($key, 'kind:')) {
            return ucfirst(substr($key, 5));
        }
        $best = $ids[0];
        $bestDegree = -1;
        foreach ($ids as $id) {
            $value = $degree[$id] ?? 0;
            if ($value > $bestDegree || ($value === $bestDegree && $id < $best)) {
                $best = $id;
                $bestDegree = $value;
            }
        }
        $title = trim($byId[$best]['title']);
        return $title === '' ? 'Untitled' : $title;
    }

    private static function clamp(float $value): float
    {
        return max(-1.15, min(1.15, $value));
    }
}
