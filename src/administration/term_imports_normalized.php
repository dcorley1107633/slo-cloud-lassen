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
        die('CSRF token validation failed');
    }
    
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
                <p><strong>Sample Files:</strong> <code>data/normalized_FILE_01_term.csv</code> through <code>normalized_FILE_12_xlist.csv</code></p>
                <p><strong>Documentation:</strong> See <code>docs/term_centric_schema.yaml</code> for complete field specifications</p>
                <div class="alert alert-info mb-0" role="note">
                    <i class="bi bi-copy me-2" aria-hidden="true"></i>
                    <strong>Using Copy Term Data?</strong> It copies the data represented by files 2&ndash;9. File 1 (Term) is not copied because the source and destination terms must already exist. Files 10&ndash;12 (Section, Enrollment, and Cross-Listing) must be imported separately.
                </div>
            </div>
        </div>
        
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
                                <h3 class="h5 mb-1 d-flex align-items-center gap-2">
                                    <i class="bi bi-<?= $fileInfo['icon'] ?>"></i><?= htmlspecialchars($fileInfo['name']) ?>
                                </h3>
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
        
        <!-- Import Order Warning -->
        <div class="alert alert-warning mt-4">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Important:</strong> Files must be imported in order (1 through 12) to maintain referential integrity. 
            Each file references entities defined in earlier files.
        </div>
        
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const forms = Array.from(document.querySelectorAll('.normalized-import-form'));
    const importButtons = Array.from(document.querySelectorAll('.import-submit-button'));
    let importInProgress = false;

    importButtons.forEach(function (button) {
        button.dataset.originalHtml = button.innerHTML;
    });

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (importInProgress) {
                event.preventDefault();
                return;
            }

            importInProgress = true;
            const submittedButton = event.submitter || form.querySelector('.import-submit-button');

            importButtons.forEach(function (button) {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            });

            if (submittedButton) {
                submittedButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing&hellip;';
                submittedButton.setAttribute('aria-busy', 'true');
                submittedButton.setAttribute('aria-label', 'Import processing');
            }
        });
    });

    // Restore controls when the browser returns this page from its back-forward cache.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }

        importInProgress = false;
        importButtons.forEach(function (button) {
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-label');
            button.innerHTML = button.dataset.originalHtml;
        });
    });
});
</script>

<?php
$theme->showFooter($context);
?>
