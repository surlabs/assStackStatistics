<?php

class ilassStackStatisticsDashboardRenderer
{
    private ilassStackStatisticsPlugin $plugin;
    private ilassStackStatisticsDataProvider $data_provider;
    private int $chartCounter = 0;
    private array $chartScripts = [];

    public function __construct(ilassStackStatisticsPlugin $plugin)
    {
        $this->plugin = $plugin;
        require_once __DIR__ . '/class.ilassStackStatisticsDataProvider.php';
        require_once __DIR__ . '/class.ilassStackStatisticsAttemptsTable.php';
        $this->data_provider = new ilassStackStatisticsDataProvider();
        $this->loadAnalyticsClass();
    }

    public function render(int $ref_id): string
    {
        global $DIC;

        $this->chartCounter = 0;
        $this->chartScripts = [];

        if (!class_exists('assStackQuestionAnalyticsDB')) {
            return '<p class="ilInfoMessage">' . $this->plugin->txt('analytics_unavailable') . '</p>';
        }

        $question_ids = $this->data_provider->getStackQuestionIds($ref_id);
        if ($question_ids === []) {
            return '<p class="ilInfoMessage">' . $this->plugin->txt('no_stack_questions') . '</p>';
        }

        $role = $this->isTeacher($ref_id) ? 'teacher' : 'student';
        $filter = $this->buildFilterComponent($ref_id, $role, $question_ids);
        $filters = $this->getFilters($question_ids, $role, $filter);
        $selected_question_ids = $filters['question_id'] > 0 ? [$filters['question_id']] : $question_ids;

        $teacher_attempts = $this->data_provider->getAttemptRows($selected_question_ids, $filters);
        $student_attempts = $role === 'student'
            ? $this->data_provider->getAttemptRows($selected_question_ids, ['user_id' => (int) $DIC->user()->getId()])
            : [];
        $cohort_attempts = $role === 'student'
            ? $this->data_provider->getAttemptRows($selected_question_ids, [])
            : [];
        $content = $role === 'teacher'
            ? $this->renderTeacherDashboard($selected_question_ids, $question_ids, $filters, $teacher_attempts)
            : $this->renderStudentDashboard($selected_question_ids, $question_ids, $filters, $student_attempts, $cohort_attempts);

        return $this->renderTemplate('dashboard_page', [
            'TOOLBAR' => $DIC->ui()->renderer()->render($filter),
            'ROLE_NOTE' => htmlspecialchars($role === 'teacher' ? $this->plugin->txt('role_teacher') : $this->plugin->txt('role_student')),
            'CONTENT' => $content,
            'FOOTER_SCRIPTS' => $this->renderChartScripts(),
        ]);
    }

