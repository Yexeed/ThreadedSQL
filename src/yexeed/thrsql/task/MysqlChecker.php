<?php


namespace yexeed\thrsql\task;


use pocketmine\plugin\Plugin;
use pocketmine\scheduler\PluginTask;

class MysqlChecker extends PluginTask
{
    public function __construct(Plugin $owner)
    {
        parent::__construct($owner);
    }

    public function onRun(int $currentTick)
    {
        /** @see ThreadedSQL::check() */

        /** @noinspection PhpUndefinedMethodInspection */
        $this->getOwner()->check();
    }
}