<?php

namespace Civi\Test\Api4\Entity;

use Civi\Api4\AbstractEntity;
use Civi\API\V4\Entity\Entity;
use Civi\Test\API\V4\Service\TestCreationParameterProvider;
use Civi\Test\API\V4\Traits\TableDropperTrait;
use Civi\Test\Api4\UnitTestCase;

/**
 * @group headless
 */
class ConformanceTest extends UnitTestCase {

  use TableDropperTrait;

  /**
   * @var TestCreationParameterProvider
   */
  protected $creationParamProvider;

  /**
   * Set up baseline for testing
   */
  public function setUp() {
    $tablesToTruncate = array(
      'civicrm_contact',
      'civicrm_custom_group',
      'civicrm_custom_field',
      'civicrm_option_group',
    );
    $this->dropByPrefix('civicrm_value_myfavorite');
    $this->cleanup(array('tablesToTruncate' => $tablesToTruncate));
    $this->loadDataSet('ConformanceTest');
    $this->creationParamProvider = new TestCreationParameterProvider(
      \Civi::container()->get('spec_gatherer')
    );
    parent::setUp();
  }

  public function testConformance() {

    $entities = Entity::get()->setCheckPermissions(FALSE)->execute();

    $this->assertNotEmpty($entities->getArrayCopy());

    foreach ($entities as $entity) {
      /** @var BaseEntity $entityClass */
      $entityClass = 'Civi\API\V4\Entity\\' . $entity;

      if ($entity === 'Entity') {
        continue;
      }

      $this->checkActions($entityClass);
      $this->checkFields($entityClass, $entity);
      $id = $this->checkCreation($entity, $entityClass);
      $this->checkGet($entityClass, $id, $entity);
      $this->checkDeletion($entityClass, $id);
      $this->checkPostDelete($entityClass, $id, $entity);
    }
  }

  /**
   * @param BaseEntity $entityClass
   * @param $entity
   */
  protected function checkFields($entityClass, $entity) {
    $fields = $entityClass::getFields()
      ->setCheckPermissions(FALSE)
      ->execute()
      ->indexBy('name');

    $errMsg = sprintf('%s is missing required ID field', $entity);
    $subset = array('data_type' => 'Integer');

    $this->assertArraySubset($subset, $fields['id'], $errMsg);
  }

  /**
   * @param BaseEntity $entityClass
   */
  protected function checkActions($entityClass) {
    $actions = $entityClass::getActions()
      ->setCheckPermissions(FALSE)
      ->execute()
      ->indexBy('name');

    $this->assertNotEmpty($actions->getArrayCopy());
  }

  /**
   * @param string $entity
   * @param BaseEntity $entityClass
   *
   * @return mixed
   */
  protected function checkCreation($entity, $entityClass) {
    $requiredParams = $this->creationParamProvider->getRequired($entity);
    $createResult = $entityClass::create()
      ->setValues($requiredParams)
      ->setCheckPermissions(FALSE)
      ->execute();

    $this->assertArrayHasKey('id', $createResult, "create missing ID");
    $id = $createResult['id'];

    $this->assertGreaterThanOrEqual(1, $id, "$entity ID not positive");

    return $id;
  }

  /**
   * @param BaseEntity $entityClass
   * @param int $id
   * @param string $entity
   */
  protected function checkGet($entityClass, $id, $entity) {
    $getResult = $entityClass::get()
      ->setCheckPermissions(FALSE)
      ->addClause(array('id', '=', $id))
      ->execute();

    $errMsg = sprintf('Failed to fetch a %s after creation', $entity);
    $this->assertEquals(1, count($getResult), $errMsg);
  }

  /**
   * @param BaseEntity $entityClass
   * @param int $id
   */
  protected function checkDeletion($entityClass, $id) {
    $deleteResult = $entityClass::delete()
      ->setCheckPermissions(FALSE)
      ->addClause(array('id', '=', $id))
      ->execute();

    // should get back an array of deleted id
    $this->assertEquals(array($id), (array)$deleteResult);
  }

  /**
   * @param BaseEntity $entityClass
   * @param int $id
   * @param string $entity
   */
  protected function checkPostDelete($entityClass, $id, $entity) {
    $getDeletedResult = $entityClass::get()
      ->setCheckPermissions(FALSE)
      ->addClause(array('id', '=', $id))
      ->execute();

    $errMsg = sprintf('Entity "%s" was not deleted', $entity);
    $this->assertEquals(0, count($getDeletedResult), $errMsg);
  }

}
