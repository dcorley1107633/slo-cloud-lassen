<?php
declare(strict_types=1);

// Imports may legitimately exceed PHP's normal request limit. IIS FastCGI
// still supplies the deployment-level safety ceiling for a stuck request.
set_time_limit(0);

/**
 * Term-Centric Import - Normalized Paradigm
 * 
 * Imports term-centric data using the NORMALIZED paradigm (12 separate files).
 * Files must be imported in dependency order: Term → Department → ISLO → Program → PSLO → 
 * Course → Program-Course Mapping → CSLO → CSLO-PSLO Mapping → Section → Enrollment → Cross-Listing
 * 
 * See: docs/term_centric_schema.yaml for complete documentation
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\StudentEncryption;

$successMessage = '';
$errorMessage = '';
$importStats = [];
$activeFile = 1; // Default to File 1 (Term)

/** Import duration in words, e.g. "0.4 seconds", "38 seconds", "2 minutes 5 seconds". */
function formatImportElapsed(float $seconds): string
{
    if ($seconds < 10) {
        return number_format($seconds, 1) . ' seconds';
    }
    $total = (int)round($seconds);
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $secs = $total % 60;
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }
    if ($secs > 0 || $parts === []) {
        $parts[] = $secs . ($secs === 1 ? ' second' : ' seconds');
    }
    return implode(' ', $parts);
}

/**
 * Recent import timings, kept on this server in <data>/var/import_timings.json
 * so the progress window can say how long an import usually takes.
 * Shape: {"<file_num>": [{"bytes": int, "rows": int, "seconds": float, "at": int}, ...]}
 */
const IMPORT_TIMING_SAMPLES = 20;

function importTimingsPath(): string
{
    return \SLOCloud\Core\Security::dataRoot(dirname(__DIR__)) . '/var/import_timings.json';
}

function readImportTimings(): array
{
    $path = importTimingsPath();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

/** Adds one successful import's timing; a timing that cannot be saved never fails the import. */
function recordImportTiming(int $fileNum, int $bytes, int $rows, float $seconds): void
{
    try {
        $path = importTimingsPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $timings = is_array($decoded) ? $decoded : [];
            $key = (string)$fileNum;
            $samples = is_array($timings[$key] ?? null) ? $timings[$key] : [];
            $samples[] = ['bytes' => $bytes, 'rows' => $rows, 'seconds' => round($seconds, 3), 'at' => time()];
            $timings[$key] = array_slice($samples, -IMPORT_TIMING_SAMPLES);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode($timings));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    } catch (\Throwable $e) {
        error_log('Import timing not recorded: ' . $e->getMessage());
    }
}

/** Per file number: seconds per byte across recent imports, for the progress window's estimate. */
function importTimingEstimates(): array
{
    $estimates = [];
    foreach (readImportTimings() as $fileNum => $samples) {
        if (!is_array($samples)) {
            continue;
        }
        $bytes = 0;
        $seconds = 0.0;
        $count = 0;
        foreach ($samples as $sample) {
            $b = (int)($sample['bytes'] ?? 0);
            $t = (float)($sample['seconds'] ?? 0);
            if ($b <= 0 || $t <= 0) {
                continue;
            }
            $bytes += $b;
            $seconds += $t;
            $count++;
        }
        if ($count === 0) {
            continue;
        }
        $estimates[(int)$fileNum] = [
            'secondsPerByte' => $seconds / $bytes,
            'samples' => $count,
        ];
    }
    return $estimates;
}

// Initialize student encryption
$studentEncryption = new StudentEncryption($config);

/**
 * Extract department code from program_code
 * Format: DEGREE_DEPARTMENT (e.g., "AS_ACCT" → "ACCT")
 */
function extractDeptFromProgram(string $programCode): string {
    $parts = explode('_', $programCode, 2);
    return isset($parts[1]) ? $parts[1] : $programCode;
}

/**
 * Extract department code from course_code  
 * Format: "DEPT CXXX" (e.g., "ACCT C100" → "ACCT")
 */
function extractDeptFromCourse(string $courseCode): string {
    $parts = explode(' ', trim($courseCode), 2);
    return $parts[0];
}

/**
 * Normalize text from CSV to valid UTF-8 and replace punctuation that commonly
 * arrives in Windows-1252 exports. This prevents SQL Server UCS-2 translation
 * failures while preserving ordinary accented Unicode characters.
 */
function normalizeCsvText(string $text): string {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

    // fgetcsv returns raw bytes. Banner/Excel exports may be Windows-1252
    // even when their extension is .csv, so convert invalid UTF-8 first.
    if (preg_match('//u', $text) !== 1 && function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = strtr($text, [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{00AB}" => '"', "\u{00BB}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
        "\u{2014}" => '--', "\u{2212}" => '-', "\u{2026}" => '...',
        "\u{00A0}" => ' ', "\u{200B}" => '', "\u{FEFF}" => '',
    ]);

    // ODBC's UCS-2 conversion cannot represent supplementary-plane symbols.
    $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text) ?? $text;
    $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $text) ?? $text;

    return trim($text);
}

/**
 * Normalize a CSV date value to YYYY-MM-DD. Institutional exports use
 * US-style M/D/YYYY (e.g., "1/1/2026") which DATE columns reject under
 * strict SQL modes. Returns '' when the value cannot be parsed.
 */
function normalizeCsvDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : '';
}

/**
 * Extract program code from PSLO code
 * Format: "DEGREE_DEPT_PSLO#" (e.g., "AS_ACCT_PSLO1" → "AS_ACCT")
 */
function extractProgramFromPslo(string $psloCode): string {
    // Find the last occurrence of "_PSLO" and extract everything before it
    $psloPos = strrpos($psloCode, '_PSLO');
    if ($psloPos !== false) {
        return substr($psloCode, 0, $psloPos);
    }
    return $psloCode; // fallback if format doesn't match
}

/**
 * Get or auto-create term by term_code
 * Returns term PK if found/created, null if not found and auto-create disabled
 */
