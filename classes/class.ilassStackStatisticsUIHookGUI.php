<?php

/**
 * @ilCtrl_IsCalledBy ilassStackStatisticsUIHookGUI: ilUIPluginRouterGUI
 */
class ilassStackStatisticsUIHookGUI extends ilUIHookPluginGUI
{
    private ilassStackStatisticsPlugin $plugin;

    public function __construct()
    {
        $this->plugin = ilassStackStatisticsPlugin::getInstance();
    }

    public function modifyGUI(string $a_comp, string $a_part, array $a_par = []): void
    {
        if ($a_part !== 'tabs') {
            return;
        }

        global $DIC;

        $params = $DIC->http()->request()->getQueryParams();
        $ref_id = (int) ($params['ref_id'] ?? 0);

        if (strtolower($params['baseClass'] ?? '') !== 'ilobjtestgui' || $ref_id <= 0) {
            return;
        }

        if (!$DIC->access()->checkAccess('write', '', $ref_id)
            && !$DIC->access()->checkAccess('tst_results', '', $ref_id)) {
            return;
        }

        $DIC->ctrl()->setParameterByClass(self::class, 'ref_id', $ref_id);
        $a_par['tabs']->addTab(
            'stack_statistics',
            $this->plugin->txt('tab_statistics'),
            $DIC->ctrl()->getLinkTargetByClass(['ilUIPluginRouterGUI', self::class], 'showStatistics')
        );
    }

    public function getHTML(string $a_comp, string $a_part, array $a_par = []): array
    {
        return ['mode' => self::KEEP, 'html' => ''];
    }

    public function executeCommand(): void
    {
        global $DIC;
        $cmd = $DIC->ctrl()->getCmd('showStatistics');
        $this->{method_exists($this, $cmd) ? $cmd : 'showStatistics'}();
    }

    public function showStatistics(): void
    {
        global $DIC;

        $ref_id = (int) ($DIC->http()->request()->getQueryParams()['ref_id'] ?? 0);

        if ($ref_id <= 0
            || (!$DIC->access()->checkAccess('write', '', $ref_id)
                && !$DIC->access()->checkAccess('tst_results', '', $ref_id))) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', $DIC->language()->txt('no_permission'), true);
            $DIC->ctrl()->redirectToURL(ilLink::_getStaticLink(1, 'root', true));
            return;
        }

        $tpl = $DIC->ui()->mainTemplate();
        $tpl->addCss('Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/assStackStatistics/templates/css/stats.css');

        $obj_id = ilObject::_lookupObjId($ref_id);
        $tpl->setTitle(ilObject::_lookupTitle($obj_id));
        $tpl->setTitleIcon(ilObject::_getIcon($obj_id, 'big', 'tst'));

        $DIC->tabs()->clearTargets();
        $DIC->tabs()->setBackTarget($this->plugin->txt('back_to_test'), ilLink::_getStaticLink($ref_id, 'tst'));

        $DIC->ctrl()->setParameterByClass(self::class, 'ref_id', $ref_id);
        $DIC->tabs()->addTab(
            'stack_statistics',
            $this->plugin->txt('tab_statistics'),
            $DIC->ctrl()->getLinkTargetByClass(['ilUIPluginRouterGUI', self::class], 'showStatistics')
        );
        $DIC->tabs()->activateTab('stack_statistics');

        try {
            $content = $this->renderContent($ref_id);
        } catch (Throwable $e) {
            $content = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        $tpl->setContent($content);
        $tpl->printToStdout();
    }

    private function renderContent(int $ref_id): string
    {
        global $DIC;

        $question_ids = $this->getStackQuestionIds($ref_id);
        if (empty($question_ids)) {
            return '<p class="ilInfoMessage">' . $this->plugin->txt('no_stack_questions') . '</p>';
        }

        $selected_qid = (int) ($DIC->http()->request()->getQueryParams()['question_id'] ?? 0);
        if (!in_array($selected_qid, $question_ids, true)) {
            $selected_qid = $question_ids[0];
        }

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $panels = [];

        if (count($question_ids) > 1) {
            $panels[] = $factory->legacy($this->renderQuestionSelector($question_ids, $selected_qid, $ref_id));
        }

        $this->loadAnalyticsClass();
        if (!class_exists('assStackQuestionAnalyticsDB')) {
            return '<p class="ilInfoMessage">' . $this->plugin->txt('analytics_unavailable') . '</p>';
        }

        $summary = assStackQuestionAnalyticsDB::getAttemptSummary($selected_qid);
        $panels[] = $factory->panel()->standard(
            $this->plugin->txt('panel_attempt_summary'),
            [$factory->legacy(
                !empty($summary) && (int) ($summary['attempt_count'] ?? 0) > 0
                    ? $this->renderAttemptSummary($summary)
                    : '<p class="ilInfoMessage">' . $this->plugin->txt('no_attempts_yet') . '</p>'
            )]
        );

        $prt_summary = assStackQuestionAnalyticsDB::getPrtSummaryByQuestion($selected_qid);
        if (!empty($prt_summary)) {
            $panels[] = $factory->panel()->standard(
                $this->plugin->txt('panel_prt_summary'),
                [$factory->legacy($this->renderPrtSummary($prt_summary))]
            );
        }

        $hint_usage = assStackQuestionAnalyticsDB::getHintUsage($selected_qid);
        if (!empty($hint_usage)) {
            $panels[] = $factory->panel()->standard(
                $this->plugin->txt('panel_hint_usage'),
                [$factory->legacy($this->renderHintUsage($hint_usage))]
            );
        }

        return $renderer->render($panels);
    }

