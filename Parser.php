<?php

// Based on https://github.com/ericbn/js-abstract-descent-parser


class Parser {
    private $input = ''; // the expression being parsed
    private $index = 0;  // current offset in the expression 
    
    private $dbg = false;
    public $errno = 0;
    public $errtxt = '';
    
    private $varfn = false;   // fn for accessing variables
    private $objects = [];    // list of defined objects
    private $functions = [];  // list of functions
    private $constants = [];  // list of defined constants 
        
    private $supported_types = [ 'number', 'string', 'object' ];
    
    
    /**
     * Create new parser
     * @param string $varfn Function to call to lookup the value of a variable
     */
    public function __construct($varfn) {
        $this->varfn = $varfn;
        
        $this->registerFunction('firstof', 'Parser::basefns', 1, 100);
    }
    
    private function err($errno, $errtxt) {
        if (!$this->errno) { // only store first error
            $this->errno = $errno;
            $this->errtxt = $errtxt;
        }
        if ($this->dbg)   echo "ERR: $errtxt<br>";
        return null;
    }
    
    /**
     * Register a variable
     * @param string $varname
     * @param string $type Type of the variable, see Parser::$supported_types
     * @param mixed $value Initial value, ignored if $readfn is set
     * @param function $readfn 
     */
    public function registerVariable($varname, $type, $value, $readfn = false) {
        // TODO
        return false;
    }
    
    /**
     * Register a function
     * @param string $fnname Name of the function
     * @param string $fn Function to call
     * @param int $min Min. number of args
     * @param int $max Max. number of args
     * @return bool
     */
    public function registerFunction($fnname, $fn, $min = 0, $max = 100) {
        if (!$this->isValidName($fnname))   return false;
        if (!is_callable($fn))    return false;

        $this->functions[$fnname] = (object)[ 'fn' => $fn, 'min' => $min, 'max' => $max ];
        return true; 
    }
    
    /**
     * Register an object
     * @param string $objname Name of the object
     * @param object $obj Must provide gv() and getType(), or $properties[]
     * @return bool
     */
    public function registerObject($objname, $obj) {
        if (!$this->isValidName($objname))   return false;

        $this->objects[$objname] = $obj;
        return true;
    }

    /**
     * Register a constant
     * @param string $name
     * @param mixed $value Must be either string or float/int
     * @return bool
     */
    public function registerConstant($name, $value) {
        if (!$this->isValidName($name))   return false;

        // detect type
        $type = false;
        if (is_string($value))   $type = 'string';
        else if (is_numeric($value))   $type = 'number';
        if (!$type)    return false;

        $this->constants[$name] = (object)[ 'type' => $type, 'value' => $value ];
        return true;
    }
    
    /**
     * Parse an expression
     * @param string $expr
     * @return object 
     */
    public function parse($expr) {
        $this->errno = 0;
        $res = $this->preprocess($expr);
        if (!is_string($res))    return null;
            
        $this->input = $res;
        $this->index = 0;
        $this->errno = 0;
        
        $res = $this->exprPLUSMINUS();
        if ($res === null) {
            $this->err(6, "Failed to parse the expression");
            return (object)[ 'type' => 'error', 'value' => $this->errno, 'name' => $this->errtxt ];
        }

        // nothing allowed after the expression, except a ';'
        if ($this->index != strlen($this->input) && substr($this->input, $this->index, 1) != ';') {
            $this->err(5, "Junk at offset ".($this->index+1)." ('... ".substr($this->input, $this->index-5)."')");
            return (object)[ 'type' => 'error', 'value' => $this->errno, 'name' => $this->errtxt ];
        }
        return $res;
    }
     
