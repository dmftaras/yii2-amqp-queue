<?php

namespace dmftaras\amqp_queue;

use yii\base\BaseObject;

class BaseJob extends BaseObject
{
    private $_amqp_msg;

    /**
     * Set AMQP Message Instance
     * @param $msg
     */
    public function setAmqpMsg($msg)
    {
        $this->_amqp_msg = $msg;
    }

    /**
     * Execute Job
     * @param Queue $queue
     */
    public function execute(Queue $queue)
    {

    }

    /**
     * Acknowledge job
     */
    public function ack()
    {
        $this->_amqp_msg->delivery_info['channel']->basic_ack($this->_amqp_msg->delivery_info['delivery_tag']);
    }
}