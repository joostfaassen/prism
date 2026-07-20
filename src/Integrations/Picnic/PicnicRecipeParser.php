<?php

namespace App\Integrations\Picnic;

/**
 * Best-effort parser for Picnic cookbook / selling-group recipe Fusion pages.
 * Ported from the mcp-picnic recipe-parser approach (MIT).
 */
class PicnicRecipeParser
{
    private const SKIP_KEYS = [
        'analytics',
        'tracking_attributes',
        'loadingConfig',
        'errorConfig',
        'placeholder',
        'fallbackSource',
        'presets',
        'viewabilityListeners',
    ];

    private const RECIPE_ID_RE = '/^[a-f0-9]{24,32}$/';
    private const ANY_ID_REF_RE = '/(?:selling_group_id|recipe_id|recipeIds)=([a-f0-9]{24,32})/';
    private const ID_PARAM_RE = '/(?:selling_group_id|recipe_id)=([a-f0-9]{24,32})/';
    private const RECIPE_NAME_CONTEXT_RE = '/"recipe_id"\s*:\s*"([a-f0-9]{24,32})"[\s\S]{0,160}?"recipe_name"\s*:\s*"([^"]+)"/';

    /**
     * @return list<array{id: string, title: string|null, image_url: string|null, segments: list<string>}>
     */
    public static function parseRecipeList(mixed $page, string $imageBaseUrl): array
    {
        $segById = self::collectSegmentsById($page);
        $tileInfo = self::collectTileInfo($page, $imageBaseUrl);
        $names = self::collectRecipeNames($page);
        $out = [];
        $seen = [];

        foreach ($segById as $id => $segs) {
            $seen[$id] = true;
            $info = $tileInfo[$id] ?? null;
            $out[] = [
                'id' => $id,
                'title' => $info['name'] ?? $names[$id] ?? null,
                'image_url' => $info['image_url'] ?? null,
                'segments' => array_values($segs),
            ];
        }

        foreach ($tileInfo as $id => $info) {
            if (isset($seen[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'title' => $info['name'] ?? $names[$id] ?? null,
                'image_url' => $info['image_url'] ?? null,
                'segments' => [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseRecipeDetails(mixed $page, string $recipeId, string $imageBaseUrl, string $countryCode): array
    {
        $struct = self::collectStructured($page);
        $imageUrl = self::buildImageUrl($struct['image_id'], $struct['image_namespace'], $imageBaseUrl);

        $result = [
            'id' => $recipeId,
            'selling_group_id' => $recipeId,
            'title' => $struct['name'],
            'description' => $struct['description'],
            'quality_cue' => $struct['quality_cue'],
            'portions' => $struct['portions'],
            'servings' => $struct['portions'] !== null ? (string) $struct['portions'] : null,
            'prep_time' => null,
            'total_time' => null,
            'image_id' => $struct['image_id'],
            'image_url' => $imageUrl,
            'image_urls' => $imageUrl !== null ? [
                'medium' => self::buildImageUrl($struct['image_id'], $struct['image_namespace'], $imageBaseUrl, 'medium'),
                'large' => $imageUrl,
            ] : [],
            'is_saved' => $struct['is_saved'],
            'source_url' => self::buildRecipeSourceUrl($countryCode, $recipeId),
            'ingredients' => [],
            'pantry_ingredients' => [],
            'tools' => [],
            'tips' => [],
            'instructions' => [],
            'parse_warning' => null,
        ];

        $tokens = PicnicFusion::collectMarkdownLines($page, self::SKIP_KEYS);
        self::fillTextSections($result, $tokens);

        if ($result['title'] === null && $result['ingredients'] === [] && $result['instructions'] === []) {
            $result['parse_warning'] = 'Could not extract structured recipe fields from Fusion page; layout may have changed.';
        } elseif ($result['ingredients'] === []) {
            $result['parse_warning'] = 'No ingredients extracted; open source_url or inspect the page layout.';
        }

        return $result;
    }

    public static function resolveRecipeId(string $input): string
    {
        $trimmed = trim($input);
        if (preg_match(self::RECIPE_ID_RE, $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match(self::ID_PARAM_RE, $trimmed, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/\/([a-f0-9]{24,32})(?:[\/?#]|$)/', $trimmed, $m) === 1) {
            return $m[1];
        }

        throw new \InvalidArgumentException(sprintf(
            'Cannot extract a Picnic recipe id from: %s',
            $trimmed,
        ));
    }

    public static function buildRecipeSourceUrl(string $countryCode, string $recipeId): string
    {
        $segment = match (strtoupper($countryCode)) {
            'DE' => 'rezepte',
            'FR' => 'recettes',
            default => 'recepten',
        };

        return sprintf('https://picnic.app/%s/%s/%s', strtolower($countryCode), $segment, $recipeId);
    }

    /**
     * @return array{name: ?string, description: ?string, quality_cue: ?string, image_id: ?string, image_namespace: ?string, portions: ?int, is_saved: ?bool}
     */
    private static function collectStructured(mixed $page): array
    {
        $out = [
            'name' => null,
            'description' => null,
            'quality_cue' => null,
            'image_id' => null,
            'image_namespace' => null,
            'portions' => null,
            'is_saved' => null,
        ];

        PicnicFusion::walk($page, static function (array $n) use (&$out): void {
            $title = $n['selling_group_title_section_data'] ?? null;
            if (is_array($title)) {
                if (is_string($title['name'] ?? null)) {
                    $out['name'] ??= $title['name'];
                }
                if (is_string($title['description'] ?? null)) {
                    $out['description'] ??= $title['description'];
                }
                if (is_string($title['quality_cue'] ?? null)) {
                    $out['quality_cue'] ??= $title['quality_cue'];
                }
            }

            $header = $n['selling_group_header_data'] ?? null;
            if (is_array($header)) {
                if (is_bool($header['is_saved'] ?? null)) {
                    $out['is_saved'] ??= $header['is_saved'];
                }
                if (is_string($header['sellable_name'] ?? null)) {
                    $out['name'] ??= $header['sellable_name'];
                }
                if ($out['portions'] === null && is_int($header['default_portions'] ?? null)) {
                    $out['portions'] = $header['default_portions'];
                }
            }

            $image = $n['selling_group_image_data'] ?? null;
            if (is_array($image)) {
                $imgs = $image['images']['images'] ?? null;
                if (is_array($imgs)) {
                    $primary = null;
                    foreach ($imgs as $img) {
                        if (is_array($img) && ($img['primary'] ?? false)) {
                            $primary = $img;
                            break;
                        }
                    }
                    $primary ??= is_array($imgs[0] ?? null) ? $imgs[0] : null;
                    if (is_array($primary)) {
                        if (is_string($primary['id'] ?? null)) {
                            $out['image_id'] ??= $primary['id'];
                        }
                        if (is_string($primary['namespace'] ?? null)) {
                            $out['image_namespace'] ??= $primary['namespace'];
                        }
                    }
                }
            }

            if (is_int($n['portions'] ?? null)) {
                $out['portions'] = $n['portions'];
            }
        });

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $tokens
     */
    private static function fillTextSections(array &$result, array $tokens): void
    {
        $ingredientHeader = '/^(zutaten|ingredi[eë]nten|ingr[eé]dients?)$/iu';
        $instructionHeader = '/^(zubereitung(sschritte)?|anleitung|so wird.?s gemacht|bereiding(swijze)?|zo maak je het|pr[eé]paration|instructions?)$/iu';
        $stepLabel = '/^(schritt|stap|[ée]tape|step)\s*\d+$/iu';
        $tipHeader = '/^(tipps?|tips?|astuces?)$/iu';
        $timeRe = '/\b\d+\s*min\b/iu';
        $totalTimeRe = '/\b(gesamt|insgesamt|total|totaal)\b/iu';
        $servingsValue = '/^\d+\s*(portion(en|s)?|porties|personen|personnes?|pers\.?)$/iu';
        $servingsRe = '/\b(portion(en|s)?|porties|personen|personnes?)\b|\b\d+\s*pers\b/iu';
        $numberOnly = '/^\d+[.)]?$/';
        $pantryLine = '/^(eigene zutaten|eigen recept|own ingredients|propres ingr[ée]dients?)\s*:\s*(.+)$/iu';
        $toolsLine = '/^(du ben[öo]tigst|je hebt nodig|you.?ll need|tu auras besoin)\s*:\s*(.+)$/iu';
        $buttons = ['ansehen', 'hinzufügen', 'view', 'add', 'bekijken', 'toevoegen', 'voir', 'ajouter'];

        $ingredientStart = -1;
        $tipStart = -1;
        $stepIdx = [];
        foreach ($tokens as $i => $t) {
            if ($ingredientStart < 0 && preg_match($ingredientHeader, $t) === 1) {
                $ingredientStart = $i;
            }
            if (preg_match($stepLabel, $t) === 1) {
                $stepIdx[] = $i;
            }
            if ($tipStart < 0 && preg_match($tipHeader, $t) === 1) {
                $tipStart = $i;
            }
        }

        $instructionStart = -1;
        foreach ($tokens as $i => $t) {
            if (preg_match($instructionHeader, $t) === 1 && ($ingredientStart < 0 || $i > $ingredientStart)) {
                $instructionStart = $i;
                break;
            }
        }

        $metaEnd = $instructionStart >= 0 ? $instructionStart : ($stepIdx[0] ?? count($tokens));
        for ($i = 0; $i < $metaEnd; $i++) {
            $t = $tokens[$i];
            if (preg_match($timeRe, $t) !== 1) {
                continue;
            }
            $next = $tokens[$i + 1] ?? '';
            if (preg_match($totalTimeRe, $t) === 1 || preg_match($totalTimeRe, $next) === 1) {
                $result['total_time'] ??= $t;
            } else {
                $result['prep_time'] ??= $t;
            }
        }

        foreach ($tokens as $t) {
            if (preg_match($servingsValue, $t) === 1) {
                $result['servings'] = $t;
                break;
            }
        }

        $ingEnd = $instructionStart >= 0 ? $instructionStart : count($tokens);
        if ($ingredientStart >= 0) {
            for ($i = $ingredientStart + 1; $i < $ingEnd; $i++) {
                $t = $tokens[$i];
                if (preg_match($numberOnly, $t) === 1 || strlen($t) < 2 || in_array(mb_strtolower($t), $buttons, true)) {
                    continue;
                }
                if (preg_match($pantryLine, $t, $m) === 1) {
                    foreach (array_filter(array_map('trim', explode(',', $m[2]))) as $part) {
                        $result['pantry_ingredients'][] = $part;
                    }
                    continue;
                }
                if (preg_match($toolsLine, $t, $m) === 1) {
                    foreach (array_filter(array_map('trim', explode(',', $m[2]))) as $part) {
                        $result['tools'][] = $part;
                    }
                    continue;
                }
                if (preg_match($servingsRe, $t) === 1) {
                    continue;
                }
                $result['ingredients'][] = $t;
            }
        }

        if ($stepIdx !== []) {
            $bounds = array_merge($stepIdx, [$tipStart >= 0 ? $tipStart : count($tokens), count($tokens)]);
            foreach ($stepIdx as $si) {
                $nextCandidates = array_values(array_filter($bounds, static fn (int $b): bool => $b > $si));
                $next = $nextCandidates === [] ? count($tokens) : min($nextCandidates);
                $body = [];
                for ($k = $si + 1; $k < $next; $k++) {
                    $t = $tokens[$k];
                    if (
                        preg_match($stepLabel, $t) === 1
                        || preg_match($servingsRe, $t) === 1
                        || preg_match($numberOnly, $t) === 1
                        || strlen($t) < 2
                        || in_array(mb_strtolower($t), $buttons, true)
                    ) {
                        continue;
                    }
                    $body[] = $t;
                }
                if ($body !== []) {
                    $result['instructions'][] = implode(' ', $body);
                }
            }
        } elseif ($instructionStart >= 0) {
            for ($k = $instructionStart + 1; $k < count($tokens); $k++) {
                $t = $tokens[$k];
                if (preg_match($tipHeader, $t) === 1) {
                    break;
                }
                if (
                    preg_match($servingsRe, $t) === 1
                    || preg_match($numberOnly, $t) === 1
                    || preg_match($stepLabel, $t) === 1
                    || strlen($t) < 2
                    || in_array(mb_strtolower($t), $buttons, true)
                ) {
                    continue;
                }
                $result['instructions'][] = $t;
            }
        }

        if ($tipStart >= 0) {
            for ($k = $tipStart + 1; $k < count($tokens); $k++) {
                $t = $tokens[$k];
                if (strlen($t) >= 2 && !in_array(mb_strtolower($t), $buttons, true)) {
                    $result['tips'][] = $t;
                }
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function collectSegmentsById(mixed $page): array
    {
        $map = [];
        $visit = function (mixed $n) use (&$visit, &$map): void {
            if (is_array($n)) {
                if (!PicnicFusion::isList($n) && array_key_exists('analytics', $n)) {
                    $json = json_encode($n['analytics'], JSON_UNESCAPED_UNICODE) ?: '';
                    if (preg_match('/"segment_type"\s*:\s*"([^"]+)"/', $json, $seg) === 1) {
                        $blob = json_encode($n, JSON_UNESCAPED_UNICODE) ?: '';
                        if (preg_match_all(self::ANY_ID_REF_RE, $blob, $ids) > 0) {
                            foreach ($ids[1] as $id) {
                                $map[$id][$seg[1]] = $seg[1];
                            }
                        }

                        return;
                    }
                }
                foreach ($n as $key => $child) {
                    if ($key === 'analytics' || in_array($key, self::SKIP_KEYS, true)) {
                        continue;
                    }
                    $visit($child);
                }
            }
        };
        $visit($page);

        return array_map(static fn (array $segs): array => array_values($segs), $map);
    }

    /**
     * @return array<string, array{name: ?string, image_url: ?string}>
     */
    private static function collectTileInfo(mixed $page, string $imageBaseUrl): array
    {
        $info = [];
        $visit = function (mixed $n) use (&$visit, &$info, $imageBaseUrl): void {
            if (!is_array($n)) {
                return;
            }
            if (!PicnicFusion::isList($n) && array_key_exists('analytics', $n) && self::hasRecipeTileAnalytics($n['analytics'])) {
                $recipeId = self::findLinkedRecipeId($n, 0);
                if ($recipeId !== null && !isset($info[$recipeId])) {
                    $img = self::firstImageIn($n);
                    $info[$recipeId] = [
                        'name' => self::firstRecipeNameIn($n),
                        'image_url' => $img !== null
                            ? self::buildImageUrl($img['id'], $img['namespace'], $imageBaseUrl)
                            : null,
                    ];
                }

                return;
            }
            foreach ($n as $key => $child) {
                if (in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $visit($child);
            }
        };
        $visit($page);

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private static function collectRecipeNames(mixed $page): array
    {
        $map = [];
        $visit = function (mixed $n) use (&$visit, &$map): void {
            if (is_string($n)) {
                if (preg_match_all(self::RECIPE_NAME_CONTEXT_RE, $n, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $m) {
                        $map[$m[1]] ??= $m[2];
                    }
                }

                return;
            }
            if (!is_array($n)) {
                return;
            }
            if (!PicnicFusion::isList($n)
                && is_string($n['recipe_id'] ?? null)
                && is_string($n['recipe_name'] ?? null)
            ) {
                $map[$n['recipe_id']] ??= $n['recipe_name'];
            }
            foreach ($n as $child) {
                $visit($child);
            }
        };
        $visit($page);

        return $map;
    }

    private static function hasRecipeTileAnalytics(mixed $node): bool
    {
        if (is_array($node)) {
            if (!PicnicFusion::isList($node)) {
                $type = $node['type'] ?? null;
                if ($type === 'recipe_tile' || $type === 'recipe_tile_mini') {
                    return true;
                }
            }
            foreach ($node as $child) {
                if (self::hasRecipeTileAnalytics($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function findLinkedRecipeId(mixed $node, int $depth): ?string
    {
        if ($depth > 8 || $node === null) {
            return null;
        }
        if (is_string($node)) {
            return preg_match(self::ID_PARAM_RE, $node, $m) === 1 ? $m[1] : null;
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                $id = self::findLinkedRecipeId($child, $depth + 1);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    private static function firstRecipeNameIn(mixed $node): ?string
    {
        $labels = ['hinzufügen', 'nicht alles vorrätig', 'add', 'toevoegen', 'ajouter'];
        $best = null;
        $visit = function (mixed $n) use (&$visit, &$best, $labels): void {
            if (!is_array($n)) {
                return;
            }
            if (!PicnicFusion::isList($n) && ($n['type'] ?? null) === 'RICH_TEXT' && is_string($n['markdown'] ?? null)) {
                foreach (preg_split('/\R/', (string) $n['markdown']) ?: [] as $raw) {
                    $v = PicnicFusion::cleanLine($raw);
                    if (
                        strlen($v) > 6 && strlen($v) <= 90
                        && !in_array(mb_strtolower($v), $labels, true)
                        && !preg_match('/\b\d+\s*min\b/iu', $v)
                        && !preg_match('/\b(portion|porties|personen)\b/iu', $v)
                        && ($best === null || strlen($v) > strlen($best))
                    ) {
                        $best = $v;
                    }
                }
            }
            foreach ($n as $key => $child) {
                if ($key === 'onPress' || in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $visit($child);
            }
        };
        $visit($node);

        return $best;
    }

    /**
     * @return array{id: string, namespace: ?string}|null
     */
    private static function firstImageIn(mixed $node): ?array
    {
        $found = null;
        $visit = function (mixed $n) use (&$visit, &$found): void {
            if ($found !== null || !is_array($n)) {
                return;
            }
            if (!PicnicFusion::isList($n) && ($n['type'] ?? null) === 'IMAGE' && is_array($n['source'] ?? null) && is_string($n['source']['id'] ?? null)) {
                $found = [
                    'id' => $n['source']['id'],
                    'namespace' => is_string($n['source']['namespace'] ?? null) ? $n['source']['namespace'] : null,
                ];

                return;
            }
            foreach ($n as $key => $child) {
                if ($key === 'onPress' || in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $visit($child);
            }
        };
        $visit($node);

        return $found;
    }

    private static function buildImageUrl(?string $imageId, ?string $namespace, string $imageBaseUrl, string $size = 'large'): ?string
    {
        if ($imageId === null || $imageId === '' || $imageBaseUrl === '') {
            return null;
        }
        $path = ($namespace && !str_contains($imageId, '/')) ? $namespace . '/' . $imageId : $imageId;

        return rtrim($imageBaseUrl, '/') . '/' . $path . '/' . $size . '.png';
    }
}
