<?php

/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2015                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */
namespace Civi\API;

use Civi\API\Service\Schema\Joinable\CustomGroupJoinable;
use Civi\API\Service\Schema\Joinable\Joinable;
use CRM_Utils_Array as ArrayHelper;
use CRM_Core_DAO_AllCoreTables as TableHelper;

/**
 * A query `node` may be in one of three formats:
 *
 * * leaf: [$fieldName, $operator, $criteria]
 * * negated: ['NOT', $node]
 * * branch: ['OR|NOT', [$node, $node, ...]]
 *
 * Leaf operators are one of:
 *
 * * '=', '<=', '>=', '>', '<', 'LIKE', "<>", "!=",
 * * "NOT LIKE", 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN',
 * * 'IS NOT NULL', or 'IS NULL'.
 */
class Api4SelectQuery extends SelectQuery {

  const JOIN_ONE_TO_MANY = 1;
  const JOIN_ONE_TO_ONE = 2;

  /**
   * @var int
   */
  protected $apiVersion = 4;

  /**
   * @var array
   *   Cache of dot notation fields that were joined in the form
   *   [table_alias, field_name]
   */
  protected $joinedFields = array();

  /**
   * @inheritDoc
   */
  protected function buildWhereClause() {
    foreach ($this->where as $clause) {
      $sql_clause = $this->treeWalkWhereClause($clause);
      $this->query->where($sql_clause);
    }
  }

  /**
   * Why walk when you can
   *
   * @return array|int
   */
  public function run() {
    $this->preRun();
    $baseResults = parent::run();
    return $this->postRun($baseResults);
  }

  // todo move me somewhere
  protected function preRun() {
    $allFields = array_merge(array_column($this->where, 0), $this->select, $this->orderBy);
    $dotFields = array_filter($allFields, function ($field) {
      return strpos($field, '.') !== false;
    });
    foreach ($dotFields as $dotField) {
      $this->joinFK($dotField);
    }
  }



  // todo move me somewhere
  protected function postRun($baseResults) {

    if (empty($baseResults)) {
      return $baseResults;
    }

    $relatedSelects = array();
    $joinedDotSelects = array_filter($this->select, function ($select) {
      $joinData = ArrayHelper::value($select, $this->joinedFields, array());
      return !empty($joinData);
    });

    // group related selects by alias so they can be executed in one query
    foreach ($joinedDotSelects as $select) {
      $parts = explode('.', $select);
      $finalAlias = $parts[count($parts) - 2];
      $relatedSelects[$finalAlias][] = $select;
    }

    foreach ($relatedSelects as $finalAlias => $selects) {

      $firstSelect = $selects[0];
      $pathParts = explode('.', $firstSelect);
      array_pop($pathParts);

      $selectFields = array();
      foreach ($selects as $select) {
        $fieldName = $this->joinedFields[$select][1];
        $selectFields[$select] = sprintf('%s.%s', $finalAlias, $fieldName);
      }

      // todo why do I need this, couldn't I use $finalAlias instead of array index
      $numParts = count($pathParts);
      $baseAlias = $numParts > 1 ? $pathParts[$numParts - 2] : self::MAIN_TABLE_ALIAS;

      $selectFields['id'] = sprintf('%s.id', $finalAlias);
      $selectFields['_parent_id'] = $baseAlias . '.id';
      $selectFields['_base_id'] = self::MAIN_TABLE_ALIAS . '.id';

      $selectFieldsAliased = array_map(function ($field, $alias) {
        return sprintf('%s as "%s"', $field, $alias);
      }, $selectFields, array_keys($selectFields));

      $newSelect = sprintf('SELECT DISTINCT %s', implode(", ", $selectFieldsAliased));

      $sql = str_replace("\n", ' ', $this->query->toSQL());
      $originalSelect = substr($sql, 0, strpos($sql, ' FROM'));
      $sql = str_replace($originalSelect, $newSelect, $sql);

      $relatedResults = array();
      $resultDAO = \CRM_Core_DAO::executeQuery($sql);
      while ($resultDAO->fetch()) {
        $relatedResults[$resultDAO->id] = array();
        foreach ($selectFields as $alias => $column) {
          $returnName = $alias;
          $alias = str_replace('.', '_', $alias);
          if (property_exists($resultDAO, $alias)) {
            $relatedResults[$resultDAO->id][$returnName] = $resultDAO->$alias;
          }
        };
      }

      foreach ($baseResults as &$baseResult) {
        $baseId = $baseResult['id'];
        $relatedResultsForBase = array_filter($relatedResults, function ($res) use ($baseId) {
          return ($res['_base_id'] === $baseId);
        });
        $this->insertAtLevel($baseResult, $pathParts, $relatedResultsForBase);
      }
    }

    return $baseResults;
  }

