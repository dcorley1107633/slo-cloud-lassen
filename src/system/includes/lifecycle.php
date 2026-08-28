<?php
declare(strict_types=1);

function lifecycle_entities(): array
{
    return [
        'terms' => ['pk' => 'terms_pk'],
        'institutional_outcomes' => ['pk' => 'institutional_outcomes_pk', 'parents' => [['terms', 'term_fk', 'terms_pk']]],
        'disciplines' => ['pk' => 'disciplines_pk', 'parents' => [['terms', 'term_fk', 'terms_pk']]],
        'programs' => ['pk' => 'programs_pk', 'parents' => [['terms', 'term_fk', 'terms_pk'], ['disciplines', 'discipline_fk', 'disciplines_pk', true]]],
        'program_outcomes' => ['pk' => 'program_outcomes_pk', 'parents' => [['programs', 'program_fk', 'programs_pk']]],
        'courses' => ['pk' => 'courses_pk', 'parents' => [['terms', 'term_fk', 'terms_pk'], ['disciplines', 'discipline_fk', 'disciplines_pk', true]]],
        'sections' => ['pk' => 'sections_pk', 'parents' => [['courses', 'course_fk', 'courses_pk'], ['terms', 'term_fk', 'terms_pk']]],
        'student_learning_outcomes' => ['pk' => 'student_learning_outcomes_pk', 'parents' => [['courses', 'course_fk', 'courses_pk']]],
        'students' => ['pk' => 'students_pk'],
        'enrollment' => ['pk' => 'enrollment_pk', 'parents' => [['sections', 'section_fk', 'sections_pk'], ['students', 'student_fk', 'students_pk']]],
        'section_xlist' => ['pk' => 'section_xlist_pk', 'parents' => [['terms', 'term_fk', 'terms_pk']]],
        'assessments' => ['pk' => 'assessments_pk', 'updated_by' => false, 'parents' => [['enrollment', 'enrollment_fk', 'enrollment_pk'], ['student_learning_outcomes', 'student_learning_outcome_fk', 'student_learning_outcomes_pk']]],
        'users' => ['pk' => 'users_pk', 'updated_by' => false],
    ];
}

function lifecycle_active_predicate(string $entity, string $alias, string $prefix): string
{
    $entities = lifecycle_entities();
    if (!isset($entities[$entity])) {
        throw new InvalidArgumentException('Unsupported lifecycle entity.');
    }
    $parts = ["{$alias}.is_active = 1", lifecycle_parent_active_predicate($entity, $alias, $prefix)];
    return '(' . implode(' AND ', array_filter($parts, static fn(string $part): bool => $part !== '1 = 1')) . ')';
}

function lifecycle_parent_active_predicate(string $entity, string $alias, string $prefix): string
{
    $entities = lifecycle_entities();
    if (!isset($entities[$entity])) {
        throw new InvalidArgumentException('Unsupported lifecycle entity.');
    }
    $parts = [];
    foreach ($entities[$entity]['parents'] ?? [] as $index => $parent) {
        [$parentEntity, $foreignKey, $parentPk] = $parent;
        $optional = (bool)($parent[3] ?? false);
        $parentAlias = $alias . '_lp' . $index;
        $parentActive = lifecycle_active_predicate($parentEntity, $parentAlias, $prefix);
        $exists = "EXISTS (SELECT 1 FROM {$prefix}{$parentEntity} {$parentAlias} "
            . "WHERE {$parentAlias}.{$parentPk} = {$alias}.{$foreignKey} AND {$parentActive})";
        $parts[] = $optional ? "({$alias}.{$foreignKey} IS NULL OR {$exists})" : $exists;
    }
    return $parts === [] ? '1 = 1' : '(' . implode(' AND ', $parts) . ')';
}

function lifecycle_status_predicate(string $entity, string $alias, string $prefix, string $mode): string
{
    $active = lifecycle_active_predicate($entity, $alias, $prefix);
    return $mode === 'inactive' ? "NOT {$active}" : $active;
}

function lifecycle_view_mode(string $screen): string
{
    unset($screen);
    return ($_REQUEST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
}

function lifecycle_action_button(string $entity, int $id, bool $isActive, string $returnUrl): string
{
    if ($isActive && str_contains($returnUrl, 'status=inactive')) {
        return '<span class="badge text-bg-secondary ms-1">Inactive via parent</span>';
    }
    $label = $isActive ? 'Deactivate' : 'Reactivate';
    $class = $isActive ? 'btn-outline-secondary' : 'btn-outline-success';
    $icon = $isActive ? 'fa-toggle-off' : 'fa-toggle-on';
    return '<form method="post" action="' . htmlspecialchars(BASE_URL . 'administration/lifecycle_status.php', ENT_QUOTES) . '" class="d-inline ms-1">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) . '">'
        . '<input type="hidden" name="entity" value="' . htmlspecialchars($entity, ENT_QUOTES) . '">'
        . '<input type="hidden" name="record_id" value="' . $id . '">'
        . '<input type="hidden" name="new_status" value="' . ($isActive ? '0' : '1') . '">'
        . '<input type="hidden" name="return_url" value="' . htmlspecialchars($returnUrl, ENT_QUOTES) . '">'
        . '<button type="submit" class="btn ' . $class . '" aria-label="' . $label . ' record" '
        . 'onclick="return confirm(\'' . $label . ' this record?\')"><i class="fas ' . $icon . '" aria-hidden="true"></i> ' . $label . '</button></form>';
}

function related_records_button(string $entity, int $id, string $recordLabel): string
{
    $safeEntity = htmlspecialchars($entity, ENT_QUOTES);
    $safeLabel = htmlspecialchars($recordLabel, ENT_QUOTES);
    $detailsId = 'related-' . preg_replace('/[^a-z0-9_-]/i', '-', $entity) . '-' . $id;

    return '<button type="button" class="btn btn-sm btn-outline-secondary js-toggle-related"'
        . ' data-related-entity="' . $safeEntity . '" data-record-id="' . $id . '"'
        . ' aria-expanded="false" aria-controls="' . htmlspecialchars($detailsId, ENT_QUOTES) . '"'
        . ' aria-label="Show related records for ' . $safeLabel . '" title="Show related records">'
        . '<i class="fas fa-chevron-right" aria-hidden="true"></i></button>';
}

function lifecycle_edit_fields(string $entity, string $returnUrl): string
{
    return '<input type="hidden" class="js-lifecycle-record-id" name="record_id" value="">'
        . '<input type="hidden" class="js-lifecycle-new-status" name="new_status" value="">'
        . '<input type="hidden" name="entity" value="' . htmlspecialchars($entity, ENT_QUOTES) . '">'
        . '<input type="hidden" name="return_url" value="' . htmlspecialchars($returnUrl, ENT_QUOTES) . '">';
}

function lifecycle_edit_controls(): string
{
    return '<button type="submit" class="btn btn-outline-secondary me-auto js-edit-lifecycle-action"'
        . ' formaction="' . htmlspecialchars(BASE_URL . 'administration/lifecycle_status.php', ENT_QUOTES) . '"'
        . ' formmethod="post" hidden>'
        . '<i class="fas fa-toggle-off" aria-hidden="true"></i> '
        . '<span class="js-lifecycle-action-label">Deactivate</span></button>'
        . '<span class="badge text-bg-secondary me-auto js-edit-inherited-status" role="status" hidden>'
        . '<i class="fas fa-circle-info me-1" aria-hidden="true"></i>Inactive via parent</span>';
}
