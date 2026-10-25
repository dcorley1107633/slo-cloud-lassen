<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use RuntimeException;

final class LtiAuthorizationException extends RuntimeException
{
}

/**
 * Resolves and enforces the database scope authorized by a signed LTI launch.
 */
final class LtiLaunchContext
{
    /**
     * Parse a tokenized format string against an actual value.
     *
     * Format example: "{CRN}.{TERM}" against "45581.202533"
     * Returns array of lowercased token names => extracted values.
     * Returns empty array if the value does not match the format.
     *
     * Rules:
     * - Delimiter characters are every character in the format outside of {TOKEN} blocks.
     * - Non-final tokens capture [^<delimiters>]+ (values must not contain delimiter chars).
     * - The final token captures everything remaining (.+).
     *
     * @param string $format e.g. "{CRN}.{TERM}"
     * @param string $value  e.g. "45581.202533"
     * @return array e.g. ['crn' => '45581', 'term' => '202533']
     */
    public static function parseTokenFormat(string $format, string $value): array
    {
        if (empty($format) || empty($value)) {
            return [];
        }

        preg_match_all('/\{([A-Z_]+)\}/', $format, $matches);
        $tokens = $matches[1];

        if (empty($tokens)) {
            return [];
        }

        // All characters outside token blocks are potential delimiters
        $delimString = preg_replace('/\{[A-Z_]+\}/', '', $format);
        $delimChars  = array_unique(array_filter(str_split($delimString), fn($c) => $c !== ''));

        $nonFinalGroup = empty($delimChars)
            ? '(.+?)'
            : '([^' . preg_quote(implode('', $delimChars), '/') . ']+)';

        $pattern = preg_quote($format, '/');
        $lastToken = end($tokens);
        foreach ($tokens as $token) {
            $replacement = ($token === $lastToken) ? '(.+)' : $nonFinalGroup;
            $pattern = str_replace(preg_quote('{' . $token . '}', '/'), $replacement, $pattern);
        }
        $pattern = '/^' . $pattern . '$/';

        if (!preg_match($pattern, $value, $m)) {
            return [];
        }

        $result = [];
        foreach ($tokens as $i => $token) {
            $result[strtolower($token)] = $m[$i + 1];
        }
        return $result;
    }

    /**
     * Read the CRN and term from an LMS offering identifier.
     *
     * XLS cross-listing is handled before the generic token parse: the optional
     * period is normalised away and the trailing 6-digit banner term extracted.
     * The full normalised value (e.g. XLS27202633) becomes the CRN for downstream
     * XLS detection.
     *
     * @return array{crn: ?string, term: ?string}
     */
    public static function parseOffering(string $format, string $raw): array
    {
        if ($raw !== '' && stripos($raw, 'XLS') === 0) {
            $normalized = str_replace('.', '', $raw);
            if (preg_match('/^XLS.+?(\d{6})$/', $normalized, $matches)) {
                return ['crn' => $normalized, 'term' => $matches[1]];
            }
            return ['crn' => null, 'term' => null];
        }

        $tokens = self::parseTokenFormat($format, $raw);
        return ['crn' => $tokens['crn'] ?? null, 'term' => $tokens['term'] ?? null];
    }

