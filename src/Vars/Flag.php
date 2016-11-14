<?php
/* 
 * BitCore (tm) : Bit Development Framework
 * Copyright (c) BitCore
 * 
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 * 
 * @copyright     BitCore
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Bit\Vars;
use Bit\Core\Vars;

abstract class Flag extends Enum{
    function __construct($var)
    {
        if(is_string($var) && !is_numeric($var))
        {
            if((strpos($var,'|') !== false))
                $var = explode('|',$var);
            else
                $var = [$var];

            $constants = $this->getConstList();
            $d = 0;
            foreach ($var as $key){
                $d |= $constants[$key];
            }
            $var = $d;
        }
        else if(is_string($var))
        {
            $var = intval($var);
        }
        parent::__construct($var);
    }
    
    public function get($object = false){
        if(!$object)
            return parent::get();
        
        $const = $this->getConstList();
        $value = $this->_value;
        $call = function($val) use ($value){
            return ($val & $value) ? $val : 0;
        };
        
        return  (object)array_combine(
                    array_keys($const), 
                    array_map($call, $const)
                );
    }
}