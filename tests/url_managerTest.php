<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\url_manager;
 
final class url_managerTest extends TestCase {

	public function testUrlManager1() {
		$_SERVER['DOCUMENT_ROOT'] = '/www/';
		$test = ['return' => true];

		// No match
		$_SERVER['REQUEST_URI'] = '/php/test.php?arg=45&cci=first';
		$handler = new url_manager();
		$handler->add_url('create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('php/test.php', $handler->uri);
		$this->assertFalse($result);

		// Has simple match
		$_SERVER['REQUEST_URI'] = '/create';
		$handler = new url_manager();
		$handler->add_url('create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
		$this->assertEquals('create', $handler->get_param(1));

		$_SERVER['REQUEST_URI'] = '/pdf/create';
		$handler = new url_manager();
		$handler->add_url('pdf/create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);

		$_SERVER['REQUEST_URI'] = '/pdf/create';
		$handler = new url_manager();
		$handler->add_url('create', 'create.php');  //pattern by must match entire URI
		$result = $handler->run($test);
		$this->assertFalse($result);
		$this->assertEquals('pdf', $handler->get_param(1));
		$this->assertEquals('create', $handler->get_param(2));

		$_SERVER['REQUEST_URI'] = '/pdf/create/';  //with trailing slash
		$handler = new url_manager();
		$handler->add_url('pdf/create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);

		$_SERVER['REQUEST_URI'] = '/old_nonexisting.php';
		$handler = new url_manager();
		$handler->add_url('old_nonexisting\.php', 'new.php');
		$result = $handler->run($test);
		$this->assertSame('/www/new.php', $result);

		// Has simple match with query string
		$_SERVER['REQUEST_URI'] = '/pdf/create?arg=abc';
		$handler = new url_manager();
		$handler->add_url('pdf/create', 'somefolder/create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/somefolder/create.php', $result);

		// Complex rules
		// TODO: make advanced stuff like  does
		$_SERVER['REQUEST_URI'] = '/create/42';  //param is a number
		$handler = new url_manager();
		$handler->add_url('create/<id:\\d+>', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
		$this->assertEquals(42, $handler->get_param('id'));

		$_SERVER['REQUEST_URI'] = '/create/car';  //param is alphanumeric
		$handler = new url_manager();
		$handler->add_url('create/<vehicle:[^/]+>', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
		$this->assertSame('car', $handler->get_param('vehicle'));
		$this->assertSame(null, $handler->get_param('brand'));

		$_SERVER['REQUEST_URI'] = '/create/car/tesla';  //without hyphen in param
		$handler = new url_manager();
		$handler->add_url('create/<vehicle:[^/]+>/<brand:[\w\-]+>', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
		$this->assertSame('car', $handler->get_param('vehicle'));
		$this->assertSame('tesla', $handler->get_param('brand'));

		$_SERVER['REQUEST_URI'] = '/create/car/land-rover';  //with hyphen in param
		$handler = new url_manager();
		$handler->add_url('create/<vehicle:[^/]+>/<brand:[\w\-]+>', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
		$this->assertSame('car', $handler->get_param('vehicle'));
		$this->assertSame('land-rover', $handler->get_param('brand'));
	}

	public function testUrlManager2() {
		$_SERVER['DOCUMENT_ROOT'] = '/www';  //without trailing slash
		$test = ['return' => true];

		$_SERVER['REQUEST_URI'] = '/create';
		$handler = new url_manager();
		$handler->add_url('create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
	}

	public function testSubdirectory() {
		$_SERVER['DOCUMENT_ROOT'] = '/www/';  //without trailing slash
		$test = ['return' => true];

		$_SERVER['REQUEST_URI'] = '/mysubdir/create';
		$handler = new url_manager(['subdirectory' => '/mysubdir']);
		$handler->add_url('create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);

		$_SERVER['REQUEST_URI'] = '/mysubdir/create';
		$handler = new url_manager(['subdirectory' => '/mysubdir/']);  //with trailing slash
		$handler->add_url('create', 'create.php');
		$result = $handler->run($test);
		$this->assertSame('/www/create.php', $result);
	}

}