function getOrCreateTermFk($db, $dbPrefix, string $termCode, bool $autoCreate, ?int $userId): ?int {
    $termResult = $db->query(
        "SELECT terms_pk, is_active FROM {$dbPrefix}terms WHERE banner_term = ? OR term_code = ?",
        [$termCode, $termCode],
        'ss'
    );
    $term = $termResult->fetch();
    if ($term !== false) {
        if (!(bool)$term['is_active']) return null;
        return (int)$term['terms_pk'];
    }
    
    if ($autoCreate) {
        try {
            // Auto-create missing term with placeholder dates
            $db->query(
                "INSERT INTO {$dbPrefix}terms (term_code, banner_term, term_name, start_date, end_date, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, {$db->curdate()}, {$db->dateAddDays(120)}, {$db->now()}, {$db->now()}, ?, ?)",
                [$termCode, $termCode, "Auto-created: {$termCode}", $userId, $userId],
                'sssii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateTermFk: Failed to create term '{$termCode}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create discipline (department) by discipline_code and term
 * Recursively creates Term if needed
 */
function getOrCreateDisciplineFk($db, $dbPrefix, string $deptCode, string $termCode, bool $autoCreate, ?int $userId): ?int {
    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreate, $userId);
    if ($termFk === null) {
        return null;
    }

    $deptResult = $db->query(
        "SELECT disciplines_pk, is_active FROM {$dbPrefix}disciplines WHERE discipline_code = ? AND term_fk = ?",
        [$deptCode, $termFk],
        'si'
    );
    $dept = $deptResult->fetch();
    if ($dept !== false) {
        if (!(bool)$dept['is_active']) return null;
        return (int)$dept['disciplines_pk'];
    }
    
    if ($autoCreate) {
        try {
            // Auto-create missing discipline
            $db->query(
                "INSERT INTO {$dbPrefix}disciplines (discipline_code, discipline_name, term_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$deptCode, "Auto-created: {$deptCode}", $termFk, $userId, $userId],
                'ssiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateDisciplineFk: Failed to create discipline '{$deptCode}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create program by program_code and term
 * Recursively creates Term and Discipline if needed
 */
function getOrCreateProgramFk($db, $dbPrefix, string $programCode, string $termCode, string $deptCode, bool $autoCreate, ?int $userId): ?int {
    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreate, $userId);
    if ($termFk === null) {
        return null;
    }

    $progResult = $db->query(
        "SELECT programs_pk, is_active FROM {$dbPrefix}programs WHERE program_code = ? AND term_fk = ?",
        [$programCode, $termFk],
        'si'
    );
    $prog = $progResult->fetch();
    if ($prog !== false) {
        if (!(bool)$prog['is_active']) return null;
        return (int)$prog['programs_pk'];
    }
    
    if ($autoCreate) {
        // Recursively ensure discipline exists (which also ensures term exists)
        $disciplineFk = getOrCreateDisciplineFk($db, $dbPrefix, $deptCode, $termCode, true, $userId);
        if ($disciplineFk === null) {
            return null;
        }
        
        try {
            // Auto-create missing program
            $db->query(
                "INSERT INTO {$dbPrefix}programs (program_code, program_name, discipline_fk, term_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$programCode, "Auto-created: {$programCode}", $disciplineFk, $termFk, $userId, $userId],
                'ssiiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateProgramFk: Failed to create program '{$programCode}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create course by course_number and term
 * Recursively creates Discipline and Term if needed
 */
function getOrCreateCourseFk($db, $dbPrefix, string $courseNumber, string $deptCode, string $termCode, bool $autoCreate, ?int $userId): ?int {
    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreate, $userId);
    if ($termFk === null) {
        return null;
    }

    $courseResult = $db->query(
        "SELECT courses_pk, is_active FROM {$dbPrefix}courses WHERE course_number = ? AND term_fk = ?",
        [$courseNumber, $termFk],
        'si'
    );
    $course = $courseResult->fetch();
    if ($course !== false) {
        if (!(bool)$course['is_active']) return null;
        return (int)$course['courses_pk'];
    }
    
    if ($autoCreate) {
        // Recursively ensure discipline exists (which also ensures term exists)
        $disciplineFk = getOrCreateDisciplineFk($db, $dbPrefix, $deptCode, $termCode, true, $userId);
        if ($disciplineFk === null) {
            return null;
        }
        
        try {
            // Auto-create missing course
            $db->query(
                "INSERT INTO {$dbPrefix}courses (course_number, course_name, term_fk, discipline_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$courseNumber, "Auto-created: {$courseNumber}", $termFk, $disciplineFk, $userId, $userId],
                'ssiiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateCourseFk: Failed to create course '{$courseNumber}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create PSLO by outcome_code, program, and term
 * Recursively creates Program, Discipline, and Term if needed
 */
function getOrCreatePsloFk($db, $dbPrefix, string $outcomeCode, string $programCode, string $termCode, string $deptCode, bool $autoCreate, ?int $userId): ?int {
    $programFk = getOrCreateProgramFk($db, $dbPrefix, $programCode, $termCode, $deptCode, $autoCreate, $userId);
    if ($programFk === null) {
        return null;
    }

    $psloResult = $db->query(
        "SELECT program_outcomes_pk, is_active FROM {$dbPrefix}program_outcomes WHERE outcome_code = ? AND program_fk = ?",
        [$outcomeCode, $programFk],
        'si'
    );
    $pslo = $psloResult->fetch();
    if ($pslo !== false) {
        if (!(bool)$pslo['is_active']) return null;
        return (int)$pslo['program_outcomes_pk'];
    }
    
    if ($autoCreate) {
        try {
            // Auto-create missing PSLO
            $db->query(
                "INSERT INTO {$dbPrefix}program_outcomes (outcome_code, outcome_description, program_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$outcomeCode, "Auto-created: {$outcomeCode}", $programFk, $userId, $userId],
                'ssiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreatePsloFk: Failed to create PSLO '{$outcomeCode}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create CSLO by outcome_code, course, and term
 * Recursively creates Course, Discipline, and Term if needed
 */
function getOrCreateCsloFk($db, $dbPrefix, string $outcomeCode, string $courseNumber, string $deptCode, string $termCode, bool $autoCreate, ?int $userId): ?int {
    $courseFk = getOrCreateCourseFk($db, $dbPrefix, $courseNumber, $deptCode, $termCode, $autoCreate, $userId);
    if ($courseFk === null) {
        return null;
    }

    $csloResult = $db->query(
        "SELECT student_learning_outcomes_pk, is_active FROM {$dbPrefix}student_learning_outcomes WHERE course_fk = ? AND slo_code = ?",
        [$courseFk, $outcomeCode],
        'is'
    );
    $cslo = $csloResult->fetch();
    if ($cslo !== false) {
        if (!(bool)$cslo['is_active']) return null;
        return (int)$cslo['student_learning_outcomes_pk'];
    }
    
    if ($autoCreate) {
        try {
            // Auto-create missing CSLO
            $db->query(
                "INSERT INTO {$dbPrefix}student_learning_outcomes (slo_code, slo_description, course_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$outcomeCode, "Auto-created: {$outcomeCode}", $courseFk, $userId, $userId],
                'ssiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateCsloFk: Failed to create CSLO '{$outcomeCode}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create section by CRN and term
 * Recursively creates Course, Discipline, and Term if needed
 */
function getOrCreateSectionFk($db, $dbPrefix, string $crn, string $courseNumber, string $termCode, string $deptCode, bool $autoCreate, ?int $userId): ?int {
    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreate, $userId);
    if ($termFk === null) {
        return null;
    }

    $sectionResult = $db->query(
        "SELECT sections_pk, is_active FROM {$dbPrefix}sections WHERE term_fk = ? AND crn = ?",
        [$termFk, $crn],
        'is'
    );
    $section = $sectionResult->fetch();
    if ($section !== false) {
        if (!(bool)$section['is_active']) return null;
        return (int)$section['sections_pk'];
    }
    
    if ($autoCreate) {
        // Recursively ensure course exists
        $courseFk = getOrCreateCourseFk($db, $dbPrefix, $courseNumber, $deptCode, $termCode, true, $userId);
        if ($courseFk === null) {
            return null;
        }
        
        try {
            // Auto-create missing section
            $db->query(
                "INSERT INTO {$dbPrefix}sections (crn, section_id, course_fk, term_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$crn, $crn, $courseFk, $termFk, $userId, $userId],
                'ssiiii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateSectionFk: Failed to create section CRN '{$crn}': " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Get or auto-create student by student_id
 * Encrypts student_id before storage
 */
function getOrCreateStudentFk($db, $dbPrefix, string $studentId, $studentEncryption, bool $autoCreate, ?int $userId): ?int {
    $studentHash = $studentEncryption->hashCNumber($studentId);
    
    $studentResult = $db->query(
        "SELECT students_pk, is_active FROM {$dbPrefix}students WHERE student_id_hash = ?",
        [$studentHash],
        's'
    );
    $student = $studentResult->fetch();
    if ($student !== false) {
        if (!(bool)$student['is_active']) return null;
        return (int)$student['students_pk'];
    }
    
    if ($autoCreate) {
        try {
            // Auto-create missing student
            $encrypted = $studentEncryption->encryptStudentData($studentId, '', '');
            $db->query(
                "INSERT INTO {$dbPrefix}students (student_data_encrypted, student_id_hash, created_at, updated_at, created_by_fk, updated_by_fk) 
                 VALUES (?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                [$encrypted['encrypted'], $encrypted['hash'], $userId, $userId],
                'ssii'
            );
            return $db->getInsertId();
        } catch (\Exception $e) {
            error_log("getOrCreateStudentFk: Failed to create student: " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    $importStartedAt = microtime(true);
    $fileNum = (int)($_POST['file_num'] ?? 1);
    $activeFile = $fileNum;
    $autoCreateParents = isset($_POST['auto_create_parents']) && $_POST['auto_create_parents'] === '1';
    
    // Handle file upload based on file number
    if (isset($_FILES['csv_upload']) && $_FILES['csv_upload']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['csv_upload']['tmp_name'];
        $handle = fopen($tmpName, 'r');
        
        if ($handle !== false) {
            $headers = fgetcsv($handle, 0, ",", "\"", "");
            $imported = 0;
            $skipped = 0;
            $errors = [];
            
            try {
                $minimumColumns = [
                    1 => 4, 2 => 3, 3 => 3, 4 => 5, 5 => 4, 6 => 4,
                    7 => 3, 8 => 4, 9 => 3, 10 => 3, 11 => 4, 12 => 4,
                ];
                if (!isset($minimumColumns[$fileNum])) {
                    throw new \RuntimeException("Invalid file number: {$fileNum}");
                }
                if ($headers === false) {
                    throw new \InvalidArgumentException('The CSV is empty or missing its header row. No data was imported.');
                }

                // Every normalized import is term-driven. Require term_code in
                // the first column because each file handler reads the row's
                // destination term from column zero. Normalize the header so
                // UTF-8 BOMs, capitalization, and surrounding whitespace do not
                // cause valid exports to be rejected.
                $termHeader = strtolower(normalizeCsvText((string)($headers[0] ?? '')));
                if ($termHeader !== 'term_code') {
                    $foundHeader = $termHeader !== '' ? "'{$termHeader}'" : 'an empty header';
                    throw new \InvalidArgumentException(
                        "File {$fileNum} must have term_code as its first column; found {$foundHeader}. No data was imported."
                    );
                }

                if (count($headers) < $minimumColumns[$fileNum]) {
                    throw new \InvalidArgumentException(
                        "File {$fileNum} requires at least {$minimumColumns[$fileNum]} columns; the uploaded CSV has " . count($headers) . '. No data was imported.'
                    );
                }

                // Normalized paradigm: 12 separate files, each importing one entity type.
                // Files 1-6 upsert standalone entities (term, department, ISLO, program, PSLO, course).
                // File 7 creates program-course mappings.
                // File 8 upserts CSLOs (student_learning_outcomes).
                // File 9 links CSLOs to PSLOs (program_outcomes_fk).
                // File 10 upserts course sections.
                // File 11 upserts student enrollment records with encrypted student data.
                // All files support optional auto-creation of missing parent entities
                // when "auto_create_parents" is checked.

                switch ($fileNum) {
                    case 1: // Term
                        // Expected columns: term_code, term_name, start_date, end_date
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $termName = normalizeCsvText(trim($row[1]));
                                $startDate = normalizeCsvDate($row[2]);
                                $endDate = normalizeCsvDate($row[3]);
                                
                                if (!empty($termCode) && !empty($termName) && !empty($startDate) && !empty($endDate)) {
                                    // Check if term exists (match banner_term or term_code)
                                    $result = $db->query(
                                        "SELECT terms_pk, is_active FROM {$dbPrefix}terms WHERE banner_term = ? OR term_code = ?",
                                        [$termCode, $termCode],
                                        'ss'
                                    );
                                    $existing = $result->fetch();
                                    if ($existing !== false && !(bool)$existing['is_active']) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Skipped term {$termCode} - matching term is deactivated";
                                        continue;
                                    }
                                    if ($existing !== false) {
                                        $db->query(
                                            "UPDATE {$dbPrefix}terms 
                                             SET term_name = ?, start_date = ?, end_date = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                             WHERE terms_pk = ?",
                                            [$termName, $startDate, $endDate, $userId, $existing['terms_pk']],
                                            'sssii'
                                        );
                                    } else {
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}terms (term_code, banner_term, term_name, start_date, end_date, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$termCode, $termCode, $termName, $startDate, $endDate, $userId, $userId],
                                            'sssssii'
                                        );
                                    }
                                    $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 1 (Term) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 2: // Department
                        // Expected columns: term_code, department_code, department_name
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 3 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $departmentCode = trim($row[1]);
                                $departmentName = normalizeCsvText(trim($row[2]));
                                
                                if (!empty($termCode) && !empty($departmentCode) && !empty($departmentName)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    if (isset($termFk)) {
                                        try {
                                            // Check if department exists for this term
                                            $result = $db->query(
                                                "SELECT disciplines_pk, is_active FROM {$dbPrefix}disciplines WHERE term_fk = ? AND discipline_code = ?",
                                                [$termFk, $departmentCode],
                                                'is'
                                            );
                                            $existing = $result->fetch();
                                            if ($existing !== false && !(bool)$existing['is_active']) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped department {$departmentCode} - matching department is deactivated";
                                                continue;
                                            }
                                            if ($existing !== false) {
                                                $db->query(
                                                    "UPDATE {$dbPrefix}disciplines 
                                                     SET discipline_name = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                     WHERE disciplines_pk = ?",
                                                    [$departmentName, $userId, $existing['disciplines_pk']],
                                                    'sii'
                                                );
                                            } else {
                                                $db->query(
                                                    "INSERT INTO {$dbPrefix}disciplines (term_fk, discipline_code, discipline_name, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                     VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                    [$termFk, $departmentCode, $departmentName, $userId, $userId],
                                                    'issii'
                                                );
                                            }
                                            $imported++;
                                        } catch (\Exception $e) {
                                            $skipped++;
                                            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                                                $errors[] = "Row " . ($imported + $skipped) . ": Duplicate discipline '{$departmentName}' in term {$termCode}";
                                            } else {
                                                $errorReference = errorReference();
                                                error_log("Term import (discipline) error [{$errorReference}]: " . $e->getMessage());
                                                $errors[] = "Row " . ($imported + $skipped) . ": Database error. Reference: {$errorReference}"
                                                    . (DEBUG_MODE ? ' (' . $e->getMessage() . ')' : '');
                                            }
                                        }
                                    }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 2 (Department) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 3: // ISLO
                        // Expected columns: term_code, islo_code, islo_language, islo_sequence_num
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 3 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $isloCode = trim($row[1]);
                                $isloLanguage = normalizeCsvText(trim($row[2]));
                                $isloSequenceNum = isset($row[3]) && is_numeric($row[3]) ? (int)$row[3] : 0;
                                
                                if (!empty($termCode) && !empty($isloCode) && !empty($isloLanguage)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                        
                                    // Check if ISLO exists for this term
                                        $result = $db->query(
                                            "SELECT institutional_outcomes_pk, is_active FROM {$dbPrefix}institutional_outcomes WHERE term_fk = ? AND outcome_code = ?",
                                            [$termFk, $isloCode],
                                            'is'
                                        );
                                        $existing = $result->fetch();
                                        if ($existing !== false && !(bool)$existing['is_active']) {
                                            $skipped++;
                                            $errors[] = "Row " . ($imported + $skipped) . ": Skipped ISLO {$isloCode} - matching outcome is deactivated";
                                            continue;
                                        }
                                        if ($existing !== false) {
                                            $db->query(
                                                "UPDATE {$dbPrefix}institutional_outcomes 
                                                 SET outcome_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                 WHERE institutional_outcomes_pk = ?",
                                                [$isloLanguage, $isloSequenceNum, $userId, $existing['institutional_outcomes_pk']],
                                                'siii'
                                            );
                                        } else {
                                            $db->query(
                                                "INSERT INTO {$dbPrefix}institutional_outcomes (term_fk, outcome_code, outcome_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                 VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                [$termFk, $isloCode, $isloLanguage, $isloSequenceNum, $userId, $userId],
                                                'issiii'
                                            );
                                        }
                                        $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 3 (ISLO) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 4: // Program
                        // Expected columns: term_code, program_code, program_name, department_code, degree_type
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 5 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $programCode = trim($row[1]);
                                $programName = normalizeCsvText(trim($row[2]));
                                $departmentCode = trim($row[3]);
                                $degreeType = trim($row[4]);
                                
                                if (!empty($termCode) && !empty($programCode) && !empty($programName) && !empty($departmentCode)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Get or auto-create discipline (recursively creates term if needed)
                                    $disciplineFk = getOrCreateDisciplineFk($db, $dbPrefix, $departmentCode, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($disciplineFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Department not found: {$departmentCode}";
                                        continue;
                                    }
                                            
                                    // Check if program exists for this term
                                            $result = $db->query(
                                                "SELECT programs_pk, is_active FROM {$dbPrefix}programs WHERE term_fk = ? AND program_code = ?",
                                                [$termFk, $programCode],
                                                'is'
                                            );
                                            $existing = $result->fetch();
                                            if ($existing !== false && !(bool)$existing['is_active']) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped program {$programCode} - matching program is deactivated";
                                                continue;
                                            }
                                            if ($existing !== false) {
                                                $db->query(
                                                    "UPDATE {$dbPrefix}programs 
                                                     SET program_name = ?, discipline_fk = ?, degree_type = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                     WHERE programs_pk = ?",
                                                    [$programName, $disciplineFk, $degreeType, $userId, $existing['programs_pk']],
                                                    'sisii'
                                                );
                                            } else {
                                                $db->query(
                                                    "INSERT INTO {$dbPrefix}programs (term_fk, program_code, program_name, discipline_fk, degree_type, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                     VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                    [$termFk, $programCode, $programName, $disciplineFk, $degreeType, $userId, $userId],
                                                    'issisii'
                                                );
                                            }
                                            $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 4 (Program) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 5: // PSLO
                        // Expected columns: term_code, pslo_code, pslo_language, program_code, pslo_sequence_num, islo_code (optional)
                        // Some older exports swap the last two columns, so detect which one is the numeric sequence.
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $psloCode = trim($row[1]);
                                $psloLanguage = normalizeCsvText(trim($row[2]));
                                $programCode = trim($row[3]);
                                $columnFive = isset($row[4]) ? trim($row[4]) : '';
                                $columnSix = isset($row[5]) ? trim($row[5]) : '';
                                if ($columnFive !== '' && is_numeric($columnFive)) {
                                    $psloSequenceNum = (int)$columnFive;
                                    $isloCode = $columnSix;
                                } elseif ($columnSix !== '' && is_numeric($columnSix)) {
                                    $isloCode = $columnFive;
                                    $psloSequenceNum = (int)$columnSix;
                                } else {
                                    // A blank sequence may still be followed by an ISLO code.
                                    $psloSequenceNum = 0;
                                    $isloCode = $columnFive !== '' ? $columnFive : $columnSix;
                                }
                                
                                if (!empty($termCode) && !empty($psloCode) && !empty($psloLanguage) && !empty($programCode)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Get or auto-create program (recursively creates discipline and term if needed)
                                    $deptCode = extractDeptFromProgram($programCode);
                                    $programFk = getOrCreateProgramFk($db, $dbPrefix, $programCode, $termCode, $deptCode, $autoCreateParents, $userId);
                                    
                                    if ($programFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Program not found: {$programCode}";
                                        continue;
                                    }
                                            
                                    // Lookup institutional_outcome_fk in the same term (optional - can be NULL)
                                    $isloFk = null;
                                    if (!empty($isloCode)) {
                                        $isloResult = $db->query(
                                            "SELECT institutional_outcomes_pk, is_active FROM {$dbPrefix}institutional_outcomes WHERE term_fk = ? AND outcome_code = ?",
                                            [$termFk, $isloCode],
                                            'is'
                                        );
                                        
                                        $islo = $isloResult->fetch();
                                        if ($islo !== false && (bool)$islo['is_active']) {
                                            $isloFk = $islo['institutional_outcomes_pk'];
                                        } else {
                                            $skipped++;
                                            $errors[] = "Row " . ($imported + $skipped) . ": ISLO not found for term {$termCode}: {$isloCode}";
                                            continue;
                                        }
                                    }
                                             
                                            // Check if this PSLO already exists for the term-specific program.
                                            try {
                                                $result = $db->query(
                                                    "SELECT program_outcomes_pk, is_active FROM {$dbPrefix}program_outcomes WHERE program_fk = ? AND outcome_code = ?",
                                                    [$programFk, $psloCode],
                                                    'is'
                                                );
                                                $existing = $result->fetch();
                                                if ($existing !== false && !(bool)$existing['is_active']) {
                                                    $skipped++;
                                                    $errors[] = "Row " . ($imported + $skipped) . ": Skipped PSLO {$psloCode} - matching outcome is deactivated";
                                                    continue;
                                                }
                                        if ($existing !== false) {
                                                    $db->query(
                                                        "UPDATE {$dbPrefix}program_outcomes 
                                                         SET outcome_description = ?, program_fk = ?, institutional_outcomes_fk = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                         WHERE program_outcomes_pk = ?",
                                                        [$psloLanguage, $programFk, $isloFk, $psloSequenceNum, $userId, $existing['program_outcomes_pk']],
                                                        'siiiii'
                                                    );
                                                } else {
                                                    $db->query(
                                                        "INSERT INTO {$dbPrefix}program_outcomes (outcome_code, outcome_description, program_fk, institutional_outcomes_fk, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                         VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                        [$psloCode, $psloLanguage, $programFk, $isloFk, $psloSequenceNum, $userId, $userId],
                                                        'ssiiiii'
                                                    );
                                                }
                                                $imported++;
                                            } catch (Exception $e) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped [{$psloCode}] - " . $e->getMessage();
                                            }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 5 (PSLO) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 6: // Course
                        // Expected columns: term_code, course_number, course_name, department_code
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $courseNumber = trim($row[1]);
                                $courseName = normalizeCsvText(trim($row[2]));
                                $departmentCode = trim($row[3]);
                                
                                if (!empty($termCode) && !empty($courseNumber) && !empty($courseName) && !empty($departmentCode)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Get or auto-create discipline (recursively creates term if needed)
                                    $disciplineFk = getOrCreateDisciplineFk($db, $dbPrefix, $departmentCode, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($disciplineFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Department not found: {$departmentCode}";
                                        continue;
                                    }
                                            
                                            // Check if the course exists in this term.
                                            try {
                                                $result = $db->query(
                                                    "SELECT courses_pk, is_active FROM {$dbPrefix}courses WHERE term_fk = ? AND course_number = ?",
                                                    [$termFk, $courseNumber],
                                                    'is'
                                                );
                                                $existing = $result->fetch();
                                                if ($existing !== false && !(bool)$existing['is_active']) {
                                                    $skipped++;
                                                    $errors[] = "Row " . ($imported + $skipped) . ": Skipped course {$courseNumber} - matching course is deactivated";
                                                    continue;
                                                }
                                                if ($existing !== false) {
                                                    $db->query(
                                                        "UPDATE {$dbPrefix}courses 
                                                         SET course_name = ?, discipline_fk = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                         WHERE courses_pk = ?",
                                                        [$courseName, $disciplineFk, $userId, $existing['courses_pk']],
                                                        'siii'
                                                    );
                                                } else {
                                                    $db->query(
                                                        "INSERT INTO {$dbPrefix}courses (term_fk, course_number, course_name, discipline_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                         VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                        [$termFk, $courseNumber, $courseName, $disciplineFk, $userId, $userId],
                                                        'issiii'
                                                    );
                                                }
                                                $imported++;
                                            } catch (Exception $e) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped [{$courseNumber}] - " . $e->getMessage();
                                            }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 6 (Course) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 7: // Program-Course Mapping
                        // Expected columns: term_code, program_code, course_number
                        $userId = getValidUserId();
                        $duplicates = 0;
                        $repaired = 0;
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 3 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $programCode = trim($row[1]);
                                $courseNumber = trim($row[2]);
                                
                                if (!empty($termCode) && !empty($programCode) && !empty($courseNumber)) {
                                    // Get or auto-create program (recursively creates discipline and term)
                                    $deptCodeFromProgram = extractDeptFromProgram($programCode);
                                    $programFk = getOrCreateProgramFk($db, $dbPrefix, $programCode, $termCode, $deptCodeFromProgram, $autoCreateParents, $userId);
                                    
                                    if ($programFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Program not found: {$programCode}";
                                        continue;
                                    }
                                    
                                    // Get or auto-create course (recursively creates discipline and term)
                                    $deptCodeFromCourse = extractDeptFromCourse($courseNumber);
                                    $courseFk = getOrCreateCourseFk($db, $dbPrefix, $courseNumber, $deptCodeFromCourse, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($courseFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Course not found: {$courseNumber}";
                                        continue;
                                    }
                                            
                                            // Check if mapping exists
                                            $result = $db->query(
                                                "SELECT program_courses_pk FROM {$dbPrefix}program_courses WHERE program_fk = ? AND course_fk = ?",
                                                [$programFk, $courseFk],
                                                'ii'
                                            );
                                            if ($result->fetch() === false) {
                                                // Older imports could pair the same logical program/course
                                                // codes across different terms. Reuse that row when possible.
                                                $legacyResult = $db->query(
                                                    "SELECT pc.program_courses_pk
                                                     FROM {$dbPrefix}program_courses pc
                                                     INNER JOIN {$dbPrefix}programs legacy_p ON pc.program_fk = legacy_p.programs_pk
                                                     INNER JOIN {$dbPrefix}courses legacy_c ON pc.course_fk = legacy_c.courses_pk
                                                     WHERE legacy_p.program_code = ?
                                                       AND legacy_c.course_number = ?
                                                       AND legacy_p.term_fk <> legacy_c.term_fk",
                                                    [$programCode, $courseNumber],
                                                    'ss'
                                                );
                                                $legacy = $legacyResult->fetch();

                                                if ($legacy !== false) {
                                                    $db->query(
                                                        "UPDATE {$dbPrefix}program_courses
                                                         SET program_fk = ?, course_fk = ?
                                                         WHERE program_courses_pk = ?",
                                                        [$programFk, $courseFk, $legacy['program_courses_pk']],
                                                        'iii'
                                                    );
                                                    $repaired++;
                                                } else {
                                                    $db->query(
                                                        "INSERT INTO {$dbPrefix}program_courses (program_fk, course_fk, created_at, created_by_fk) 
                                                         VALUES (?, ?, {$db->now()}, ?)",
                                                        [$programFk, $courseFk, $userId],
                                                        'iii'
                                                    );
                                                    $imported++;
                                                }
                                            } else {
                                                $duplicates++;
                                            }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 7 (Program-Course Mapping) import: {$imported} imported, {$repaired} repaired, {$duplicates} duplicates, {$skipped} errors";
                        $importStats['repaired'] = $repaired;
                        break;
                        
                    case 8: // CSLO
                        // Expected columns: term_code, cslo_code, cslo_language, course_number, cslo_sequence_num
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $csloCode = trim($row[1]);
                                $csloLanguage = normalizeCsvText(trim($row[2]));
                                $courseNumber = trim($row[3]);
                                $csloSequenceNum = isset($row[4]) && is_numeric($row[4]) ? (int)$row[4] : 0;
                                
                                if (!empty($termCode) && !empty($csloCode) && !empty($csloLanguage) && !empty($courseNumber)) {
                                    // Get or auto-create course (recursively creates discipline and term)
                                    $deptCode = extractDeptFromCourse($courseNumber);
                                    $courseFk = getOrCreateCourseFk($db, $dbPrefix, $courseNumber, $deptCode, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($courseFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Course not found: {$courseNumber}";
                                        continue;
                                    }
                                            
                                            // Check if CSLO exists for this course (unique per course_fk + slo_code)
                                            $result = $db->query(
                                                "SELECT student_learning_outcomes_pk, is_active FROM {$dbPrefix}student_learning_outcomes WHERE course_fk = ? AND slo_code = ?",
                                                [$courseFk, $csloCode],
                                                'is'
                                            );
                                            $existing = $result->fetch();
                                            if ($existing !== false && !(bool)$existing['is_active']) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped CSLO {$csloCode} - matching outcome is deactivated";
                                                continue;
                                            }
                                            if ($existing !== false) {
                                                $db->query(
                                                    "UPDATE {$dbPrefix}student_learning_outcomes 
                                                     SET slo_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                     WHERE student_learning_outcomes_pk = ?",
                                                    [$csloLanguage, $csloSequenceNum, $userId, $existing['student_learning_outcomes_pk']],
                                                    'siii'
                                                );
                                            } else {
                                                $db->query(
                                                    "INSERT INTO {$dbPrefix}student_learning_outcomes (slo_code, slo_description, sequence_num, course_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                     VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                    [$csloCode, $csloLanguage, $csloSequenceNum, $courseFk, $userId, $userId],
                                                    'ssiiii'
                                                );
                                            }
                                            $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 8 (CSLO) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 9: // CSLO-PSLO Mapping
                        // Expected columns: term_code, cslo_code, pslo_code
                        // Maps CSLOs to PSLOs by updating program_outcomes_fk in student_learning_outcomes table
                        // Note: If multiple courses in a term use the same slo_code, all are mapped to the same PSLO
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 3 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $csloCode = trim($row[1]);
                                $psloCode = trim($row[2]);
                                
                                if (!empty($termCode) && !empty($csloCode) && !empty($psloCode)) {
                                    // Get or auto-create term
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Extract program code from PSLO code and get/create PSLO
                                    $programCode = extractProgramFromPslo($psloCode);
                                    $deptCode = extractDeptFromProgram($programCode);
                                    
                                    $psloFk = getOrCreatePsloFk($db, $dbPrefix, $psloCode, $programCode, $termCode, $deptCode, $autoCreateParents, $userId);
                                    
                                    if ($psloFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": PSLO not found: {$psloCode}";
                                        continue;
                                    }
                                    
                                    // Find ALL CSLOs in courses for this term that match the slo_code
                                    $csloResult = $db->query(
                                        "SELECT slo.student_learning_outcomes_pk 
                                         FROM {$dbPrefix}student_learning_outcomes slo
                                         INNER JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
                                         WHERE c.term_fk = ? AND slo.slo_code = ?",
                                        [$termFk, $csloCode],
                                        'is'
                                    );
                                    
                                    $updateCount = 0;
                                    while ($cslo = $csloResult->fetch()) {
                                            // Update CSLO to link to PSLO
                                            $db->query(
                                                "UPDATE {$dbPrefix}student_learning_outcomes 
                                                 SET program_outcomes_fk = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                 WHERE student_learning_outcomes_pk = ?",
                                                [$psloFk, $userId, $cslo['student_learning_outcomes_pk']],
                                                'iii'
                                            );
                                            $updateCount++;
                                    }
                                    if ($updateCount === 0) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": No CSLOs found for term {$termCode} with code {$csloCode}";
                                    } else {
                                        $imported += $updateCount;
                                    }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 9 (CSLO-PSLO Mapping) import: {$imported} imported, {$skipped} skipped";
                        break;
                        
                    case 10: // Section
                        // Expected columns: term_code, crn, course_number
                        $userId = getValidUserId();
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 3 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $crn = trim($row[1]); // CRN - unique within a term
                                $courseNumber = trim($row[2]);
                                
                                if (!empty($termCode) && !empty($crn) && !empty($courseNumber)) {
                                    // Get or auto-create term (recursively if needed)
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Get or auto-create course (recursively creates discipline and term)
                                    $deptCode = extractDeptFromCourse($courseNumber);
                                    $courseFk = getOrCreateCourseFk($db, $dbPrefix, $courseNumber, $deptCode, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($courseFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Course not found: {$courseNumber}";
                                        continue;
                                    }
                                            
                                            // Check if the section exists in this term.
                                            $result = $db->query(
                                                "SELECT sections_pk, is_active FROM {$dbPrefix}sections WHERE term_fk = ? AND crn = ?",
                                                [$termFk, $crn],
                                                'is'
                                            );
                                            $existing = $result->fetch();
                                            if ($existing !== false && !(bool)$existing['is_active']) {
                                                $skipped++;
                                                $errors[] = "Row " . ($imported + $skipped) . ": Skipped section {$crn} - matching section is deactivated";
                                                continue;
                                            }
                                            if ($existing !== false) {
                                                $db->query(
                                                    "UPDATE {$dbPrefix}sections 
                                                     SET course_fk = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                     WHERE sections_pk = ?",
                                                    [$courseFk, $userId, $existing['sections_pk']],
                                                    'iii'
                                                );
                                            } else {
                                                // For INSERT, we need both section_id and crn
                                                // Use crn as section_id if not provided separately in import
                                                $db->query(
                                                    "INSERT INTO {$dbPrefix}sections (section_id, crn, term_fk, course_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                                                     VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                                    [$crn, $crn, $termFk, $courseFk, $userId, $userId],
                                                    'ssiiii'
                                                );
                                            }
                                            $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 10 (Section) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 11: // Enrollment
                        // Expected columns: term_code, student_id, student_name, section_code (used as CRN)
                        $userId = getValidUserId();
                        $termFkCache = [];
                        $studentFkCache = [];
                        $sectionFkCache = [];
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $studentId = trim($row[1]);
                                $studentName = trim($row[2]);
                                $crn = trim($row[3]); // section_code from CSV (treated as CRN)
                                
                                if (!empty($termCode) && !empty($studentId) && !empty($crn)) {
                                    // Validate or auto-create term (from CSV data, not dashboard selection)
                                    if (array_key_exists($termCode, $termFkCache)) {
                                        $termFk = $termFkCache[$termCode];
                                    } else {
                                        $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                        $termFkCache[$termCode] = $termFk;
                                    }
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Upsert student (with encrypted data)
                                    // Store full name as-is (don't parse into first/last to avoid assumptions)
                                    $firstName = $studentName; // Store full name in first_name field
                                    $lastName = ''; // Leave last_name empty
                                    
                                    $hash = $studentEncryption->hashCNumber($studentId);
                                    if (isset($studentFkCache[$hash])) {
                                        $studentFk = $studentFkCache[$hash];
                                        $studentExists = true;
                                    } else {
                                        $studentResult = $db->query(
                                            "SELECT students_pk, is_active FROM {$dbPrefix}students WHERE student_id_hash = ?",
                                            [$hash],
                                            's'
                                        );
                                        $student = $studentResult->fetch();
                                        if ($student !== false && !(bool)$student['is_active']) {
                                            $skipped++;
                                            $errors[] = "Row " . ($imported + $skipped) . ": Skipped student {$studentId} - matching student is deactivated";
                                            continue;
                                        }
                                        $studentExists = $student !== false;
                                        $studentFk = $studentExists ? $student['students_pk'] : null;
                                    }

                                    if ($studentExists) {
                                        // Update student name if provided
                                        if (!empty($studentName)) {
                                            $encrypted = $studentEncryption->encryptStudentData($studentId, $firstName, $lastName);
                                            $db->query(
                                                "UPDATE {$dbPrefix}students SET student_data_encrypted = ?, updated_at = {$db->now()}, updated_by_fk = ? WHERE students_pk = ?",
                                                [$encrypted['encrypted'], $userId, $studentFk],
                                                'sii'
                                            );
                                        }
                                    } else {
                                        $encrypted = $studentEncryption->encryptStudentData($studentId, $firstName, $lastName);
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}students (student_data_encrypted, student_id_hash, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$encrypted['encrypted'], $encrypted['hash'], $userId, $userId],
                                            'ssii'
                                        );
                                        $studentFk = $db->getInsertId();
                                    }
                                    $studentFkCache[$hash] = $studentFk;
                                    
                                    // Look up the section in the same term. File 11 does not
                                    // contain a course number, so it cannot safely create one.
                                    $sectionCacheKey = $termFk . ':' . $crn;
                                    if (array_key_exists($sectionCacheKey, $sectionFkCache)) {
                                        $sectionFk = $sectionFkCache[$sectionCacheKey];
                                    } else {
                                        $sectionResult = $db->query(
                                                "SELECT sections_pk, is_active FROM {$dbPrefix}sections WHERE term_fk = ? AND crn = ?",
                                            [$termFk, $crn],
                                            'is'
                                        );
                                        $section = $sectionResult->fetch();
                                        $sectionFk = $section !== false && (bool)$section['is_active']
                                            ? (int)$section['sections_pk']
                                            : false;
                                        $sectionFkCache[$sectionCacheKey] = $sectionFk;
                                    }

                                    if ($sectionFk === false) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Section not found for term {$termCode} (CRN: {$crn}); import File 10 first";
                                        continue;
                                    }
                                        
                                    // Check if the student is already enrolled in this section.
                                    $result = $db->query(
                                        "SELECT enrollment_pk, is_active FROM {$dbPrefix}enrollment WHERE section_fk = ? AND student_fk = ?",
                                        [$sectionFk, $studentFk],
                                        'ii'
                                    );
                                    $existing = $result->fetch();
                                    if ($existing !== false && !(bool)$existing['is_active']) {
                                        $skipped++;
                                        $errors[] = "Row " . ($imported + $skipped) . ": Skipped enrollment - matching enrollment is deactivated";
                                        continue;
                                    }
                                            if ($existing !== false) {
                                        $db->query(
                                            "UPDATE {$dbPrefix}enrollment 
                                             SET updated_at = {$db->now()}, updated_by_fk = ?
                                             WHERE enrollment_pk = ?",
                                            [$userId, $existing['enrollment_pk']],
                                            'ii'
                                        );
                                    } else {
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}enrollment (section_fk, student_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$sectionFk, $studentFk, $userId, $userId],
                                            'iiii'
                                        );
                                    }
                                    $imported++;
                                } else {
                                    $skipped++;
                                    $errors[] = "Row " . ($imported + $skipped) . ": Missing required fields";
                                }
                            } else {
                                $skipped++;
                            }
                        }
                        $successMessage = "FILE 11 (Enrollment) import: {$imported} imported/updated, {$skipped} skipped";
                        break;
                        
                    case 12: // Cross-Listing (XList)
                        // Expected columns: term_code, xlist_group, course_number, crn
                        $userId = getValidUserId();
                        $updated = 0;
                        $duplicates = 0;
                        $rowNum = 1; // Start at 1 (header row)
                        while (($row = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                            $rowNum++;
                            if (count($row) >= 4 && !empty(trim($row[0]))) {
                                $termCode = trim($row[0]);
                                $xlistGroup = trim($row[1]);
                                $courseNumber = trim($row[2]);
                                $crn = trim($row[3]);
                                
                                if (!empty($termCode) && !empty($xlistGroup) && !empty($crn)) {
                                    // Validate or auto-create term
                                    $termFk = getOrCreateTermFk($db, $dbPrefix, $termCode, $autoCreateParents, $userId);
                                    
                                    if ($termFk === null) {
                                        $skipped++;
                                        $errors[] = "Row {$rowNum}: Term not found: {$termCode}";
                                        continue;
                                    }
                                    
                                    // Check if this CRN already exists in xlist mapping
                                    $checkResult = $db->query(
                                        "SELECT section_xlist_pk, xlist_group, course_number, is_active FROM {$dbPrefix}section_xlist 
                                         WHERE term_fk = ? AND crn = ?",
                                        [$termFk, $crn],
                                        'is'
                                    );
                                    
                                    $existing = $checkResult->fetch();
                                    if ($existing !== false && !(bool)$existing['is_active']) {
                                        $skipped++;
                                        $errors[] = "Row {$rowNum}: Skipped cross-list {$crn} - matching record is deactivated";
                                        continue;
                                    }
                                    if ($existing !== false) {
                                        // Update if either imported value changed.
                                        if ($existing['xlist_group'] !== $xlistGroup || ($existing['course_number'] ?? null) !== $courseNumber) {
                                            $db->query(
                                                "UPDATE {$dbPrefix}section_xlist 
                                                 SET xlist_group = ?, course_number = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                                 WHERE section_xlist_pk = ?",
                                                [$xlistGroup, $courseNumber, $userId, $existing['section_xlist_pk']],
                                                'ssii'
                                            );
                                            $updated++;
                                        } else {
                                            $duplicates++;
                                        }
                                    } else {
                                        // Insert new xlist mapping
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}section_xlist 
                                             (term_fk, xlist_group, crn, course_number, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$termFk, $xlistGroup, $crn, $courseNumber, $userId, $userId],
                                            'isssii'
                                        );
                                        $imported++;
                                    }
                                } else {
                                    $skipped++;
                                    $errors[] = "Row {$rowNum}: Missing required fields (term_code, xlist_group, or crn)";
                                }
                            } else {
                                $skipped++;
                                $errors[] = "Row {$rowNum}: Invalid row format or empty term code";
                            }
                        }
                        $successMessage = "FILE 12 (Cross-Listing) import: {$imported} imported, {$updated} updated, {$duplicates} duplicates, {$skipped} skipped";
                        $importStats['updated'] = $updated;
                        $importStats['duplicates'] = $duplicates;
                        break;
                        
                    default:
                        $errorMessage = "Invalid file number: {$fileNum}";
                }
                
                $importStats = array_merge($importStats, ['imported' => $imported, 'skipped' => $skipped]);
                
            } catch (\InvalidArgumentException $e) {
                $errorMessage = 'Import error: ' . $e->getMessage();
            } catch (\Exception $e) {
                $errorReference = errorReference();
                error_log("Term import error (normalized, file {$fileNum}) [{$errorReference}]: " . $e->getMessage());
                $errorMessage = DEBUG_MODE
                    ? "Import error: " . $e->getMessage()
                    : "Import error. Reference: {$errorReference}";
            }
            
            fclose($handle);
        } else {
            $errorMessage = 'Failed to read uploaded file';
        }
    } else {
        $errorMessage = 'Please select a valid CSV file';
    }

    $importElapsed = microtime(true) - $importStartedAt;
    $importStats['elapsed_seconds'] = round($importElapsed, 3);
    if ($successMessage !== '' && $errorMessage === '') {
        recordImportTiming(
            $fileNum,
            (int)($_FILES['csv_upload']['size'] ?? 0),
            (int)($importStats['imported'] ?? 0) + (int)($importStats['skipped'] ?? 0),
            $importElapsed
        );
    }
    if ($successMessage !== '') {
        $successMessage .= ' (took ' . formatImportElapsed($importElapsed) . ')';
    } elseif ($errorMessage !== '' && $importElapsed >= 1) {
        $errorMessage .= ' (stopped after ' . formatImportElapsed($importElapsed) . ')';
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Term Imports - Normalized Paradigm';
$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_term_imports_normalized',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['url' => BASE_URL . 'administration/', 'label' => 'Administration'],
        ['label' => 'Term Imports (Normalized)']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<style>
    .file-card {
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }
    .file-card:hover {
        border-left-color: var(--accent-blue);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .file-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-blue);
        color: white;
        font-weight: bold;
        margin-right: 1rem;
    }
    /* Import progress window */
    .import-progress .modal-content { border-radius: 1rem; }
    .import-timer { position: relative; width: 7.5rem; height: 7.5rem; }
    .import-timer::before {
        content: ""; position: absolute; inset: 0; border-radius: 50%;
        border: .4rem solid var(--bs-secondary-bg, #e9ecef);
    }
    .import-timer-ring { position: absolute; inset: 0; width: 100%; height: 100%; border-width: .4rem; color: var(--bs-primary); }
    .import-timer[data-state="failed"] .import-timer-ring { display: none; }
    .import-timer[data-state="failed"]::before { border-color: var(--bs-danger); }
    .import-timer-text { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .import-timer-value { font-size: 1.75rem; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .import-timer-label { font-size: .7rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--bs-secondary-color, #495057); }
    .import-file { color: var(--bs-secondary-color, #495057); word-break: break-all; }
    .import-plan { background: var(--bs-tertiary-bg, #f8f9fa); border-radius: .75rem; padding: .875rem 1rem; }
    .import-plan-heading { font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--bs-secondary-color, #495057); margin-bottom: .5rem; }
    .import-plan li { display: flex; gap: .5rem; font-size: .875rem; line-height: 1.4; padding: .15rem 0; }
    .import-plan li .bi { color: var(--bs-primary); flex-shrink: 0; margin-top: .1rem; }
    .import-info-button { color: var(--bs-secondary-color, #495057); font-size: 1rem; line-height: 1; text-decoration: none; }
    .import-info-button:hover, .import-info-button:focus-visible, .import-info-button[aria-expanded="true"] { color: var(--bs-primary); }
    .import-info-popover { --bs-popover-max-width: 22rem; }
    .import-info-popover .popover-header { font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--bs-secondary-color, #495057); background: var(--bs-tertiary-bg, #f8f9fa); }
    .import-plan-list li { display: flex; gap: .5rem; font-size: .875rem; line-height: 1.4; padding: .15rem 0; }
    .import-plan-list li .bi { color: var(--bs-primary); flex-shrink: 0; margin-top: .1rem; }
    .import-steps li { display: flex; align-items: center; gap: .75rem; padding: .4rem 0; }
    .import-steps li + li { border-top: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.1)); }
    .import-step-icon { width: 1.25rem; display: inline-flex; justify-content: center; flex-shrink: 0; }
    .import-step-label { font-weight: 500; }
    .import-step-detail { margin-left: auto; font-size: .875rem; font-variant-numeric: tabular-nums; color: var(--bs-secondary-color, #495057); }
    .import-steps li[data-state="pending"] { color: var(--bs-secondary-color, #495057); }
    .import-steps li[data-state="active"] .import-step-icon { color: var(--bs-primary); }
    .import-steps li[data-state="done"] .import-step-icon { color: var(--bs-success); }
    .import-steps li[data-state="failed"] .import-step-icon { color: var(--bs-danger); }
    .import-bar { height: .5rem; border-radius: 1rem; }
    .import-bar .progress-bar { transition: width .6s ease; }
    .import-estimate, .import-hint { color: var(--bs-secondary-color, #495057); }
</style>

<div class="app-content">
    <div class="container-fluid">
        
        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss success message"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss error message"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <h2 class="h5 alert-heading"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Import Warnings</h2>
            <p><strong><?= count($errors) ?> row(s) were skipped due to errors:</strong></p>
            <div style="max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 0.9em; background: rgba(0,0,0,0.05); padding: 10px; border-radius: 4px;">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss import warnings"></button>
        </div>
        <?php endif; ?>
        
        <!-- Overview Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Normalized Paradigm Overview</h2>
            </div>
            <div class="card-body">
                <p><strong>Paradigm:</strong> NORMALIZED - Separate files with explicit relationships</p>
                <p><strong>Files:</strong> 12 separate CSV files (imported in dependency order)</p>
                <p><strong>Use Case:</strong> Complete institutional catalogs, incremental updates, staged rollouts</p>
                <p><strong>Benefits:</strong></p>
                <ul>
                    <li>Supports orphaned parent records (e.g., programs without CSLOs)</li>
                    <li>Minimal data duplication</li>
                    <li>Import entities independently</li>
                    <li>Update specific entities without re-importing entire hierarchy</li>
                </ul>
                <p><strong>Sample Files:</strong> Each file below has a <em>Download sample</em> link (<code>normalized_FILE_01_term.csv</code> through <code>normalized_FILE_12_xlist.csv</code>) showing the expected columns.</p>
                <p><strong>Documentation:</strong> See <code>docs/term_centric_schema.yaml</code> for complete field specifications</p>
                <div class="alert alert-info mb-0" role="note">
                    <i class="bi bi-copy me-2" aria-hidden="true"></i>
                    <strong>Using Copy Term Data?</strong> It copies the data represented by files 2&ndash;9. File 1 (Term) is not copied because the source and destination terms must already exist. Files 10&ndash;12 (Section, Enrollment, and Cross-Listing) must be imported separately.
                </div>
            </div>
        </div>
        
<?php
// Plain-language summary of what each file's import does, shown in each card's
// info hint and in the progress window while the import runs.
// 'parents' names everything "Auto-create missing parent records" can add for
// that file, including the parents' own parents (a new course also gets its
// department and term; a new PSLO gets its program, department and term).
$importPlans = [
    1 => ['actions' => [
            'Adds terms that are new',
            'Updates terms that already exist, matched by term code',
            'Leaves deactivated terms unchanged and skips their rows',
        ], 'parents' => null],
    2 => ['actions' => [
            'Adds departments that are new',
            'Updates departments that already exist, matched by term and department code',
            'Leaves deactivated departments unchanged and skips their rows',
        ], 'parents' => 'terms'],
    3 => ['actions' => [
            'Adds institutional outcomes (ISLOs) that are new',
            'Updates ISLOs that already exist, matched by term and outcome code',
            'Leaves deactivated ISLOs unchanged and skips their rows',
        ], 'parents' => 'terms'],
    4 => ['actions' => [
            'Adds programs that are new',
            'Updates programs that already exist, matched by term and program code',
            'Leaves deactivated programs unchanged and skips their rows',
        ], 'parents' => 'terms and departments'],
    5 => ['actions' => [
            'Adds program outcomes (PSLOs) that are new and links each one to its ISLO',
            'Updates PSLOs that already exist, matched by program and outcome code',
            'Skips rows whose ISLO is not in the term yet (import File 3 first)',
        ], 'parents' => 'terms, departments and programs'],
    6 => ['actions' => [
            'Adds courses that are new',
            'Updates courses that already exist, matched by term and course number',
            'Leaves deactivated courses unchanged and skips their rows',
        ], 'parents' => 'terms and departments'],
    7 => ['actions' => [
            'Links each program to its courses',
            'Keeps links that already exist as they are',
            'Repairs links from older imports that paired a program and a course from different terms',
        ], 'parents' => 'terms, departments, programs and courses'],
    8 => ['actions' => [
            'Adds course outcomes (CSLOs) that are new',
            'Updates CSLOs that already exist, matched by course and outcome code',
            'Leaves deactivated CSLOs unchanged and skips their rows',
        ], 'parents' => 'terms, departments and courses'],
    9 => ['actions' => [
            'Links course outcomes (CSLOs) to program outcomes (PSLOs)',
            'Links every CSLO in the term that has the outcome code in the row',
            'Skips rows with no matching CSLO (import File 8 first)',
        ], 'parents' => 'terms, departments, programs and PSLOs'],
    10 => ['actions' => [
            'Adds sections that are new',
            'Updates sections that already exist, matched by term and CRN',
            'Leaves deactivated sections unchanged and skips their rows',
        ], 'parents' => 'terms, departments and courses'],
    11 => ['actions' => [
            'Adds students who are new and updates the names of existing students, stored encrypted',
            'Enrolls each student in the section with the row\'s term and CRN, or updates the existing enrollment',
            'Skips rows whose section is not there yet (import File 10 first)',
        ], 'parents' => 'terms'],
    12 => ['actions' => [
            'Puts each section, matched by term and CRN, in its cross-list group',
            'Moves a section that is already cross-listed to the group in the row',
            'Leaves deactivated cross-listings unchanged and skips their rows',
        ], 'parents' => 'terms'],
];
?>

        <!-- File Import Forms -->
        <div class="row">
            <?php
            $files = [
                1 => ['name' => 'Term', 'icon' => 'calendar-week', 'desc' => 'Term definitions (term_code, term_name, dates)'],
                2 => ['name' => 'Department', 'icon' => 'building', 'desc' => 'Departments/Disciplines per term'],
                3 => ['name' => 'ISLO', 'icon' => 'flag', 'desc' => 'Institutional Student Learning Outcomes'],
                4 => ['name' => 'Program', 'icon' => 'mortarboard', 'desc' => 'Academic programs per term'],
                5 => ['name' => 'PSLO', 'icon' => 'bullseye', 'desc' => 'Program Student Learning Outcomes'],
                6 => ['name' => 'Course', 'icon' => 'book', 'desc' => 'Courses per term'],
                7 => ['name' => 'Program-Course Mapping', 'icon' => 'diagram-3', 'desc' => 'Junction table linking programs to courses'],
                8 => ['name' => 'CSLO', 'icon' => 'journal-check', 'desc' => 'Course Student Learning Outcomes'],
                9 => ['name' => 'CSLO-PSLO Mapping', 'icon' => 'arrow-left-right', 'desc' => 'Junction table linking CSLOs to PSLOs'],
                10 => ['name' => 'Section', 'icon' => 'people', 'desc' => 'Course sections (CRN mapping)'],
                11 => ['name' => 'Enrollment', 'icon' => 'person-check', 'desc' => 'Student enrollments per section'],
                12 => ['name' => 'Cross-Listing', 'icon' => 'layer-group', 'desc' => 'Cross-listed section groups (XList mapping)']
            ];
            
            foreach ($files as $fileNum => $fileInfo):
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card file-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start mb-3">
                            <span class="file-number flex-shrink-0"><?= $fileNum ?></span>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h3 class="h5 mb-0 d-flex align-items-center gap-2">
                                        <i class="bi bi-<?= $fileInfo['icon'] ?>" aria-hidden="true"></i><?= htmlspecialchars($fileInfo['name']) ?>
                                    </h3>
                                    <?php if (isset($importPlans[$fileNum])): ?>
                                    <button type="button" class="btn btn-link import-info-button p-0 ms-auto" data-file-num="<?= $fileNum ?>" aria-label="What the <?= htmlspecialchars($fileInfo['name']) ?> import does" aria-expanded="false">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <p class="card-text text-muted small mb-0"><?= htmlspecialchars($fileInfo['desc']) ?></p>
                            </div>
                        </div>
                         
                        <form method="POST" enctype="multipart/form-data" class="mt-3 normalized-import-form d-flex flex-column flex-grow-1">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="file_num" value="<?= $fileNum ?>">
                            
                            <div class="mb-3">
                                <label for="csv_upload_<?= $fileNum ?>" class="form-label">Upload CSV File <?= $fileNum ?></label>
                                <input type="file" class="form-control" id="csv_upload_<?= $fileNum ?>" name="csv_upload" accept=".csv" required>
                                <div class="form-text">Expected: <code>normalized_FILE_<?= sprintf('%02d', $fileNum) ?>_*.csv</code></div>
                                <?php
                                $sampleMatches = glob(__DIR__ . '/../assets/sample_data/normalized_FILE_' . sprintf('%02d', $fileNum) . '_*.csv') ?: [];
                                if ($sampleMatches):
                                    $sampleName = basename($sampleMatches[0]);
                                ?>
                                <div class="form-text">
                                    <a href="<?= htmlspecialchars(BASE_URL . 'assets/sample_data/' . rawurlencode($sampleName)) ?>" download="<?= htmlspecialchars($sampleName) ?>">
                                        <i class="bi bi-download me-1" aria-hidden="true"></i>Download sample <?= htmlspecialchars($sampleName) ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($fileNum > 1): ?>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="auto_create_<?= $fileNum ?>" name="auto_create_parents" value="1" checked>
                                <label class="form-check-label" for="auto_create_<?= $fileNum ?>">
                                    <i class="bi bi-magic me-1"></i>Auto-create missing parent records
                                </label>
                                <div class="form-text">If checked, automatically creates missing dependencies instead of skipping rows</div>
                            </div>
                            <?php endif; ?>
                            
                            <button type="submit" class="btn btn-primary w-100 import-submit-button mt-auto">
                                <i class="bi bi-upload me-2"></i>Import FILE <?= $fileNum ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Import Order -->
        <div class="alert alert-warning mt-4">
            <h2 class="h6 alert-heading fw-semibold"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>Important: import the files in order, 1 through 12</h2>
            <p>Each file refers to records from earlier files, so importing in order gets everything right on the first pass.</p>
            <p class="mb-1">An import done out of order can be fixed by importing again. Every import updates the records it matches instead of adding duplicates, so importing a file again is safe.</p>
            <ul class="mb-0">
                <li>With <strong>Auto-create missing parent records</strong> on, a missing parent is added with a placeholder name such as &ldquo;Auto-created: ENGL-101&rdquo; (a term also gets placeholder dates). Importing that parent&rsquo;s file afterwards replaces the placeholders with the real names, dates and links.</li>
                <li>Rows that were skipped are not saved. That happens when auto-create is off, or when a row needs an ISLO (File 3), a CSLO (File 8) or a section (File 10) that is not there yet. Import the missing file, then import the skipped file again.</li>
            </ul>
        </div>
        
    </div>
</div>

<!-- Import progress window: locks the page while an import runs. -->
<div class="modal fade import-progress" id="importProgressModal" tabindex="-1" aria-labelledby="importProgressTitle" aria-describedby="importProgressFile" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body px-4 py-4 px-md-5 text-center">
                <div class="import-timer mx-auto mb-3" id="importTimer" data-state="running">
                    <div class="spinner-border import-timer-ring" aria-hidden="true"></div>
                    <div class="import-timer-text">
                        <span class="import-timer-value" id="importProgressElapsed">0:00</span>
                        <span class="import-timer-label">Elapsed</span>
                    </div>
                </div>
                <h2 class="modal-title h5 mb-1" id="importProgressTitle">Importing</h2>
                <p class="import-file small mb-4" id="importProgressFile"></p>

                <div class="import-plan text-start mb-4">
                    <h3 class="import-plan-heading">What this import does</h3>
                    <ul class="list-unstyled mb-0" id="importProgressPlan"></ul>
                </div>

                <ol class="import-steps list-unstyled text-start mb-3">
                    <li id="importStepUpload" data-state="active">
                        <span class="import-step-icon" aria-hidden="true"></span>
                        <span class="import-step-label">Upload file</span>
                        <span class="import-step-detail" id="importUploadDetail"></span>
                    </li>
                    <li id="importStepProcess" data-state="pending">
                        <span class="import-step-icon" aria-hidden="true"></span>
                        <span class="import-step-label">Import records</span>
                        <span class="import-step-detail" id="importProcessDetail"></span>
                    </li>
                </ol>

                <div class="progress import-bar mb-2" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="importProgressBar">
                    <div class="progress-bar" style="width: 0%"></div>
                </div>
                <p class="import-estimate small mb-0" id="importProgressEstimate"></p>

                <div class="alert alert-danger text-start mt-3 mb-0 d-none" role="alert" id="importProgressError"></div>
                <p class="import-hint small mt-3 mb-0" id="importProgressHint"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Keep this tab open. The results appear here when the import finishes.</p>
                <div class="visually-hidden" role="status" aria-live="polite" id="importProgressStatus"></div>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0 pb-4 d-none" id="importProgressFooter">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Recent timings on this server, per file number (seconds per byte).
    const timingEstimates = <?= json_encode((object)importTimingEstimates(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const importPlans = <?= json_encode($importPlans, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;

    const forms = Array.from(document.querySelectorAll('.normalized-import-form'));
    const modalEl = document.getElementById('importProgressModal');
    const timerEl = document.getElementById('importTimer');
    const elapsedEl = document.getElementById('importProgressElapsed');
    const titleEl = document.getElementById('importProgressTitle');
    const fileEl = document.getElementById('importProgressFile');
    const uploadStep = document.getElementById('importStepUpload');
    const processStep = document.getElementById('importStepProcess');
    const uploadDetail = document.getElementById('importUploadDetail');
    const processDetail = document.getElementById('importProcessDetail');
    const barWrap = document.getElementById('importProgressBar');
    const bar = barWrap.querySelector('.progress-bar');
    const estimateEl = document.getElementById('importProgressEstimate');
    const errorEl = document.getElementById('importProgressError');
    const hintEl = document.getElementById('importProgressHint');
    const statusEl = document.getElementById('importProgressStatus');
    const footerEl = document.getElementById('importProgressFooter');
    const planEl = document.getElementById('importProgressPlan');
    const modal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

    const stepIcons = {
        pending: '<i class="bi bi-circle"></i>',
        active: '<span class="spinner-border spinner-border-sm"></span>',
        done: '<i class="bi bi-check-circle-fill"></i>',
        failed: '<i class="bi bi-x-circle-fill"></i>'
    };

    let importInProgress = false;
    let tick = null;
    let startedAt = 0;
    let processingStartedAt = 0;
    let estimateSeconds = 0;

    modalEl.addEventListener('shown.bs.modal', function () { modalEl.removeAttribute('data-opening'); });

    function setStep(step, state) {
        step.dataset.state = state;
        step.querySelector('.import-step-icon').innerHTML = stepIcons[state];
    }

    function setBar(percent, indeterminate) {
        bar.style.width = percent + '%';
        bar.classList.toggle('progress-bar-striped', indeterminate);
        bar.classList.toggle('progress-bar-animated', indeterminate);
        if (indeterminate) {
            barWrap.removeAttribute('aria-valuenow');
        } else {
            barWrap.setAttribute('aria-valuenow', String(Math.round(percent)));
        }
    }

    function clock(totalSeconds) {
        const total = Math.max(0, Math.floor(totalSeconds));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = String(total % 60).padStart(2, '0');
        return hours > 0 ? hours + ':' + String(minutes).padStart(2, '0') + ':' + seconds : minutes + ':' + seconds;
    }

    function roughDuration(seconds) {
        if (seconds < 5) return 'a few seconds';
        if (seconds < 55) return 'about ' + Math.max(5, Math.round(seconds / 5) * 5) + ' seconds';
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return 'about ' + minutes + (minutes === 1 ? ' minute' : ' minutes');
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        return 'about ' + hours + (hours === 1 ? ' hour' : ' hours') + (rest ? ' ' + rest + ' minutes' : '');
    }

    function fileSize(bytes) {
        if (bytes < 1024) return bytes + (bytes === 1 ? ' byte' : ' bytes');
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function announce(text) {
        statusEl.textContent = text;
    }

    function onTick() {
        const now = Date.now();
        elapsedEl.textContent = clock((now - startedAt) / 1000);
        if (!processingStartedAt) {
            return;
        }
        const processing = (now - processingStartedAt) / 1000;
        if (estimateSeconds > 0) {
            // Ease toward 95% so the bar never claims to be finished.
            const ratio = processing / estimateSeconds;
            setBar(Math.min(95, ratio * 95), false);
            processDetail.textContent = ratio < 0.9
                ? roughDuration(estimateSeconds - processing) + ' left'
                : (ratio < 1.5 ? 'Almost done' : 'Taking longer than usual');
        } else {
            processDetail.textContent = clock(processing);
        }
    }

    function stopClock() {
        window.clearInterval(tick);
        tick = null;
    }

    // The plan's lines, with the parent-record line following the auto-create box.
    function planLines(fileNum, autoCreate) {
        const plan = importPlans[fileNum];
        if (!plan) {
            return [];
        }
        const lines = plan.actions.slice();
        if (plan.parents) {
            lines.push(autoCreate
                ? 'Creates any ' + plan.parents + ' that rows refer to but are not there yet'
                : 'Skips rows whose ' + plan.parents + ' are not there yet (auto-create is off)');
        }
        return lines;
    }

    function fillPlanList(list, lines) {
        list.textContent = '';
        lines.forEach(function (line) {
            const item = document.createElement('li');
            const icon = document.createElement('i');
            icon.className = 'bi bi-check2';
            icon.setAttribute('aria-hidden', 'true');
            item.appendChild(icon);
            item.appendChild(document.createTextNode(line));
            list.appendChild(item);
        });
    }

    function autoCreateChecked(form) {
        const input = form ? form.querySelector('input[name="auto_create_parents"]') : null;
        return !!(input && input.checked);
    }

    function showPlan(fileNum, autoCreate) {
        const lines = planLines(fileNum, autoCreate);
        planEl.parentElement.classList.toggle('d-none', lines.length === 0);
        fillPlanList(planEl, lines);
    }

    // Info hint on each card: the same plan, shown before importing.
    // Opens on hover or keyboard focus, stays open when clicked, and closes
    // with Escape or a click elsewhere (WCAG 1.4.13).
    document.querySelectorAll('.import-info-button').forEach(function (button) {
        if (!window.bootstrap) {
            return;
        }
        const card = button.closest('.card-body');
        const form = card.querySelector('.normalized-import-form');
        const fileNum = button.dataset.fileNum;
        let pinned = false;
        let hideTimer = null;

        function content() {
            const wrap = document.createElement('div');
            const list = document.createElement('ul');
            list.className = 'list-unstyled mb-0 import-plan-list';
            fillPlanList(list, planLines(fileNum, autoCreateChecked(form)));
            wrap.appendChild(list);
            return wrap;
        }

        const popover = new bootstrap.Popover(button, {
            trigger: 'manual',
            placement: 'auto',
            html: true,
            title: 'What this import does',
            content: content,
            customClass: 'import-info-popover',
            container: 'body',
            // Keep a gap from the screen edge on narrow screens.
            popperConfig: function (config) {
                config.modifiers.push({ name: 'preventOverflow', options: { padding: 8 } });
                return config;
            }
        });

        function show() {
            window.clearTimeout(hideTimer);
            if (!button.hasAttribute('data-open')) {
                popover.show();
            }
        }
        function hide() {
            window.clearTimeout(hideTimer);
            pinned = false;
            popover.hide();
        }
        function hideSoon() {
            if (pinned) {
                return;
            }
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(function () {
                const tip = popover.tip;
                if (!pinned && !(tip && tip.matches(':hover')) && document.activeElement !== button) {
                    popover.hide();
                }
            }, 200);
        }

        button.addEventListener('shown.bs.popover', function () {
            button.setAttribute('data-open', '');
            button.setAttribute('aria-expanded', 'true');
            const tip = popover.tip;
            if (tip) {
                button.setAttribute('aria-describedby', tip.id);
                tip.addEventListener('mouseleave', hideSoon);
                tip.addEventListener('mouseenter', function () { window.clearTimeout(hideTimer); });
            }
        });
        button.addEventListener('hidden.bs.popover', function () {
            button.removeAttribute('data-open');
            button.setAttribute('aria-expanded', 'false');
            button.removeAttribute('aria-describedby');
        });
        button.addEventListener('mouseenter', show);
        button.addEventListener('mouseleave', hideSoon);
        button.addEventListener('focus', show);
        button.addEventListener('blur', hideSoon);
        button.addEventListener('click', function () {
            if (pinned) {
                hide();
            } else {
                pinned = true;
                show();
            }
        });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hide();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && button.hasAttribute('data-open')) {
                hide();
            }
        });
        document.addEventListener('click', function (event) {
            const tip = popover.tip;
            if (pinned && !button.contains(event.target) && !(tip && tip.contains(event.target))) {
                hide();
            }
        });
        // Keep an open hint in step with the auto-create box.
        const autoCreate = form ? form.querySelector('input[name="auto_create_parents"]') : null;
        if (autoCreate) {
            autoCreate.addEventListener('change', function () {
                if (button.hasAttribute('data-open')) {
                    popover.setContent({ '.popover-body': content() });
                }
            });
        }
    });

    function resetWindow(label, file, fileNum) {
        titleEl.textContent = 'Importing ' + label;
        fileEl.textContent = file ? file.name + ' · ' + fileSize(file.size) : '';
        timerEl.dataset.state = 'running';
        elapsedEl.textContent = '0:00';
        setStep(uploadStep, 'active');
        setStep(processStep, 'pending');
        uploadDetail.textContent = '0%';
        processDetail.textContent = '';
        setBar(0, false);
        errorEl.classList.add('d-none');
        errorEl.textContent = '';
        hintEl.classList.remove('d-none');
        footerEl.classList.add('d-none');

        const history = timingEstimates[fileNum];
        estimateSeconds = history && file ? history.secondsPerByte * file.size : 0;
        if (estimateSeconds > 0) {
            estimateEl.textContent = 'Usually takes ' + roughDuration(estimateSeconds) + ' for a file this size, based on the last '
                + (history.samples === 1 ? 'import' : history.samples + ' imports') + ' of this file on this server.';
        } else {
            estimateEl.textContent = 'This server has no timing history for this file yet. The time will be recorded for next time.';
        }
    }

    function showFailure(message) {
        importInProgress = false;
        stopClock();
        timerEl.dataset.state = 'failed';
        titleEl.textContent = 'Import did not finish';
        setStep(uploadStep.dataset.state === 'done' ? processStep : uploadStep, 'failed');
        barWrap.classList.add('d-none');
        estimateEl.textContent = '';
        hintEl.classList.add('d-none');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
        footerEl.classList.remove('d-none');
        const closeButton = footerEl.querySelector('button');
        // A fast failure can arrive before the window finishes opening and takes focus.
        if (modalEl.classList.contains('show') && !modalEl.hasAttribute('data-opening')) {
            closeButton.focus();
        } else {
            modalEl.addEventListener('shown.bs.modal', function () { closeButton.focus(); }, { once: true });
        }
    }

    modalEl.addEventListener('hidden.bs.modal', function () {
        barWrap.classList.remove('d-none');
    });

    // Warn before leaving while the server is still importing.
    window.addEventListener('beforeunload', function (event) {
        if (importInProgress) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!modal || !window.XMLHttpRequest || !window.FormData) {
                return; // Fall back to a normal form post.
            }
            event.preventDefault();
            if (importInProgress) {
                return;
            }
            importInProgress = true;

            const heading = form.closest('.card-body').querySelector('h3');
            const label = heading ? heading.textContent.trim() : 'file';
            const fileInput = form.querySelector('input[type="file"]');
            const file = fileInput && fileInput.files.length ? fileInput.files[0] : null;
            const fileNum = form.querySelector('input[name="file_num"]').value;

            resetWindow(label, file, fileNum);
            showPlan(fileNum, autoCreateChecked(form));
            startedAt = Date.now();
            processingStartedAt = 0;
            tick = window.setInterval(onTick, 1000);
            modalEl.setAttribute('data-opening', '');
            modal.show();
            announce('Uploading ' + label + ' file.');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action') || window.location.href);

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable && !processingStartedAt) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    uploadDetail.textContent = percent + '%';
                    setBar(percent, false);
                }
            });
            xhr.upload.addEventListener('load', function () {
                processingStartedAt = Date.now();
                setStep(uploadStep, 'done');
                uploadDetail.textContent = clock((processingStartedAt - startedAt) / 1000);
                setStep(processStep, 'active');
                if (estimateSeconds > 0) {
                    setBar(0, false);
                } else {
                    setBar(100, true);
                }
                onTick();
                announce('Upload complete. Importing records.');
            });

            xhr.addEventListener('load', function () {
                if (xhr.status >= 200 && xhr.status < 400 && xhr.responseText) {
                    // Show the server's results page in place of this one.
                    importInProgress = false;
                    stopClock();
                    document.open();
                    document.write(xhr.responseText);
                    document.close();
                    return;
                }
                // A short plain-text refusal (an expired form) is worth showing as is.
                const body = (xhr.responseText || '').trim();
                const isPlainText = body !== '' && body.length < 300 && body.indexOf('<') === -1;
                showFailure(isPlainText
                    ? body
                    : 'The server returned an error (HTTP ' + xhr.status + '). The import may not have finished. Check the data before trying again.');
            });
            xhr.addEventListener('error', function () {
                showFailure('The connection to the server was lost. The import may still be running. Wait a few minutes, then reload the page to check.');
            });

            xhr.send(new FormData(form));
        });
    });
});
</script>

<?php
$theme->showFooter($context);
?>