    public function preprocess($expr) {
        // strip space outside quotes, and unescape
        $res = '';
        $inquote = false;
        $esc = false;
        for ($i = 0; $i < strlen($expr); $i++) {
            $c = substr($expr, $i, 1);
            if ($c == '"') {
                $res .= $c;
                $inquote = !$inquote;
                $esc = false;
            } else if ($inquote && $esc) {
                switch ($c) {
                    case 'n':    $res .= "\n";   break;
                    case 'r':    $res .= "\r";   break;
                    case 't':    $res .= "\t";   break;
                    case '\\':   $res .= "\\";   break;
                }
                $esc = false;
            } else if ($c == '\\' && $inquote && !$esc) {
                $esc = true;
            } else if ($c != ' ' || $inquote)
                $res .= $c;
        }
        if ($inquote)   return $this->err(1, "Dangling quote");
        if ($esc)       return $this->err(10, "Dangling \\");
        if (!$res)      return $this->err(2, "Empty expression");
        return $res;
    }
    
    /**
     * Register the most common maths functions
     */
    public function enableMathsFunctions() {
        foreach (['sin','cos','tan','asin','acos','atan','log','ln','sqrt','exp','floor','ceil','round'] as $fn)
            $this->registerFunction($fn, 'Parser::mathsfns', 1, 1);
        $this->registerFunction('pow', 'Parser::mathsfns', 2, 2);
        $this->registerFunction('random', 'Parser::mathsfns', 0, 2);
        $this->registerFunction('min', 'Parser::mathsfns', 2, 100);
        $this->registerFunction('max', 'Parser::mathsfns', 2, 100);
        
        $this->constants['PI'] = (object)[ 'type' => 'number', 'value' => 3.141592653589793 ];
        $this->constants['e'] = (object)[ 'type' => 'number', 'value' => 2.718281828459045 ];
    }

    public function enableTimeFunctions() {
        $this->registerFunction('time', 'Parser::timefns', 0, 0);
        $this->registerFunction('date', 'Parser::timefns', 1, 2);
        $this->registerFunction('strtotime', 'Parser::timefns', 1, 1);
        $this->registerFunction('dateadjust', 'Parser::timefns', 2, 2);
        $this->registerFunction('timeadjust', 'Parser::timefns', 2, 2);
    }

    public function enableStringFunctions() {
        foreach (['tolower','toupper','strlen'] as $fn)
            $this->registerFunction($fn, 'Parser::stringfns', 1, 1);
        $this->registerFunction('replace', 'Parser::stringfns', 3, 3);
        $this->registerFunction('substr', 'Parser::stringfns', 2, 3);
        $this->registerFunction('sprintf', 'Parser::stringfns', 1, 100);
    }

    /**
     * Convert an internal value (ie an object) to an int
     * @param object $val
     * @return int
     */
    private function toInt($val) {
        if (!$val)    return null;
        if ($val->type == 'number')   return (int)$val->value;
        if ($val->type == 'string')   return (int)$val->value;
        return null;
    }
    /**
     * Convert an internal value (ie an object) to a float
     * @param object $val
     * @return float
     */
    private function toFloat($val) {
        if (!$val)    return null;
        if ($val->type == 'number')   return (float)$val->value;
        if ($val->type == 'string')   return (float)$val->value;
        return null;
    }
    /**
     * Convert an internal value (ie an object) to a string
     * @param object $val
     * @return string
     */
    private function toString($val) {
        if (!$val)    return null;
        if ($val->type == 'number')   return (string)$val->value;
        if ($val->type == 'string')   return $val->value;
        return null;
    }

    /**
     * Convert a float to an internal value (ie an object)
     * @param float $val
     * @return object
     */
    private function fromFloat($val) {
        if ($val === null)    return null;
        return (object)[ 'type' => 'number', 'value' => $val ];
    }
    
    /**
     * Handler for a bunch of time functions
     */
    private static function basefns($fn, $argv) {
        switch ($fn) {
            case 'firstof': // res = firstof(<arg1>,...)
                foreach ($argv as $arg) {
                    switch ($arg->type) {
                        case 'number':
                            if ($arg->value)    return $arg;
                        case 'string':
                            if ($arg->value)    return $arg;
                    }
                }
                break;
        }
        return null;
    }
    
