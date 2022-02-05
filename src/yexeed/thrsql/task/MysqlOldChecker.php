<?php


namespace yexeed\thrsql\task;


use pocketmine\scheduler\Task;
use yexeed\thrsql\ThreadedSQL;

class MysqlOldChecker extends Task
{
    /** @var ThreadedSQL */
    private $owner;

    public function __construct(ThreadedSQL $owner)
    {
        $this->owner = $owner;
    }

    public function onRun(int $currentTick): void
    {
        $this->owner->check();
    }
}