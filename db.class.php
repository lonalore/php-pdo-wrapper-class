<?php
/**
 * @file
 * A minimal extension for PHP's PDO class designed to make running SQL
 * statements easier.
 *
 * Project Overview
 *
 * This project provides a minimal extension for PHP's PDO (PHP Data Objects)
 * class designed for ease-of-use and saving development time/effort. This is
 * achieved by providing methods - SELECT, INSERT, UPDATE, DELETE - for quickly
 * building common SQL statements, handling exceptions when SQL errors are
 * produced, and automatically returning results/number of affected rows for
 * the appropriate SQL statement types.
 *
 * System Requirements:
 * - PHP 5
 * - PDO Extension
 * - Appropriate PDO Driver(s) - PDO_SQLITE, PDO_MYSQL, PDO_PGSQL
 * - Only MySQL, SQLite, and PostgreSQL database types are currently supported.
 */


/**
 * Class db.
 */
class db extends PDO {
  private $error;
  private $sql;
  private $bind;
  private $errorCallbackFunction;
  private $tablePrefix;

  /**
   * Class constructor.
   *
   * @param string $dsn
   *  More information can be found on how to set the dsn parameter by following
   *  the links provided below.
   *
   *  - MySQL - http://us3.php.net/manual/en/ref.pdo-mysql.connection.php
   *  - SQLite - http://us3.php.net/manual/en/ref.pdo-sqlite.connection.php
   *  - PostreSQL - http://us3.php.net/manual/en/ref.pdo-pgsql.connection.php
   *
   * @param string $user
   *  Username for database connection.
   *
   * @param string $passwd
   *  Password for database connection.
   *
   * @param string $prefix
   *  Prefix for database tables.
   */
  public function __construct($dsn = '', $user = '', $passwd = '', $prefix = '') {
    $options = array(
      PDO::ATTR_PERSISTENT => TRUE,
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    );

    try {
      parent::__construct($dsn, $user, $passwd, $options);
    }
    catch (PDOException $e) {
      $this->error = $e->getMessage();
    }

    $this->tablePrefix = $prefix;
  }

  /**
   * SELECT statement.
   *
   * @param string $table
   *  Table name.
   *
   * @param string $where
   *  WHERE conditions.
   *
   * @param mixed $bind
   *  Bind parameters as string or array.
   *
   * @param string $fields
   *  Comma separated field names.
   *
   * @return array|bool|int
   *  If no SQL errors are produced, this method will return the number of rows
   *  affected by the SELECT statement.
   */
  public function select($table = '', $where = '', $bind = '', $fields = '*') {
    $table = $this->tablePrefix . $table;

    $sql = "SELECT " . $fields . " FROM `" . $table . "`";
    if (!empty($where)) {
      $sql .= " WHERE " . $where;
    }
    $sql .= ";";

    return $this->run($sql, $bind);
  }

  /**
   * INSERT statement.
   *
   * @param string $table
   *  Table name.
   *
   * @param array $info
   *  Associative array with field names and values.
   *
   * @return array|bool|int
   *  If no SQL errors are produced, this method will return with the the last
   *  inserted ID. Otherwise 0.
   */
  public function insert($table = '', $info = array()) {
    $table = $this->tablePrefix . $table;
    $fields = $this->filter($table, $info);
    $sql = "INSERT INTO " . $table . " (`" . implode($fields, "`, `") . "`) ";
    $sql .= "VALUES (:" . implode($fields, ", :") . ");";

    $bind = array();
    foreach ($fields as $field) {
      $bind[":$field"] = $info[$field];
    }

    return $this->run($sql, $bind);
  }

  /**
   * UPDATE statement.
   *
   * @param string $table
   *  Table name.
   *
   * @param array $info
   *  Associated array with fields and their values.
   *
   * @param string $where
   *  WHERE conditions.
   *
   * @param mixed $bind
   *  Bind parameters as string or array.
   *
   * @return array|bool|int
   *  If no SQL errors are produced, this method will return the number of rows
   *  affected by the UPDATE statement.
   */
  public function update($table = '', $info = array(), $where = '', $bind = '') {
    $table = $this->tablePrefix . $table;
    $fields = $this->filter($table, $info);
    $fieldSize = sizeof($fields);

    $sql = "UPDATE " . $table . " SET ";
    for ($f = 0; $f < $fieldSize; ++$f) {
      if ($f > 0) {
        $sql .= ", ";
      }
      $sql .= $fields[$f] . " = :update_" . $fields[$f];
    }
    $sql .= " WHERE " . $where . ";";

    $bind = $this->cleanup($bind);
    foreach ($fields as $field) {
      $bind[":update_$field"] = $info[$field];
    }

    return $this->run($sql, $bind);
  }

