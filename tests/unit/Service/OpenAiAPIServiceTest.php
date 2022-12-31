<?php

namespace OCA\OpenAi\Tests;


use OCA\OpenAi\AppInfo\Application;

class OpenAiAPIServiceTest extends \PHPUnit\Framework\TestCase {

	public function testDummy() {
		$app = new Application();
		$this->assertEquals('integration_openai', $app::APP_ID);
	}
}
