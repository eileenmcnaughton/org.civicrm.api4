<?php
namespace Civi\Api4\Action\Setting;

/**
 * Get the value of one or more CiviCRM settings.
 *
 * @method array getSelect
 * @method $this addSelect(string $name)
 * @method $this setSelect(array $select)
 */
class Get extends AbstractSettingAction {

  /**
   * Names of settings to retrieve
   *
   * @var array
   */
  protected $select = [];

  public function _run(\Civi\Api4\Generic\Result $result) {
    $meta = $this->validateSettings($this->select);
    $settingsBag = \Civi::settings($this->domainId);
    if ($this->select) {
      foreach ($this->select as $name) {
        $result[$name] = $settingsBag->get($name);
      }
    }
    else {
      $result->exchangeArray($settingsBag->all());
    }
    foreach ($result as $name => &$value) {
      if (isset($value) && !empty($meta[$name]['serialize'])) {
        $value = \CRM_Core_DAO::unSerializeField($value, $meta[$name]['serialize']);
      }
    }
  }

}