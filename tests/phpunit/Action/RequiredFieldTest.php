<?php

namespace Civi\Test\Api4\Action;

use Civi\Api4\Event;
use Civi\Test\Api4\UnitTestCase;

/**
 * @group headless
 */
class RequiredFieldTest extends UnitTestCase {

  public function testRequired() {
    $msg = '';
    try {
      Event::create()->execute();
    }
    catch (\API_Exception $e) {
      $msg = $e->getMessage();
    }
    $this->assertEquals('Mandatory values missing from Api4 Event::create: title, event_type_id, start_date', $msg);
  }

}
