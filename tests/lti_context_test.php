<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/system/Core/LtiLaunchContext.php';

use SLOCloud\Core\LtiAuthorizationException;
use SLOCloud\Core\LtiLaunchContext;

final class LtiContextFakeResult
{
    private int $offset = 0;

    public function __construct(private array $rows)
    {
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }

    public function fetch(): array|false
    {
        return $this->rows[$this->offset++] ?? false;
    }
}

final class LtiContextFakeDb
{
    public array $queries = [];

    public function __construct(private array $responses)
    {
    }

    public function query(string $sql, array $params = [], string $types = ''): LtiContextFakeResult
    {
        $this->queries[] = compact('sql', 'params', 'types');
        return new LtiContextFakeResult(array_shift($this->responses) ?? []);
    }
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$expectDenied = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (LtiAuthorizationException) {
    }
};

$sectionRow = static fn(int $pk, string $crn, int $courseFk, string $courseNumber): array => [
    'terms_pk' => 9,
    'banner_term' => '202633',
    'term_code' => 'FA26',
    'term_name' => 'Fall 2026',
    'sections_pk' => $pk,
    'section_id' => '01',
    'crn' => $crn,
    'course_fk' => $courseFk,
    'course_number' => $courseNumber,
    'course_name' => $courseNumber . ' title',
];

$regularDb = new LtiContextFakeDb([[$sectionRow(41, '12345', 7, 'ENG-101')]]);
$regular = LtiLaunchContext::resolve($regularDb, 'tbl_', [
    'lti_term_id' => '202633',
    'lti_crn' => '12345',
]);
$check($regular['term_pk'] === 9, 'Regular launch did not retain the immutable term PK.');
$check($regular['sections'][0]['section_pk'] === 41, 'Regular launch did not retain the section PK.');
$check(
    $regularDb->queries[0]['params'] === ['202633', '12345'],
    'Regular launch was not resolved by the signed term and CRN together.'
);

$xlistRows = [
    ['xlist_crn' => '12345'] + $sectionRow(41, '12345', 7, 'ENG-101'),
    ['xlist_crn' => '67890'] + $sectionRow(52, '67890', 8, 'HUM-101'),
];
$xlistDb = new LtiContextFakeDb([$xlistRows]);
$xlist = LtiLaunchContext::resolve($xlistDb, 'tbl_', [
    'lti_term_id' => '202633',
    'lti_crn' => 'XLS27.202633',
]);
$check($xlist['is_crosslisted'] === true && $xlist['xlist_group'] === '27', 'Cross-list group was not derived from the signed launch.');
$check(array_column($xlist['sections'], 'section_pk') === [41, 52], 'Cross-listed scope did not include every resolved section PK.');
$check($xlistDb->queries[0]['params'] === ['202633', '27'], 'Cross-list lookup was not bound to the signed term and group.');

$authorizedSloRow = [
    'sections_pk' => 41,
    'crn' => '12345',
    'course_fk' => 7,
    'terms_pk' => 9,
    'term_code' => 'FA26',
    'student_learning_outcomes_pk' => 81,
];
$xlistSloDb = new LtiContextFakeDb([[$authorizedSloRow]]);
LtiLaunchContext::authorizeSlo($xlistSloDb, 'tbl_', $xlist, 41, 81);
$check(
    $xlistSloDb->queries[0]['params'] === ['27', 41, '12345', 7, 9, 81]
        && str_contains($xlistSloDb->queries[0]['sql'], 'section_xlist'),
    'Cross-listed writes did not revalidate current group membership.'
);

$expectDenied(
    static fn() => LtiLaunchContext::resolve(new LtiContextFakeDb([]), 'tbl_', [
        'lti_term_id' => '202633',
        'lti_crn' => 'XLS27.202699',
    ]),
    'A cross-list launch whose embedded term differed from the signed term was accepted.'
);

