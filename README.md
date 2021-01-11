Yii 2 - AMQP Queue
==================

Brought to you by [dmftaras](http://dmftaras.com). 

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist dmftaras/yii2-amqp-queue "dev-master"
```

or add the following line to the require section of your `composer.json` file:

```
"dmftaras/yii2-amqp-queue": "dev-master"
```

## Requirements

Yii 2 and above.
PHP AMQPlib


## Usage

Once the extension is installed, set your configuration in common config file:

```php
    'components' => [
        'test_queue' => [
            'class' => \common\library\AMQP\Queue::class,
            'queue_name' => 'test',
            'exchange_name' => 'test',
            'routing_key' => 'test'
        ],
    ],
```

Add component to bootstrap section:

```php
    'bootstrap' => [
        'test_queue'
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
