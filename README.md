Yii 2 - AMQP Queue
==================

Brought to you by [dmftaras](http://dmftaras.com). 

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist dmftaras/amqp-queue "*"
```

or add the following line to the require section of your `composer.json` file:

```
"dmftaras/amqp-queue": "*"
```

## Requirements

Yii 2 and above.
PHP AMQPlib


## Usage

Once the extension is installed, set your configuration in common config file:

```php
    'components' => [
        'master_queue' => [
            'class' => \dmftaras\amqp_queue\Queue::class,
            'exchange_name' => 'master.tasks',
            'queue_name' => 'master.tasks',
            'routing_key' => 'master.tasks'
        ],
    ],
```

Add component to bootstrap section:

```php
    'bootstrap' => [
        'master_queue'
    ],
```

Add job to the queue

```php
\Yii::$app->test_queue->push(new TestJob([
    'property' => 'value'
]));
```

To consume queue
```
php yii test-queue/listen
```

## License

Code released under [MIT License](LICENSE).
