<?php

class ilassStackStatisticsDataProvider
{
    private array $question_titles = [];
    private array $question_order = [];

    public function getStackQuestionIds(int $ref_id): array
    {
        global $DIC;

        $db = $DIC->database();
        $obj_id = (int) ilObject::_lookupObjId($ref_id);
        $res = $db->queryF(
            'SELECT DISTINCT qq.question_id'
            . ' FROM tst_tests tt'
            . ' JOIN tst_test_question ttq ON ttq.test_fi = tt.test_id'
            . ' JOIN qpl_questions qq ON qq.question_id = ttq.question_fi'
            . ' JOIN qpl_qst_type qqt ON qqt.question_type_id = qq.question_type_fi'
            . ' WHERE tt.obj_fi = %s AND qqt.type_tag = %s'
            . ' ORDER BY ttq.sequence',
            ['integer', 'text'],
            [$obj_id, 'assStackQuestion']
        );

        $ids = [];
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (int) $row['question_id'];
        }
        $this->question_order = array_flip($ids);
        return $ids;
    }

    public function getAttemptRows(array $question_ids, array $filters): array
    {
        global $DIC;

        if ($question_ids === []) {
            return [];
        }

        $db = $DIC->database();
        $question_in = $db->in('a.question_id', $question_ids, false, 'integer');
        $res = $db->query(
            'SELECT a.question_id, a.active_id, a.pass, a.user_id, a.total_points, a.max_points, a.has_error, a.stamp,'
            . ' COALESCE(h.open_count, 0) AS hint_open_count,'
            . ' COALESCE(t.total_ms, 0) AS total_time_ms'
            . ' FROM xqcas_anl_attempts a'
            . ' LEFT JOIN ('
            . '   SELECT question_id, active_id, pass, COUNT(*) AS open_count'
            . '   FROM xqcas_hint_tracking'
            . "   WHERE event_type = 'open'"
            . '   GROUP BY question_id, active_id, pass'
            . ' ) h ON h.question_id = a.question_id AND h.active_id = a.active_id AND h.pass = a.pass'
            . ' LEFT JOIN xqcas_time_tracking t ON t.question_id = a.question_id AND t.active_id = a.active_id AND t.pass = a.pass AND t.user_id = a.user_id'
            . ' WHERE ' . $question_in
            . ' ORDER BY a.stamp DESC'
        );

        $rows = [];
        while ($row = $db->fetchAssoc($res)) {
            $row['fraction'] = ((float) $row['max_points'] > 0.0)
                ? ((float) $row['total_points'] / (float) $row['max_points'])
                : 0.0;
            $row['hint_open_count'] = (int) $row['hint_open_count'];
            $row['total_time_ms'] = (int) ($row['total_time_ms'] ?? 0);
            $row['hint_used'] = $row['hint_open_count'] > 0;
            $row['attempt_no'] = (int) $row['pass'] + 1;

            if (!empty($filters['user_id']) && (int) $filters['user_id'] !== (int) $row['user_id']) {
                continue;
            }
            if (($filters['hint_usage'] ?? 'all') === 'with' && !$row['hint_used']) {
                continue;
            }
            if (($filters['hint_usage'] ?? 'all') === 'without' && $row['hint_used']) {
                continue;
            }
            if (!$this->matchesScoreBand($row['fraction'], (string) ($filters['score_band'] ?? 'all'))) {
                continue;
            }

            $rows[] = $row;
        }

        $attempt_scope = (string) ($filters['attempt_scope'] ?? 'all');
        if (ctype_digit($attempt_scope)) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['attempt_no'] === (int) $attempt_scope
            ));
        }

        if ($attempt_scope === 'latest') {
            $latest = [];
            foreach ($rows as $row) {
                $key = $row['question_id'] . ':' . $row['user_id'];
                if (!isset($latest[$key])) {
                    $latest[$key] = $row;
                }
            }
            $rows = array_values($latest);
        }

        return $rows;
    }

    public function buildQuestionStats(array $question_ids, array $filters): array
    {
        $rows = [];
        foreach ($question_ids as $question_id) {
            $attempts = $this->getAttemptRows([$question_id], $filters);
            if ($attempts === []) {
                continue;
            }

            $rows[] = [
                'question_id' => $question_id,
                'title' => $this->getQuestionTitle($question_id),
                'avg_score' => $this->average(array_column($attempts, 'fraction')),
                'hint_rate' => $this->hintRate($attempts),
                'avg_time_ms' => $this->average(array_column($attempts, 'total_time_ms')),
                'attempt_count' => count($attempts),
            ];
        }

        return $rows;
    }

    public function getUserOptions(array $question_ids): array
    {
        $attempts = $this->getAttemptRows($question_ids, []);
        $user_ids = array_unique(array_map(static fn(array $row): int => (int) $row['user_id'], $attempts));
        sort($user_ids);

        $options = [];
        foreach ($user_ids as $user_id) {
            $options[$user_id] = ilObjUser::_lookupFullname($user_id);
        }

        return $options;
    }

    public function getAttemptNumberOptions(array $question_ids): array
    {
        $numbers = array_unique(array_column($this->getAttemptRows($question_ids, []), 'attempt_no'));
        sort($numbers);

        return $numbers;
    }

    /**
     * One row per hint and question, restricted to the given attempts.
     */
    public function getHintUsageRows(array $attempts): array
    {
        $rows = [];
        foreach ($this->getHintOpenEvents($attempts) as $event) {
            $key = $event['question_id'] . ':' . $event['hint_index'];
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'question_id' => $event['question_id'],
                    'hint_index' => $event['hint_index'],
                    'hint_title' => $event['hint_title'],
                    'open_count' => 0,
                    'attempt_keys' => [],
                ];
            }
            $rows[$key]['open_count']++;
            $rows[$key]['attempt_keys'][$event['active_id'] . ':' . $event['pass']] = true;
        }

        $attempts_per_question = array_count_values(array_map(
            static fn(array $attempt): int => (int) $attempt['question_id'],
            $attempts
        ));

        foreach ($rows as &$row) {
            $row['attempt_count'] = count($row['attempt_keys']);
            $row['attempt_rate'] = $row['attempt_count'] / max(1, $attempts_per_question[$row['question_id']] ?? 0);
            unset($row['attempt_keys']);
        }
        unset($row);

        usort($rows, fn(array $left, array $right): int => [$this->getQuestionOrder($left['question_id']), $left['hint_index']]
            <=> [$this->getQuestionOrder($right['question_id']), $right['hint_index']]);

        return $rows;
    }

    /**
     * One row per attempt and hint opened in it, restricted to the given attempts.
     */
    public function getHintUsagePerAttemptRows(array $attempts): array
    {
        $attempts_by_key = [];
        foreach ($attempts as $attempt) {
            $attempts_by_key[$attempt['question_id'] . ':' . $attempt['active_id'] . ':' . $attempt['pass']] = $attempt;
        }

        $rows = [];
        foreach ($this->getHintOpenEvents($attempts) as $event) {
            $attempt_key = $event['question_id'] . ':' . $event['active_id'] . ':' . $event['pass'];
            $key = $attempt_key . ':' . $event['hint_index'];
            if (!isset($rows[$key])) {
                $rows[$key] = $attempts_by_key[$attempt_key] + [
                    'hint_index' => $event['hint_index'],
                    'hint_title' => $event['hint_title'],
                    'hint_opens' => 0,
                    'first_opened' => $event['stamp'],
                ];
            }
            $rows[$key]['hint_opens']++;
            $rows[$key]['first_opened'] = min($rows[$key]['first_opened'], $event['stamp']);
        }

        return array_values($rows);
    }

    private function getHintOpenEvents(array $attempts): array
    {
        global $DIC;

        if ($attempts === []) {
            return [];
        }

        $allowed = [];
        foreach ($attempts as $attempt) {
            $allowed[$attempt['question_id'] . ':' . $attempt['active_id'] . ':' . $attempt['pass']] = true;
        }

        $db = $DIC->database();
        $question_ids = array_unique(array_map(static fn(array $attempt): int => (int) $attempt['question_id'], $attempts));
        $res = $db->query(
            'SELECT question_id, active_id, pass, hint_index, hint_title, stamp'
            . ' FROM xqcas_hint_tracking'
            . ' WHERE ' . $db->in('question_id', $question_ids, false, 'integer')
            . " AND event_type = 'open'"
            . ' ORDER BY stamp ASC'
        );

        $events = [];
        while ($row = $db->fetchAssoc($res)) {
            if (!isset($allowed[$row['question_id'] . ':' . $row['active_id'] . ':' . $row['pass']])) {
                continue;
            }
            $events[] = [
                'question_id' => (int) $row['question_id'],
                'active_id' => (int) $row['active_id'],
                'pass' => (int) $row['pass'],
                'hint_index' => (int) $row['hint_index'],
                'hint_title' => trim((string) $row['hint_title']),
                'stamp' => (int) $row['stamp'],
            ];
        }

        return $events;
    }

    private function getQuestionOrder(int $question_id): int
    {
        return $this->question_order[$question_id] ?? PHP_INT_MAX;
    }

    public function getPrtOptions(int $question_id): array
    {
        return array_keys(assStackQuestionAnalyticsDB::getPrtSummaryByQuestion($question_id));
    }

    public function getPrtAnswerNoteDistribution(int $question_id, string $prt_name, array $attempts): array
    {
        global $DIC;

        if ($attempts === []) {
            return [];
        }

        $allowed = [];
        foreach ($attempts as $attempt) {
            if ((int) $attempt['question_id'] !== $question_id) {
                continue;
            }
            $allowed[$attempt['active_id'] . ':' . $attempt['pass']] = true;
        }
        if ($allowed === []) {
            return [];
        }

        $db = $DIC->database();
        $res = $db->queryF(
            'SELECT active_id, pass, answer_notes FROM xqcas_anl_prt WHERE question_id = %s AND prt_name = %s',
            ['integer', 'text'],
            [$question_id, $prt_name]
        );

        $distribution = [];
        while ($row = $db->fetchAssoc($res)) {
            $key = $row['active_id'] . ':' . $row['pass'];
            if (!isset($allowed[$key])) {
                continue;
            }
            foreach (explode(';', (string) $row['answer_notes']) as $note) {
                $note = trim($note);
                if ($note === '') {
                    continue;
                }
                $distribution[$note] = ($distribution[$note] ?? 0) + 1;
            }
        }

        arsort($distribution);
        return $distribution;
    }

    public function getQuestionTitle(int $question_id): string
    {
        if (isset($this->question_titles[$question_id])) {
            return $this->question_titles[$question_id];
        }

        global $DIC;
        $db = $DIC->database();

        $row = $db->fetchAssoc($db->queryF(
            'SELECT title FROM qpl_questions WHERE question_id = %s',
            ['integer'],
            [$question_id]
        ));

        $this->question_titles[$question_id] = trim((string) ($row['title'] ?? '')) ?: ('Q' . $question_id);

        return $this->question_titles[$question_id];
    }

    public function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    public function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);
        return $count % 2 === 0
            ? (((float) $values[$middle - 1] + (float) $values[$middle]) / 2)
            : (float) $values[$middle];
    }

    public function hintRate(array $attempts): float
    {
        if ($attempts === []) {
            return 0.0;
        }

        $with_hints = count(array_filter($attempts, static fn(array $row): bool => $row['hint_used']));
        return $with_hints / count($attempts);
    }

    private function matchesScoreBand(float $fraction, string $score_band): bool
    {
        return match ($score_band) {
            'low' => $fraction < 0.5,
            'mid' => $fraction >= 0.5 && $fraction < 0.8,
            'high' => $fraction >= 0.8,
            default => true,
        };
    }
}
