<?php
declare(strict_types=1);

namespace SLOCloud\Reporting;

use RuntimeException;

final class ReportStore
{
    private const SEED_LIBRARY_VERSION = 6;

    public function __construct(private readonly string $directory, private readonly ?string $seedDirectory = null)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create report storage: {$directory}");
        }
        @chmod($directory, 0700);
        if (!is_writable($directory)) {
            throw new RuntimeException("Report storage is not writable: {$directory}");
        }
        $this->installSeeds();
    }

    public function all(): array
    {
        $definitions = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $definitions[] = $decoded;
        }
        usort($definitions, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $definitions;
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) return null;
        $path = $this->path($id);
        if (!is_file($path)) return null;
        $definition = json_decode((string)file_get_contents($path), true);
        return is_array($definition) ? $definition : null;
    }

    public function save(array $definition): int
    {
        $id = max(0, (int)($definition['id'] ?? 0));
        if ($id === 0) $id = $this->nextId();
        $existing = $this->find($id);
        $now = gmdate('c');
        $definition['id'] = $id;
        $definition['created_at'] = $existing['created_at'] ?? $definition['created_at'] ?? $now;
        $definition['updated_at'] = $now;
        $json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = tempnam($this->directory, 'report_');
        if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false) throw new RuntimeException('Unable to write report definition.');
        $path = $this->path($id);
        if (!@rename($temporary, $path)) {
            @unlink($path);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to save report definition.');
            }
        }
        return $id;
    }

    public function delete(int $id): bool
    {
        $path = $this->path($id);
        return is_file($path) && unlink($path);
    }

    private function path(int $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function nextId(): int
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . '.sequence.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) throw new RuntimeException('Unable to lock report storage.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock report storage.');
            $max = 0;
            foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) $max = max($max, (int)basename($path, '.json'));
            return $max + 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function installSeeds(): void
    {
        if ($this->seedDirectory === null) return;
        $marker = $this->directory . DIRECTORY_SEPARATOR . '.seed-library-v' . self::SEED_LIBRARY_VERSION;
        if (is_file($marker)) return;

        // Add newly shipped starter reports once per library version without
        // replacing an administrator's report with the same name. The marker
        // also means a deliberately deleted starter report stays deleted.
        $existingNames = [];
        foreach ($this->all() as $definition) {
            $name = strtolower(trim((string)($definition['name'] ?? '')));
            if ($name !== '') $existingNames[$name] = $definition;
        }
        $termRootMigrations = [
            'assessment outcomes by course' => [
                'base' => 'assessments',
                'from' => ['assessments.enrollment_fk->enrollment.enrollment_pk', 'enrollment.course_fk->courses.courses_pk', 'courses.term_fk->terms.terms_pk'],
                'to' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
            ],
            'assessment outcomes with nested breaks' => [
                'base' => 'assessments',
                'from' => ['assessments.enrollment_fk->enrollment.enrollment_pk', 'enrollment.course_fk->courses.courses_pk', 'courses.term_fk->terms.terms_pk'],
                'to' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
            ],
            'cslo assessment coverage' => [
                'base' => 'student_learning_outcomes',
                'from' => ['student_learning_outcomes.course_fk->courses.courses_pk', 'courses.term_fk->terms.terms_pk', 'assessments.student_learning_outcome_fk->student_learning_outcomes.student_learning_outcomes_pk'],
                'to' => ['courses.term_fk->terms.terms_pk', 'student_learning_outcomes.course_fk->courses.courses_pk', 'assessments.student_learning_outcome_fk->student_learning_outcomes.student_learning_outcomes_pk'],
            ],
            'enrollment by course' => [
                'base' => 'enrollment',
                'from' => ['enrollment.course_fk->courses.courses_pk', 'courses.term_fk->terms.terms_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
                'to' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
            ],
            'program outcome inventory' => [
                'base' => 'program_outcomes',
                'from' => ['program_outcomes.program_fk->programs.programs_pk', 'programs.term_fk->terms.terms_pk', 'programs.discipline_fk->disciplines.disciplines_pk'],
                'to' => ['programs.term_fk->terms.terms_pk', 'program_outcomes.program_fk->programs.programs_pk', 'programs.discipline_fk->disciplines.disciplines_pk'],
            ],
            'section roster' => [
                'base' => 'sections',
                'from' => ['sections.course_fk->courses.courses_pk', 'sections.term_fk->terms.terms_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
                'to' => ['sections.term_fk->terms.terms_pk', 'sections.course_fk->courses.courses_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
            ],
        ];
        $sectionEnrollmentMigrations = [
            'assessment participation by course' => [
                'from' => [
                    'courses.term_fk->terms.terms_pk',
                    'courses.discipline_fk->disciplines.disciplines_pk',
                    'enrollment.course_fk->courses.courses_pk',
                    'assessments.enrollment_fk->enrollment.enrollment_pk',
                ],
                'to' => [
                    'sections.term_fk->terms.terms_pk',
                    'sections.course_fk->courses.courses_pk',
                    'courses.discipline_fk->disciplines.disciplines_pk',
                    'enrollment.section_fk->sections.sections_pk',
                    'assessments.enrollment_fk->enrollment.enrollment_pk',
                ],
            ],
            'assessment outcomes by course' => [
                'from' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
                'to' => ['sections.term_fk->terms.terms_pk', 'sections.course_fk->courses.courses_pk', 'enrollment.section_fk->sections.sections_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
            ],
            'assessment outcomes with nested breaks' => [
                'from' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
                'to' => ['sections.term_fk->terms.terms_pk', 'sections.course_fk->courses.courses_pk', 'enrollment.section_fk->sections.sections_pk', 'assessments.enrollment_fk->enrollment.enrollment_pk'],
            ],
            'enrollment by course' => [
                'from' => ['courses.term_fk->terms.terms_pk', 'enrollment.course_fk->courses.courses_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
                'to' => ['sections.term_fk->terms.terms_pk', 'sections.course_fk->courses.courses_pk', 'enrollment.section_fk->sections.sections_pk', 'courses.discipline_fk->disciplines.disciplines_pk'],
            ],
        ];
        foreach (glob($this->seedDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $seed) {
            $definition = json_decode((string)file_get_contents($seed), true);
            if (!is_array($definition)) continue;
            $name = strtolower(trim((string)($definition['name'] ?? '')));
            if ($name === '') continue;
            if (isset($existingNames[$name])) {
                $existing = $existingNames[$name];
                // Version 3 makes Terms the mandatory root. Re-root only a
                // starter whose legacy base and relationship graph still
                // match the shipped shape. Columns, filters, labels, and
                // options remain under administrator control.
                $migration = $termRootMigrations[$name] ?? null;
                if ($migration !== null
                    && ($existing['base_table'] ?? '') === $migration['base']
                    && array_values($existing['joins'] ?? []) === $migration['from']) {
                    $existing['base_table'] = 'terms';
                    $existing['joins'] = $migration['to'];
                    $this->save($existing);
                    $existingNames[$name] = $existing;
                }
                // Version 5 normalizes enrollment ownership under sections.
                // Migrate only unchanged starter join graphs; administrator
                // customizations remain untouched and will be surfaced by the
                // normal catalog repair workflow if they use removed fields.
                $migration = $sectionEnrollmentMigrations[$name] ?? null;
                if ($migration !== null
                    && ($existing['base_table'] ?? '') === 'terms'
                    && array_values($existing['joins'] ?? []) === $migration['from']) {
                    $existing['joins'] = $migration['to'];
                    $this->save($existing);
                    $existingNames[$name] = $existing;
                }
                continue;
            }
            $this->save($definition);
            $existingNames[$name] = $definition;
        }
        if (file_put_contents($marker, gmdate('c') . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to record the installed report library version.');
        }
        @chmod($marker, 0600);
    }
}
