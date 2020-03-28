<?php
use PHPUnit\Framework\TestCase;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use winternet\jensenfw2\walk_website;

final class walk_websiteTest extends TestCase {
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
		Assert::assertArraySubset($result, $firstForm, true);


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
		Assert::assertArraySubset($result, $secondForm, true);

		$result = $walk->get_form_details($html_forms, ['name' => 'form2']);
		Assert::assertArraySubset($result, $secondForm, true);

		$result = $walk->get_form_details($html_forms, ['id' => 'formid2']);
		Assert::assertArraySubset($result, $secondForm, true);


		$allFormFields = array_merge($firstForm['formfields'], $secondForm['formfields']);

		$result = $walk->get_form_details($html_forms, '*');
		Assert::assertArraySubset($result, [
			'formfields' => $allFormFields,
		], true);
	}
}