    private function renderTeacherDashboard(array $selected_question_ids, array $all_question_ids, array $filters, array $attempts): string
    {
        if ($attempts === []) {
            return $this->renderEmptyState($this->plugin->txt('no_attempts_filtered'));
        }

        $question_stats = $this->data_provider->buildQuestionStats($all_question_ids, $filters);
        $selected_question = $filters['question_id'] > 0 ? $filters['question_id'] : $selected_question_ids[0];
        $prt_options = $this->data_provider->getPrtOptions($selected_question);
        $selected_prt = $filters['prt_name'] !== '' && in_array($filters['prt_name'], $prt_options, true)
            ? $filters['prt_name']
            : ($prt_options[0] ?? '');
        $prt_distribution = $selected_prt !== ''
            ? $this->data_provider->getPrtAnswerNoteDistribution($selected_question, $selected_prt, $attempts)
            : [];

        $cards = [
            ['label' => $this->plugin->txt('stat_attempts'), 'value' => count($attempts)],
            ['label' => $this->plugin->txt('stat_users'), 'value' => count(array_unique(array_column($attempts, 'user_id')))],
            ['label' => $this->plugin->txt('stat_avg_score'), 'value' => $this->formatPercent($this->data_provider->average(array_column($attempts, 'fraction')))],
            ['label' => $this->plugin->txt('stat_hint_rate'), 'value' => $this->formatPercent($this->data_provider->hintRate($attempts))],
            ['label' => $this->plugin->txt('stat_avg_time'), 'value' => $this->formatDuration((int) round($this->data_provider->average(array_column($attempts, 'total_time_ms'))))],
        ];

        $html = $this->renderStatCards($cards);
        $html .= '<div class="xstsa-grid">';
        $html .= $this->renderPanel($this->plugin->txt('chart_score_histogram'), $this->renderHistogram(array_column($attempts, 'fraction'), null));
        $html .= $this->renderPanel($this->plugin->txt('chart_hint_donut'), $this->renderDonutChart([
            $this->plugin->txt('legend_with_hints') => count(array_filter($attempts, static fn(array $row): bool => $row['hint_used'])),
            $this->plugin->txt('legend_without_hints') => count(array_filter($attempts, static fn(array $row): bool => !$row['hint_used'])),
        ]));
        $html .= $this->renderPanel($this->plugin->txt('chart_question_heatmap'), $this->renderQuestionHeatmap($question_stats));
        $html .= $this->renderPanel(
            $this->plugin->txt('chart_prt_distribution') . ($selected_prt !== '' ? ': ' . htmlspecialchars($selected_prt) : ''),
            $selected_prt !== ''
                ? $this->renderDonutChart($prt_distribution)
                : $this->renderEmptyState($this->plugin->txt('no_prt_available'), false)
        );
        $html .= '</div>';

        $html .= $this->renderPanel($this->plugin->txt('panel_hint_usage_per_hint'), $this->renderHintUsageTable($this->data_provider->getHintUsageRows($attempts)));
        $html .= $this->renderPanel($this->plugin->txt('panel_attempts_table'), $this->renderAttemptsTable($attempts, true));

        return $this->renderExportButtons() . $html;
    }

    private function renderStudentDashboard(array $selected_question_ids, array $all_question_ids, array $filters, array $student_attempts, array $cohort_attempts): string
    {
        if ($student_attempts === []) {
            return $this->renderEmptyState($this->plugin->txt('no_personal_attempts'));
        }

        $question_stats = $this->data_provider->buildQuestionStats($all_question_ids, ['user_id' => $filters['user_id']]);
        $selected_question = $filters['question_id'] > 0 ? $filters['question_id'] : $selected_question_ids[0];
        $prt_options = $this->data_provider->getPrtOptions($selected_question);
        $selected_prt = $filters['prt_name'] !== '' && in_array($filters['prt_name'], $prt_options, true)
            ? $filters['prt_name']
            : ($prt_options[0] ?? '');
        $prt_distribution = $selected_prt !== ''
            ? $this->data_provider->getPrtAnswerNoteDistribution($selected_question, $selected_prt, $student_attempts)
            : [];

        $cards = [
            ['label' => $this->plugin->txt('stat_attempts'), 'value' => count($student_attempts)],
            ['label' => $this->plugin->txt('stat_personal_avg_score'), 'value' => $this->formatPercent($this->data_provider->average(array_column($student_attempts, 'fraction')))],
            ['label' => $this->plugin->txt('stat_best_score'), 'value' => $this->formatPercent(max(array_column($student_attempts, 'fraction')))],
            ['label' => $this->plugin->txt('stat_hint_uses'), 'value' => array_sum(array_column($student_attempts, 'hint_open_count'))],
            ['label' => $this->plugin->txt('stat_avg_time'), 'value' => $this->formatDuration((int) round($this->data_provider->average(array_column($student_attempts, 'total_time_ms'))))],
        ];

        $html = $this->renderStatCards($cards);
        $html .= '<div class="xstsa-grid">';
        $html .= $this->renderPanel(
            $this->plugin->txt('chart_score_histogram'),
            $this->renderHistogram(
                array_column($cohort_attempts, 'fraction'),
                $this->data_provider->average(array_column($student_attempts, 'fraction'))
            )
        );
        $html .= $this->renderPanel($this->plugin->txt('chart_hint_donut'), $this->renderDonutChart([
            $this->plugin->txt('legend_with_hints') => count(array_filter($student_attempts, static fn(array $row): bool => $row['hint_used'])),
            $this->plugin->txt('legend_without_hints') => count(array_filter($student_attempts, static fn(array $row): bool => !$row['hint_used'])),
        ]));
        $html .= $this->renderPanel($this->plugin->txt('chart_question_heatmap'), $this->renderQuestionHeatmap($question_stats));
        $html .= $this->renderPanel(
            $this->plugin->txt('chart_prt_distribution') . ($selected_prt !== '' ? ': ' . htmlspecialchars($selected_prt) : ''),
            $selected_prt !== ''
                ? $this->renderDonutChart($prt_distribution)
                : $this->renderEmptyState($this->plugin->txt('no_prt_available'), false)
        );
        $html .= '</div>';

        $html .= $this->renderPanel($this->plugin->txt('panel_attempts_table'), $this->renderAttemptsTable($student_attempts, false));

        return $html;
    }

