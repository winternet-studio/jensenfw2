<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\walk_website;

final class walk_websiteTest extends TestCase {
	public function testFetchingPages() {
		$walk = new walk_website();
		$response = $walk->fetch_page('https://allanville.com/?echotest=1', [
			'post_variables' => ['field1' => 'A', 'field2' => 'B'],
			'extraheaders' => ['X-My-Header' => 'JensenFW2'],
		]);
		$responseDecoded = json_decode($response);
		$this->assertNotEmpty($responseDecoded);
		$this->assertEquals($responseDecoded->post->field1, 'A');
		$this->assertEquals($responseDecoded->post->field2, 'B');
		$this->assertEquals($responseDecoded->headers->{'X-My-Header'}, 'JensenFW2');
	}

	public function testForms() {
		$walk = new walk_website();
		$html_forms = file_get_contents(__DIR__ .'/fixtures/walk_website/forms.htm');


		$firstForm = [
			'action' => '/action_page1.php',
			'method' => 'post',
			'formfields' => [
				'email1' => 'john@doe.com',
				'password1' => '1234',
				'radiobuttons_a' => 'radiovalue1',
				'country1' => 'dk',
				'categories1' => ['option2', 'option3'],
				'comments1' => 'Praise God for the power He gives us if we just honestly search for Him.',
				'checkbox1' => '2',
			],
		];

		$result = $walk->get_form_details($html_forms);
		$this->assertArraySubsetCompatible($result, $firstForm);


		$secondForm = [
			'action' => '/action_page2.php',
			'method' => 'post',
			'formfields' => [
				'email2' => 'mary@doe.com',
				'password2' => '5678',
				'radiobuttons_a' => 'radiovalue5',
				'country2' => 'us',
				'categories2' => ['option2', 'option4'],
				'comments2' => 'Praise God for the power He gives us if we just honestly search for Him!',
				'checkbox2' => '3',
			],
		];

		$result = $walk->get_form_details($html_forms, 2);
		$this->assertArraySubsetCompatible($result, $secondForm);

		$result = $walk->get_form_details($html_forms, ['name' => 'form2']);
		$this->assertArraySubsetCompatible($result, $secondForm);

		$result = $walk->get_form_details($html_forms, ['id' => 'formid2']);
		$this->assertArraySubsetCompatible($result, $secondForm);


		$allFormFields = array_merge($firstForm['formfields'], $secondForm['formfields']);

		$result = $walk->get_form_details($html_forms, '*');
		$this->assertArraySubsetCompatible($result, [
			'formfields' => $allFormFields,
		]);
	}

	public function assertArraySubsetCompatible($subset, $array) {
		foreach ($subset as $key => $value) {
			$this->assertArrayHasKey($key, $array);
			if (is_array($value)) {
				$this->assertIsArray($array[$key]);
				$this->assertArraySubsetCompatible($value, $array[$key]);
			} else {
				$this->assertSame($value, $array[$key]);
			}
		}
	}
}
