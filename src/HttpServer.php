<?php

namespace Mix\Http\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;

/**
 * Class HttpServer
 * @package Mix\Http\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class HttpServer extends AbstractObject
{

    /**
     * 主机
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * 端口
     * @var int
     */
    public $port = 9501;

    /**
     * 应用配置文件
     * @var string
     */
    public $configFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $setting = [];

    /**
     * 默认运行参数
     * @var array
     */
    protected $_defaultSetting = [
        // 开启协程
        'enable_coroutine'     => false,
        // 主进程事件处理线程数
        'reactor_num'          => 8,
        // 工作进程数
        'worker_num'           => 8,
        // 任务进程数
        'task_worker_num'      => 0,
        // PID 文件
        'pid_file'             => '/var/run/mix-httpd.pid',
        // 日志文件路径
        'log_file'             => '/tmp/mix-httpd.log',
        // 异步安全重启
        'reload_async'         => true,
        // 退出等待时间
        'max_wait_time'        => 60,
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay'     => true,
        // 进程的最大任务数
        'max_request'          => 0,
        // 主进程启动事件回调
        'hook_start'           => null,
        // 主进程停止事件回调
        'hook_shutdown'        => null,
        // 管理进程启动事件回调
        'hook_manager_start'   => null,
        // 工作进程错误事件
        'hook_worker_error'    => null,
        // 管理进程停止事件回调
        'hook_manager_stop'    => null,
        // 工作进程启动事件回调
        'hook_worker_start'    => null,
        // 工作进程停止事件回调
        'hook_worker_stop'     => null,
        // 工作进程退出事件回调
        'hook_worker_exit'     => null,
        // 请求成功回调
        'hook_request_success' => null,
        // 请求错误回调
        'hook_request_error'   => null,
    ];

    /**
     * 服务器
     * @var \Swoole\Http\Server
     */
    public $server;

    /**
     * 服务名称
     * @var string
     */
    const SERVER_NAME = 'mix-httpd';

    /**
     * 初始化事件
     */
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 快捷引用
        \Mix::$server = $this;
    }

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->server = new \Swoole\Http\Server($this->host, $this->port);
        // 配置参数
        $this->setting += $this->_defaultSetting;
        $this->server->set($this->setting);
        // 覆盖参数
        $this->server->set([
            'enable_coroutine' => false, // 关闭默认协程，回调中有手动开启支持上下文的协程
        ]);
        // 绑定事件
        $this->server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->server->on(SwooleEvent::SHUTDOWN, [$this, 'onShutdown']);
        $this->server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->server->on(SwooleEvent::WORKER_ERROR, [$this, 'onWorkerError']);
        $this->server->on(SwooleEvent::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->server->on(SwooleEvent::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->server->on(SwooleEvent::WORKER_EXIT, [$this, 'onWorkerExit']);
        $this->server->on(SwooleEvent::REQUEST, [$this, 'onRequest']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->setting['hook_start'] and call_user_func($this->setting['hook_start'], $this->server);
        // 启动
        return $this->server->start();
    }

    /**
     * 主进程启动事件
     * 仅允许echo、打印Log、修改进程名称，不得执行其他操作
     * @param \Swoole\Http\Server $server
     */
    public function onStart(\Swoole\Http\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": master {$this->host}:{$this->port}");
    }

    /**
     * 主进程停止事件
     * 请勿在onShutdown中调用任何异步或协程相关API，触发onShutdown时底层已销毁了所有事件循环设施
     * @param \Swoole\Http\Server $server
     */
    public function onShutdown(\Swoole\Http\Server $server)
    {
        try {

            // 执行回调
            $this->setting['hook_shutdown'] and call_user_func($this->setting['hook_shutdown'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 管理进程启动事件
     * 可以使用基于信号实现的同步模式定时器swoole_timer_tick，不能使用task、async、coroutine等功能
     * @param \Swoole\Http\Server $server
     */
    public function onManagerStart(\Swoole\Http\Server $server)
    {
        try {

            // 进程命名
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": manager");
            // 执行回调
            $this->setting['hook_manager_start'] and call_user_func($this->setting['hook_manager_start'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程错误事件
     * 当Worker/Task进程发生异常后会在Manager进程内回调此函数。
     * @param \Swoole\Http\Server $server
     */
    public function onWorkerError(\Swoole\Http\Server $server, int $workerId, int $workerPid, int $exitCode, int $signal)
    {
        try {

            // 执行回调
            $this->setting['hook_worker_error'] and call_user_func($this->setting['hook_worker_error'], $server, $workerId, $workerPid, $exitCode, $signal);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 管理进程停止事件
     * @param \Swoole\Http\Server $server
     */
    public function onManagerStop(\Swoole\Http\Server $server)
    {
        try {

            // 执行回调
            $this->setting['hook_manager_stop'] and call_user_func($this->setting['hook_manager_stop'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId)
    {
        try {

            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": task #{$workerId}");
            }
            // 执行回调
            $this->setting['hook_worker_start'] and call_user_func($this->setting['hook_worker_start'], $server);
            // 实例化App
            new \Mix\Http\Application(require $this->configFile);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程停止事件
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     */
    public function onWorkerStop(\Swoole\Http\Server $server, int $workerId)
    {
        try {

            // 执行回调
            $this->setting['hook_worker_stop'] and call_user_func($this->setting['hook_worker_stop'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程退出事件
     * 仅在开启reload_async特性后有效。异步重启特性，会先创建新的Worker进程处理新请求，旧的Worker进程自行退出
     * @param \Swoole\Http\Server $server
     */
    public function onWorkerExit(\Swoole\Http\Server $server, int $workerId)
    {
        try {

            // 执行回调
            $this->setting['hook_worker_exit'] and call_user_func($this->setting['hook_worker_exit'], $server, $workerId);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 请求事件
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($request, $response) {
                call_user_func([$this, 'onRequest'], $request, $response);
            });
            return;
        }
        try {

            // 执行请求
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->response->beforeInitialize($response);
            \Mix::$app->run();
            // 执行回调
            $this->setting['hook_request_success'] and call_user_func($this->setting['hook_request_success'], $this->server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_request_error'] and call_user_func($this->setting['hook_request_error'], $this->server, $request);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion    = PHP_VERSION;
        echo <<<EOL
                             _____
_______ ___ _____ ___   _____  / /_  ____
__/ __ `__ \/ /\ \/ /__ / __ \/ __ \/ __ \
_/ / / / / / / /\ \/ _ / /_/ / / / / /_/ /
/_/ /_/ /_/_/ /_/\_\  / .___/_/ /_/ .___/
                     /_/         /_/


EOL;
        println('Server         Name:      ' . static::SERVER_NAME);
        println('System         Name:      ' . strtolower(PHP_OS));
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println('Framework      Version:   ' . \Mix::$version);
        $this->setting['max_request'] == 1 and println('Hot            Update:    enabled');
        $this->setting['enable_coroutine'] and println('Coroutine      Mode:      enabled');
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->setting['reactor_num']);
        println('Worker         Num:       ' . $this->setting['worker_num']);
        println("Configuration  File:      {$this->configFile}");
    }

}
