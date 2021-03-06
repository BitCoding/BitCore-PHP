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

namespace Bit\Controller\Exception;

use Bit\Core\Exception\Exception;

/**
 * Missing Action exception - used when a controller action
 * cannot be found, or when the controller's isAction() method returns false.
 */
class MissingActionException extends Exception
{

    /**
     * {@inheritDoc}
     */
    protected $_messageTemplate = 'Action %s::%s() could not be found, or is not accessible.';

    /**
     * MissingActionException constructor.
     *
     * {@inheritDoc}
     *
     * @param $message
     * @param int $code
     */
    public function __construct($message, $code = 404)
    {
        parent::__construct($message, $code);
    }
}