    public static function resolve(object $db, string $dbPrefix, array $launch): array
    {
        $bannerTerm = trim((string)($launch['lti_term_id'] ?? ''));
        $launchCrn = trim((string)($launch['lti_crn'] ?? ''));
        // The term is only known when lti.offering_format defines a {TERM}
        // token (or for XLS cross-listings, which embed it). Without one the
        // section is matched on CRN alone.
        if ($launchCrn === '') {
            throw new LtiAuthorizationException(sprintf(
                'The launch did not identify a course section (parsed CRN "%s", term "%s").',
                $launchCrn,
                $bannerTerm
            ));
        }

        $xlistGroup = self::crossListGroup($launchCrn, $bannerTerm);
        if ($xlistGroup !== null) {
            $result = $db->query(
                "SELECT t.terms_pk, t.banner_term, t.term_code, t.term_name, "
                . "sx.crn AS xlist_crn, s.sections_pk, s.section_id, s.crn, "
                . "s.course_fk, c.course_number, c.course_name "
                . "FROM {$dbPrefix}terms t "
                . "JOIN {$dbPrefix}section_xlist sx ON sx.term_fk = t.terms_pk "
                . "LEFT JOIN {$dbPrefix}sections s ON s.term_fk = t.terms_pk AND s.crn = sx.crn "
                . "LEFT JOIN {$dbPrefix}courses c ON c.courses_pk = s.course_fk "
                . "WHERE t.banner_term = ? AND sx.xlist_group = ? "
                . "ORDER BY sx.course_number ASC, sx.crn ASC",
                [$bannerTerm, $xlistGroup],
                'ss'
            );
            $rows = $result ? $result->fetchAll() : [];
            $sections = self::normalizeSections($rows, true);
        } else {
            $result = $db->query(
                "SELECT t.terms_pk, t.banner_term, t.term_code, t.term_name, "
                . "s.sections_pk, s.section_id, s.crn, s.course_fk, "
                . "c.course_number, c.course_name "
                . "FROM {$dbPrefix}sections s "
                . "JOIN {$dbPrefix}terms t ON t.terms_pk = s.term_fk "
                . "JOIN {$dbPrefix}courses c ON c.courses_pk = s.course_fk "
                . "WHERE " . ($bannerTerm !== '' ? "t.banner_term = ? AND " : '') . "s.crn = ?",
                $bannerTerm !== '' ? [$bannerTerm, $launchCrn] : [$launchCrn],
                $bannerTerm !== '' ? 'ss' : 's'
            );
            $rows = $result ? $result->fetchAll() : [];
            $sections = self::normalizeSections($rows, false);
        }

        if ($sections === []) {
            throw new LtiAuthorizationException(sprintf(
                $xlistGroup !== null
                    ? 'No sections found for cross-list group "%2$s" in term "%3$s" (launch CRN "%1$s").'
                    : ($bannerTerm !== ''
                        ? 'No section in the database matches CRN "%1$s" in banner term "%3$s".'
                        : 'No section in the database matches CRN "%1$s" (the launch carries no term).'),
                $launchCrn,
                (string)$xlistGroup,
                $bannerTerm
            ));
        }

        $first = $sections[0];
        return [
            'version' => 1,
            'term_pk' => $first['term_pk'],
            'banner_term' => $first['banner_term'],
            'term_code' => $first['term_code'],
            'is_crosslisted' => $xlistGroup !== null,
            'xlist_group' => $xlistGroup,
            'sections' => $sections,
            'resolved_at' => time(),
        ];
    }

    public static function section(array $context, int $sectionPk): array
    {
        foreach ($context['sections'] ?? [] as $section) {
            if ((int)($section['section_pk'] ?? 0) === $sectionPk) {
                return $section;
            }
        }
        throw new LtiAuthorizationException(sprintf(
            'Section %d is not part of this launch (launch sections: %s).',
            $sectionPk,
            implode(', ', array_map(static fn($x) => (string)($x['section_pk'] ?? '?'), $context['sections'] ?? [])) ?: 'none'
        ));
    }

    public static function authorizeSlo(
        object $db,
        string $dbPrefix,
        array $context,
        int $sectionPk,
        int $sloPk
    ): array {
        $section = self::section($context, $sectionPk);
        [$xlistJoin, $xlistParams, $xlistTypes] = self::crossListConstraint($context);
        $result = $db->query(
            "SELECT s.sections_pk, s.crn, s.course_fk, t.terms_pk, t.term_code, "
            . "slo.student_learning_outcomes_pk "
            . "FROM {$dbPrefix}sections s "
            . "JOIN {$dbPrefix}terms t ON t.terms_pk = s.term_fk "
            . str_replace('{prefix}', $dbPrefix, $xlistJoin)
            . "JOIN {$dbPrefix}student_learning_outcomes slo ON slo.course_fk = s.course_fk "
            . "WHERE s.sections_pk = ? AND s.crn = ? AND s.course_fk = ? "
            . "AND t.terms_pk = ? AND slo.student_learning_outcomes_pk = ?",
            array_merge($xlistParams, [
                $sectionPk,
                $section['crn'],
                $section['course_fk'],
                (int)$context['term_pk'],
                $sloPk,
            ]),
            $xlistTypes . 'isiii'
        );
        $row = $result ? $result->fetch() : false;
        if (!$row) {
            throw new LtiAuthorizationException(sprintf(
                'Outcome %d does not exist for course %s (section %d, CRN %s, term %s).',
                $sloPk,
                $section['course_number'] ?: ('ID ' . $section['course_fk']),
                $sectionPk,
                $section['crn'],
                $section['term_code']
            ));
        }
        return $row;
    }

