<?php

class ilassStackStatisticsDataProvider
{
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
            . ' COALESCE(h.open_count, 0) AS hint_open_count'
            . ' FROM xqcas_anl_attempts a'
            . ' LEFT JOIN ('
            . '   SELECT question_id, active_id, pass, COUNT(*) AS open_count'
            . '   FROM xqcas_hint_tracking'
            . "   WHERE event_type = 'open'"
            . '   GROUP BY question_id, active_id, pass'
            . ' ) h ON h.question_id = a.question_id AND h.active_id = a.active_id AND h.pass = a.pass'
            . ' WHERE ' . $question_in
            . ' ORDER BY a.stamp DESC'
        );

        $rows = [];
        while ($row = $db->fetchAssoc($res)) {
            $row['fraction'] = ((float) $row['max_points'] > 0.0)
                ? ((float) $row['total_points'] / (float) $row['max_points'])
                : 0.0;
            $row['hint_open_count'] = (int) $row['hint_open_count'];
            $row['hint_used'] = $row['hint_open_count'] > 0;

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

        if (($filters['attempt_scope'] ?? 'all') === 'latest') {
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
                'title' => ilObject::_lookupTitle($question_id) ?: ('Q' . $question_id),
                'avg_score' => $this->average(array_column($attempts, 'fraction')),
                'hint_rate' => $this->hintRate($attempts),
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
