<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\base32;
 
final class base32Test extends TestCase {
	public function testMyCase() {
		$input = 'Allan Jensen';		
		$result = base32::encode($input);
		$expect = 'IFWGYYLOEBFGK3TTMVXA';
		$this->assertSame($expect, $result);
		$this->assertSame($input, base32::decode($result));

		$input = 'Allan Jensen';
		$charmap = 'csExtraSafe';
		$result = base32::encode($input, $charmap);
		$expect = 'A7Q8SSDG6378CVMMEPR2';
		$this->assertSame($expect, $result);
		$this->assertSame($input, base32::decode($result, $charmap));

		$input = 'Allan Jensen';
		$charmap = '*/+=!#ghjklmnpqrstuvwxyz23456789';
		$result = base32::encode($input, $charmap);
		$expect = 'J#YG22MQ!/#GL5VVNXZ*';
		$this->assertSame($expect, $result);
		$this->assertSame($input, base32::decode($result, $charmap));

		$input = 'Bedamp dismission nitriary pleiomazia jawless morris tatterdemalionry vasoconstriction photodynamically eulogious appealable aurophobia supersaint intertrace elderberry strategic nonprevalent esoteric hyperanabolic facultied convulsionism dithionate underworld unforgiving !?239587&%&/35';
		$result = base32::encode($input);
		$expect = 'IJSWIYLNOAQGI2LTNVUXG43JN5XCA3TJORZGSYLSPEQHA3DFNFXW2YL2NFQSA2TBO5WGK43TEBWW64TSNFZSA5DBOR2GK4TEMVWWC3DJN5XHE6JAOZQXG33DN5XHG5DSNFRXI2LPNYQHA2DPORXWI6LOMFWWSY3BNRWHSIDFOVWG6Z3JN52XGIDBOBYGKYLMMFRGYZJAMF2XE33QNBXWE2LBEBZXK4DFOJZWC2LOOQQGS3TUMVZHI4TBMNSSAZLMMRSXEYTFOJZHSIDTORZGC5DFM5UWGIDON5XHA4TFOZQWYZLOOQQGK43PORSXE2LDEBUHS4DFOJQW4YLCN5WGSYZAMZQWG5LMORUWKZBAMNXW45TVNRZWS33ONFZW2IDENF2GQ2LPNZQXIZJAOVXGIZLSO5XXE3DEEB2W4ZTPOJTWS5TJNZTSAIJ7GIZTSNJYG4TCKJRPGM2Q';
		$this->assertSame($expect, $result);
		$this->assertSame($input, base32::decode($result));

		$input = '671289408798225425232';
		$result = base32::encode($input);
		$expect = 'GY3TCMRYHE2DAOBXHE4DEMRVGQZDKMRTGI';
		$this->assertSame($expect, $result);
		$this->assertSame($input, base32::decode($result));

		$input = 67128940879;
		$result = base32::encode($input);
		$expect = 'GY3TCMRYHE2DAOBXHE';
		$this->assertSame($expect, $result);
		$this->assertSame($input, (int) base32::decode($result));
	}
}
