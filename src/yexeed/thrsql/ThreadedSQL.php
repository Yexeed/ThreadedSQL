<?php
/**
 * Created by PhpStorm.
 * User: yexee
 * Date: 08.02.2019
 * Time: 17:44
 */

namespace yexeed\thrsql;


use Closure;
use Exception;
use mysqli;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use yexeed\thrsql\task\MysqlChecker;
use yexeed\thrsql\utils\MysqlSettings;
use yexeed\thrsql\utils\PrepareWrap;
use yexeed\thrsql\utils\ResultWrap;

class ThreadedSQL extends PluginBase
{
    /** @var MysqlOldWorker|MysqlNewWorker */
    private $thread;
    /** @var self */
    private static $instance;
    /** @var int */
    private $id = 0;
    /** @var array */
    private $callbacks = [], $timeout = [];

    /** @var TaskHandler */
    private $checkerTaskHandler = null;
    private $working = true;

    public function onEnable(): void
    {
        self::$instance = $this;
        $this->loadMysqlSettings();
        $settings = MysqlSettings::get();

        $params = [$settings->getHostname(),
            $settings->getUsername(),
            $settings->getPassword(),
            $settings->getDatabase(),
            $settings->getPort(),
            $this->getServer()->getLogger()];
        if(self::isPm4()){
            $this->thread = new MysqlNewWorker(...$params);
        }else{
            $this->thread = new MysqlOldWorker(...$params);
        }
        $this->startChecker();
    }

    private function loadMysqlSettings()
    {
        $this->saveDefaultConfig();
        $array = $this->getConfig()->get("mysql");
        MysqlSettings::init($array);
    }

    public function check(){
        if($this->thread->isCrashed()){
            //wow?
            foreach ($this->callbacks as $callback){
                $callback(new ResultWrap([], "MySQL crashed"));
            }
            $this->timeout = [];

            if(self::isPm4()){
                $this->checkerTaskHandler->cancel();
            }else{
                /** @noinspection PhpUndefinedMethodInspection */
                $this->getSched()->cancelTask($this->checkerTaskHandler->getId());
            }
            $this->working = false;
            $this->getLogger()->emergency("MysqlWorker CRASH!!!");
            return;
        }
        while($line = $this->thread->outputs->shift()){
            $json = json_decode($line, true);
            $id = $json['id'];
            $result = $json['result'];

            unset($this->timeout[$id]);

            if (isset($this->callbacks[$id])) {
                $c = $this->callbacks[$id];
                $c(ResultWrap::unserialize($result));
                unset($this->callbacks[$id]);
            }
        }
        foreach ($this->timeout as $id => &$timeout){
            if($timeout <= 0){
                $c = $this->callbacks[$id];
                $c(new ResultWrap([], "Timed-Out", true));

                unset($this->thread->inputs[$id]);
                unset($this->callbacks[$id]);
                unset($this->timeout[$id]);
            }
            $timeout--;
        }
    }

    private function getSched()
    {
        if(method_exists($this, "getScheduler")){
            return $this->getScheduler();
        }else{
            /** @noinspection PhpUndefinedMethodInspection */
            return $this->getServer()->getScheduler();
        }
    }

    public function startChecker(){
        $handler = $this->getSched()->scheduleRepeatingTask(new MysqlChecker($this), 1);
        $this->checkerTaskHandler = $handler;
    }

    /**
     * @param PrepareWrap|string $wrap
     * @param Closure|null $func
     * @param int|null $timeout
     * @throws Exception
     */
    public static function query($wrap, ?Closure $func = null, ?int $timeout = null){
        if(!$wrap instanceof PrepareWrap){
            $wrap = new PrepareWrap($wrap); //deprecated API support :O
        }
        $id = self::$instance->id;
        $wrap->setQueryId($id);
        self::$instance->thread->inputs[] = $wrap->jsonSerialize();
        if($func !== null){
            if($timeout !== null){
                self::$instance->timeout[$id] = $timeout * 20; // second = 20 ticks (task run every tick)
            }
            self::$instance->callbacks[$id] = $func;
        }
        self::$instance->id++;
        if(self::$instance->id === PHP_INT_MAX){ //hz, на всякий случай
            self::$instance->id = 0;
        }
    }

    /**
     * @param string|PrepareWrap $wrap
     * @return array|null
     * @throws Exception
     */
    public static function forceQuery($wrap): ?array{
        if(!$wrap instanceof PrepareWrap){
            $wrap = new PrepareWrap($wrap);
        }
        $settings = MysqlSettings::get();
        $my = new mysqli($settings->getHostname(), $settings->getUsername(), $settings->getPassword(), $settings->getDatabase(), $settings->getPort());
        if(!$my){
            self::$instance->getLogger()->error("Can't connect to forced mysql: " . $my->connect_error);
            return null;
        }

        $mysqlStmt = $my->prepare($wrap->getQuery());
        foreach ($wrap->getBindParameters() as $bindParameter){
            $mysqlStmt->bind_param($bindParameter[0], $bindParameter[1]);
        }

        if(!$mysqlStmt->execute()){
            self::$instance->getLogger()->error("Can't run forced mysql stmt: " . $mysqlStmt->error);
            $rows = null;
        }else{
            $result = $mysqlStmt->get_result();
            $rows = [];
            if($result === false){
                if($mysqlStmt->errno !== 0) {
                    //ERROR!
                    $rows = null;
                }else{
                    $rows = [$mysqlStmt->insert_id]; //no error, just empty result.
                }
            }else {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
                $mysqlStmt->close();
            }
        }
        $mysqlStmt->close();
        $my->close();
        return $rows;
    }

    /**
     * @return bool
     */
    public static function isWorking(): bool
    {
        return self::$instance->working;
    }

    public function onDisable(): void
    {
        if($this->thread->isRunning()) {
            $this->thread->quit();
        }
    }

    private static function isPm4(): bool
    {
        return class_exists("\pocketmine\player\Player");
    }

}