<?php
namespace Bit\Chronos;

/**
 * Handles formatting differences in text.
 *
 * Provides a swappable component for other libraries to leverage.
 * when localizing or customizing the difference output.
 */
class DifferenceFormatter
{
    /**
     * Constructor.
     *
     * @param \Bit\Chronos\Translator|null $translate The text translator object.
     */
    public function __construct($translate = null)
    {
        $this->translate = $translate ?: new Translator();
    }

    /**
     * Get the difference in a human readable format.
     *
     * @param \Bit\Chronos\ChronosInterface $date The datetime to start with.
     * @param \Bit\Chronos\ChronosInterface|null $other The datetime to compare against.
     * @param bool $absolute removes time difference modifiers ago, after, etc
     * @return string The difference between the two days in a human readable format
     * @see Bit\Chronos\ChronosInterface::diffForHumans
     */
    public function diffForHumans(ChronosInterface $date, ChronosInterface $other = null, $absolute = false)
    {
        $isNow = $other === null;
        if ($isNow) {
            $other = $date->now($date->tz);
        }
        $diffInterval = $date->diff($other);

        switch (true) {
            case ($diffInterval->y > 0):
                $unit = 'year';
                $count = $diffInterval->y;
                break;
            case ($diffInterval->m > 0):
                $unit = 'month';
                $count = $diffInterval->m;
                break;
            case ($diffInterval->d > 0):
                $unit = 'day';
                $count = $diffInterval->d;
                if ($count >= ChronosInterface::DAYS_PER_WEEK) {
                    $unit = 'week';
                    $count = (int)($count / ChronosInterface::DAYS_PER_WEEK);
                }
                break;
            case ($diffInterval->h > 0):
                $unit = 'hour';
                $count = $diffInterval->h;
                break;
            case ($diffInterval->i > 0):
                $unit = 'minute';
                $count = $diffInterval->i;
                break;
            default:
                $count = $diffInterval->s;
                $unit = 'second';
                break;
        }
        if ($count === 0) {
            $count = 1;
        }
        $time = $this->translate->plural($unit, $count, ['count' => $count]);
        if ($absolute) {
            return $time;
        }
        $isFuture = $diffInterval->invert === 1;
        $transId = $isNow ? ($isFuture ? 'from_now' : 'ago') : ($isFuture ? 'after' : 'before');

        // Some langs have special pluralization for past and future tense.
        $tryKeyExists = $unit . '_' . $transId;
        if ($this->translate->exists($tryKeyExists)) {
            $time = $this->translate->plural($tryKeyExists, $count, ['count' => $count]);
        }
        return $this->translate->singular($transId, ['time' => $time]);
    }
}
