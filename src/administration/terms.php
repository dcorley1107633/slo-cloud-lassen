<?php
declare(strict_types=1);

/**
 * Terms Administration (Import Only)
 * 
 * Manage academic terms via CSV import.
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// Handle POST requests
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $errorMessage = 'Deletion is unavailable in the dashboard. Deactivate the record instead.';
        $action = '';
    }
    
    try {
        if ($action === 'copy_term_data') {
            $sourceTermCode = trim($_POST['source_term_code'] ?? '');
            $destTermCode = trim($_POST['dest_term_code'] ?? '');
            
            if (empty($sourceTermCode) || empty($destTermCode)) {
                $errorMessage = 'Both source and destination term codes are required';
            } elseif ($sourceTermCode === $destTermCode) {
                $errorMessage = 'Source and destination terms cannot be the same';
            } else {
                // Verify both terms exist and get their PKs
                $sourceCheck = $db->query("SELECT terms_pk FROM {$dbPrefix}terms WHERE term_code = ? AND is_active = 1", [$sourceTermCode], 's');
                $sourceTermRow = $sourceCheck->fetch();
                $destCheck = $db->query("SELECT terms_pk FROM {$dbPrefix}terms WHERE term_code = ? AND is_active = 1", [$destTermCode], 's');
                $destTermRow = $destCheck->fetch();
                
                if (!$sourceTermRow) {
                    $errorMessage = "Source term '$sourceTermCode' not found";
                } elseif (!$destTermRow) {
                    $errorMessage = "Destination term '$destTermCode' not found";
                } else {
                    $sourceTermPk = $sourceTermRow['terms_pk'];
                    $destTermPk = $destTermRow['terms_pk'];
                    
                    // Start transaction
                    if (!$db->beginTransaction()) {
                        throw new \Exception('Failed to start database transaction');
                    }
                    
                    try {
                        $copiedInstitutionalOutcomes = 0;
                        $copiedDisciplines = 0;
                        $copiedPrograms = 0;
                        $copiedProgramOutcomes = 0;
                        $copiedCourses = 0;
                        $copiedSLOs = 0;
                        $copiedProgramCourses = 0;
                        $updatedInstitutionalOutcomes = 0;
                        $updatedDisciplines = 0;
                        $updatedPrograms = 0;
                        $updatedProgramOutcomes = 0;
                        $updatedCourses = 0;
                        $updatedSLOs = 0;
                        $skippedInactive = 0;
                        $userId = getValidUserId();
                        
                        // FK mapping arrays - map source PKs to destination PKs
                        $ioMapping = [];           // institutional_outcomes_pk mapping
                        $disciplineMapping = [];   // disciplines_pk mapping
                        $programMapping = [];      // programs_pk mapping
                        $programOutcomeMapping = []; // program_outcomes_pk mapping
                        $courseMapping = [];       // courses_pk mapping
                        
                        // Copy Institutional Outcomes
                        $ioResult = $db->query(
                            "SELECT io.institutional_outcomes_pk, io.outcome_code, io.outcome_description, io.sequence_num
                             FROM {$dbPrefix}institutional_outcomes io
                             WHERE io.term_fk = ? AND " . lifecycle_active_predicate('institutional_outcomes', 'io', $dbPrefix),
                            [$sourceTermPk],
                            'i'
                        );
                        $institutionalOutcomes = $ioResult->fetchAll();
                        
                        foreach ($institutionalOutcomes as $io) {
                            // Check if exists in destination
                            $existingCheck = $db->query(
                                "SELECT institutional_outcomes_pk, is_active FROM {$dbPrefix}institutional_outcomes 
                                 WHERE term_fk = ? AND outcome_code = ?",
                                [$destTermPk, $io['outcome_code']],
                                'is'
                            );
                            $existing = $existingCheck->fetch();
                            if ($existing && !(bool)$existing['is_active']) {
                                $skippedInactive++;
                                continue;
                            }
                            
                            if ($existing) {
                                $db->query(
                                    "UPDATE {$dbPrefix}institutional_outcomes
                                     SET outcome_description = ?, sequence_num = ?,
                                         updated_at = {$db->now()}, updated_by_fk = ?
                                     WHERE institutional_outcomes_pk = ?",
                                    [$io['outcome_description'], $io['sequence_num'], $userId, $existing['institutional_outcomes_pk']],
                                    'siii'
                                );
                                // Map to existing
                                $ioMapping[$io['institutional_outcomes_pk']] = $existing['institutional_outcomes_pk'];
                                $updatedInstitutionalOutcomes++;
                            } else {
                                // Insert new
                                $db->query(
                                    "INSERT INTO {$dbPrefix}institutional_outcomes 
                                     (term_fk, outcome_code, outcome_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                                     VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                    [$destTermPk, $io['outcome_code'], $io['outcome_description'], $io['sequence_num'], $userId, $userId],
                                    'issiii'
                                );
                                $newIoPk = $db->getInsertId();
                                $ioMapping[$io['institutional_outcomes_pk']] = $newIoPk;
                                $copiedInstitutionalOutcomes++;
                            }
                        }
                        
                        // Copy Disciplines
                        $disciplinesResult = $db->query(
                            "SELECT d.disciplines_pk, d.discipline_name, d.discipline_code
                             FROM {$dbPrefix}disciplines d
                             WHERE d.term_fk = ? AND " . lifecycle_active_predicate('disciplines', 'd', $dbPrefix),
                            [$sourceTermPk],
                            'i'
                        );
                        $disciplines = $disciplinesResult->fetchAll();
                        
                        foreach ($disciplines as $discipline) {
                            $sourceDisciplinePk = $discipline['disciplines_pk'];
                            
                            // Check if discipline exists in destination
                            $existingCheck = $db->query(
                                "SELECT disciplines_pk, is_active FROM {$dbPrefix}disciplines 
                                 WHERE term_fk = ? AND discipline_code = ?",
                                [$destTermPk, $discipline['discipline_code']],
                                'is'
                            );
                            $existing = $existingCheck->fetch();
                            if ($existing && !(bool)$existing['is_active']) {
                                $skippedInactive++;
                                continue;
                            }
                            
                            if ($existing) {
                                $db->query(
                                    "UPDATE {$dbPrefix}disciplines
                                     SET discipline_name = ?, updated_at = {$db->now()}, updated_by_fk = ?
                                     WHERE disciplines_pk = ?",
                                    [$discipline['discipline_name'], $userId, $existing['disciplines_pk']],
                                    'sii'
                                );
                                // Map to existing discipline
                                $disciplineMapping[$sourceDisciplinePk] = $existing['disciplines_pk'];
                                $updatedDisciplines++;
                            } else {
                                // Insert new discipline
                                $db->query(
                                    "INSERT INTO {$dbPrefix}disciplines 
                                     (term_fk, discipline_code, discipline_name, created_at, updated_at, created_by_fk, updated_by_fk) 
                                     VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                    [$destTermPk, $discipline['discipline_code'], $discipline['discipline_name'], $userId, $userId],
                                    'issii'
                                );
                                $newDisciplinePk = $db->getInsertId();
                                $disciplineMapping[$sourceDisciplinePk] = $newDisciplinePk;
                                $copiedDisciplines++;
                            }
                        }
                        
                        // Copy Programs
                        $programsResult = $db->query(
                            "SELECT p.programs_pk, p.program_code, p.program_name, p.degree_type, p.discipline_fk
                             FROM {$dbPrefix}programs p
                             WHERE p.term_fk = ? AND " . lifecycle_active_predicate('programs', 'p', $dbPrefix),
                            [$sourceTermPk],
                            'i'
                        );
                        $programs = $programsResult->fetchAll();
                        
                        foreach ($programs as $program) {
                            $sourceProgramPk = $program['programs_pk'];

                            // Map discipline_fk to the destination term for both
                            // the update and insert paths.
                            $newDisciplineFk = null;
                            if ($program['discipline_fk']) {
                                $newDisciplineFk = $disciplineMapping[$program['discipline_fk']] ?? null;
                            }
                            
                            // Check if program exists in destination term
                            $existingCheck = $db->query(
                                "SELECT programs_pk, is_active FROM {$dbPrefix}programs 
                                 WHERE term_fk = ? AND program_code = ?",
                                [$destTermPk, $program['program_code']],
                                'is'
                            );
                            $existing = $existingCheck->fetch();
                            if ($existing && !(bool)$existing['is_active']) {
                                $skippedInactive++;
                                continue;
                            }
                            
                            if ($existing) {
                                $db->query(
                                    "UPDATE {$dbPrefix}programs
                                     SET program_name = ?, discipline_fk = ?, degree_type = ?,
                                         updated_at = {$db->now()}, updated_by_fk = ?
                                     WHERE programs_pk = ?",
                                    [$program['program_name'], $newDisciplineFk, $program['degree_type'], $userId, $existing['programs_pk']],
                                    'sisii'
                                );
                                // Map to existing program
                                $programMapping[$sourceProgramPk] = $existing['programs_pk'];
                                $newProgramPk = $existing['programs_pk'];
                                $updatedPrograms++;
                            } else {
                                // Insert program
                                $db->query(
                                    "INSERT INTO {$dbPrefix}programs 
                                     (term_fk, program_code, program_name, discipline_fk, degree_type, created_at, updated_at, created_by_fk, updated_by_fk) 
                                     VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                    [$destTermPk, $program['program_code'], $program['program_name'], $newDisciplineFk, $program['degree_type'], $userId, $userId],
                                    'issisii'
                                );
                                $newProgramPk = $db->getInsertId();
                                $programMapping[$sourceProgramPk] = $newProgramPk;
                                $copiedPrograms++;
                            }
                            
                            // Copy Program Outcomes for this program
                            if ($sourceProgramPk) {
                                $programOutcomesResult = $db->query(
                                "SELECT po.program_outcomes_pk, po.outcome_code, po.outcome_description, po.sequence_num, po.institutional_outcomes_fk
                                 FROM {$dbPrefix}program_outcomes po
                                 WHERE po.program_fk = ? AND " . lifecycle_active_predicate('program_outcomes', 'po', $dbPrefix),
                                    [$sourceProgramPk],
                                    'i'
                                );
                                $programOutcomes = $programOutcomesResult->fetchAll();
                                
                                foreach ($programOutcomes as $outcome) {
                                    // Map institutional_outcomes_fk to new PK if it exists
                                    $newIoFk = null;
                                    if ($outcome['institutional_outcomes_fk']) {
                                        if (!isset($ioMapping[$outcome['institutional_outcomes_fk']])) {
                                            throw new \Exception(
                                                "Program outcome '{$outcome['outcome_code']}' is linked to an institutional outcome outside the source term"
                                            );
                                        }
                                        $newIoFk = $ioMapping[$outcome['institutional_outcomes_fk']];
                                    }

                                    $existingOutcomeCheck = $db->query(
                                        "SELECT program_outcomes_pk, is_active FROM {$dbPrefix}program_outcomes
                                         WHERE program_fk = ? AND outcome_code = ?",
                                        [$newProgramPk, $outcome['outcome_code']],
                                        'is'
                                    );
                                    $existingOutcome = $existingOutcomeCheck->fetch();
                                    if ($existingOutcome && !(bool)$existingOutcome['is_active']) {
                                        $skippedInactive++;
                                        continue;
                                    }

                                    if ($existingOutcome) {
                                        $newProgramOutcomePk = $existingOutcome['program_outcomes_pk'];
                                        $db->query(
                                            "UPDATE {$dbPrefix}program_outcomes
                                             SET outcome_description = ?, program_fk = ?,
                                                 institutional_outcomes_fk = ?, sequence_num = ?,
                                                 updated_at = {$db->now()}, updated_by_fk = ?
                                             WHERE program_outcomes_pk = ?",
                                            [
                                                $outcome['outcome_description'],
                                                $newProgramPk,
                                                $newIoFk,
                                                $outcome['sequence_num'],
                                                $userId,
                                                $newProgramOutcomePk,
                                            ],
                                            'siiiii'
                                        );
                                        $updatedProgramOutcomes++;
                                    } else {
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}program_outcomes 
                                             (outcome_code, outcome_description, program_fk, institutional_outcomes_fk, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$outcome['outcome_code'], $outcome['outcome_description'], $newProgramPk, $newIoFk, $outcome['sequence_num'], $userId, $userId],
                                            'ssiiiii'
                                        );
                                        $newProgramOutcomePk = $db->getInsertId();
                                        $copiedProgramOutcomes++;
                                    }

                                    $programOutcomeMapping[$outcome['program_outcomes_pk']] = $newProgramOutcomePk;
                                }
                            }
                        }
                        
                        // Copy Courses
                        $coursesResult = $db->query(
                            "SELECT c.courses_pk, c.course_number, c.course_name, c.discipline_fk
                             FROM {$dbPrefix}courses c
                             WHERE c.term_fk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix),
                            [$sourceTermPk],
                            'i'
                        );
                        $courses = $coursesResult->fetchAll();
                        
                        foreach ($courses as $course) {
                            $sourceCoursePk = $course['courses_pk'];

                            // Map discipline_fk to the destination term for both
                            // the update and insert paths.
                            $newDisciplineFk = null;
                            if ($course['discipline_fk']) {
                                $newDisciplineFk = $disciplineMapping[$course['discipline_fk']] ?? null;
                            }
                            
                            // Check if course exists in destination
                            $existingCheck = $db->query(
                                "SELECT courses_pk, is_active FROM {$dbPrefix}courses 
                                 WHERE term_fk = ? AND course_number = ?",
                                [$destTermPk, $course['course_number']],
                                'is'
                            );
                                    $existing = $existingCheck->fetch();
                                    if ($existing && !(bool)$existing['is_active']) {
                                        $skippedInactive++;
                                        continue;
                                    }
                                    
                                    if ($existing) {
                                $db->query(
                                    "UPDATE {$dbPrefix}courses
                                     SET course_name = ?, discipline_fk = ?,
                                         updated_at = {$db->now()}, updated_by_fk = ?
                                     WHERE courses_pk = ?",
                                    [$course['course_name'], $newDisciplineFk, $userId, $existing['courses_pk']],
                                    'siii'
                                );
                                // Map to existing course
                                $courseMapping[$sourceCoursePk] = $existing['courses_pk'];
                                $newCoursePk = $existing['courses_pk'];
                                $updatedCourses++;
                            } else {
                                // Insert course
                                $db->query(
                                    "INSERT INTO {$dbPrefix}courses 
                                     (term_fk, course_number, course_name, discipline_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                                     VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                    [$destTermPk, $course['course_number'], $course['course_name'], $newDisciplineFk, $userId, $userId],
                                    'issiii'
                                );
                                $newCoursePk = $db->getInsertId();
                                $courseMapping[$sourceCoursePk] = $newCoursePk;
                                $copiedCourses++;
                            }
                            
                            // Copy Student Learning Outcomes for this course
                            if ($sourceCoursePk) {
                                $slosResult = $db->query(
                                "SELECT slo.slo_code, slo.slo_description, slo.sequence_num, slo.program_outcomes_fk
                                 FROM {$dbPrefix}student_learning_outcomes slo
                                 WHERE slo.course_fk = ? AND " . lifecycle_active_predicate('student_learning_outcomes', 'slo', $dbPrefix),
                                    [$sourceCoursePk],
                                    'i'
                                );
                                $slos = $slosResult->fetchAll();
                                
                                foreach ($slos as $slo) {
                                    // Map program_outcomes_fk to the destination
                                    // before either updating or inserting the CSLO.
                                    $newProgramOutcomeFk = null;
                                    if ($slo['program_outcomes_fk']) {
                                        $newProgramOutcomeFk = $programOutcomeMapping[$slo['program_outcomes_fk']] ?? null;
                                    }

                                    // Copy Term Data must be idempotent just like the
                                    // normalized CSLO import. A CSLO is unique within
                                    // a course by (course_fk, slo_code).
                                    $existingSloCheck = $db->query(
                                        "SELECT student_learning_outcomes_pk, is_active
                                         FROM {$dbPrefix}student_learning_outcomes
                                         WHERE course_fk = ? AND slo_code = ?",
                                        [$newCoursePk, $slo['slo_code']],
                                        'is'
                                    );

                                    $existingSlo = $existingSloCheck->fetch();
                                    if ($existingSlo && !(bool)$existingSlo['is_active']) {
                                        $skippedInactive++;
                                        continue;
                                    }
                                    if ($existingSlo) {
                                        $db->query(
                                            "UPDATE {$dbPrefix}student_learning_outcomes
                                             SET slo_description = ?, sequence_num = ?,
                                                 program_outcomes_fk = ?,
                                                 updated_at = {$db->now()}, updated_by_fk = ?
                                             WHERE student_learning_outcomes_pk = ?",
                                            [
                                                $slo['slo_description'],
                                                $slo['sequence_num'],
                                                $newProgramOutcomeFk,
                                                $userId,
                                                $existingSlo['student_learning_outcomes_pk'],
                                            ],
                                            'siiii'
                                        );
                                        $updatedSLOs++;
                                    } else {
                                        $db->query(
                                            "INSERT INTO {$dbPrefix}student_learning_outcomes 
                                             (course_fk, program_outcomes_fk, slo_code, slo_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                                             VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                                            [$newCoursePk, $newProgramOutcomeFk, $slo['slo_code'], $slo['slo_description'], $slo['sequence_num'], $userId, $userId],
                                            'iissiii'
                                        );
                                        $copiedSLOs++;
                                    }
                                }
                            }
                        }
                        
                        // Copy Program-Course relationships
                        $programCoursesResult = $db->query(
                            "SELECT pc.program_fk, pc.course_fk 
                             FROM {$dbPrefix}program_courses pc
                             INNER JOIN {$dbPrefix}programs p ON pc.program_fk = p.programs_pk
                             WHERE p.term_fk = ?",
                            [$sourceTermPk],
                            'i'
                        );
                        $programCourses = $programCoursesResult->fetchAll();
                        
                        foreach ($programCourses as $pc) {
                            $newProgramFk = $programMapping[$pc['program_fk']] ?? null;
                            $newCourseFk = $courseMapping[$pc['course_fk']] ?? null;
                            
                            if ($newProgramFk && $newCourseFk) {
                                // Check if already exists
                                $existingCheck = $db->query(
                                    "SELECT program_courses_pk FROM {$dbPrefix}program_courses 
                                     WHERE program_fk = ? AND course_fk = ?",
                                    [$newProgramFk, $newCourseFk],
                                    'ii'
                                );
                                
                                if (!$existingCheck->fetch()) {
                                    $db->query(
                                        "INSERT INTO {$dbPrefix}program_courses (program_fk, course_fk, created_at, created_by_fk) 
                                         VALUES (?, ?, {$db->now()}, ?)",
                                        [$newProgramFk, $newCourseFk, $userId],
                                        'iii'
                                    );
                                    $copiedProgramCourses++;
                                }
                            }
                        }
                        
                        $db->commit();
                        
                        $copiedTotal = $copiedInstitutionalOutcomes + $copiedDisciplines + $copiedPrograms + $copiedProgramOutcomes + $copiedCourses + $copiedSLOs + $copiedProgramCourses;
                        $updatedTotal = $updatedInstitutionalOutcomes + $updatedDisciplines + $updatedPrograms + $updatedProgramOutcomes + $updatedCourses + $updatedSLOs;
                        if ($copiedTotal === 0 && $updatedTotal === 0) {
                            $successMessage = "Copy operation completed from '$sourceTermCode' to '$destTermCode'. The source term contained no records to upsert.";
                        } else {
                            $successMessage = "Upserted data from '$sourceTermCode' to '$destTermCode': $copiedTotal created, $updatedTotal updated, and $skippedInactive skipped because a destination match is deactivated. Created records: $copiedInstitutionalOutcomes institutional outcomes, $copiedDisciplines disciplines, $copiedPrograms programs, $copiedProgramOutcomes program outcomes, $copiedCourses courses, $copiedSLOs student learning outcomes, $copiedProgramCourses program-course links";
                        }
                    } catch (\Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                }
            }
        } elseif ($action === 'add') {
            $bannerTerm = trim($_POST['banner_term'] ?? '');
            $termCode = trim($_POST['term_code'] ?? '');
            $termName = trim($_POST['term_name'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            
            $errors = [];
            if (empty($bannerTerm)) {
                $errors[] = 'Banner term is required';
            }
            if (empty($termCode)) {
                $errors[] = 'Term code is required';
            }
            if (empty($termName)) {
                $errors[] = 'Term name is required';
            }
            // Dates are optional - used for sorting purposes only
            
            // Check for duplicate banner_term
            if (!empty($bannerTerm)) {
                $checkResult = $db->query(
                    "SELECT terms_pk FROM {$dbPrefix}terms WHERE banner_term = ?",
                    [$bannerTerm],
                    's'
                );
                if ($checkResult->fetch()) {
                    $errors[] = 'Banner term already exists';
                }
            }
            
            // Check for duplicate term_code
            if (!empty($termCode)) {
                $checkResult = $db->query(
                    "SELECT terms_pk FROM {$dbPrefix}terms WHERE term_code = ?",
                    [$termCode],
                    's'
                );
                if ($checkResult->fetch()) {
                    $errors[] = 'Term code already exists';
                }
            }
            
            if (empty($errors)) {
                $userId = getValidUserId();
                $db->query(
                    "INSERT INTO {$dbPrefix}terms (banner_term, term_code, term_name, academic_year, start_date, end_date, created_at, updated_at, created_by_fk, updated_by_fk)
                     VALUES (?, ?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                    [$bannerTerm, $termCode, $termName, $academicYear, $startDate, $endDate, $userId, $userId],
                    'ssssssii'
                );
                $successMessage = 'Term added successfully';
            } else {
                $errorMessage = implode('<br>', $errors);
            }
        } elseif ($action === 'edit') {
            $termsPk = (int)($_POST['terms_pk'] ?? 0);
            $bannerTerm = trim($_POST['banner_term'] ?? '');
            $termCode = trim($_POST['term_code'] ?? '');
            $termName = trim($_POST['term_name'] ?? '');
            $academicYear = trim($_POST['academic_year'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            
            $errors = [];
            if ($termsPk <= 0) {
                $errors[] = 'Invalid term ID';
            }
            if (empty($bannerTerm)) {
                $errors[] = 'Banner term is required';
            }
            if (empty($termCode)) {
                $errors[] = 'Term code is required';
            }
            if (empty($termName)) {
                $errors[] = 'Term name is required';
            }
            // Dates are optional - used for sorting purposes only
            
            // Check for duplicate term_code (excluding current record)
            if (!empty($termCode)) {
                $checkResult = $db->query(
                    "SELECT terms_pk FROM {$dbPrefix}terms WHERE term_code = ? AND terms_pk != ?",
                    [$termCode, $termsPk],
                    'si'
                );
                if ($checkResult->fetch()) {
                    $errors[] = 'Term code already exists';
                }
            }
            
            // Check for duplicate banner_term (excluding current record)
            if (!empty($bannerTerm)) {
                $checkResult = $db->query(
                    "SELECT terms_pk FROM {$dbPrefix}terms WHERE banner_term = ? AND terms_pk != ?",
                    [$bannerTerm, $termsPk],
                    'si'
                );
                if ($checkResult->fetch()) {
                    $errors[] = 'Banner term already exists';
                }
            }
            
            if (empty($errors)) {
                $userId = getValidUserId();
                $db->query(
                    "UPDATE {$dbPrefix}terms 
                     SET banner_term = ?, term_code = ?, term_name = ?, academic_year = ?, start_date = ?, end_date = ?, updated_at = {$db->now()}, updated_by_fk = ?
                     WHERE terms_pk = ?",
                    [$bannerTerm, $termCode, $termName, $academicYear, $startDate, $endDate, $userId, $termsPk],
                    'ssssssii'
                );
                $successMessage = 'Term updated successfully';
            } else {
                $errorMessage = implode('<br>', $errors);
            }
        } elseif ($action === 'delete') {
            $termsPk = (int)($_POST['terms_pk'] ?? 0);
            if ($termsPk > 0) {
                // Check if term has dependent records
                $checkPrograms = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}programs WHERE term_fk = ?", [$termsPk], 'i');
                $checkCourses = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}courses WHERE term_fk = ?", [$termsPk], 'i');
                $checkIO = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}institutional_outcomes WHERE term_fk = ?", [$termsPk], 'i');
                
                $programsCount = $checkPrograms->fetch()['total'] ?? 0;
                $coursesCount = $checkCourses->fetch()['total'] ?? 0;
                $ioCount = $checkIO->fetch()['total'] ?? 0;
                
                if ($programsCount > 0 || $coursesCount > 0 || $ioCount > 0) {
                    $errorMessage = "Cannot delete term: it has $programsCount programs, $coursesCount courses, and $ioCount institutional outcomes. Delete those first or use 'Clear Term Data'.";
                } else {
                    $db->query("DELETE FROM {$dbPrefix}terms WHERE terms_pk = ?", [$termsPk], 'i');
                    $successMessage = 'Term deleted successfully';
                }
            }
        } elseif ($action === 'clear_term_data') {
            $termCode = trim($_POST['term_code'] ?? '');
            $confirmText = trim($_POST['confirm_text'] ?? '');
            
            if (empty($termCode)) {
                $errorMessage = 'Term code is required';
            } elseif ($confirmText !== 'DELETE') {
                $errorMessage = 'You must type "DELETE" to confirm';
            } else {
                // Verify term exists
                $termCheck = $db->query("SELECT terms_pk FROM {$dbPrefix}terms WHERE term_code = ?", [$termCode], 's');
                $termRow = $termCheck->fetch();
                
                if (!$termRow) {
                    $errorMessage = "Term '$termCode' not found";
                } else {
                    $termPk = $termRow['terms_pk'];
                    
                    // Start transaction
                    if (!$db->beginTransaction()) {
                        throw new \Exception('Failed to start database transaction');
                    }
                     
                    try {
                        // Delete child records before their parents. Each operation
                        // stays set-based in the database and rowCount() supplies
                        // the success-message totals without a separate COUNT(*).
                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}assessments
                             WHERE enrollment_fk IN (
                                 SELECT e.enrollment_pk
                                 FROM {$dbPrefix}enrollment e
                                 INNER JOIN {$dbPrefix}sections s ON s.sections_pk = e.section_fk
                                 WHERE s.term_fk = ?
                             )",
                            [$termPk],
                            'i'
                        );
                        $deletedAssessments = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}section_slo_methods WHERE term_code = ?",
                            [$termCode],
                            's'
                        );
                        $deletedSectionSloMethods = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}enrollment
                             WHERE section_fk IN (
                                 SELECT sections_pk FROM {$dbPrefix}sections WHERE term_fk = ?
                             )",
                            [$termPk],
                            'i'
                        );
                        $deletedEnrollments = $statement->rowCount();
                        $deletedStudents = $deletedEnrollments > 0 ? cleanupOrphanedStudents() : 0;

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}sections WHERE term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedSections = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE pc FROM {$dbPrefix}program_courses pc
                             INNER JOIN {$dbPrefix}courses c
                                 ON c.courses_pk = pc.course_fk
                             WHERE c.term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedProgramCourses = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE slo FROM {$dbPrefix}student_learning_outcomes slo
                             INNER JOIN {$dbPrefix}courses c
                                 ON c.courses_pk = slo.course_fk
                             WHERE c.term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedSLOs = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE po FROM {$dbPrefix}program_outcomes po
                             INNER JOIN {$dbPrefix}programs p
                                 ON p.programs_pk = po.program_fk
                             WHERE p.term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedProgramOutcomes = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}courses WHERE term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedCourses = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}programs WHERE term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedPrograms = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}disciplines WHERE term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedDisciplines = $statement->rowCount();

                        $statement = $db->query(
                            "DELETE FROM {$dbPrefix}institutional_outcomes WHERE term_fk = ?",
                            [$termPk],
                            'i'
                        );
                        $deletedInstitutionalOutcomes = $statement->rowCount();
                        
                        $db->commit();
                        
                        // Build success message
                        $totalDeleted = $deletedAssessments + $deletedSectionSloMethods + $deletedEnrollments + $deletedSections + $deletedSLOs + 
                                       $deletedProgramCourses + $deletedCourses + $deletedDisciplines + $deletedProgramOutcomes + 
                                       $deletedPrograms + $deletedInstitutionalOutcomes + $deletedStudents;
                        
                        if ($totalDeleted === 0) {
                            $successMessage = "Clear operation completed for term '$termCode'. No data found to delete.";
                        } else {
                            $details = [];
                            if ($deletedAssessments > 0) $details[] = "$deletedAssessments assessments";
                            if ($deletedSectionSloMethods > 0) $details[] = "$deletedSectionSloMethods section SLO methods";
                            if ($deletedEnrollments > 0) $details[] = "$deletedEnrollments enrollments";
                            if ($deletedSections > 0) $details[] = "$deletedSections sections";
                            if ($deletedSLOs > 0) $details[] = "$deletedSLOs SLOs";
                            if ($deletedProgramCourses > 0) $details[] = "$deletedProgramCourses program-course links";
                            if ($deletedCourses > 0) $details[] = "$deletedCourses courses";
                            if ($deletedDisciplines > 0) $details[] = "$deletedDisciplines disciplines";
                            if ($deletedProgramOutcomes > 0) $details[] = "$deletedProgramOutcomes program outcomes";
                            if ($deletedPrograms > 0) $details[] = "$deletedPrograms programs";
                            if ($deletedInstitutionalOutcomes > 0) $details[] = "$deletedInstitutionalOutcomes institutional outcomes";
                            if ($deletedStudents > 0) $details[] = "$deletedStudents orphaned students";
                            
                            $successMessage = "Cleared data for term '$termCode': " . implode(', ', $details) . " deleted";
                        }
                    } catch (\Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                }
            }
        }
    } catch (\Exception $e) {
        $errorMessage = 'Error: ' . $e->getMessage();
        // Log the error for debugging
        error_log("Terms page error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Calculate statistics
$statsResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}terms");
$statsRow = $statsResult->fetch();
$totalTerms = $statsRow['total'] ?? 0;

$coursesResult = $db->query("SELECT COUNT(DISTINCT term_fk) as total FROM {$dbPrefix}courses");
$coursesRow = $coursesResult->fetch();
$termsWithCourses = $coursesRow['total'] ?? 0;

// Get all terms for dropdowns
$allTermsResult = $db->query("
    SELECT term_code, term_name 
    FROM {$dbPrefix}terms 
    ORDER BY term_code ASC
");
$allTerms = $allTermsResult->fetchAll();

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Terms Management',
    'currentPage' => 'admin_terms',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Terms']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .modal-body {
        max-height: 70vh;
        overflow-y: auto;
    }
</style>

<div class="app-content">
    <div class="container-fluid">
        
        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle" aria-hidden="true"></i> <?= $successMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Terms Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-calendar-week" aria-hidden="true"></i> Academic Terms
                </h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm me-1" onclick="exportAllTerms()" aria-label="Export all term records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#addTermModal" aria-label="Add new term">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Term
                    </button>
                    <button type="button" class="btn btn-warning btn-sm me-1" data-bs-toggle="modal" data-bs-target="#copyTermModal" aria-label="Copy term data">
                        <i class="fas fa-copy" aria-hidden="true"></i> Copy Term Data
                    </button>
                    <button type="button" class="btn btn-danger btn-sm me-1" data-bs-toggle="modal" data-bs-target="#clearTermModal" aria-label="Clear term data">
                        <i class="fas fa-trash" aria-hidden="true"></i> Clear Term Data
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="termsTable" class="table table-bordered table-striped table-hover" aria-label="Academic terms data table">
                    <caption class="visually-hidden">List of academic terms with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Banner Term</th>
                            <th scope="col">Term Code</th>
                            <th scope="col">Term Name</th>
                            <th scope="col">Academic Year</th>
                            <th scope="col">Start Date</th>
                            <th scope="col">End Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                        <tr>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Term Modal -->
<div class="modal fade" id="addTermModal" tabindex="-1" aria-labelledby="addTermModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addTermModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Term</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="banner_term" class="form-label">Banner Term <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="banner_term" name="banner_term" maxlength="20" required aria-required="true" placeholder="e.g., 202533" aria-describedby="bannerTermHelp">
                        <small id="bannerTermHelp" class="text-muted">Banner system term code</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="term_code" class="form-label">Term Code <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="term_code" name="term_code" maxlength="50" required aria-required="true" placeholder="e.g., 202630" aria-describedby="termCodeHelp">
                        <small id="termCodeHelp" class="text-muted">Institutional term code</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="term_name" class="form-label">Term Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="term_name" name="term_name" maxlength="100" required aria-required="true" placeholder="e.g., Spring 2026">
                    </div>
                    
                    <div class="mb-3">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <input type="text" class="form-control" id="academic_year" name="academic_year" maxlength="20" placeholder="e.g., 2025-2026">
                        <small class="text-muted">Optional. Format: YYYY-YYYY</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date">
                            <small class="text-muted">Optional. Used for sorting terms.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date">
                            <small class="text-muted">Optional. Used for sorting terms.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Term Modal -->
<div class="modal fade" id="editTermModal" tabindex="-1" aria-labelledby="editTermModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editTermModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Term</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editTermForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('terms', 'terms.php?status=' . lifecycle_view_mode('terms')) ?>
                    <input type="hidden" name="terms_pk" id="edit_terms_pk">
                    
                    <div class="mb-3">
                        <label for="edit_banner_term" class="form-label">Banner Term <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="edit_banner_term" name="banner_term" maxlength="20" required aria-required="true" aria-describedby="editBannerTermHelp">
                        <small id="editBannerTermHelp" class="text-muted">Banner system term code (e.g., 202533)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_term_code" class="form-label">Term Code <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="edit_term_code" name="term_code" maxlength="50" required aria-required="true" aria-describedby="editTermCodeHelp">
                        <small id="editTermCodeHelp" class="text-muted">Institutional term code (e.g., 202630)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_term_name" class="form-label">Term Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="edit_term_name" name="term_name" maxlength="100" required aria-required="true">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_academic_year" class="form-label">Academic Year</label>
                        <input type="text" class="form-control" id="edit_academic_year" name="academic_year" maxlength="20" placeholder="e.g., 2025-2026">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date">
                            <small class="text-muted">Optional. Used for sorting terms.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date">
                            <small class="text-muted">Optional. Used for sorting terms.</small>
                        </div>
                    </div>
                    
                    </fieldset>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteTerm()" aria-label="Delete term">
                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </div>
                    <!-- RIGHT SIDE: Primary Actions -->
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="terms_pk" id="toggle_terms_pk">
</form>

<!-- Delete Form (hidden) -->
<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="terms_pk" id="delete_terms_pk">
</form>

<!-- Copy Term Data Modal -->
<div class="modal fade" id="copyTermModal" tabindex="-1" aria-labelledby="copyTermModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h2 class="modal-title h5" id="copyTermModalLabel"><i class="fas fa-copy" aria-hidden="true"></i> Copy Term Data</h2>
            </div>
            <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="copy_term_data">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="source_term_code" class="form-label">Source Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="source_term_code" name="source_term_code" required>
                                <option value="">Select Source Term</option>
                                <?php foreach ($allTerms as $term): ?>
                                    <option value="<?= htmlspecialchars($term['term_code']) ?>">
                                        <?= htmlspecialchars($term['term_code']) ?> - <?= htmlspecialchars($term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="dest_term_code" class="form-label">Destination Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="dest_term_code" name="dest_term_code" required>
                                <option value="">Select Destination Term</option>
                                <?php foreach ($allTerms as $term): ?>
                                    <option value="<?= htmlspecialchars($term['term_code']) ?>">
                                        <?= htmlspecialchars($term['term_code']) ?> - <?= htmlspecialchars($term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>What will be copied:</strong>
                        <ul class="mb-0 mt-2">
                            <li><i class="fas fa-building text-secondary"></i> Departments</li>
                            <li><i class="fas fa-trophy text-primary"></i> Institutional Outcomes</li>
                            <li><i class="fas fa-graduation-cap text-success"></i> Programs and their Program Outcomes</li>
                            <li><i class="fas fa-book text-info"></i> Courses, Program-Course Mappings, and Student Learning Outcomes</li>
                        </ul>
                        <p class="mb-0 mt-2"><small>Note: Enrollments and assessments are NOT copied. Use CSV import to populate enrollment data.</small></p>
                    </div>
                    
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> This will refresh institutional outcomes, programs, program outcomes, courses, and student learning outcomes in the destination term. Existing records with the same keys will be updated, and missing records will be created.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-copy"></i> Copy Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Clear Term Data Modal -->
<div class="modal fade" id="clearTermModal" tabindex="-1" aria-labelledby="clearTermModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h2 class="modal-title h5" id="clearTermModalLabel"><i class="fas fa-trash" aria-hidden="true"></i> Clear Term Data</h2>
            </div>
            <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="clear_term_data">
                    
                    <div class="mb-3">
                        <label for="clear_term_code" class="form-label">Term to Clear <span class="text-danger">*</span></label>
                        <select class="form-select" id="clear_term_code" name="term_code" required>
                            <option value="">Select Term</option>
                            <?php foreach ($allTerms as $term): ?>
                                <option value="<?= htmlspecialchars($term['term_code']) ?>">
                                    <?= htmlspecialchars($term['term_code']) ?> - <?= htmlspecialchars($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <strong>WARNING:</strong> This will permanently delete ALL data for this term:
                        <ul class="mb-0 mt-2">
                            <li>All assessments</li>
                            <li>All enrollments</li>
                            <li>All sections</li>
                            <li>All student learning outcomes (SLOs)</li>
                            <li>All courses</li>
                            <li>All program outcomes (PSLOs)</li>
                            <li>All programs</li>
                            <li>All institutional outcomes (ISLOs)</li>
                        </ul>
                        <p class="mb-0 mt-2"><strong>This action cannot be undone! The term record itself will remain.</strong></p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_text" class="form-label">Type <code>DELETE</code> to confirm <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="confirm_text" name="confirm_text" required placeholder="Type DELETE in all caps">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $theme->showFooter($context); ?>

<!-- DataTables JS -->
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/jszip/3.10.1/jszip.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // Setup - add a text input or dropdown to each header cell (second row)
    $('#termsTable thead tr:eq(1) td').each(function(i) {
        var title = $('#termsTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions') {
            $(this).html('');
        } else {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        }
    });
    
    var table = $('#termsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: '<?= BASE_URL ?>administration/terms_data.php', type: 'POST' },
        dom: 'rtip',
        order: [[0, 'desc']],
        pageLength: 25,
        orderCellsTop: true, // Use the top row for sorting
        columnDefs: [
            { targets: [6], orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ terms",
            infoEmpty: "No terms found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No terms available",
            search: "_INPUT_",
            searchPlaceholder: "Search terms..."
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                // Find input or select in the second header row (tr:eq(1)) for this column
                $('input, select', $('#termsTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });
});

function editTerm(term) {
    $('#edit_terms_pk').val(term.terms_pk);
    $('#edit_banner_term').val(term.banner_term || '');
    $('#edit_term_code').val(term.term_code);
    $('#edit_term_name').val(term.term_name);
    $('#edit_academic_year').val(term.academic_year || '');
    $('#edit_start_date').val(term.start_date || '');
    $('#edit_end_date').val(term.end_date || '');
    configureLifecycleEditAction('#editTermForm', term.terms_pk, term.is_active, 'term', term.term_code || '');
    new bootstrap.Modal(document.getElementById('editTermModal')).show();
}

function deleteTerm(id, termCode) {
    if (confirm('Are you sure you want to DELETE term "' + termCode + '"? This action cannot be undone.')) {
        $('#delete_terms_pk').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteTerm() {
    const termPk = $('#edit_terms_pk').val();
    const termCode = $('#edit_term_code').val();
    deleteTerm(termPk, termCode);
}

function exportAllTerms() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/terms_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1
        },
        success: function(response) {
            var csvContent = "Banner Term,Term Code,Term Name,Academic Year,Start Date,End Date\n";
            
            response.data.forEach(function(row) {
                var bannerTerm = escapeCSV(row[0]);
                var termCode = escapeCSV(row[1]);
                var termName = escapeCSV(row[2]);
                var academicYear = escapeCSV(row[3]);
                var startDate = escapeCSV(row[4]);
                var endDate = escapeCSV(row[5]);
                
                csvContent += bannerTerm + "," + termCode + "," + termName + "," + academicYear + "," + startDate + "," + endDate + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "terms_export_" + new Date().toISOString().slice(0,10) + ".csv");
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            button.innerHTML = originalHTML;
            button.disabled = false;
        },
        error: function() {
            alert('Error exporting data. Please try again.');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    });
}

function escapeCSV(str) {
    if (str === null || str === undefined) return '""';
    str = String(str);
    if (str.includes('"') || str.includes(',') || str.includes('\n')) {
        return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
}
</script>
