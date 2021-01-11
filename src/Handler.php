<?php
namespace dmftaras\amqp_queue;

use PhpAmqpLib\Message\AMQPMessage;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Class AMQP
 *
 * @property \PhpAmqpLib\Connection\AMQPStreamConnection $connection
 * @property \PhpAmqpLib\Channel\AMQPChannel $channel;
 * @property ExponentialBackoffHelper $helper
 */
class Handler extends Component
{
    private $_exchange_name;
    private $_queue_name;
    private $_routing_key;
    private $_helper_options = [
        'max_attempts' => 6,
        'max_delay'    => 86400, // 24 hrs
        'factor'       => 6,
    ];
    public $connection;
    public $channel;
    public $helper;

    public function init()
    {
        parent::init();

        if (!$this->_exchange_name) throw new InvalidConfigException('exchange_name required');
        if (!$this->_routing_key) throw new InvalidConfigException('routing_key required');

        $this->_init_amqp();
    }

    protected function setExchange_name(string $value)
    {
        $this->_exchange_name = $value;
    }

    protected function setQueue_name(string $value)
    {
        $this->_queue_name = $value;
    }

    protected function setRouting_key(string $value)
    {
        $this->_routing_key = $value;
    }

    protected function setHelper_options(array $value)
    {
        $defaults = $this->_helper_options;

        if (array_key_exists('max_attempts', $value))   $defaults['max_attempts'] = $value['max_attempts'];
        if (array_key_exists('max_delay', $value))      $defaults['max_delay'] = $value['max_delay'];
        if (array_key_exists('factor', $value))         $defaults['factor'] = $value['factor'];

        $this->_helper_options = $defaults;
    }

    /**
     * Init AMQP
     */
    private function _init_amqp()
    {
        try {
            $this->connection = \Yii::$app->amqp->client();
        } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException | \PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
            // reconnect if old connection died
            $this->connection = \Yii::$app->amqp->reconnect();
        }

        $this->channel = $this->connection->channel();
        // exchange definition
        $this->channel->exchange_declare($this->_exchange_name . '.exchange', 'direct', false, true, false);
        // failure exchange definition
        $this->channel->exchange_declare($this->_exchange_name . '.error.exchange', 'direct', false, true, false, false, false);

        $this->channel->basic_qos(null, 1, null);
    }

    /**
     * Define & Bind queue
     * @throws InvalidConfigException
     */
    private function _init_queue()
    {
        if (!$this->_queue_name) throw new InvalidConfigException('queue_name required');

        // queue definition
        $this->channel->queue_declare($this->_queue_name, false, true, false, false, false, [
            'x-dead-letter-exchange' => ['S', $this->_exchange_name . '.error.exchange'],
            'x-dead-letter-routing-key' => ['S', $this->_routing_key]
        ]);
        // bind queue to the exchange & key
        $this->channel->queue_bind($this->_queue_name, $this->_exchange_name . '.exchange', $this->_routing_key);
        // failure queue definition
        $this->channel->queue_declare($this->_queue_name. '.error', false, true, false, false, false);
        // failure queue binding
        $this->channel->queue_bind($this->_queue_name . '.error', $this->_exchange_name . '.error.exchange', $this->_routing_key);
    }

    /**
     * Init Retry Helper
     * @throws \Exception
     */
    private function _init_helper()
    {
        $this->helper = new ExponentialBackoffHelper($this->channel, $this->_queue_name, $this->_exchange_name . '.exchange', $this->_routing_key, $this->_helper_options);
    }

    /**
     * Define a callback for message processing
     * @param callable $callback
     */
    public function consume(callable $callback)
    {
        // init queue and their bindings
        $this->_init_queue();

        // init exponential delay helper
        $this->_init_helper();

        return $this->channel->basic_consume($this->_queue_name, '', false, false, false, false, function ($msg) use ($callback) {
            $callback($msg); // return callback
        });
    }

    /**
     * Send message into "error" exchange for further investigation
     * @param string $msg
     */
    public function fatal(string $msg)
    {
        $new_msg = new AMQPMessage($msg, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        $this->channel->basic_publish($new_msg, $this->_exchange_name . '.error.exchange', $this->_routing_key);
    }

    /**
     * Publish a new message
     * @param string $msg
     */
    public function publish(string $msg)
    {
        $new_msg = new AMQPMessage($msg, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        $this->channel->basic_publish($new_msg, $this->_exchange_name . '.exchange', $this->_routing_key);
    }

    /**
     * Prepare message for batch publish
     * @param string $msg
     */
    public function batch_publish(string $msg)
    {
        $new_msg = new AMQPMessage($msg, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

        $this->channel->batch_basic_publish($new_msg, $this->_exchange_name . '.exchange', $this->_routing_key);
    }

    /**
     * Submit prepared messages
     */
    public function batch_submit()
    {
        $this->channel->publish_batch();
    }

    /**
     * Start events loop
     * @throws \ErrorException
     */
    public function listen()
    {
        // Loop as long as the channel has callbacks registered
        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }
    }

    /**
     * Close connection
     * @throws \Exception
     */
    public function close()
    {
        $this->channel->close();
        $this->connection->close();
    }
}