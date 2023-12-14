<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\url_manager;
 
final class url_managerTest extends TestCase {

	public function testUrlManager() {
		$_SERVER['DOCUMENT_ROOT'] = '/www-root/';

		// No match
		$_SERVER['REQUEST_URI'] = '/php/test.php?arg=45&cci=first';
		$handler = new url_manager();
		$handler->add_url('^create$', 'create.php');
		$result = $handler->run(['return' => true]);
		$this->assertSame('php/test.php', $handler->uri);
		$this->assertFalse($result);

		// Has simple match
		$_SERVER['REQUEST_URI'] = '/pdf/create';
		$handler = new url_manager();
		$handler->add_url('^pdf/create$', 'create.php');
		$result = $handler->run(['return' => true]);
		$this->assertSame('/www-root/create.php', $result);

		$_SERVER['REQUEST_URI'] = '/create';
		$handler = new url_manager();
		$handler->add_url('^create$', 'create.php');
		$result = $handler->run(['return' => true]);
		$this->assertSame('/www-root/create.php', $result);

		// Has simple match with query string
		$_SERVER['REQUEST_URI'] = '/pdf/create?arg=abc';
		$handler = new url_manager();
		$handler->add_url('^pdf/create$', 'somefolder/create.php');
		$result = $handler->run(['return' => true]);
		$this->assertSame('/www-root/somefolder/create.php', $result);
	}

}