    /**
     * Handler for a bunch of time functions
     */
    private static function timefns($fn, $argv) {
        switch ($fn) {
            case 'date': // string = date([<format>,[<timestamp>]])
                $ts = time();
                $format = 'Y-m-d H:i:s';
                if (count($argv) >= 1)   $format = $this->toString($argv[0]);
                if (count($argv) >= 2)   $ts = $this->toInt($argv[1]);
                if ($ts === null || $format === null)   return null;
                return (object)[ 'type' => 'string', 'value' => date($format, $ts) ];
            case 'time': // number = time()
                return (object)[ 'type' => 'number', 'value' => time() ];
            case 'strtotime': // number = strtotime(<string>)
                return (object)[ 'type' => 'number', 'value' => strtotime($this->toString($argv[0])) ];
            case 'timeadjust': // number = timeadjust(<timestamp>, <string>)
                $t = date('Y-m-d H:i:s', $this->toInt($argv[0]));
                $t = $this->dateadjust($t, $this->toString($argv[1]));
                $t = strtotime($t);
                return (object)[ 'type' => 'number', 'value' => $t ];
            case 'dateadjust': // string = dateadjust(<date>, <string>)
                $hastime = strlen($this->toString($argv[0])) == 19;
                $t = $this->dateadjust($this->toString($argv[0]), $this->toString($argv[1]));
                return (object)[ 'type' => 'number', 'value' => substr($t, 0, $hastime ? 19 : 10) ];
        }
        return null;
    }
    