    private function renderQuestionSelector(array $question_ids, int $selected_qid, int $ref_id): string
    {
        global $DIC;

        $html = '<div class="xstsa-question-selector"><span class="xstsa-question-selector__label">'
            . $this->plugin->txt('question') . ':</span><div class="xstsa-question-selector__actions">';

        foreach ($question_ids as $qid) {
            $DIC->ctrl()->setParameterByClass(self::class, 'ref_id', $ref_id);
            $DIC->ctrl()->setParameterByClass(self::class, 'question_id', $qid);
            $url = $DIC->ctrl()->getLinkTargetByClass(['ilUIPluginRouterGUI', self::class], 'showStatistics');
            $class = $qid === $selected_qid ? 'btn btn-primary btn-sm' : 'btn btn-default btn-sm';
            $html .= '<a href="' . htmlspecialchars($url) . '" class="' . $class . '">'
                . htmlspecialchars(ilObject::_lookupTitle($qid) ?: ('Q' . $qid)) . '</a>';
        }

        return $html . '</div></div>';
    }

    private function renderAttemptSummary(array $summary): string
    {
        $rows = [
            [$this->plugin->txt('stat_attempts'),   (int) ($summary['attempt_count'] ?? 0)],
            [$this->plugin->txt('stat_users'),       (int) ($summary['unique_users'] ?? 0)],
            [$this->plugin->txt('stat_avg_score'),   round((float) ($summary['avg_fraction'] ?? 0) * 100, 1) . ' %'],
            [$this->plugin->txt('stat_avg_points'),  round((float) ($summary['avg_total_points'] ?? 0), 2)],
            [$this->plugin->txt('stat_error_rate'),  round((float) ($summary['error_rate'] ?? 0) * 100, 1) . ' %'],
        ];

        $html = '<table class="table table-condensed xstsa-table xstsa-table--summary">';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function renderPrtSummary(array $prt_summary): string
    {
        $rows = [];
        foreach ($prt_summary as $prt_name => $data) {
            $rows[] = [
                htmlspecialchars((string) $prt_name),
                (int) ($data['attempt_count'] ?? 0),
                round((float) ($data['avg_fraction'] ?? 0) * 100, 1) . ' %',
                round((float) ($data['max_points'] ?? 0), 2),
                (int) ($data['error_count'] ?? 0),
            ];
        }
        return $this->buildHeaderTable(
            [$this->plugin->txt('col_prt_name'), $this->plugin->txt('col_attempts'),
             $this->plugin->txt('col_avg_score'), $this->plugin->txt('col_max_points'), $this->plugin->txt('col_errors')],
            $rows
        );
    }

    private function renderHintUsage(array $hint_usage): string
    {
        $rows = [];
        foreach ($hint_usage as $hint) {
            $rows[] = [
                (int) ($hint['hint_index'] ?? 0),
                htmlspecialchars((string) ($hint['hint_title'] ?? '')),
                (int) ($hint['open_count'] ?? 0),
                (int) ($hint['unique_attempts'] ?? 0),
            ];
        }
        return $this->buildHeaderTable(
            ['#', $this->plugin->txt('col_hint_title'), $this->plugin->txt('col_open_count'), $this->plugin->txt('col_unique_attempts')],
            $rows
        );
    }

    private function buildHeaderTable(array $headers, array $rows): string
    {
        $html = '<table class="table table-condensed table-striped xstsa-table"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . $h . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private function getStackQuestionIds(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $obj_id = (int) ($db->fetchAssoc($db->queryF(
            "SELECT obj_id FROM object_reference WHERE ref_id = %s",
            ['integer'], [$ref_id]
        ))['obj_id'] ?? 0);

        $res = $db->query(
            "SELECT DISTINCT xaa.question_id
             FROM tst_active ta
             JOIN xqcas_anl_attempts xaa ON xaa.active_id = ta.active_id
             WHERE ta.test_fi = (SELECT test_id FROM tst_tests WHERE obj_fi = " . $db->quote($obj_id, 'integer') . ")
             ORDER BY xaa.question_id"
        );

        $ids = [];
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (int) $row['question_id'];
        }
        return $ids;
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
