<?php
/**
 * BitCore-PHP:  Rapid Development Framework (https://phpcore.bitcoding.eu)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://phpcore.bitcoding.eu BitCore-PHP Project
 * @since         0.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Bit\Database\Statement;

use PDO;

/**
 * Statement class meant to be used by an Sqlserver driver
 *
 * @internal
 */
class SqlserverStatement extends PDOStatement
{

    /**
     * The SQL Server PDO driver requires that binary parameters be bound with the SQLSRV_ENCODING_BINARY attribute.
     * This overrides the PDOStatement::bindValue method in order to bind binary columns using the required attribute.
     *
     * {@inheritDoc}
     *
     * @param int|string $column
     * @param mixed $value
     * @param string $type
     */
    public function bindValue($column, $value, $type = 'string')
    {
        if ($type === null) {
            $type = 'string';
        }
        if (!ctype_digit($type)) {
            list($value, $type) = $this->cast($value, $type);
        }
        if ($type == PDO::PARAM_LOB) {
            $this->_statement->bindParam($column, $value, $type, 0, PDO::SQLSRV_ENCODING_BINARY);
        } else {
            $this->_statement->bindValue($column, $value, $type);
        }
    }
}
