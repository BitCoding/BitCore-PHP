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

/**
 * Wraps a statement in a callback that allows row results
 * to be modified when being fetched.
 *
 * This is used by BitPHP to eagerly load association data.
 */
class CallbackStatement extends StatementDecorator
{

    /**
     * A callback function to be applied to results.
     *
     * @var callable
     */
    protected $_callback;

    /**
     * Constructor
     *
     * @param \Bit\Database\StatementInterface $statement The statement to decorate.
     * @param \Bit\Database\Driver $driver The driver instance used by the statement.
     * @param callable $callback The callback to apply to results before they are returned.
     */
    public function __construct($statement, $driver, $callback)
    {
        parent::__construct($statement, $driver);
        $this->_callback = $callback;
    }

    /**
     * Fetch a row from the statement.
     *
     * The result will be processed by the callback when it is not `false`.
     *
     * @param string $type Either 'num' or 'assoc' to indicate the result format you would like.
     * @return array|false
     */
    public function fetch($type = 'num')
    {
        $callback = $this->_callback;
        $row = $this->_statement->fetch($type);
        return $row === false ? $row : $callback($row);
    }

    /**
     * Fetch all rows from the statement.
     *
     * Each row in the result will be processed by the callback when it is not `false.
     *
     * @param string $type Either 'num' or 'assoc' to indicate the result format you would like.
     * @return array
     */
    public function fetchAll($type = 'num')
    {
        return array_map($this->_callback, $this->_statement->fetchAll($type));
    }
}
