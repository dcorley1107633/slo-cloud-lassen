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
    public static function resolve(object $db, string $dbPrefix, array $launch): array
    {
        $bannerTerm = trim((string)($launch['lti_term_id'] ?? ''));
        $launchCrn = trim((string)($launch['lti_crn'] ?? ''));
        if ($bannerTerm === '' || $launchCrn === '') {
            throw new LtiAuthorizationException('The launch did not identify a course section.');
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
                . "WHERE t.banner_term = ? AND s.crn = ?",
                [$bannerTerm, $launchCrn],
                'ss'
            );
            $rows = $result ? $result->fetchAll() : [];
            $sections = self::normalizeSections($rows, false);
        }

        if ($sections === []) {
            throw new LtiAuthorizationException('The launched section is not available.');
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
        throw new LtiAuthorizationException('The requested section is outside this launch.');
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
            throw new LtiAuthorizationException('The requested outcome is outside this launch.');
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
            throw new LtiAuthorizationException('The requested enrollment is outside this launch.');
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
            throw new LtiAuthorizationException('The cross-listed launch context is invalid.');
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

        $sections = [];
        $seenCrns = [];
        foreach ($rows as $row) {
            $expectedCrn = (string)($row['xlist_crn'] ?? $row['crn'] ?? '');
            $sectionPk = (int)($row['sections_pk'] ?? 0);
            if ($expectedCrn === '' || $sectionPk <= 0 || (string)($row['crn'] ?? '') !== $expectedCrn) {
                throw new LtiAuthorizationException('The launch contains an unresolved section.');
            }
            if (isset($seenCrns[$expectedCrn])) {
                throw new LtiAuthorizationException('The launch contains an ambiguous section.');
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
            throw new LtiAuthorizationException('The launched section is ambiguous.');
        }
        return $sections;
    }
}