  /**
   * DELETE statement.
   *
   * @param string $table
   *  Table name.
   *
   * @param string $where
   *  Where conditions.
   *
   * @param mixed $bind
   *  Bind parameters as string or array.
   *
   * @return array
   *  If no SQL errors are produced, this method will return the number of rows
   *  affected by the DELETE statement.
   */
  public function delete($table = '', $where = '', $bind = '') {
    $table = $this->tablePrefix . $table;
    $sql = "DELETE FROM " . $table . " WHERE " . $where . ";";
    $this->run($sql, $bind);
  }

  /**
   * This method is used to run free-form SQL statements that can't be handled
   * by the included delete, insert, select, or update methods.
   *
   * @param string $sql
   *  SQL query.
   *
   * @param mixed $bind
   *  Bind parameters as string or array.
   *
   * @return array|bool|int
   *  If no SQL errors are produced, this method will return the number of
   *  affected rows for DELETE and UPDATE statements, the last inserted ID for
   *  INSERT statement, or an associate array of results for SELECT, DESCRIBE,
   *  and PRAGMA statements. Otherwise FALSE.
   */
  public function run($sql = '', $bind = '') {
    $this->sql = trim($sql);
    $this->bind = $this->cleanup($bind);
    $this->error = '';

    try {
      $pdostmt = $this->prepare($this->sql);

      if ($pdostmt->execute($this->bind) !== FALSE) {
        if (preg_match("/^(" . implode("|", array(
            "select",
            "describe",
            "pragma"
          )) . ") /i", $this->sql)) {

          return $pdostmt->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif (preg_match("/^(" . implode("|", array(
            "delete",
            "update"
          )) . ") /i", $this->sql)) {

          return $pdostmt->rowCount();
        }
        elseif (preg_match("/^(" . implode("|", array(
            "insert",
          )) . ") /i", $this->sql)) {
          return $this->lastInsertId();
        }
      }
    }
    catch (PDOException $e) {
      $this->error = $e->getMessage();
      $this->debug();
      return FALSE;
    }

    return FALSE;
  }

  /**
   * When a SQL error occurs, this project will send an error message to a
   * callback function specified through the setErrorCallbackFunction method.
   * The callback function's name should be supplied as a string without
   * parenthesis.
   *
   * If no SQL errors are produced, this method will return an associative
   * array of results.
   *
   * @param $errorCallbackFunction
   *  Callback function.
   */
  public function setErrorCallbackFunction($errorCallbackFunction) {
    // Variable functions for won't work with language constructs such as echo
    // and print, so these are replaced with print_r.
    if (in_array(strtolower($errorCallbackFunction), array("echo", "print"))) {
      $errorCallbackFunction = "print_r";
    }

    if (function_exists($errorCallbackFunction)) {
      $this->errorCallbackFunction = $errorCallbackFunction;
    }
  }

  /**
   * Debug.
   */
  private function debug() {
    if (!empty($this->errorCallbackFunction)) {
      $error = array(
        'Error' => $this->error,
      );

      if (!empty($this->sql)) {
        $error['SQL Statement'] = $this->sql;
      }

      if (!empty($this->bind)) {
        $error['Bind Parameters'] = trim(print_r($this->bind, TRUE));
      }

      $backtrace = debug_backtrace();
      if (!empty($backtrace)) {
        foreach ($backtrace as $info) {
          if ($info['file'] != __FILE__) {
            $error['Backtrace'] = $info['file'] . ' at line ' . $info['line'];
          }
        }
      }

      $msg = 'SQL Error' . PHP_EOL . str_repeat('-', 50);
      foreach ($error as $key => $val) {
        $msg .= PHP_EOL . PHP_EOL . $key . ':' . PHP_EOL . $val;
      }

      $func = $this->errorCallbackFunction;
      $func($msg);
    }
  }

  /**
   * Filter.
   *
   * @param string $table
   *  Table name.
   *
   * @param array $info
   *  Associated array with fields and their values.
   *
   * @return array
   */
  private function filter($table = '', $info = array()) {
    $table = $this->tablePrefix . $table;
    $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver == 'sqlite') {
      $sql = "PRAGMA table_info('" . $table . "');";
      $key = "name";
    }
    elseif ($driver == 'mysql') {
      $sql = "DESCRIBE `" . $table . "`;";
      $key = "Field";
    }
    else {
      $sql = "SELECT column_name FROM information_schema.columns ";
      $sql .= "WHERE table_name = '" . $table . "';";
      $key = "column_name";
    }

    if (FALSE !== ($list = $this->run($sql))) {
      $fields = array();
      foreach ($list as $record) {
        $fields[] = $record[$key];
      }

      return array_values(array_intersect($fields, array_keys($info)));
    }

    return array();
  }

  /**
   * Cleanup parameters.
   *
   * @param mixed $bind
   *  Bind parameters as string/array.
   *
   * @return array
   *  Bind parameters as array.
   */
  private function cleanup($bind = '') {
    if (!is_array($bind)) {
      if (!empty($bind)) {
        $bind = array($bind);
      }
      else {
        $bind = array();
      }
    }

    return $bind;
  }
}
