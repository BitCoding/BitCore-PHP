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

namespace Bit\Chronos\Traits;

use Bit\Chronos\ChronosInterface;
use InvalidArgumentException;

/**
 * Provides the magic methods that allow read access
 * to magic properties.
 */
trait MagicPropertyTrait
{
    /**
     * Get a part of the ChronosInterface object
     *
     * @param string $name The property name to read.
     * @return string|int|DateTimeZone The property value.
     * @throws InvalidArgumentException
     */
    public function __get($name)
    {
        switch (true) {
            case array_key_exists($name, $formats = [
                'year' => 'Y',
                'yearIso' => 'o',
                'month' => 'n',
                'day' => 'j',
                'hour' => 'G',
                'minute' => 'i',
                'second' => 's',
                'micro' => 'u',
                'dayOfWeek' => 'N',
                'dayOfYear' => 'z',
                'weekOfYear' => 'W',
                'daysInMonth' => 't',
                'timestamp' => 'U',
            ]):
                return (int)$this->format($formats[$name]);

            case $name === 'weekOfMonth':
                return (int)ceil($this->day / ChronosInterface::DAYS_PER_WEEK);

            case $name === 'age':
                return (int)$this->diffInYears();

            case $name === 'quarter':
                return (int)ceil($this->month / 3);

            case $name === 'offset':
                return $this->getOffset();

            case $name === 'offsetHours':
                return $this->getOffset() / ChronosInterface::SECONDS_PER_MINUTE / ChronosInterface::MINUTES_PER_HOUR;

            case $name === 'dst':
                return $this->format('I') === '1';

            case $name === 'local':
                return $this->offset === $this->copy()->setTimezone(date_default_timezone_get())->offset;

            case $name === 'utc':
                return $this->offset === 0;

            case $name === 'timezone' || $name === 'tz':
                return $this->getTimezone();

            case $name === 'timezoneName' || $name === 'tzName':
                return $this->getTimezone()->getName();

            default:
                throw new InvalidArgumentException(sprintf("Unknown getter '%s'", $name));
        }
    }

    /**
     * Check if an attribute exists on the object
     *
     * @param string $name The property name to check.
     * @return bool Whether or not the property exists.
     */
    public function __isset($name)
    {
        try {
            $this->__get($name);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }
}
