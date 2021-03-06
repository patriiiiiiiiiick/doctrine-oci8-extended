<?php

/*
 * This file is part of the doctrine-oci8-extended package.
 *
 * (c) Jason Hofer <jason.hofer@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Doctrine\DBAL\Driver\OCI8Ext;

use Doctrine\DBAL\Driver\OCI8\OCI8Statement as BaseStatement;

/**
 * Class OCI8Statement
 *
 * @package Doctrine\DBAL\Driver\OCI8Ext
 * @author  Jason Hofer <jason.hofer@gmail.com>
 * 2018-02-21 7:54 PM
 */
class OCI8Statement extends BaseStatement
{
    /**
     * Holds references to bound parameter values.
     *
     * This is a new requirement for PHP7's oci8 extension that prevents bound values from being garbage collected.
     *
     * @see \Doctrine\DBAL\Driver\OCI8\OCI8Statement::$boundValues
     *
     * @var array
     */
    private $references = array();

    /** @var array */
    protected $cursorFields = array();
    /** @var bool */
    protected $checkedForCursorFields = false;
    /** @var bool */
    protected $hasCursorFields = false;

    /**
     * Used because parent::fetchAll() calls $this->fetch().
     *
     * @var bool
     */
    private $returningResources = false;

    /**
     * Used because parent::fetchAll() calls $this->fetch().
     *
     * @var bool
     */
    private $returningCursors = false;

