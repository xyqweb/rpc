# RPC SDK for php

----

[![Latest Stable Version](https://packagist.org/packages/xyqweb/rpc)](https://packagist.org/packages/xyqweb/rpc)


### Run environment
- PHP 7.1+.

### Install RPC PHP SDK

	composer require xyqweb/rpc
	
- If you use the ***composer*** to manage project dependencies, run the following command in your project's root directory:

        composer require xyqweb/rpc

   You can also declare the dependency on Log SDK for PHP in the `composer.json` file.

        "require": {
            "xyqweb/rpc": "~0.1"
        }

   Then run `composer install` to install the dependency. After the Composer Dependency Manager is installed, import the dependency in your PHP code: 

        require_once __DIR__ . '/vendor/autoload.php';
        
## Quick use

### Initialize an LogClient

#### Load in normal mode
     
```php

<?php
$config = [
    'logs'            => [ // Record request and response information，Optional parameters 
            'driver'      => 'logDriver',//Yii and Phalcon support the injected log component. In other cases, please pass the log component object 
            'file'        => 'app.log',//log name
            'levels'      => ['error'],//log level accept info、error、debug
            'infoMinTime' => 1 //The recording response time is longer than 1 second。if info not in levels，the params is not work
        ],
        'domain'         => [// Domain and module work only once 
            'test'      => '127.0.0.1:89/',//test project
        ],
        'module'         => [
            'test',//module name
        ],
    //    'serverType'     => 'module',server type.only accept module or domain 
        'serverPort'     => 80, //server port ，only accept 80 or 443
        'server'         => 'local',//only accept local or test,only work in serverType=domain 。 Examples：When the value is local and server type is domain，real url：test.xxx.com/,When the value is local and server type is domain:real url：127.0.0.1:89/
        'rootDomain'     => '.xxx.com/',//base domain
        'yarPackageType' => 'json',//only work in yar request
        'timeout'        => 5000,//response timeout default 5000ms (Unit millisecond)
        'connect_timeout' => 1000,//connect timeout default 1000ms（Unit millisecond）
        'error' => [// Error message configuration，Optional parameters 
            'display_error' => true,//Whether to throw an exception directly when a request error occurs
            'code_key'      => 'status',//response data status key
            'msg_key'       => 'msg',//response data msg key
            'success_code' => [1],//response data success status value
            'fail_code'    => [0]//response data fail status value
        ],
    //    'proxy'          => [ //only work in http request and outer params is true
    //        'host' => 'proxy server ip',
    //        'port' => 'proxy server port',
    //    ],
];
```

```php
//initialization rpc config in your project
\xyqWeb\rpc\Request::initRpc('yar', //only accept yar or http
$config
);
//star send your request
$rpc = \xyqWeb\rpc\Request::init()
        ->setParams(
            [
                [
                    'url'     => '_xxx/xxx',
                    'method'  => 'xx',
                    'params'  => [
                        'xxx'=>'xxx'
                    ],
                   //'outer' => true,// Internet request identifier，when the value is true，url will be no restructuring 
                   //'headers' => [ //The custom header is set here
                   //     'xxx' => 'xxx',
                   //],
                   //'callback'=>['callback class','function'] //only work in serial requests。 If there are multiple serial requests, the callback must be set（Don't set the last request because it doesn't work），The response data of the current request is processed by callback
                ]
             ]
            //,['xx'=>'xx']//Back end verification token content，Optional parameters default null，only accept string、array、null，
            //,false //Optional parameters default false，only accept bool，true is a parallel request，false is a serial request                                           
            )->get();
```

#### Load in normal mode yii2
```php
'components' => [
    'rpc' => [
        'class' => 'xyqWeb\rpc\YiiRequest',
        'config' => $config
    ]
]
```


```php
<?php
Yii::$app->rpc->setParams(
            [
                [
                    'url'     => '_xxx/xxx',
                    'method'  => 'xx',
                    'params'  => [
                        'xxx'=>'xxx'
                    ],
                   //'headers' => [ //The custom header is set here
                   //     'xxx' => 'xxx',
                   //],
                   //'callback'=>['callback class','function'] //only work in serial requests。 If there are multiple serial requests, the callback must be set（Don't set the last request because it doesn't work），The response data of the current request is processed by callback
                ]
             ]
            //,['xx'=>'xx']//Back end verification token content，Optional parameters default null，only accept string、array、null，
            //,false //Optional parameters default false，only accept bool，true is a parallel request，false is a serial request                                                  
            )->get();
```
