<?php

namespace Zan\Framework\Network\Connection\Driver;


use Zan\Framework\Contract\Network\Connection;
use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Connection\ReconnectionPloy;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Store\Database\Mysql\Mysql as Engine;
use Zan\Framework\Store\Database\Mysql\Mysql2 as Engine2;
use Zan\Framework\Utilities\Types\Time;

class Mysql extends Base implements Connection
{
    protected $isAsync = true;
    private $classHash = null;

    public function closeSocket()
    {
        try {
            $this->getSocket()->close();
        } catch (\Exception $e) {
            echo_exception($e);
        }
    }

    public function init() {
        $this->classHash = spl_object_hash($this);
    }

    // mariodb connect 回调无result 参数
    public function onConnect(\swoole_mysql $cli, $result = true) {
        Timer::clearAfterJob($this->getConnectTimeoutJobId());
        if ($result) {
            $this->release();
            ReconnectionPloy::getInstance()->connectSuccess(spl_object_hash($this));
            $this->heartbeat();
            sys_echo("mysql client connect to server");
        } else {
            if ($cli->connect_errno) {
                sys_error("mysql connect fail [errno={$cli->connect_errno}, error={$cli->connect_error}]");
                $this->close();
            }
        }
    }

    public function onClose(\swoole_mysql $cli){
        Timer::clearAfterJob($this->getConnectTimeoutJobId());
        $this->close();
        sys_echo("mysql client close [errno={$cli->errno}, error={$cli->error}]");
    }

    public function onError(\swoole_mysql $cli){
        Timer::clearAfterJob($this->getConnectTimeoutJobId());
        $this->close();
        if (\swoole2x()) {
            sys_echo("mysql client error [errno={$cli->errno}, error={$cli->error}]");
        } else {
            sys_echo("mysql client error");
        }
    }

    public function close()
    {
        Timer::clearAfterJob($this->getHeartBeatingJobId());
        parent::close();
    }

    public function heartbeat()
    {
        $this->heartbeatLater();
    }

    public function heartbeatLater()
    {
        Timer::after($this->config['pool']['heartbeat-time'], [$this,'heartbeating'], $this->getHeartBeatingJobId());
    }
    
    public function heartbeating()
    {
        $time = Time::current(true) - $this->lastUsedTime;
        $hearBeatTime = $this->config['pool']['heartbeat-time'] / 1000;
        if ($this->lastUsedTime != 0 && $time <  $hearBeatTime) {
            Timer::after(($hearBeatTime-$time) * 1000, [$this,'heartbeating'], $this->getHeartBeatingJobId());
            return;
        }

        $coroutine = $this->ping();
        Task::execute($coroutine);
    }

    public function ping()
    {
        $connection = (yield $this->pool->get($this));
        if (null == $connection) {
            $this->heartbeatLater();
            return;
        }
        $this->setUnReleased();

        if (_mysql2()) {
            $engine = new Engine2($this);
        } else {
            $engine = new Engine($this);
        }

        try{
            yield $engine->query('select 1');
        } catch (\Exception $e){
            return; 
        }

        $this->release();
        $this->heartbeatLater();
    }

    private function getHeartBeatingJobId()
    {
        return $this->classHash . '_heart_beating_job_id';
    }
}