    /**
     * @param string     $param
     * @param mixed      $value
     * @param int|string $type
     *
     * @return bool
     *
     * @throws \LogicException
     */
    public function bindValue($param, $value, $type = null)
    {
        list($type, $ociType) = $this->normalizeType($type);

        if (\PDO::PARAM_STMT === $type || OCI_B_CURSOR === $ociType) {
            throw new \LogicException('You must call "bindParam()" to bind a cursor.');
        }

        return parent::bindValue($param, $value, $type);
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param string     $column
     * @param mixed      $variable
     * @param int|string $type
     * @param int|null   $length
     *
     * @return bool
     */
    public function bindParam($column, &$variable, $type = \PDO::PARAM_STR, $length = null)
    {
        $origCol = $column;

        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        $column = isset($this->_paramMap[$column]) ? $this->_paramMap[$column] : $column;

        list($type, $ociType) = $this->normalizeType($type);

        // Type: Cursor.
        if (\PDO::PARAM_STMT === $type || OCI_B_CURSOR === $ociType) {
            /** @var OCI8Connection $conn Because my IDE complains. */
            $conn     = $this->_conn;
            $variable = $conn->newCursor();

            return $this->bindByName($column, $variable->_sth, -1, OCI_B_CURSOR);
        }

        // Type: Null. (Must come *after* types that can expect $variable to be null, like 'cursor'.)
        if (null === $variable) {
            return $this->bindByName($column, $variable);
        }

        // Type: Array.
        if (is_array($variable)) {
            $length = null === $length ? -1 : $length;

            if (!$ociType) {
                $ociType = \PDO::PARAM_INT === $type ? SQLT_INT : SQLT_CHR;
            }

            return $this->bindArrayByName(
                $column,
                $variable,
                max(count($variable), 1),
                empty($variable) ? 0 : $length,
                $ociType
            );
        }

        // Type: Lob
        if (OCI_B_CLOB === $ociType || OCI_B_BLOB === $ociType) {
            $type = \PDO::PARAM_LOB;
        } elseif ($ociType) {
            return $this->bindByName($column, $variable, null === $length ? -1 : $length, $ociType);
        }

        return parent::bindParam($origCol, $variable, $type, $length);
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param string $column
     * @param mixed  $variable
     * @param int    $maxLength
     * @param int    $type
     *
     * @return bool
     */
    protected function bindByName($column, &$variable, $maxLength = -1, $type = SQLT_CHR)
    {
        // For PHP 7's OCI8 extension (prevents garbage collection).
        $this->references[$column] =& $variable;

        return oci_bind_by_name($this->_sth, $column, $variable, $maxLength, $type);
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param string $column
     * @param mixed  $variable
     * @param int    $maxTableLength
     * @param int    $maxItemLength
     * @param int    $type
     *
     * @return bool
     */
    protected function bindArrayByName($column, &$variable, $maxTableLength, $maxItemLength = -1, $type = SQLT_AFC)
    {
        // For PHP 7's OCI8 extension (prevents garbage collection).
        $this->references[$column] =& $variable;

        return oci_bind_array_by_name($this->_sth, $column, $variable, $maxTableLength, $maxItemLength, $type);
    }

    /**
     * @param int|string $type
     *
     * @return array
     */
    protected function normalizeType($type)
    {
        $ociType = null;

        // Figure out the type.
        if (is_numeric($type)) {
            $type = (int) $type;
            if (OCI8::isParamConstant($type)) {
                $ociType = OCI8::decodeParamConstant($type);
            }
        } elseif ('cursor' === strtolower($type)) {
            $type    = \PDO::PARAM_STMT;
            $ociType = OCI_B_CURSOR;
        }

        return array($type, $ociType);
    }

    /**
     * Returns the next row of a result set.
     *
     * Cursors will be fetched, unless otherwise specified by the fetch mode.
     *
     * @param int|null $fetchMode Controls how the next row will be returned to the caller.
     *                            The value must be one of the PDO::FETCH_* and/or OCI8::RETURN_* constants,
     *                            defaulting to PDO::FETCH_BOTH.
     *
     * @return array|bool|mixed The return value of this method on success depends on the fetch mode.
     *                          In all cases, FALSE is returned on failure.
     *
     * @see PDO::FETCH_* and OCI8::RETURN_* constants.
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    public function fetch($fetchMode = null)
    {
        list(
            $fetchMode,
            $returnResources,
            $returnCursors
        ) = $this->processFetchMode($fetchMode, true);
        // "Globals" are checked because parent::fetchAll() uses $this->fetch().

        $row = parent::fetch($fetchMode);

        if (!$returnResources) {
            $this->fetchCursorFields($row, $fetchMode, $returnCursors);
        }

        return $row;
    }

    /**
     * Returns an array containing all of the result set rows.
     *
     * Cursors will be fetched, unless otherwise specified by the fetch mode.
     *
     * @param int|null $fetchMode Controls how the next row will be returned to the caller.
     *                            The value must be one of the PDO::FETCH_* and/or OCI8::RETURN_* constants,
     *                            defaulting to PDO::FETCH_BOTH.
     *
     * @return array|mixed
     *
     * @see PDO::FETCH_* and OCI8::RETURN_* constants.
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    public function fetchAll($fetchMode = null)
    {
        list(
            $fetchMode,
            $this->returningResources,
            $this->returningCursors
        ) = $this->processFetchMode($fetchMode);
        // "Globals" are set because parent::fetchAll() uses $this->fetch().

        $results = parent::fetchAll($fetchMode);

        if (
            !$this->returningResources &&
            $results &&
            is_array($results) &&
            self::$fetchModeMap[$fetchMode] !== OCI_BOTH // handled by $this->fetch() in parent::fetchAll().
        ) {
            if (\PDO::FETCH_COLUMN !== $fetchMode) {
                foreach ($results as &$row) {
                    $this->fetchCursorFields($row, $fetchMode, $this->returningCursors);
                }
                unset($row);
            } elseif (is_resource(reset($results))) {
                $self    = $this;
                $results = array_map(function ($value) use ($self, $fetchMode) {
                    return $self->fetchCursorValue($value, $fetchMode, $self->returningCursors);
                }, $results);
            }
        }

        $this->returningResources =
        $this->returningCursors   = false;
        $this->resetCursorFields();

        return $results;
    }

    /**
     * Returns a single column from the next row of a result set or FALSE if there are no more rows.
     *
     * Cursors will be fetched, unless otherwise specified by the fetch mode.
     *
     * @param int $columnIndex    0-indexed number of the column you wish to retrieve from the row.
     *                            If no value is supplied, PDOStatement->fetchColumn()
     *                            fetches the first column.
     * @param int|null $fetchMode Controls how the next column value will be returned to the caller, if it is
     *                            a cursor. The value must be one of the PDO::FETCH_* and/or OCI8::RETURN_* constants,
     *                            defaulting to PDO::FETCH_BOTH.
     *
     * @return string|bool|array|resource|OCI8Cursor A single column in the next row of a result set, or FALSE if
     *                                               there are no more rows.
     *
     * @see PDO::FETCH_* and OCI8::RETURN_* constants.
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    public function fetchColumn($columnIndex = 0, $fetchMode = null)
    {
        list(
            $fetchMode,
            $returnResources,
            $returnCursors
        ) = $this->processFetchMode($fetchMode);

        /** @var array|bool|null|string|resource $columnValue */
        $columnValue = parent::fetchColumn($columnIndex);

        if (!$returnResources && is_resource($columnValue)) {
            return $this->fetchCursorValue($columnValue, $fetchMode, $returnCursors);
        }

        return $columnValue;
    }

    /**
     * @param array|mixed $row
     * @param int         $fetchMode
     * @param bool        $returnCursors
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    protected function fetchCursorFields(&$row, $fetchMode, $returnCursors)
    {
        if (!is_array($row)) {
            $this->resetCursorFields();
        } elseif (!$this->checkedForCursorFields) {
            // This will also call fetchCursorField() on each cursor field of the first row.
            $this->findCursorFields($row, $fetchMode, $returnCursors);
        } elseif ($this->hasCursorFields) {
            $shared = array();
            foreach ($this->cursorFields as $field) {
                $key = (string) $row[$field];
                if (isset($shared[$key])) {
                    $row[$field] = $shared[$key];
                    continue;
                }
                $row[$field]  =  $this->fetchCursorValue($row[$field], $fetchMode, $returnCursors);
                $shared[$key] =& $row[$field];
            }
        }
    }

    protected function resetCursorFields()
    {
        $this->cursorFields           = array();
        $this->checkedForCursorFields =
        $this->hasCursorFields        = false;
    }

    /**
     * @param array $row
     * @param int   $fetchMode
     * @param bool  $returnCursors
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    protected function findCursorFields(array &$row, $fetchMode, $returnCursors)
    {
        $shared = array();
        foreach ($row as $field => $value) {
            if (!is_resource($value)) {
                continue;
            }
            $this->hasCursorFields = true;
            $this->cursorFields[]  = $field;
            $key = (string) $value;
            if (isset($shared[$key])) {
                $row[$field] = $shared[$key];
                continue;
            }
            // We are already here, so might as well process it.
            $row[$field]  =  $this->fetchCursorValue($row[$field], $fetchMode, $returnCursors);
            $shared[$key] =& $row[$field];
        }
        $this->checkedForCursorFields = true;
    }

    /**
     * @param resource $resource
     * @param int      $fetchMode
     * @param bool     $returnCursor
     *
     * @return array|mixed|OCI8Cursor
     *
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    protected function fetchCursorValue($resource, $fetchMode, $returnCursor)
    {
        /** @var OCI8Connection $conn Because my IDE complains. */
        $conn   = $this->_conn;
        $cursor = $conn->newCursor($resource);

        if ($returnCursor) {
            return $cursor;
        }

        $cursor->execute();
        $results = $cursor->fetchAll($fetchMode);
        $cursor->closeCursor();

        return $results;
    }

    /**
     * @param int  $fetchMode
     * @param bool $checkGlobal
     *
     * @return array
     */
    protected function processFetchMode($fetchMode, $checkGlobal = false)
    {
        $returnResources  = ($checkGlobal && $this->returningResources) || ($fetchMode & OCI8::RETURN_RESOURCES);
        $returnCursors    = ($checkGlobal && $this->returningCursors)   || ($fetchMode & OCI8::RETURN_CURSORS);
        $fetchMode       &= ~(OCI8::RETURN_RESOURCES+OCI8::RETURN_CURSORS); // Must unset the flags or there will be an error.
        $fetchMode        = (int) ($fetchMode ?: $this->_defaultFetchMode);

        return array($fetchMode, $returnResources, $returnCursors);
    }
}
