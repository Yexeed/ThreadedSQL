<?php


namespace yexeed\thrsql\task;


use pocketmine\scheduler\Task;
use yexeed\thrsql\ThreadedSQL;

class MysqlChecker extends Task
{
    /** @var ThreadedSQL */
    private $owner;

    public function __construct(ThreadedSQL $owner)
    {
        $this->owner = $owner;
    }

    public function onRun(int $currentTick)
    {
        $this->owner->check();
    }
}