    private function buildFilterComponent(int $ref_id, string $role, array $question_ids)
    {
        global $DIC;

        $DIC->ctrl()->setParameterByClass(ilassStackStatisticsUIHookGUI::class, 'ref_id', $ref_id);
        $action = $DIC->ctrl()->getLinkTargetByClass(['ilUIPluginRouterGUI', ilassStackStatisticsUIHookGUI::class], 'showStatistics');
        $field_factory = $DIC->ui()->factory()->input()->field();

        $question_options = [0 => $this->plugin->txt('all_questions')];
        foreach ($question_ids as $question_id) {
            $question_options[$question_id] = $this->data_provider->getQuestionTitle($question_id);
        }

        $filter_inputs = [
            'question_id' => $field_factory->select($this->plugin->txt('filter_question'), $question_options),
            'prt_name' => $field_factory->select($this->plugin->txt('filter_prt'), [0 => $this->plugin->txt('filter_all_prts')] + $this->mapOptions($this->collectPrtOptions($question_ids))),
        ];
        $rendered = [true, true];

        if ($role === 'teacher') {
            $filter_inputs['user_id'] = $field_factory->select(
                $this->plugin->txt('filter_student'),
                [0 => $this->plugin->txt('all_students')] + $this->data_provider->getUserOptions($question_ids)
            );
            $filter_inputs['hint_usage'] = $field_factory->select($this->plugin->txt('filter_hints'), [
                'all' => $this->plugin->txt('filter_all'),
                'with' => $this->plugin->txt('filter_with_hints'),
                'without' => $this->plugin->txt('filter_without_hints'),
            ])->withValue('all');
            $filter_inputs['score_band'] = $field_factory->select($this->plugin->txt('filter_score'), [
                'all' => $this->plugin->txt('filter_all'),
                'low' => $this->plugin->txt('filter_score_low'),
                'mid' => $this->plugin->txt('filter_score_mid'),
                'high' => $this->plugin->txt('filter_score_high'),
            ])->withValue('all');
            $attempt_options = [
                'all' => $this->plugin->txt('filter_attempts_all'),
                'latest' => $this->plugin->txt('filter_attempts_latest'),
            ];
            foreach ($this->data_provider->getAttemptNumberOptions($question_ids) as $attempt_no) {
                $attempt_options[(string) $attempt_no] = sprintf($this->plugin->txt('filter_attempts_number'), $attempt_no);
            }
            $filter_inputs['attempt_scope'] = $field_factory->select($this->plugin->txt('filter_attempts'), $attempt_options)->withValue('all');
            $rendered = [true, true, true, true, true, true];
        }

        return $DIC->uiService()->filter()->standard(
            'xstsa_filter_' . $ref_id . '_' . $role,
            $action,
            $filter_inputs,
            $rendered,
            true,
            true
        );
    }

    private function getFilters(array $question_ids, string $role, $filter): array
    {
        global $DIC;

        $filter_data = $DIC->uiService()->filter()->getData($filter) ?? [];
        $question_id = (int) ($filter_data['question_id'] ?? 0);
        if ($question_id > 0 && !in_array($question_id, $question_ids, true)) {
            $question_id = 0;
        }

        $user_id = $role === 'teacher' ? (int) ($filter_data['user_id'] ?? 0) : (int) $DIC->user()->getId();
        $hint_usage = (string) ($filter_data['hint_usage'] ?? 'all');
        $score_band = (string) ($filter_data['score_band'] ?? 'all');
        $attempt_scope = (string) ($filter_data['attempt_scope'] ?? 'all');
        $prt_name = trim((string) ($filter_data['prt_name'] ?? ''));

        if ($prt_name === '0') {
            $prt_name = '';
        }
        if (!in_array($hint_usage, ['all', 'with', 'without'], true)) {
            $hint_usage = 'all';
        }
        if (!in_array($score_band, ['all', 'low', 'mid', 'high'], true)) {
            $score_band = 'all';
        }
        if (!in_array($attempt_scope, ['all', 'latest'], true) && !ctype_digit($attempt_scope)) {
            $attempt_scope = 'all';
        }

        return [
            'question_id' => $question_id,
            'user_id' => $user_id,
            'hint_usage' => $hint_usage,
            'score_band' => $score_band,
            'attempt_scope' => $attempt_scope,
            'prt_name' => $prt_name,
        ];
    }

