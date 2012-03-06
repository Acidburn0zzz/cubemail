<?php

/**
 * Kolab format model class wrapping libkolabxml bindings
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

abstract class kolab_format
{
    public static $timezone;

    /**
     * Factory method to instantiate a kolab_format object of the given type
     */
    public static function factory($type)
    {
        if (!isset(self::$timezone))
            self::$timezone = new DateTimeZone('UTC');

        $suffix = preg_replace('/[^a-z]+/', '', $type);
        $classname = 'kolab_format_' . $suffix;
        if (class_exists($classname))
            return new $classname();

        return PEAR::raiseError(sprintf("Failed to load Kolab Format wrapper for type %s", $type));
    }

    /**
     * Generate random UID for Kolab objects
     *
     * @return string  MD5 hash with a unique value
     */
    public static function generate_uid()
    {
        return md5(uniqid(mt_rand(), true));
    }

    /**
     * Convert the given date/time value into a c_DateTime object
     *
     * @param mixed         Date/Time value either as unix timestamp, date string or PHP DateTime object
     * @param DateTimeZone  The timezone the date/time is in. Use global default if empty
     * @param boolean       True of the given date has no time component
     * @return c_DateTime   The libkolabxml date/time object or null on error
     */
    public static function getDateTime($datetime, $tz = null, $dateonly = false)
    {
        if (!$tz) $tz = self::$timezone;
        $result = null;

        if (is_numeric($datetime))
            $datetime = new DateTime('@'.$datetime, $tz);
        else if (is_string($datetime))
            $datetime = new DateTime($datetime, $tz);

        if (is_a($datetime, 'DateTime')) {
            $result = new KolabDateTime();
            $result->setDate($datetime->format('Y'), $datetime->format('n'), $datetime->format('j'), 0, 0, 0);

            if (!$dateonly)
                $result->setTime($datetime->format('G'), $datetime->format('i'), $datetime->format('s'));
            if ($tz)
                $result->setTimezone($tz->getName());
        }

        return $result;
    }

    /**
     * Convert a libkolabxml vector to a PHP array
     *
     * @param object vector Object
     * @return array Indexed array contaning vector elements
     */
    public static function vector2array($vec)
    {
        $arr = array();
        for ($i=0; $i < $vec->size(); $i++)
            $arr[] = $vec->get($i);
        return $arr;
    }

    /**
     * Load Kolab object data from the given XML block
     *
     * @param string XML data
     */
    abstract public function load($xml);

    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    abstract public function set(&$object);

    /**
     *
     */
    abstract public function is_valid();

    /**
     * Write object data to XML format
     *
     * @return string XML data
     */
    abstract public function write();

    /**
     * Convert the Kolab object into a hash array data structure
     *
     * @return array  Kolab object data as hash array
     */
    abstract public function to_array();

}