  /**
   * @param $array
   * @param $parts
   * @param $values
   */
  private function insertAtLevel(&$array, array $parts, array $values) {
    $currentLevel = array_shift($parts);
    if (!array_key_exists($currentLevel, $array)) {
      $array[$currentLevel][] = [];
    }
    if (empty($parts)) {
      $parentId = ArrayHelper::value('id', $array);
      $values = array_filter($values, function ($value) use ($parentId) {
        return $value['_parent_id'] === $parentId;
      });
      array_walk($values, function (&$value) {
        unset($value['_parent_id'], $value['_base_id']);
      });
      $array[$currentLevel] = $values;
    } else {
      foreach ($array[$currentLevel] as $key => &$current) {
        $this->insertAtLevel($current, $parts, $values);
      }
    }
  }

  /**
   * Recursively validate and transform a branch or leaf clause array to SQL.
   *
   * @param array $clause
   * @return string SQL where clause
   *
   * @uses validateClauseAndComposeSql() to generate the SQL etc.
   * @todo if an 'and' is nested within and 'and' (or or-in-or) then should
   * flatten that to be a single list of clauses.
   */
  protected function treeWalkWhereClause($clause) {
    switch ($clause[0]) {
    case 'OR':
    case 'AND':
      // handle branches
      if (count($clause[1]) === 1) {
        // a single set so AND|OR is immaterial
        return $this->treeWalkWhereClause($clause[1][0]);
      }
      else {
        $sql_subclauses = [];
        foreach ($clause[1] as $subclause) {
          $sql_subclauses[] = $this->treeWalkWhereClause($subclause);
        }
        return '(' . implode("\n" . $clause[0], $sql_subclauses) . ')';
      }
    case 'NOT':
      // possibly these brackets are redundant
      return 'NOT ('
        . $this->treeWalkWhereClause($clause[1]) . ')';
      break;
    default:
      return $this->validateClauseAndComposeSql($clause);
    }
  }

  /**
   * Validate and transform a leaf clause array to SQL.
   * @param array $clause [$fieldName, $operator, $criteria]
   * @return string SQL
   */
  protected function validateClauseAndComposeSql($clause) {
    list($key, $operator, $criteria) = $clause;
    $value = array($operator => $criteria);
    // $field = $this->getField($key); // <<-- unused
    // derive table and column:
    $table_name = NULL;
    $column_name = NULL;
    if (in_array($key, $this->entityFieldNames)) {
      $table_name = self::MAIN_TABLE_ALIAS;
      $column_name = $key;
    }
    elseif (strpos($key, '.')) {
      $fkInfo = ArrayHelper::value($key, $this->joinedFields);
      $table_name = $fkInfo[0];
      $column_name = $fkInfo[1];
    }

    if (!$table_name || !$column_name || is_null($value)) {
      throw new \API_Exception("Invalid field '$key' in where clause.");
    }

    $sql_clause = \CRM_Core_DAO::createSQLFilter("`$table_name`.`$column_name`", $value);
    if ($sql_clause === NULL) {
      throw new \API_Exception("Invalid value in where clause for field '$key'");
    }
    return $sql_clause;
  }

  /**
   * @inheritDoc
   */
  protected function getFields() {
    $fields = civicrm_api4($this->entity, 'getFields')->indexBy('name');
    return (array) $fields;
  }

  /**
   * Fetch a field from the getFields list
   *
   * @param string $fieldName
   *
   * @return string|null
   */
  protected function getField($fieldName) {
    if ($fieldName && isset($this->apiFieldSpec[$fieldName])) {
      return $this->apiFieldSpec[$fieldName];
    }
    return NULL;
  }

  /**
   * @param $key
   **/
  protected function joinFK($key) {
    $stack = explode('.', $key);

    if (count($stack) < 2) {
      return;
    }

    $joiner = \Civi::container()->get('joiner');
    $finalDot = strrpos($key, '.');
    $pathString = substr($key, 0, $finalDot);
    $field = substr($key, $finalDot + 1);

    // todo check if can join before joining
    $joinPath = $joiner->join($this, $pathString, 'LEFT');

    $lastLink = end($joinPath);
    // custom groups use aliases for field names
    if ($lastLink instanceof CustomGroupJoinable) {
      $field = \CRM_Core_DAO_CustomField::getFieldValue(\CRM_Core_DAO_CustomField::class, $field, 'column_name', 'name');
    }

    $this->joinedFields[$key] = array($lastLink->getAlias(), $field);
  }

  /**
   * @return FALSE|string
   */
  public function getFrom() {
    return TableHelper::getTableForClass(TableHelper::getFullName($this->entity));
  }

}