    private function renderStatCards(array $cards): string
    {
        $html = '<div class="xstsa-cards">';
        foreach ($cards as $card) {
            $html .= $this->renderTemplate('stat_card', [
                'LABEL' => htmlspecialchars((string) $card['label']),
                'VALUE' => htmlspecialchars((string) $card['value']),
            ]);
        }
        return $html . '</div>';
    }

    private function renderPanel(string $title, string $body): string
    {
        return $this->renderTemplate('panel', [
            'TITLE' => htmlspecialchars($title),
            'BODY' => $body,
        ]);
    }

    private function renderHistogram(array $fractions, ?float $marker_fraction): string
    {
        if ($fractions === []) {
            return $this->renderEmptyState($this->plugin->txt('no_attempts_yet'), false);
        }

        $bins = array_fill(0, 10, 0);
        foreach ($fractions as $fraction) {
            $value = max(0.0, min(1.0, (float) $fraction));
            $index = min(9, (int) floor($value * 10));
            $bins[$index]++;
        }

        $mean = $this->data_provider->average($fractions);
        $median = $this->data_provider->median($fractions);
        $markers = [
            [
                'fraction' => $mean,
                'color' => '#dc2626',
                'dash' => [],
            ],
            [
                'fraction' => $median,
                'color' => '#f59e0b',
                'dash' => [6, 4],
            ],
        ];
        if ($marker_fraction !== null) {
            $markers[] = [
                'fraction' => $marker_fraction,
                'color' => '#2563eb',
                'dash' => [2, 4],
            ];
        }

        $chart = $this->renderChartCanvas('bar', [
            'data' => [
                'labels' => array_map(static fn(int $idx): string => ($idx * 10) . '-' . (($idx + 1) * 10), array_keys($bins)),
                'datasets' => [[
                    'label' => $this->plugin->txt('chart_score_histogram'),
                    'data' => array_values($bins),
                    'backgroundColor' => 'rgba(99, 102, 241, 0.65)',
                    'borderColor' => 'rgba(51, 65, 85, 1)',
                    'borderWidth' => 1,
                ]],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['display' => false],
                    'xstsaMarkers' => $markers,
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => ['precision' => 0],
                    ],
                ],
            ],
        ]);

        $legend = '<div class="xstsa-legend">'
            . '<span><i class="xstsa-swatch xstsa-swatch--mean"></i>' . htmlspecialchars($this->plugin->txt('legend_mean')) . ': ' . $this->formatPercent($mean) . '</span>'
            . '<span><i class="xstsa-swatch xstsa-swatch--median"></i>' . htmlspecialchars($this->plugin->txt('legend_median')) . ': ' . $this->formatPercent($median) . '</span>';
        if ($marker_fraction !== null) {
            $legend .= '<span><i class="xstsa-swatch xstsa-swatch--personal"></i>' . htmlspecialchars($this->plugin->txt('legend_personal')) . ': ' . $this->formatPercent($marker_fraction) . '</span>';
        }
        $legend .= '</div>';

        return $chart . $legend;
    }

    private function renderDonutChart(array $data): string
    {
        $data = array_filter($data, static fn($value): bool => (int) $value > 0);
        if ($data === []) {
            return $this->renderEmptyState($this->plugin->txt('no_chart_data'), false);
        }

        $total = array_sum($data);
        $colors = ['#4f46e5', '#f97316', '#14b8a6', '#e11d48', '#8b5cf6', '#84cc16', '#06b6d4'];
        $legend = '<div class="xstsa-donut-legend">';
        $index = 0;
        $background = [];
        foreach ($data as $label => $count) {
            $percentage = ($count / $total) * 100;
            $color = $colors[$index % count($colors)];
            $background[] = $color;
            $legend .= '<div class="xstsa-donut-legend__item"><i class="xstsa-donut-legend__swatch" style="background:' . $color . '"></i><span>'
                . htmlspecialchars((string) $label) . '</span><strong>' . round($percentage, 1) . '%</strong></div>';
            $index++;
        }
        $legend .= '</div>';

        $chart = $this->renderChartCanvas('doughnut', [
            'data' => [
                'labels' => array_values(array_map('strval', array_keys($data))),
                'datasets' => [[
                    'data' => array_values($data),
                    'backgroundColor' => $background,
                    'borderWidth' => 0,
                ]],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['display' => false],
                ],
                'cutout' => '62%',
            ],
        ], (string) $total);

        return '<div class="xstsa-donut">' . $chart . $legend . '</div>';
    }

    private function renderQuestionHeatmap(array $rows): string
    {
        if ($rows === []) {
            return $this->renderEmptyState($this->plugin->txt('no_chart_data'), false);
        }

        $html = '<div class="xstsa-table-scroll"><table class="xstsa-heatmap">'
            . '<thead><tr>'
            . '<th>' . htmlspecialchars($this->plugin->txt('question')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('stat_avg_score')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('stat_hint_rate')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('stat_avg_time')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $avg   = (float) $row['avg_score'];
            $hints = (float) $row['hint_rate'];

            // avg score: red (0) → yellow (0.5) → green (1)
            $avg_hue  = (int) round($avg * 120);
            $avg_bg   = 'hsl(' . $avg_hue . ',65%,88%)';
            $avg_text = 'hsl(' . $avg_hue . ',55%,28%)';

            // hint rate: green (0) → yellow (0.5) → red (1)  (more hints = worse)
            $hint_hue  = (int) round((1 - $hints) * 120);
            $hint_bg   = 'hsl(' . $hint_hue . ',65%,88%)';
            $hint_text = 'hsl(' . $hint_hue . ',55%,28%)';
            $avg_time = (int) round((float) ($row['avg_time_ms'] ?? 0));

            $count = (int) $row['attempt_count'];
            $html .= '<tr>'
                . '<td class="xstsa-heatmap__label">'
                . htmlspecialchars((string) $row['title'])
                . '<span class="xstsa-heatmap__attempts">(' . $count . ')</span>'
                . '</td>'
                . '<td class="xstsa-heatmap__cell">'
                . '<span class="xstsa-heatmap__cell-inner" style="background:' . $avg_bg . ';color:' . $avg_text . '">'
                . $this->formatPercent($avg) . '</span></td>'
                . '<td class="xstsa-heatmap__cell">'
                . '<span class="xstsa-heatmap__cell-inner" style="background:' . $hint_bg . ';color:' . $hint_text . '">'
                . $this->formatPercent($hints) . '</span></td>'
                . '<td class="xstsa-heatmap__cell">'
                . '<span class="xstsa-heatmap__cell-inner">' . htmlspecialchars($this->formatDuration($avg_time)) . '</span></td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    public function deliverExport(int $ref_id, string $type): void
    {
        $question_ids = $this->data_provider->getStackQuestionIds($ref_id);
        $filter = $this->buildFilterComponent($ref_id, 'teacher', $question_ids);
        $filters = $this->getFilters($question_ids, 'teacher', $filter);
        $selected_question_ids = $filters['question_id'] > 0 ? [$filters['question_id']] : $question_ids;
        $attempts = $this->data_provider->getAttemptRows($selected_question_ids, $filters);

        $csv = new ilCSVWriter();
        $csv->setSeparator(';');

        if ($type === 'hints') {
            $this->addCsvRow($csv, [
                $this->plugin->txt('col_student'),
                $this->plugin->txt('question'),
                $this->plugin->txt('col_attempt_no'),
                $this->plugin->txt('col_hint_no'),
                $this->plugin->txt('col_hint_title'),
                $this->plugin->txt('col_open_count'),
                $this->plugin->txt('col_first_opened'),
                $this->plugin->txt('col_score'),
            ]);
            foreach ($this->data_provider->getHintUsagePerAttemptRows($attempts) as $row) {
                $this->addCsvRow($csv, [
                    ilObjUser::_lookupFullname((int) $row['user_id']),
                    $this->data_provider->getQuestionTitle((int) $row['question_id']),
                    $row['attempt_no'],
                    $row['hint_index'] + 1,
                    $row['hint_title'],
                    $row['hint_opens'],
                    date('Y-m-d H:i:s', (int) $row['first_opened']),
                    round((float) $row['fraction'] * 100, 1),
                ]);
            }
        } else {
            $this->addCsvRow($csv, [
                $this->plugin->txt('col_student'),
                $this->plugin->txt('question'),
                $this->plugin->txt('col_attempt_no'),
                $this->plugin->txt('col_score'),
                $this->plugin->txt('col_points'),
                $this->plugin->txt('col_max_points'),
                $this->plugin->txt('col_hints'),
                $this->plugin->txt('col_time_spent_seconds'),
                $this->plugin->txt('col_attempt_time'),
            ]);
            foreach ($attempts as $row) {
                $this->addCsvRow($csv, [
                    ilObjUser::_lookupFullname((int) $row['user_id']),
                    $this->data_provider->getQuestionTitle((int) $row['question_id']),
                    $row['attempt_no'],
                    round((float) $row['fraction'] * 100, 1),
                    (float) $row['total_points'],
                    (float) $row['max_points'],
                    $row['hint_open_count'],
                    (int) round($row['total_time_ms'] / 1000),
                    date('Y-m-d H:i:s', (int) $row['stamp']),
                ]);
            }
        }

        $file_name = 'stack_statistics_' . ($type === 'hints' ? 'hints' : 'attempts') . '_' . $ref_id . '_' . date('Ymd_His') . '.csv';
        ilUtil::deliverData("\xEF\xBB\xBF" . $csv->getCSVString(), $file_name, 'text/csv');
    }

    private function addCsvRow(ilCSVWriter $csv, array $columns): void
    {
        $csv->addRow();
        foreach ($columns as $column) {
            $csv->addColumn((string) $column);
        }
    }

    private function renderExportButtons(): string
    {
        global $DIC;

        $ctrl = $DIC->ctrl();
        $factory = $DIC->ui()->factory();
        $class_path = ['ilUIPluginRouterGUI', ilassStackStatisticsUIHookGUI::class];

        return '<div class="xstsa-actions">' . $DIC->ui()->renderer()->render([
            $factory->button()->standard($this->plugin->txt('export_attempts'), $ctrl->getLinkTargetByClass($class_path, 'exportAttempts')),
            $factory->button()->standard($this->plugin->txt('export_hints'), $ctrl->getLinkTargetByClass($class_path, 'exportHints')),
        ]) . '</div>';
    }

    private function renderHintUsageTable(array $rows): string
    {
        if ($rows === []) {
            return $this->renderEmptyState($this->plugin->txt('no_hint_usage'), false);
        }

        $html = '<div class="xstsa-table-scroll"><table class="table table-striped xstsa-hint-table">'
            . '<thead><tr>'
            . '<th>' . htmlspecialchars($this->plugin->txt('question')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('col_hint_no')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('col_hint_title')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('col_open_count')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('col_unique_attempts')) . '</th>'
            . '<th>' . htmlspecialchars($this->plugin->txt('col_attempt_rate')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td>' . htmlspecialchars($this->data_provider->getQuestionTitle((int) $row['question_id'])) . '</td>'
                . '<td>' . ((int) $row['hint_index'] + 1) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['hint_title']) . '</td>'
                . '<td>' . (int) $row['open_count'] . '</td>'
                . '<td>' . (int) $row['attempt_count'] . '</td>'
                . '<td>' . $this->formatPercent((float) $row['attempt_rate']) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function renderAttemptsTable(array $attempts, bool $include_user): string
    {
        global $DIC;

        $table = new ilassStackStatisticsAttemptsTable(
            $attempts,
            $include_user,
            ''
        );

        return $DIC->ui()->renderer()->render($table->getComponent());
    }

    private function renderEmptyState(string $message, bool $wrapped = true): string
    {
        $html = '<p class="ilInfoMessage xstsa-empty">' . htmlspecialchars($message) . '</p>';
        return $wrapped ? $this->renderPanel($this->plugin->txt('panel_empty'), $html) : $html;
    }

    private function collectPrtOptions(array $question_ids): array
    {
        $all = [];
        foreach ($question_ids as $question_id) {
            foreach ($this->data_provider->getPrtOptions($question_id) as $prt_name) {
                $all[$prt_name] = $prt_name;
            }
        }
        ksort($all);
        return array_keys($all);
    }

    private function mapOptions(array $options): array
    {
        $mapped = [];
        foreach ($options as $option) {
            $mapped[$option] = $option;
        }
        return $mapped;
    }

    private function formatPercent(float $fraction): string
    {
        return round($fraction * 100, 1) . ' %';
    }

    private function formatDuration(int $milliseconds): string
    {
        $seconds = max(0, (int) round($milliseconds / 1000));
        $minutes = intdiv($seconds, 60);
        $remaining_seconds = $seconds % 60;

        if ($minutes > 0) {
            return $minutes . 'm ' . $remaining_seconds . 's';
        }

        return $remaining_seconds . 's';
    }

    private function isTeacher(int $ref_id): bool
    {
        global $DIC;

        return $DIC->access()->checkAccess('write', '', $ref_id)
            || $DIC->access()->checkAccess('tst_results', '', $ref_id);
    }

    private function renderTemplate(string $name, array $vars): string
    {
        $path = ILIAS_ABSOLUTE_PATH
            . '/public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/assStackStatistics/templates/'
            . 'tpl.' . $name . '.html';

        $template = file_get_contents($path);
        if ($template === false) {
            throw new RuntimeException('Template not found: ' . $name);
        }

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return preg_replace('/\{\{[A-Z_]+}}/', '', $template) ?? $template;
    }

    private function renderChartCanvas(string $type, array $config, ?string $center_label = null): string
    {
        $chart_id = 'xstsa-chart-' . (++$this->chartCounter);
        $chart_config = array_merge(['type' => $type], $config);
        $encoded = json_encode($chart_config, JSON_UNESCAPED_SLASHES);
        $this->chartScripts[] = 'new Chart(document.getElementById(' . json_encode($chart_id) . '), ' . $encoded . ');';

        $center_html = '';
        if ($center_label !== null) {
            $center_html = '<div class="xstsa-chart-center">' . htmlspecialchars($center_label) . '</div>';
        }

        return '<div class="xstsa-chart-wrap"><div class="xstsa-chart-canvas-wrap"><canvas id="' . htmlspecialchars($chart_id) . '" class="xstsa-chart-canvas"></canvas>' . $center_html . '</div></div>';
    }

    private function renderChartScripts(): string
    {
        if ($this->chartScripts === []) {
            return '';
        }

        return '<script src="Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/assStackStatistics/templates/js/chart.umd.min.js"></script>'
            . '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof Chart==="undefined"){return;}const xstsaMarkersPlugin={id:"xstsaMarkers",afterDatasetsDraw(chart,args,pluginOptions){const markers=(pluginOptions||[]);if(!markers.length){return;}const area=chart.chartArea;if(!area){return;}const ctx=chart.ctx;markers.forEach(function(marker){const fraction=Math.max(0,Math.min(1,Number(marker.fraction)||0));const x=area.left+((area.right-area.left)*fraction);ctx.save();ctx.beginPath();ctx.setLineDash(Array.isArray(marker.dash)?marker.dash:[]);ctx.strokeStyle=marker.color||"#000";ctx.lineWidth=2;ctx.moveTo(x,area.top);ctx.lineTo(x,area.bottom);ctx.stroke();ctx.restore();});}};Chart.register(xstsaMarkersPlugin);' . implode('', $this->chartScripts) . '});</script>';
    }

    private function loadAnalyticsClass(): void
    {
        $path = ILIAS_ABSOLUTE_PATH
            . '/public/Customizing/global/plugins/Modules/TestQuestionPool/Questions'
            . '/assStackQuestion/classes/analytics/class.assStackQuestionAnalyticsDB.php';

        if (!class_exists('assStackQuestionAnalyticsDB') && file_exists($path)) {
            require_once $path;
        }
    }
}