$unresolved = $sectionRow(0, '', 0, '');
$unresolved['xlist_crn'] = '99999';
$expectDenied(
    static fn() => LtiLaunchContext::resolve(
        new LtiContextFakeDb([[$unresolved]]),
        'tbl_',
        ['lti_term_id' => '202633', 'lti_crn' => 'XLS27.202633']
    ),
    'A partially mapped cross-list group was accepted.'
);

$sloDb = new LtiContextFakeDb([[$authorizedSloRow]]);
LtiLaunchContext::authorizeSlo($sloDb, 'tbl_', $regular, 41, 81);
$check(
    $sloDb->queries[0]['params'] === [41, '12345', 7, 9, 81],
    'SLO authorization did not bind section, CRN, course, term, and SLO together.'
);
$expectDenied(
    static fn() => LtiLaunchContext::authorizeSlo(new LtiContextFakeDb([[]]), 'tbl_', $regular, 41, 999),
    'An SLO outside the section course was accepted.'
);
$expectDenied(
    static fn() => LtiLaunchContext::authorizeSlo(new LtiContextFakeDb([]), 'tbl_', $regular, 52, 81),
    'A section outside the launch context was accepted.'
);

$enrollmentDb = new LtiContextFakeDb([[[
    'enrollment_pk' => 101,
    'sections_pk' => 41,
    'crn' => '12345',
    'course_fk' => 7,
    'terms_pk' => 9,
    'term_code' => 'FA26',
    'student_learning_outcomes_pk' => 81,
]]]);
LtiLaunchContext::authorizeEnrollment($enrollmentDb, 'tbl_', $regular, 41, 81, 101);
$check(
    $enrollmentDb->queries[0]['params'] === [41, '12345', 7, 9, 81, 101],
    'Enrollment authorization did not bind the full launch relationship.'
);
$deniedEnrollmentDb = new LtiContextFakeDb([[]]);
$expectDenied(
    static fn() => LtiLaunchContext::authorizeEnrollment($deniedEnrollmentDb, 'tbl_', $regular, 41, 81, 202),
    'An enrollment outside the authorized section was accepted.'
);
$check(
    array_reduce(
        $deniedEnrollmentDb->queries,
        static fn(bool $clean, array $query): bool => $clean && (bool)preg_match('/^\s*SELECT\b/i', $query['sql']),
        true
    ),
    'A denied authorization attempted a database mutation.'
);

$assessmentSource = (string)file_get_contents(__DIR__ . '/../src/lti/assessment.php');
$launchSource = (string)file_get_contents(__DIR__ . '/../src/lti/launch.php');
$authorizationPosition = strpos($assessmentSource, 'LtiLaunchContext::authorizeEnrollment(');
$transactionPosition = strpos($assessmentSource, '$writeTransactionStarted = $db->beginTransaction();', $authorizationPosition ?: 0);
$check(
    $authorizationPosition !== false && $transactionPosition !== false && $authorizationPosition < $transactionPosition,
    'Assessment mutations can begin before enrollment authorization completes.'
);
$check(!str_contains($assessmentSource, "\$_POST['term_code']"), 'Assessment writes still trust a posted term code.');
$check(!str_contains($assessmentSource, "\$_POST['crn']"), 'Assessment writes still trust a posted CRN.');
$check(!str_contains($assessmentSource, "\$_POST['crns']"), 'Assessment writes still trust posted CRNs.');
$check(
    str_contains($launchSource, "stripos(\$crn, 'XLS') !== 0"),
    'A cross-list pseudo-CRN can be auto-created as an ordinary section.'
);
$expiredLaunchPosition = strpos($assessmentSource, "if (empty(\$launchData['lti_authenticated']))");
$check(
    $expiredLaunchPosition !== false
        && strpos($assessmentSource, 'http_response_code(403);', $expiredLaunchPosition) !== false,
    'A missing or stale launch context does not return HTTP 403.'
);

if ($failures !== []) {
    fwrite(STDERR, "LTI context tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "LTI context tests passed.\n";
