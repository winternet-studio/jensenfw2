JensenFramework 2
=================

Many PHP classes and methods for doing common tasks.

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist winternet-studio/jensenfw2 "*"
```

or add

```
"winternet-studio/jensenfw2": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once installed, simply use it in your code by  :


```php
<?php
require_once('vendor/autoload.php');

// Check if variable is an integer in any shape or form
$result = \winternet\jensenfw2\core::is_integer(45);  //returns true
$result = \winternet\jensenfw2\core::is_integer('45');  //returns true
$result = \winternet\jensenfw2\core::is_integer(45.3);  //returns false
$result = \winternet\jensenfw2\core::is_integer('45.3');  //returns false

// Convert an amount using today's exchange rate
echo \winternet\jensenfw2\currency::convert(100, 'USD', 'EUR');
```


[TODO] How to set global class defaults
---------------------------------------

Global class defaults can be set through a global configuration file. I need to complete and verify this part of the documentation...

Create the configuration file eg. at /config/jensenfw2.php

```php
<?php
class jensenfw2 {
	public static function returnConfig($class_name) {
		$map = [  //[name of class to get config for ($class_name)] => [method in this class]
			'core' => 'core',
			'mail' => 'mail',
		];

		if (!$map[$class_name]) {
			return [];
		} else {
			$self = self::class;
			return call_user_func(array($self, $map[$class_name]));
		}
	}

	public static function core() {
		$cfg = [];

		// System
		$cfg['system_name'] = 'My system name';
		$cfg['administrator_name'] = 'John Doe';
		$cfg['administrator_email'] = 'john.doe@sample.com';
		$cfg['developer_name'] = 'Jane Smith';
		$cfg['developer_email'] = 'jane.smith@sample.com';

		// Core paths and files
		$cfg['path_filesystem'] = '/var/www/html/mysite.com/web';
		$cfg['path_webserver'] = '';

		// Databases
		$cfg['databases'] = [];

		//   1st and primary server
		$cfg['databases'][0] = [   //key 0 is the server ID (primary server must always be 0) (IDs must always be numeric)
			'db_host' => 'thehost.com',
			'db_port' => 3306,
			'db_user' => 'username',
			'db_pw'   => 'password',
			'db_name' => 'mydatabasename',
		];
		$cfg['databases'][1] = [
			'db_host' => 'thehost.com',
			'db_port' => 3306,
			'db_user' => 'username',
			'db_pw'   => 'password',
			'db_name' => 'myloggingdatabase',
		];

		// See class_defaults() in core.php for all options...

		return $cfg;
	}

	public static function mail() {
		$cfg = [];
		$cfg['swift_host'] = 'some.smtp.com';
		$cfg['swift_user'] = 'username';
		$cfg['swift_pass'] = 'password';

		// See class_defaults() in mail.php for all options...

		return $cfg;
	}
}

```

In your bootstrap file includes this piece of code:

```php
<?php
// \winternet\jensenfw2\core::$is_dev = true;  //enable this to turn on some debugging features

// NOTE: this using class autoloader from Yii2 - so if you don't have a class autoloader some changes are needed...
\winternet\jensenfw2\core::$userconfig = ['\app\config\jensenfw2', 'returnConfig'];
```

Tests
-----

Before running tests with the command `phpunit`, you should start the internal web server used for the tests in `networkTest.php`.
To do so run the command `php -S localhost:8018 -t tests/webserver_docroot`.

Other notes
-----------

Yes, I know, sorry for not using camel-case naming convention. The beginning of this library started way before that became the most popular and now I don't have the time that it takes to migrate everything, so the library will keep using underscores.
