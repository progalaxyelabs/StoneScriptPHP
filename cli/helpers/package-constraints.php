<?php
/**
 * StoneScriptPHP CLI Helper — installed-package versions + a small version-constraint matcher.
 *
 * Deliberately dependency-free: the app's vendor/ does not necessarily contain composer/semver, and the
 * post-install / deps:check commands must work from a bare `vendor/` tree.
 *
 * Supported constraint syntax (a subset of Composer's, enough for an allow-list):
 *   *            any version        1.2.3        exact             ^1.2   ~1.2.3   ~1.2
 *   >=1.0 <2.0   AND (space/comma)  ^1.0 || ^2.0 OR                 1.2.*  wildcard
 * Anything it cannot parse does NOT match (fail closed). Non-numeric installed versions (dev-main) match only '*'
 * or an identical string.
 */

if (!function_exists('ssp_read_installed_packages')) {

    /**
     * Every package in vendor/composer/installed.json (composer v2 shape or the old flat list).
     *
     * @return list<array<string,mixed>>
     */
    function ssp_read_installed_packages(string $projectRoot): array
    {
        $file = $projectRoot . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $packages = $data['packages'] ?? $data;
        return is_array($packages) ? array_values(array_filter($packages, 'is_array')) : [];
    }

    /** @return array<string,string> package name => installed version (leading "v" stripped) */
    function ssp_installed_versions(string $projectRoot): array
    {
        $out = [];
        foreach (ssp_read_installed_packages($projectRoot) as $pkg) {
            if (is_string($pkg['name'] ?? null)) {
                $out[$pkg['name']] = ltrim((string) ($pkg['version'] ?? ''), 'vV');
            }
        }
        return $out;
    }

    /**
     * Parse a version into [major, minor, patch, stability rank, stability number], or null when it is not a
     * plain numeric version. Stability follows Composer: dev(0) < alpha(1) < beta(2) < RC(3) < stable(4) < patch(5),
     * so 2.0.0-beta sorts before 2.0.0. Build metadata after "+" is ignored.
     *
     * @return list<int>|null
     */
    function ssp_parse_version(string $v): ?array
    {
        $v = ltrim(trim($v), 'vV');
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?(?:[-._]?(dev|alpha|a|beta|b|RC|patch|pl|p)[.-]?(\d*))?(?:\+[0-9A-Za-z.+-]*)?$/i', $v, $m)) {
            return null;
        }
        $rank = 4;
        $num = 0;
        if (($m[4] ?? '') !== '') {
            $rank = match (strtolower($m[4])) {
                'dev' => 0,
                'alpha', 'a' => 1,
                'beta', 'b' => 2,
                'rc' => 3,
                default => 5,
            };
            $num = (int) ($m[5] ?? 0);
        }
        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), $rank, $num];
    }

    function ssp_cmp_version(array $a, array $b): int
    {
        return $a <=> $b;
    }

    function ssp_version_satisfies(string $installed, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '*') {
            return true;
        }
        if ($constraint === '') {
            return false;
        }
        $iv = ssp_parse_version($installed);
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $orPart) {
            if ($iv === null) {
                if (trim($orPart) === ltrim($installed, 'vV') && $installed !== '') {
                    return true;
                }
                continue;
            }
            $ok = true;
            $terms = preg_split('/\s*,\s*|\s+(?=[\^~<>=!\d*vV])/', trim($orPart), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // re-join "operator version" pairs split by whitespace, e.g. ">= 1.0"
            if ($terms === []) {
                $ok = false;
            }
            foreach ($terms as $term) {
                if (!ssp_term_satisfied($iv, $term)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    function ssp_term_satisfied(array $iv, string $term): bool
    {
        $term = trim($term);
        if ($term === '*') {
            return true;
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?\.[*xX]$/', $term, $m)) {
            if ($iv[0] !== (int) $m[1]) {
                return false;
            }
            return !isset($m[2]) || $m[2] === '' || $iv[1] === (int) $m[2];
        }
        if (!preg_match('/^(\^|~|>=|<=|>|<|=|==|!=)?\s*v?(\d+(?:\.\d+){0,3}(?:[-._]?[A-Za-z]+[.-]?\d*)?(?:\+[0-9A-Za-z.+-]*)?)$/', $term, $m)) {
            return false;
        }
        $op = $m[1] ?? '';
        $cv = ssp_parse_version($m[2]);
        if ($cv === null) {
            return false;
        }
        // Composer semantics: a bound written without a stability suffix on ^, ~, >= and < is a "-dev" bound,
        // so 2.0.0-beta satisfies ^2.0 and >=2.0 but not <2.0; = , > , <= and != compare exact stabilities.
        $hasSuffix = $cv[3] !== 4 || (bool) preg_match('/[A-Za-z]/', explode('+', $m[2])[0]);
        $dev = static fn (array $v): array => [$v[0], $v[1], $v[2], 0, 0];
        $lower = $hasSuffix ? $cv : $dev($cv);
        $parts = substr_count(preg_replace('/[-._]?[A-Za-z].*$/', '', $m[2]), '.') + 1;
        switch ($op) {
            case '':
            case '=':
            case '==':
                return $parts >= 3
                    ? $iv === $cv
                    : ssp_cmp_version($iv, $dev($cv)) >= 0 && ssp_cmp_version($iv, $dev($parts === 2 ? [$cv[0], $cv[1] + 1, 0] : [$cv[0] + 1, 0, 0])) < 0;
            case '!=':
                return $iv !== $cv;
            case '>=':
                return ssp_cmp_version($iv, $lower) >= 0;
            case '>':
                return ssp_cmp_version($iv, $cv) > 0;
            case '<=':
                return ssp_cmp_version($iv, $cv) <= 0;
            case '<':
                return ssp_cmp_version($iv, $lower) < 0;
            case '~':
                $upper = $parts >= 3 ? [$cv[0], $cv[1] + 1, 0] : [$cv[0] + 1, 0, 0];
                return ssp_cmp_version($iv, $lower) >= 0 && ssp_cmp_version($iv, $dev($upper)) < 0;
            case '^':
                if ($cv[0] > 0) {
                    $upper = [$cv[0] + 1, 0, 0];
                } elseif ($cv[1] > 0) {
                    $upper = [0, $cv[1] + 1, 0];
                } else {
                    $upper = [0, 0, $cv[2] + 1];
                }
                return ssp_cmp_version($iv, $lower) >= 0 && ssp_cmp_version($iv, $dev($upper)) < 0;
        }
        return false;
    }
}
