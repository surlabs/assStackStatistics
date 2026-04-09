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
        require_once __DIR__ . '/class.ilassStackStatisticsDashboardRenderer.php';
    }

    public function modifyGUI(string $a_comp, string $a_part, array $a_par = []): void
    {
        if ($a_part !== 'tabs') {
            return;
        }

        global $DIC;

        $params = $DIC->http()->request()->getQueryParams();
        $ref_id = (int) ($params['ref_id'] ?? 0);
        $cmd_class = strtolower((string) ($params['cmdClass'] ?? ''));
        $base_class = strtolower((string) ($params['baseClass'] ?? ''));

        if (!in_array($base_class, ['ilobjtestgui', 'ilrepositorygui'], true) || $ref_id <= 0) {
            return;
        }

        if ($cmd_class === 'ilassquestionpreviewgui') {
            return;
        }

        if (ilObject::_lookupType(ilObject::_lookupObjId($ref_id)) !== 'tst') {
            return;
        }

        if (!$this->hasStatisticsAccess($ref_id) || !$this->hasStackQuestions($ref_id)) {
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
        if ($ref_id <= 0 || !$this->hasStatisticsAccess($ref_id)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', $DIC->language()->txt('no_permission'), true);
            $DIC->ctrl()->redirectToURL(ilLink::_getStaticLink(1, 'root', true));
            return;
        }

        $tpl = $DIC->ui()->mainTemplate();
        $tpl->addCss('Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/assStackStatistics/templates/css/stats.css');

        $obj_id = ilObject::_lookupObjId($ref_id);
        $tpl->setTitle(ilObject::_lookupTitle($obj_id));
        $tpl->setTitleIcon(ilObject::_getIcon($obj_id, 'big', 'tst'));

        $test_gui = new ilObjTestGUI();
        $test_gui->getTabs();

        $DIC->ctrl()->setParameterByClass(self::class, 'ref_id', $ref_id);
        $DIC->tabs()->addTab(
            'stack_statistics',
            $this->plugin->txt('tab_statistics'),
            $DIC->ctrl()->getLinkTargetByClass(['ilUIPluginRouterGUI', self::class], 'showStatistics')
        );
        $DIC->tabs()->activateTab('stack_statistics');

        try {
            $renderer = new ilassStackStatisticsDashboardRenderer($this->plugin);
            $content = $renderer->render($ref_id);
        } catch (Throwable $e) {
            $content = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        $tpl->setContent($content);
        $tpl->printToStdout();
    }

    private function getStackQuestionIds(int $ref_id): array
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

    private function hasStackQuestions(int $ref_id): bool
    {
        return $this->getStackQuestionIds($ref_id) !== [];
    }

    private function hasStatisticsAccess(int $ref_id): bool
    {
        global $DIC;

        return $DIC->access()->checkAccess('write', '', $ref_id)
            || $DIC->access()->checkAccess('tst_results', '', $ref_id)
            || $DIC->access()->checkAccess('read', '', $ref_id);
    }
}
