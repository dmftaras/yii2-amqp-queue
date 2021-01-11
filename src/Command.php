<?php

namespace dmftaras\amqp_queue;
use yii\console\Controller;
use yii\queue\cli\Queue;


class Command extends Controller
{
    /**
     * @var Queue
     */
    public $queue;


    /**
     * Listen Queue
     */
    public function actionListen()
    {
        $this->queue->listen();
    }
}