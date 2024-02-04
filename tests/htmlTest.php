<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\html;
 
final class htmlTest extends TestCase {
	public function testMyCase() {
		$result = html::parse_to_flat_array('&aelig;&oslash;&aring;&ouml;&auml;&uuml;&ucirc;&AElig;&Oslash;&Aring;&Ouml;&Auml;&Uuml;&Ucirc;');
		$expect = 'æøåöäüûÆØÅÖÄÜÛ';
		$this->assertSame($expect, $result[0]['text']);
	}

	public function testExtractJsonLd() {
		$result = html::extract_json_ld('<script type="application/ld+json">  
  {"@context": "http://schema.org", "@type": "Airport", "icaoCode": "KWE", "latitude": -2.6434}  
</script>');
		$expect = [
			(object) [
				'@context' => 'http://schema.org',
				'@type' => 'Airport',
				'icaoCode' => 'KWE',
				'latitude' => -2.6434,
			],
		];
		$this->assertEquals($expect, $result);
	}
}
