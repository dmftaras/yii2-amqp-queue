<?php
namespace dmftaras\amqp_queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use yii\base\Component;
use \Exception;

class Connection extends Component
{
    public $pool;
    public $options;

    /**
     * @var AMQPStreamConnection|null $_connection connection
     */
    private $_connection = false;

    /**
     * Establishes a connection.
     * It does nothing if a connection has already been established.
     * @throws Exception if connection fails
     */
    public function open()
    {
        if ($this->_connection !== false) {
            return;
        }

        $this->_connection = AMQPStreamConnection::create_connection($this->pool, $this->options);
    }

    public function close()
    {
        if ($this->_connection !== false) {
            $this->_connection->close();
            $this->_connection = false;
        }
    }

    public function client()
    {
        $this->open();

        return $this->_connection;
    }

    public function reconnect()
    {
        if ($this->_connection !== false) {
            $this->_connection->reconnect();
        }

        return $this->_connection;
    }
}