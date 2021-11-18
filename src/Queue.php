<?php
namespace dmftaras\amqp_queue;

use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\di\Instance;
use yii\helpers\Inflector;

class Queue extends Component implements BootstrapInterface
{
    /**
     * @var Connection|array|string
     */
    public $amqp = 'amqp';

    /**
     * @var string
     */
    public $queue_name = 'queue';
    public $exchange_name = 'exchange';
    public $routing_key = 'routing_key';
    public $max_attempts = 10;
    public $max_delay = 1000;
    public $delay_factor = 3;
    public $enabled = true;

    /**
     * @var Handler
     */
    private $_handler;


    /**
     * Open connection
     */
    public function open()
    {
        if (!$this->enabled) return false;

        $this->amqp = Instance::ensure($this->amqp, Connection::class);

        if (!$this->_handler) {
            $this->_handler = new Handler([
                'exchange_name' => $this->exchange_name,
                'queue_name' => $this->queue_name,
                'routing_key' => $this->routing_key,
                'helper_options' => [
                    'max_attempts' => $this->max_attempts,
                    'max_delay' => $this->max_delay,
                    'factor' => $this->delay_factor
                ]
            ]);
        }
    }

    /**
     * @return string command id
     * @throws
     */
    protected function getCommandId()
    {
        foreach (\Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return Inflector::camel2id($id);
            }
        }
        throw new InvalidConfigException('Queue must be an application component.');
    }

    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        if ($app instanceof ConsoleApp) {
            $app->controllerMap[$this->getCommandId()] = [
                'class' => Command::class,
                'queue' => $this,
            ];
        }
    }

    /**
     * Add job into the queue
     * @param BaseJob $job
     */
    public function push(BaseJob $job, ?int $delay = null)
    {
        return $this->push_raw(json_encode([
            'class' => get_class($job),
            'props'  => get_object_vars($job)
        ]), $delay);
    }

    /**
     * Add raw message into the queue
     * @param string $message
     */
    public function push_raw(string $message, ?int $delay = null)
    {
        if (!$this->enabled) return false;
        $this->open();

        return $this->_handler->publish($message, $delay);
    }

    /**
     * Prepare batch job
     * @param BaseJob $job
     */
    public function batch_push(BaseJob $job)
    {
        return $this->batch_push_raw(json_encode([
            'class' => get_class($job),
            'props'  => get_object_vars($job)
        ]));
    }

    /**
     * Prepare batch message
     * @param BaseJob $job
     */
    public function batch_push_raw(string $message)
    {
        if (!$this->enabled) return false;
        $this->open();

        return $this->_handler->batch_publish($message);
    }

    /**
     * Submit prepared batch messages
     */
    public function batch_submit()
    {
        if (!$this->enabled) return false;
        $this->open();

        return $this->_handler->batch_submit();
    }

    /**
     * Send heartbeat on each tick to keep RabbitMQ connection alive
     */
    public function checkHeartbeat()
    {
        $this->_handler->connection->checkHeartBeat();
    }

    /**
     * Listen Queue
     * @throws \ErrorException
     */
    public function listen()
    {
        if (!$this->enabled) return false;
        $this->open();

        register_tick_function([&$this, "checkHeartbeat"]);

        declare(ticks=2) {
            $this->_handler->consume(function($msg) {
                $message = json_decode($msg->body, true);

                if (isset($message['class'], $message['props'])) {
                    /* @var BaseJob $job */
                    $job = new $message['class']($message['props']);
                    $job->setAmqpMsg($msg);
                    $job->execute($this);
                } else {
                    throw new InvalidArgumentException('class or props missing');
                }
            });
        }

        // Loop as long as the channel has callbacks registered
        try {
            $this->_handler->listen();
        } catch (AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            echo date("Y-m-d H:i:s") . " AMQP: missed heartbeat, restarting worker \n";

            exit(1);
        }
    }

    /**
     * Move job into the error queue
     */
    public function fatal(BaseJob $job)
    {
        return $this->_handler->fatal((string)$job->getAmqpMsg()->body);
    }

    /**
     * Requeue Job for a new attempt
     * @param BaseJob $job
     */
    public function requeue(BaseJob $job)
    {
        $this->_handler->helper->error($job->getAmqpMsg());
    }
}