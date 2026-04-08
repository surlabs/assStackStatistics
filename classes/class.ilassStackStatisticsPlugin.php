<?php

class ilassStackStatisticsPlugin extends ilUserInterfaceHookPlugin
{
    public const PLUGIN_NAME = "assStackStatistics";

    private static ?ilassStackStatisticsPlugin $instance = null;

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    public static function getInstance(): ilassStackStatisticsPlugin
    {
        if (self::$instance === null) {
            global $DIC;
            $info = $DIC["component.repository"]->getPluginByName(self::PLUGIN_NAME);
            self::$instance = $DIC["component.factory"]->getPlugin($info->getId());
        }
        return self::$instance;
    }
}
