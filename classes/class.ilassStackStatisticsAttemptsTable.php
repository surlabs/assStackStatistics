<?php

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRowBuilder;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\Data;

class ilassStackStatisticsAttemptsTable implements DataRetrieval
{
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
                'question' => ilObject::_lookupTitle((int) $attempt['question_id']) ?: ('Q' . $attempt['question_id']),
                'score' => round((float) $attempt['fraction'] * 100, 1) . ' %',
                'hints' => (int) $attempt['hint_open_count'],
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
        $columns['score'] = $cf->text($this->txt('col_score'))->withIsSortable(true);
        $columns['hints'] = $cf->number($this->txt('col_hints'))->withIsSortable(true);
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
                    ilObject::_lookupTitle((int) $left['question_id']) ?: ('Q' . $left['question_id']),
                    ilObject::_lookupTitle((int) $right['question_id']) ?: ('Q' . $right['question_id'])
                ),
                'score' => ((float) $left['fraction']) <=> ((float) $right['fraction']),
                'hints' => ((int) $left['hint_open_count']) <=> ((int) $right['hint_open_count']),
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
}