    /**
     * Modify a date+time in various ways
     * @param string $date Date+time, Y-m-d H:i:s
     * @param string $actions List of actions
     * @return string Date+time, Y-m-d H:i:s
     */
    private static function dateadjust($date, $actions) {
        $weekdays = [ 'mon','tue','wed','thu','fri','sat','sun'];
        $months = [ 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec' ];
        $units = [ 'yr','mo','da','wk','hr','mi','se' ];

        $ts = strtotime($date);
        foreach (explode(' ', $actions) as $action) {
            echo "AC $action ";
            $wd = array_search(substr($action, -3), $weekdays);
            $m = array_search(substr($action, -3), $months);
            $u = array_search(substr($action, -2), $units);
            
            // [+-]<amount><unit>
            if ($u !== false) {
                $u = substr($action, -2);
                $action = substr($action, 0, strlen($action)-2); // strip the units
                $adj = 0;
                if (substr($action, 0, 1) == '+')   $adj = 1;
                if (substr($action, 0, 1) == '-')   $adj = -1;
                if ($adj)   $action = substr($action, 1);
                $amount = (int)$action;
                
                $t = localtime($ts, true);
                $t['tm_year'] += 1900;
                $t['tm_mon'] += 1;
                
                $field = false;
                switch ($u) {
                    case 'yr':   $field = 'tm_year';   break;
                    case 'mo':   $field = 'tm_mon';   break;
                    case 'da':   $field = 'tm_mday';   break;
                    case 'hr':   $field = 'tm_hour';   break;
                    case 'mi':   $field = 'tm_min';   break;
                    case 'se':   $field = 'tm_sec';   break;
                    case 'wk':
                        if ($adj)
                            $t['tm_mday'] = $t['tm_mday'] + 7*$adj*$amount;
                        else {
                            $t['tm_mday'] = 7*$amount;
                            $t['tm_mon'] = 1;
                        }
                        break;
                }
                if ($field)
                    $t[$field] = $adj ? ($t[$field] + $adj*$amount) : $amount;  
                $ts = mktime($t['tm_hour'], $t['tm_min'], $t['tm_sec'], $t['tm_mon'], $t['tm_mday'], $t['tm_year']);
                continue;
            }
            
            // first<weekday>
            // last<weekday>
            if ((substr($action, 0, 5) == 'first' || substr($action, 0, 4) == 'last') && $wd !== false) {
                $first = substr($action, 0, 5) == 'first';
                // go to the first or last day in the month
                $t = localtime($ts, true);
                if ($first)  $t['tm_mday'] = 1;   else   $t['tm_mday'] = date('t', $ts);
                $ts = mktime($t['tm_hour'], $t['tm_min'], $t['tm_sec'], $t['tm_mon']+1, $t['tm_mday'], $t['tm_year']+1900);
                // go forwards or backwards until we reach the desired weekday
                $add = $first ? 1 : -1;
                while (true) {
                    if (date('N', $ts) == $wd+1)    break;
                    $t = localtime($ts, true);
                    $ts = mktime($t['tm_hour'], $t['tm_min'], $t['tm_sec'], $t['tm_mon']+1, $t['tm_mday']+$add, $t['tm_year']+1900);
                }
                continue;
            }
            
            // next<weekday>
            // prev<weekday>
            if ((substr($action, 0, 4) == 'next' || substr($action, 0, 4) == 'prev') && $wd !== false) {
                $add = (substr($action, 0, 4) == 'next') ? 1 : -1;
                // go forwards or backwards until we reach the desired weekday
                while (true) {
                    $t = localtime($ts, true);
                    $ts = mktime($t['tm_hour'], $t['tm_min'], $t['tm_sec'], $t['tm_mon']+1, $t['tm_mday']+$add, $t['tm_year']+1900);
                    if (date('N', $ts) == $wd+1)    break;
                }
                continue;
            }

            // next<month>
            // prev<month>
            if ((substr($action, 0, 4) == 'next' || substr($action, 0, 4) == 'prev') && $m !== false) {
                $add = (substr($action, 0, 4) == 'next') ? 1 : -1;
                // go forwards or backwards until we reach the desired month
    // TODO what about adding 1 month to 31.jan ?
                while (true) {
                    $t = localtime($ts, true);
                    $ts = mktime($t['tm_hour'], $t['tm_min'], $t['tm_sec'], $t['tm_mon']+1+$add, $t['tm_mday'], $t['tm_year']+1900);
                    if (date('n', $ts) == $m+1)    break;
                }
                continue;
            }
        }
        return date('Y-m-d H:i:s', $ts);
;
    }
        
    /**
     * Handler for a bunch of string functions
     */
    private static function stringfns($fn, $argv) {
        $arg = $this->toString($argv[0]);
        if ($arg === null)    return null;
        switch ($fn) {
            case 'strlen': // number = strlen(<string>)
                return (object)[ 'type' => 'number', 'value' => strlen($arg) ];
            case 'tolower': // string = tolower(<string>)
                return (object)[ 'type' => 'string', 'value' => strtolower($arg) ];
            case 'toupper': // string = toupper(<string>)
                return (object)[ 'type' => 'string', 'value' => strtoupper($arg) ];
            case 'substr': // string = substr(<string>,<int>), string = substr(<string>,<int>,<int>)
                $offset = $this->toInt($argv[1]);
                $len = 100000;
                if (count($argv) ==3)     $len = $this->toInt($argv[2]);
                if ($offset === null || $len === null)    return null;
                return (object)[ 'type' => 'string', 'value' => substr($arg, $offset, $len) ];
            case 'sprintf': // string = sprintf(<string>,<arg1>...)
                $format = $this->toString($argv[0]);
                $args = [];
                for ($i = 1; $i < count($argv); $i++)   $args[] = $argv[$i]->value;
                return (object)[ 'type' => 'string', 'value' => vsprintf($format, $args) ];
            case 'replace': // string = str_replace(<from>, <to>, <string>)
                return str_replace($this->toString($argv[0]),
                                   $this->toString($argv[1]),
                                   $this->toString($argv[2]));
        }
        return null;
    }
    
    /**
     * Handler for a bunch of maths functions
     */
    private static function mathsfns($fn, $argv) {
        switch ($fn) {
            case 'random': // float = random(), float = random(<max>), int = random(<min>,<max>)
                $ceil = 1000000;
                $r = rand(0, $ceil);
                // 0 args
                if (count($argv) == 0)    return $r/$ceil;
                // 1 arg
                if (count($argv) == 1) {
                    $max = $this->toInt($argv[0]);
                    if ($max === null)    return $this->err(7, "Invalid argument to $fn()");
                    return $this->fromFloat($max*$r/$ceil);
                }
                // 2 args
                $min = $this->toInt($argv[0]);
                $max = $this->toInt($argv[1]);
                if ($min === null || $max === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat((int)($min + ($max-$min)*$r/$ceil));
            
            case 'min': // number = min(<num1>, ...)
                $res = null;
                for ($i = 0; $i < count($argv); $i++) {
                    $arg = $argv[$i];
                    if ($arg->type != 'string' && $arg->type != 'number')   continue;
                    if ($res === null || $arg->value < $res->value)   $res = $arg;
                }
                if ($res === null)   return $this->err(7, "Invalid argument to $fn()");
                return $res;
            case 'max': // number = max(<num1>, ....)
                $res = null;
                for ($i = 0; $i < count($argv); $i++) {
                    $arg = $argv[$i];
                    if ($arg->type != 'string' && $arg->type != 'number')   continue;
                    if ($res === null || $arg->value > $res->value)   $res = $arg;
                }
                if ($res === null)   return $this->err(7, "Invalid argument to $fn()");
                return $res;
                
            case 'floor': // number = floor(<number>)
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(floor($arg));
            case 'ceil': // number = ceil(<number>)
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(ceil($arg));
            case 'round': // number = round(<number>)
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(round($arg));

            case 'sin':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(sin($arg));
            case 'cos':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(cos($arg));
            case 'tan':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(tan($arg));
            case 'asin':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(asin($arg));
            case 'acos':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(acos($arg));
            case 'atan':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(atan($arg));
            case 'log':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(log($arg));
            case 'ln':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(ln($arg));
            case 'sqrt':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(sqrt($arg));
            case 'exp':
                $arg = $this->toFloat($argv[0]);
                if ($arg === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(exp($arg));

            case 'pow': // number = pow(<number>, <number>)
                $arg1 = $this->toFloat($argv[0]);
                $arg2 = $this->toFloat($argv[1]);
                if ($arg1 === null || $arg2 === null)    return $this->err(7, "Invalid argument to $fn()");
                return $this->fromFloat(pow($arg1, $arg2));
        }
        return null;
    } 
    
    // -----------------------------------
    
    /**
     * Check if next char is an expected char
     * @param mixed $expected Either a char or an array of chars
     * @return mixed Either the char, or null if an unexpected char was found
     */
    private function char($expected) {
        if (!is_array($expected))   $expected = [ $expected ];
        if ($this->index >= strlen($this->input))   return null;
        $c = substr($this->input, $this->index++, 1);
        return in_array($c, $expected) ? $c : null;
    }
    
    /**
     * Check if the next part matches an regexp, consumes the chars that match
     * @param string $regexp
     * @return mixed Either the matching data, or null if not matching
     */
    private function regExp($regexp) {
        if ($this->index >= strlen($this->input))   return null;
        
        $matches = [];
        $res = preg_match($regexp, substr($this->input, $this->index), $matches, PREG_OFFSET_CAPTURE);
        
        if ($this->dbg) {
            echo "regexp $regexp; input='".substr($this->input, $this->index)."' , res="; 
            print_r($matches);
            echo "<br>";
        }
        if (!$res)   return null;
        
        $match = $matches[0];
        if (!$match || !is_array($match) || !$match[1] == 0)   return null;

        $this->index += strlen($match[0]);
        return $match[0];
    }
    
    private function binOp($leftval, $op, $rightval) {
        if ($op == '+' && $leftval->type == 'string' && $rightval->type == 'string') {
            // + on string
            return (object)[ 'type' => 'string', 'value' => $leftval->value.$rightval->value ];
        }
        
        $l = $this->toFloat($leftval);
        $r = $this->toFloat($rightval);
        if ($l === null || $r === null)   return null;
        switch ($op) {
            case '+':
                $l += $r;
                break;
            case '-':
                $l -= $r;
                break;
            case '*':
                $l *= $r;
                break;
            case '/':
                $l /= $r;
                break;
            case '%':
                $l %= $r;
                break;
        }
        return $this->fromFloat($l);
    }
    
    /**
     * Check for +- expression:  <expression> "+" <expression>
     * @return mixed
     */
    private function exprPLUSMINUS() {
        $leftval = $this->exprMULDIV();
        if ($leftval === null)   return null;

        while (true) {
            if ($this->dbg)   echo "PLUSMINUS<br>";
            $backtrace = $this->index;
            
            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index++, 1);
            if ($op && strpos("+-", $op) !== false && ($rightval = $this->exprMULDIV()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            } else if ($this->dbg)   echo "plusminus fail<br>";
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }

    /**
     * Check for / * expression:  <expression> "*" <expression>
     * @return mixed
     */
    private function exprMULDIV() {
        $leftval = $this->exprDOT();
        if ($leftval === null)    return null;
        
        while (true) {
            if ($this->dbg)   echo "MULDIV<br>";
            $backtrace = $this->index;

            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index++, 1);
            if ($op && strpos("*/%", $op) !== false && ($rightval = $this->exprDOT()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            } else if ($this->dbg)   echo "muldiv fail<br>";
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }

    private function exprDOT() {

        // try if this is an objectname
        $leftval = $this->factor_obj();
        if ($leftval === null) {
            $leftval = $this->factor();
            // what if it returned a function, and the function returns an object?
            if ($leftval === null || $leftval->type != 'object')    return $leftval;
        }

        if ($this->dbg) {
            echo "DOT3<br>";
            print_r($leftval);
        }
        
        while (true) {
            if ($this->dbg) {
                echo "DOT4<br>";
                print_r($leftval);
            }

            // object must be followed by .
            $op = substr($this->input, $this->index, 1);
            if ($op !== '.')    return $leftval;

            $backtrace = $this->index;
            $this->index++; // skip .

            if (($prop = $this->factor_prop($leftval)) !== null) {
                $leftval = $prop;
                if ($leftval->type != 'object')    return $leftval;
                $res = true;
            } else {
                if ($this->dbg)   echo "DOT fail<br>";
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }
    
    /**
     * Check for number/function/variable/expression
     * @return object Returns null if nothing was found
     */
    private function factor() {
        $res = $this->factor_num();
        if ($res !== null)    return $res;
        $res = $this->factor_str();
        if ($res !== null)    return $res;
        $res = $this->factor_func();
        if ($res !== null)    return $res;
        $res = $this->factor_var();
        if ($res !== null)    return $res;
        $res = $this->factor_expr();
        if ($res !== null)    return $res;
        
        if ($this->dbg)
           echo "factor fail ".substr($this->input, $this->index)."<br>";
        return null;
    }
    
    /**
     * Check for number: <number>
     * @return object Returns null if nothing was found
     */
    private function factor_num() {
        $str = $this->regExp("/^[-]?[0-9]+(\\.[0-9]+)?/");
        if ($str === null)   return null;
        return $this->fromFloat((float)$str);
    }
        
    /**
     * Check for string: " <chars> "
     * @return object Returns null if nothing was found
     */
    private function factor_str() {
        if (substr($this->input, $this->index, 1) != '"')    return null;
        $this->index++;
        $res = '';
        while (true) {
             $c = substr($this->input, $this->index++, 1);
             if ($c == '"')    return (object)[ 'type' => 'string', 'value' => $res ];
             $res .= $c;
        }
        return null;
    }
    
    /**
     * Check if a string is a valid variable/constant/object name
     * @param string $name
     * @return bool
     */
    private function isValidName($name) {
        if (!$name)   return false;
        $matches = [];
        $res = preg_match("/^[a-zA-Z_][a-zA-Z_0-9]*/", $name, $matches, PREG_OFFSET_CAPTURE);
        if (!$res)   return false;
        
        $match = $matches[0];
        if (!$match || !is_array($match) || !$match[1] == 0)   return false;
        return true;
    }

    /**
     * Check for object property
     * @param object $obj The object
     * @return object Returns null if nothing was found
     */
    private function factor_prop($obj) {
        $backtrace = $this->index;
        $prop = $this->regExp("/^[a-zA-Z_][a-zA-Z_0-9]*/");
        if ($prop === null)    return null;

        // check if prop exists in object
        
        // if the object provides a properties[] array with all allowed props...
        if (is_array($obj->value->properties)) {
            if (!isset($obj->value->properties[$prop]))    return null;
            $propval = $obj->value->properties[$prop];
            if (is_string($propval))
                return (object)['type' => 'string', 'value' => $propval];
            if (is_numeric($propval))
                return (object)['type' => 'number', 'value' => $propval];
            return null;
        }

        // if there is no properties[] array then use gv() and getType()
        if (!is_object($obj->value) || 
            !method_exists($obj->value, 'getType') ||
            !method_exists($obj->value, 'gv'))   return $this->err(9, "Invalid object");
        
        $proptype = $obj->value->getType($prop);
        if (!$proptype) {
            $this->index = $backtrace;
            return null;
        }

        $propval = $obj->value->gv($prop, false);
        if ($propval === null)    return null;
        
        if (substr($this->input, $this->index, 1) != '(')
            return $propval;
        // check if accessing non-fn prop as a function
        if (substr($this->input, $this->index, 1) == '(' && $propval->type != 'function')
            return $propval;

        if (!is_callable($propval->fn))
            return $this->err();

        $this->index++; // skip over (
        // parse args, then call fn
        $argv = $this->factor_exprlist();
        if ($argv === null)
            return null;

        $res = call_user_func($propval->fn, $prop, $argv);
        return $res;
    }

    /**
     * Check for object name
     * @return object Returns null if nothing was found
     */
    private function factor_obj() {
        $backtrace = $this->index;
        $objname = $this->regExp("/^[a-zA-Z_][a-zA-Z_0-9]*/");
        if (!$objname)    return null;

        if (!isset($this->objects[$objname])) {
            $this->index = $backtrace;
            return null;
        }
        return (object)[ 'type' => 'object', 'value' => $this->objects[$objname] ];
    }

    /**
     * Check for variables/constants/object reference: <name>
     * @return object Returns null if nothing was found
     */
    private function factor_var() {
        $str = $this->regExp("/^[a-zA-Z_][a-zA-Z_0-9]*/");
        if (!$str)    return null;
        
        // check for constants
        if (isset($this->constants[$str])) 
            return $this->constants[$str];

        // check for variables
        if (is_callable($this->varfn)) {
            $res = call_user_func($this->varfn, $str);
            if ($res !== null)    return $res;
        }

        return null;
    }
    
    /**
     * Check for expression: "(" <expression> ")"
     * @return object Returns null if nothing was found
     */
    private function factor_expr() {
        $val = null;
        return ($this->char('(') && ($val = $this->exprPLUSMINUS()) !== null && $this->char(')')) ? $val : null;
    }
    
    /**
     * Check for function: <name> "(" <expression> ")"
     * @return object Returns null if nothing was found
     */
    private function factor_func() {
        $fn = $this->regExp("/^[a-zA-Z_][a-zA-Z_0-9]*\(/");
        if ($fn === null)    return null;

        // strip trailing (
        $fn = substr($fn, 0, strlen($fn)-1);

        $fndef = $this->functions[$fn];
        if (!$fndef)
            return $this->err(3, "Unknown func: $fn");

        // parse arguments
        $argv = $this->factor_exprlist();
        if ($argv === null)
            return null;

        // validate arguments
        if (count($argv) < $fndef->min || count($argv) > $fndef->max)
            return $this->err(4, "Invalid arg count for fn: $fn");
        
        $res = call_user_func($fndef->fn, $fn, $argv);
        return $res;
    }
    
    /**
     * Read a list of expressions, index must point to first char after (
     * @return array<object>
     */
    private function factor_exprlist() {
        $argv = [];
        while (1) {
            if (substr($this->input, $this->index, 1) == ')') {
                // end of arg list
                $this->index++;
                return $argv;
            }
            // parse an argument
            $arg = $this->exprPLUSMINUS();
            if ($arg === null)
                return null;
            $argv[] = $arg;
            // check if there are more arguments
            if (substr($this->input, $this->index, 1) == ',')
                $this->index++; // skip , to next arg
        }
    }
}
 
?>
