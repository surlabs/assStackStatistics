<?php

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRowBuilder;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\Data;

class ilassStackStatisticsAttemptsTable implements DataRetrieval
{
    private array $question_titles = [];

    public function __construct(
        private readonly array $attempts,
        private readonly bool $include_user,
        private readonly string $title
    ) {
    }

    public function getComponent(): Data
    {
        global $DIC;

        return $DIC->ui()->factory()
            ->table()
            ->data(
                $this->title,
                $this->getColumns(),
                $this
            )
            ->withId(str_replace('\\', '', self::class) . '_' . md5($this->title . (int) $this->include_user))
            ->withRange(new Range(0, 20))
            ->withOrder(new Order('stamp', Order::DESC))
            ->withRequest($DIC->http()->request());
    }

    public function getRows(
        DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): Generator {
        global $DIC;

        $records = $this->getOrderedRecords($order);
        $records = array_slice($records, $range->getStart(), $range->getLength());

        foreach ($records as $index => $attempt) {
            $record = [
                'question' => $this->getQuestionTitle((int) $attempt['question_id']),
                'attempt_no' => (int) $attempt['attempt_no'],
                'score' => round((float) $attempt['fraction'] * 100, 1) . ' %',
                'hints' => (int) $attempt['hint_open_count'],
                'time_spent' => $this->formatDuration((int) ($attempt['total_time_ms'] ?? 0)),
                'stamp' => (new DateTimeImmutable('@' . (int) $attempt['stamp']))
                    ->setTimezone(new DateTimeZone($DIC->user()->getTimeZone() ?: date_default_timezone_get())),
            ];

            if ($this->include_user) {
                $record['student'] = ilObjUser::_lookupFullname((int) $attempt['user_id']);
            }

            yield $row_builder->buildDataRow((string) ($attempt['question_id'] . '_' . $attempt['active_id'] . '_' . $attempt['pass'] . '_' . $index), $record);
        }
    }

    public function getTotalRowCount(?array $filter_data, ?array $additional_parameters): ?int
    {
        return count($this->attempts);
    }

    private function getColumns(): array
    {
        global $DIC;

        $cf = $DIC->ui()->factory()->table()->column();
        $date_format = $DIC->user()->getDateTimeFormat();

        $columns = [];
        if ($this->include_user) {
            $columns['student'] = $cf->text($DIC->language()->txt('user'))->withIsSortable(true);
        }
        $columns['question'] = $cf->text($DIC->language()->txt('question'))->withIsSortable(true);
        $columns['attempt_no'] = $cf->number($this->txt('col_attempt_no'))->withIsSortable(true);
        $columns['score'] = $cf->text($this->txt('col_score'))->withIsSortable(true);
        $columns['hints'] = $cf->number($this->txt('col_hints'))->withIsSortable(true);
        $columns['time_spent'] = $cf->text($this->txt('col_time_spent'))->withIsSortable(true);
        $columns['stamp'] = $cf->date($this->txt('col_attempt_time'), $date_format)->withIsSortable(true);

        return $columns;
    }

    private function getOrderedRecords(Order $order): array
    {
        $records = $this->attempts;
        [$field, $direction] = $order->join([], static fn($ret, $key, $value) => [$key, $value]);

        usort($records, function (array $left, array $right) use ($field): int {
            return match ($field) {
                'student' => ilStr::strCmp(ilObjUser::_lookupFullname((int) $left['user_id']), ilObjUser::_lookupFullname((int) $right['user_id'])),
                'question' => ilStr::strCmp(
                    $this->getQuestionTitle((int) $left['question_id']),
                    $this->getQuestionTitle((int) $right['question_id'])
                ),
                'attempt_no' => ((int) $left['attempt_no']) <=> ((int) $right['attempt_no']),
                'score' => ((float) $left['fraction']) <=> ((float) $right['fraction']),
                'hints' => ((int) $left['hint_open_count']) <=> ((int) $right['hint_open_count']),
                'time_spent' => ((int) ($left['total_time_ms'] ?? 0)) <=> ((int) ($right['total_time_ms'] ?? 0)),
                default => ((int) $left['stamp']) <=> ((int) $right['stamp']),
            };
        });

        if ($direction === 'DESC') {
            $records = array_reverse($records);
        }

        return $records;
    }

    private function txt(string $key): string
    {
        return ilassStackStatisticsPlugin::getInstance()->txt($key);
    }

    private function getQuestionTitle(int $question_id): string
    {
        if (isset($this->question_titles[$question_id])) {
            return $this->question_titles[$question_id];
        }

        global $DIC;

        $row = $DIC->database()->fetchAssoc($DIC->database()->queryF(
            'SELECT title FROM qpl_questions WHERE question_id = %s',
            ['integer'],
            [$question_id]
        ));

        $this->question_titles[$question_id] = trim((string) ($row['title'] ?? '')) ?: ('Q' . $question_id);

        return $this->question_titles[$question_id];
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
}
