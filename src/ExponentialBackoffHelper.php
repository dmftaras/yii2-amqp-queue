<?php
namespace dmftaras\amqp_queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class ExponentialBackoffHelper
{
    /**
     * @var AMQPChannel
     */
    private $channel;

    /**
     * @var string
     */
    private $queue;

    /**
     * @var string
     */
    private $exchange;

    /**
     * @var string
     */
    private $routing_key;

    /**
     * @var integer $max_delay The max time (in seconds) to wait before a retry.
     */
    private $max_delay = 60;

    /**
     * @var integer $factor The base number for the exponential back off.
     */
    private $factor = 2;

    /**
     * @var integer $max_attempts The max number of attempts allowed.
     */
    private $max_attempts = 10;

    private $debug = false;

    public function __construct(AMQPChannel $channel, $queue, $exchange, $routing_key, array $options = [])
    {
        $this->channel = $channel;
        $this->queue = $queue;
        $this->exchange = $exchange;
        $this->routing_key = $routing_key;

        if (isset($options['max_delay'])) {
            if ($options['max_delay'] <= 0) {
                throw new \Exception("Option 'max_delay' must be greater than 0.");
            }

            $this->max_delay = (int) $options['max_delay'];
        }

        if (isset($options['factor'])) {
            if ($options['factor'] <= 0) {
                throw new \Exception("Option 'factor' must be greater than 0.");
            }

            $this->factor = (int) $options['factor'];
        }

        if (isset($options['max_attempts'])) {
            if ($options['max_attempts'] < 0) {
                throw new \Exception("Option 'max_attempts' must not be negative.");
            }

            $this->max_attempts = (int) $options['max_attempts'];
        }

        if (isset($options['debug'])) {
            $this->debug = $options['debug'];
        }

        if ($this->debug) echo "exbh[" . time() . "]: starting \n";
    }

    public function acknowledge(AMQPMessage $message)
    {
        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag'], false);

        if ($this->debug) echo "exbh[" . time() . "]: acknowledge \n";
    }

    public function reject(AMQPMessage $message, $requeue = false)
    {
        $this->retry($message);
        if ($this->debug) echo "exbh[" . time() . "]: rejected \n";
    }

    public function error(AMQPMessage $message)
    {
        $this->retry($message);
        if ($this->debug) echo "exbh[" . time() . "]: error \n";
    }

    public function timeout(AMQPMessage $message)
    {
        $this->retry($message);
        if ($this->debug) echo "exbh[" . time() . "]: timeout \n";
    }

    private function retry(AMQPMessage $message)
    {
        $attempts = $this->deaths($message);

        if ($attempts < $this->max_attempts) {
            $delay = $this->delay($attempts);

            $routing_key = $this->queue . '.' . $delay;

            $queue = $this->createRetryQueue($delay);
            if ($this->debug) echo "exbh[" . time() . "]: creating queue {$queue} \n";

            $this->channel->queue_bind($queue, $this->exchange, $routing_key);
            if ($this->debug) echo "exbh[" . time() . "]: binding exchange {$this->exchange} via routing {$routing_key} to queue {$queue} \n";

            $this->channel->basic_publish($message, $this->exchange, $routing_key);
            if ($this->debug) echo "exbh[" . time() . "]: publishing \n";

            $this->acknowledge($message);
        } else {
            $message->delivery_info['channel']->basic_reject($message->delivery_info['delivery_tag'], false);
        }
    }

    private function deaths(AMQPMessage $message)
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : null;

        if (is_null($headers) || !isset($headers['x-death'])) {
            return 0;
        }

        $count = 0;

        foreach ($headers['x-death'] as $death) {
            if (strpos($death['queue'], $this->queue) === 0) {
                $count += $death['count'];
            }
        }

        return $count;
    }

    private function createRetryQueue($delay)
    {
        $queue = $this->queue . '.retry.' . $delay;

        $this->channel->queue_declare($queue, false, true, false, false, false, [
            'x-dead-letter-exchange' => ['S', $this->exchange],
            'x-dead-letter-routing-key' => ['S', $this->queue],
            'x-message-ttl' => ['I', $delay * 1000],
            'x-expires' => ['I', $delay * 1000 * 2]
        ]);

        return $queue;
    }

    public function delay($attempts)
    {
        return min($this->max_delay, ($attempts + 1) ** $this->factor);
    }
}