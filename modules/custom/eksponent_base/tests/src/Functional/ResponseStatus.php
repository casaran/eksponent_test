<?php

namespace Drupal\Tests\eksponent_base\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests response status of an API call.
 */
class ResponseStatus extends BrowserTestBase {

  /**
   * Tests that response to correct path returns 200.
   */
  public function testResponseStatus() {
    $actual_json = $this->drupalGet('https://eksponent.com/sites/default/files/sample-api/events.json', ['query' => ['_format' => 'json']]);
    $this->assertSession()->statusCodeEquals(200);
  }

}