    public static function authorizeEnrollment(
        object $db,
        string $dbPrefix,
        array $context,
        int $sectionPk,
        int $sloPk,
        int $enrollmentPk
    ): array {
        $section = self::section($context, $sectionPk);
        [$xlistJoin, $xlistParams, $xlistTypes] = self::crossListConstraint($context);
        $result = $db->query(
            "SELECT e.enrollment_pk, s.sections_pk, s.crn, s.course_fk, "
            . "t.terms_pk, t.term_code, slo.student_learning_outcomes_pk "
            . "FROM {$dbPrefix}sections s "
            . "JOIN {$dbPrefix}terms t ON t.terms_pk = s.term_fk "
            . str_replace('{prefix}', $dbPrefix, $xlistJoin)
            . "JOIN {$dbPrefix}student_learning_outcomes slo ON slo.course_fk = s.course_fk "
            . "JOIN {$dbPrefix}enrollment e ON e.section_fk = s.sections_pk "
            . "WHERE s.sections_pk = ? AND s.crn = ? AND s.course_fk = ? "
            . "AND t.terms_pk = ? AND slo.student_learning_outcomes_pk = ? "
            . "AND e.enrollment_pk = ?",
            array_merge($xlistParams, [
                $sectionPk,
                $section['crn'],
                $section['course_fk'],
                (int)$context['term_pk'],
                $sloPk,
                $enrollmentPk,
            ]),
            $xlistTypes . 'isiiii'
        );
        $row = $result ? $result->fetch() : false;
        if (!$row) {
            throw new LtiAuthorizationException(sprintf(
                'Enrollment %d is not a student enrolled in section %d (%s, CRN %s) for outcome %d.',
                $enrollmentPk,
                $sectionPk,
                $section['course_number'] ?: ('course ID ' . $section['course_fk']),
                $section['crn'],
                $sloPk
            ));
        }
        return $row;
    }

    private static function crossListGroup(string $launchCrn, string $bannerTerm): ?string
    {
        $normalized = str_replace('.', '', $launchCrn);
        if (stripos($normalized, 'XLS') !== 0) {
            return null;
        }
        if (!preg_match('/^XLS(.+)(\d{6})$/i', $normalized, $matches)
            || !hash_equals($bannerTerm, $matches[2])) {
            throw new LtiAuthorizationException(sprintf(
                'Cross-list launch CRN "%s" does not end in the launch term "%s".',
                $launchCrn,
                $bannerTerm
            ));
        }
        return $matches[1];
    }

    private static function crossListConstraint(array $context): array
    {
        if (empty($context['is_crosslisted'])) {
            return ['', [], ''];
        }
        $group = (string)($context['xlist_group'] ?? '');
        if ($group === '') {
            throw new LtiAuthorizationException('The cross-listed launch context is incomplete.');
        }
        return [
            'JOIN {prefix}section_xlist sx ON sx.term_fk = t.terms_pk '
                . 'AND sx.crn = s.crn AND sx.xlist_group = ? ',
            [$group],
            's',
        ];
    }

    private static function normalizeSections(array $rows, bool $crosslisted): array
    {
        if ($rows === []) {
            return [];
        }

        if (!$crosslisted && count($rows) > 1) {
            throw new LtiAuthorizationException(sprintf(
                'CRN "%s" matched %d sections in terms %s. Add {TERM} to lti.offering_format so the launch carries a term.',
                (string)($rows[0]['crn'] ?? ''),
                count($rows),
                implode(', ', array_unique(array_map(static fn($r) => (string)($r['banner_term'] ?? '?'), $rows)))
            ));
        }

        $sections = [];
        $seenCrns = [];
        foreach ($rows as $row) {
            $expectedCrn = (string)($row['xlist_crn'] ?? $row['crn'] ?? '');
            $sectionPk = (int)($row['sections_pk'] ?? 0);
            if ($expectedCrn === '' || $sectionPk <= 0 || (string)($row['crn'] ?? '') !== $expectedCrn) {
                throw new LtiAuthorizationException(sprintf(
                    'Cross-listed CRN "%s" has no matching section row in the database.',
                    $expectedCrn
                ));
            }
            if (isset($seenCrns[$expectedCrn])) {
                throw new LtiAuthorizationException(sprintf('CRN "%s" matched more than one section.', $expectedCrn));
            }
            $seenCrns[$expectedCrn] = true;
            $sections[] = [
                'section_pk' => $sectionPk,
                'section_id' => (string)$row['section_id'],
                'crn' => $expectedCrn,
                'course_fk' => (int)$row['course_fk'],
                'course_number' => (string)($row['course_number'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'term_pk' => (int)$row['terms_pk'],
                'banner_term' => (string)$row['banner_term'],
                'term_code' => (string)$row['term_code'],
                'term_name' => (string)($row['term_name'] ?? $row['term_code']),
            ];
        }

        if (!$crosslisted && count($sections) !== 1) {
            throw new LtiAuthorizationException(sprintf('The launch matched %d sections; expected exactly 1.', count($sections)));
        }
        return $sections;
    }
}
