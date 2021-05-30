<?php

// Based on https://github.com/ericbn/js-abstract-descent-parser

// Simple use
// $cfg = [];
// $p = new Parser($cfg);
// $res = $p->parse("123+456");
// echo $res->value;
//
// Features
// types: number string array object dict
// strings are UTF-8 in "", \uXXXX is supported, so is \r \n \b \t \"
// arithmetic/bitwise/logical ops: + - * / %  & | ^  && ||
// comparison: <= >= == != === !== < >
// names start with a-zA-Z_ and may contain a-zA-Z0-9_
// variables may be supported through callbacks, see $cfg['variablefn']
// functions may be defined by calling Parser->registerFunction()
// constants may be defined by calling Parser->registerConstant(), either string,number or array
// objects may be defined by calling Parser->registerObject()
// arrays: myarray[2]+100
// object: myobject.myproperty+100
// dict: mydict={a:100,b:"second",c:myarray}         mydict.a !== 100
// binary ops (+ - * <= >= === etc) may be overloaded

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
    private $variables = [];  // list of variables 

    private $terminator = ';';

    private $regexp_name = "/^[a-zA-Z_][a-zA-Z_0-9]*/";
    private $regexp_func = "/^[a-zA-Z_][a-zA-Z_0-9]*\(/"; // the '(' at the end is important
    private $regexp_prop = "/^[a-zA-Z_][a-zA-Z_0-9]*/"; // currently must be the same as $regexp_name
    private $regexp_number = "/^[-]?[0-9]+(\\.[0-9]+)?/";

    private $supported_types = [ 'number', 'string', 'object', 'array', 'data', 'dict' ];
    
    const INFINITY = 2100776655; // primitive way of handling result of eg division by zero

    private $cfg = [];
    private $features = [
    ];

    /**
     * Create new parser
     * @param array $cfg
     */
    public function __construct($cfg = false) {
        if (!is_array($cfg))   $cfg = self::getDefaultConfig();
        $this->cfg = $cfg;

        if ($cfg['enablemathsfns'])    $this->enableMathsFunctions();
        if ($cfg['enablemiscfns'])     $this->enableMiscFunctions();
        if ($cfg['enabletimefns'])     $this->enableTimeFunctions();
        if ($cfg['enablestringfns'])   $this->enableStringFunctions();
        if (is_array($cfg['disabledfns']))
            foreach ($cfg['disabledfns'] as $disfn)
                unset($this->functions[$disfn]);

        $this->registerFunction('firstof', 'Parser::basefns', 1, 100);
        $this->constants['true'] = self::fromFloat(1);
        $this->constants['false'] = self::fromFloat(1);

    }
    
    public function setConstant($name, $value) {
        $this->constants[$name] = $value;
    }

    public static function getDefaultConfig() {
        $cfg = [
            'variablefn' => null,        // callback for reading variable value
            'variablefn_arg' => null,    // callback arg
            'enablemathsfns' => true,    // enable groups of functions
            'enabletimefns' => true,
            'enablestringfns' => true,
            'enablemiscfns' => true,
            'disabledfns' => [],         // list of disabled fns
            'overloadfn' => [
              // '+' => 'Parser::overloadPLUS',
            ],
            'overloadfn_arg' => [
              // '+' => 1234, // value to pass to the overload fn
            ]
        ];
        return $cfg;
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
     * Register a function
     * @param string $fnname Name of the function
     * @param string $fn Function to call, will be called as $fn($fnname, $argv, $param, $parser)
     * @param int $min Min. number of args
     * @param int $max Max. number of args
     * @param mixed $param Value to pass to the function
     * @return bool
     */
    public function registerFunction($fnname, $fn, $min = 0, $max = 100, $param = false) {
        if (!$this->isValidName($fnname))   return false;
        if (!is_callable($fn))    return false;

        $this->functions[$fnname] = (object)[ 'fn' => $fn, 'min' => $min, 'max' => $max, 'param' => $param ];
        return true; 
    }
    
    /**
     * Register an object
     * @param string $objname Name of the object
     * @param object $obj Must provide gv() or $properties[]
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
     * @param mixed $value Must be either string, array (values only), float/int
     * @return bool
     */
    public function registerConstant($name, $value) {
        if (!$this->isValidName($name))   return false;

        // detect type
        $type = false;
        if (is_string($value))
            $this->constants[$name] = self::fromString($value);
        else if (is_numeric($value))
            $this->constants[$name] = self::fromFloat($value);
        else if (is_array($value))
            $this->constants[$name] = self::fromArray($value);
        else
            return false;

        return true;
    }
    
    /**
     * Parse multiple expressions, possibly assign results to variables
     * @param string $expr The expressions must be separated by the terminator char (typically ';')
     * @return object 
     */
    public function parseMultiple($expr) {
        $res = null;
        while (1) {
            $expr = $this->preprocess($expr);
            if (!is_string($expr))    return null; // TODO error

            $this->errno = 0;
            $res = $this->parse($expr);
            if (!$res || $res->type === 'error')  break;
            
            // check if there is another expression
            $expr = substr($this->input, $this->index);
            if (substr($expr, 0, 1) == $this->terminator)
                $expr = substr($expr, 1);
            
            if (!$expr)   break;
        }
        return $res;
    }

    /**
     * Parse an expression
     * @param string $expr
     * @return object 
     */
    public function parse($expr) {
        $expr = $this->preprocess($expr); // strip unnecessary spaces
        if (!is_string($expr))    return null;

        $input = $this->input; // in case the parser is called recursively
        $index = $this->index;
        
        $this->input = $expr;
        $this->index = 0;
        $this->errno = 0;

        $res = $this->expr();

        $this->input = $input;
        $this->index = $index;
        if ($res === null) {
            $this->err(6, "Failed to parse the expression");
            return (object)[ 'type' => 'error', 'value' => $this->errno, 'name' => $this->errtxt ];
        }

        return $res;
    }
    
    /**
     * Read/set/check a variable
     * @param string $op What to do, either 'read' or 'set' or 'check'
     * @param string $var The name of the variable
     * @param object $value The value to set (if $op='set')
     */
    private function varOp($op, $var, $value = false) {
        $rval = null;
        if (is_callable($this->cfg['variablefn'])) {
            $param = isset($this->cfg['variablefn_arg']) ? $this->cfg['variablefn_arg'] : null; 
            $rval = call_user_func($this->cfg['variablefn'], $op, $var, $value, $param, $this);
        }
        
        // simple variable handling
        if ($op == 'set' && !$rval)
            $this->variables[$var] = $value;
        else if ($op == 'check' && !$rval) {
            if (isset($this->variables[$var]))
                return $this->variables[$var];
        } else if ($op == 'read' && !$rval) {
            // last resort, check for built in variables
            if (isset($this->variables[$var])) 
                return $this->variables[$var];
        }
        return $rval;
    }

    /**
     * Strip space outside quotes and do a few checks
     * @param string $expr
     * @return mixed
     */
    public function preprocess($expr) {
        $res = '';
        $inquote = false;
        $esc = false;
        for ($i = 0; $i < strlen($expr); $i++) {
            $c = substr($expr, $i, 1);
            if ($c == '"') {
                $res .= $c;
                $inquote = !$inquote;
            } else if ($c != ' ' || $inquote)
                $res .= $c;
        }
        if ($inquote)   return $this->err(1, "Dangling quote");
        if ($esc)       return $this->err(10, "Dangling \\");
        if ($res === '')      return $this->err(2, "Empty expression");
        return $res;
    }
    
    /**
     * Register the most common maths functions
     */
    public function enableMathsFunctions() {
        foreach (['sin','cos','tan','asin','acos','atan','log','ln','sqrt','exp','floor','ceil','round','abs'] as $fn)
            $this->registerFunction($fn, 'Parser::mathsfns', 1, 1);
        $this->registerFunction('pow', 'Parser::mathsfns', 2, 2);
        $this->registerFunction('random', 'Parser::mathsfns', 0, 2);
        $this->registerFunction('min', 'Parser::mathsfns', 1, 100);
        $this->registerFunction('max', 'Parser::mathsfns', 1, 100);
        $this->registerFunction('sum', 'Parser::mathsfns', 1, 100);
        
        $this->constants['PI'] = self::fromFloat(3.141592653589793);
        $this->constants['e'] = self::fromFloat(2.718281828459045);
    }

    public function enableTimeFunctions() {
        $this->registerFunction('time', 'Parser::timefns', 0, 0);
        $this->registerFunction('date', 'Parser::timefns', 1, 2);
        $this->registerFunction('strtotime', 'Parser::timefns', 1, 1);
        $this->registerFunction('dateadjust', 'Parser::timefns', 2, 2);
        $this->registerFunction('timeadjust', 'Parser::timefns', 2, 2);
    }
    
    public function enableMiscFunctions() {
        $this->registerFunction('get_class', 'Parser::miscfns', 1, 1);
        $this->registerFunction('typeof', 'Parser::miscfns', 1, 1);
        $this->registerFunction('caseof', 'Parser::miscfns', 2, 100);
        $this->registerFunction('pie', 'Parser::miscfns', 1, 100);
        $this->registerFunction('bar', 'Parser::miscfns', 1, 100);
        $this->registerFunction('table', 'Parser::miscfns', 1, 100);
    }
    
    public function enableStringFunctions() {
        foreach (['tolower','toupper','length'] as $fn)
            $this->registerFunction($fn, 'Parser::stringfns', 1, 1);
        $this->registerFunction('replace', 'Parser::stringfns', 3, 3);
        $this->registerFunction('substr', 'Parser::stringfns', 2, 3);
        $this->registerFunction('sprintf', 'Parser::stringfns', 1, 100);
        $this->registerFunction('explode', 'Parser::stringfns', 2, 2);
        $this->registerFunction('implode', 'Parser::stringfns', 2, 2);
    }

    /**
     * Convert an internal value (ie an object) to an int
     * @param object $val
     * @return int
     */
    public static function toInt($val) {
        if ($val === null)    return null;
        if ($val->type == 'number')   return (int)$val->value;
        if ($val->type == 'string')   return (int)$val->value;
        return null;
    }
    /**
     * Convert an internal value (ie an object) to a float
     * @param object $val
     * @return float
     */
    public static function toFloat($val) {
        if ($val === null)    return null;
        if ($val->type == 'number')   return (float)$val->value;
        if ($val->type == 'string')   return (float)$val->value;
        return null;
    }

    /**
     * Convert an internal value (ie an object) to a string
     * @param object $val
     * @return string
     */
    public static function toString($val) {
        if ($val === null)    return null;
        if ($val->type == 'number')   return (string)$val->value;
        if ($val->type == 'string')   return $val->value;
        return null;
    }

    /**
     * Convert an internal value (ie an object) of type array to
     * an array of internal values (strings)
     * @param object $val
     * @return array<object> 
     */
    public static function toStringArray($val) {
        if ($val === null || $val->type != 'array')   return [];
        $res = [];
        foreach ($val->value as $v) {
            $s = self::toString($v);
            if ($s === null)   $s = self::fromString('');
            $res[] = $s;
        }
        return $res;
    }

    /**
     * Convert a float to an internal value (ie an object)
     * @param float $val
     * @return object
     */
    public static function fromFloat($val) {
        if ($val === null)    return null;
        return (object)[ 'type' => 'number', 'value' => $val ];
    }

    /**
     * Convert a string to an internal value (ie an object)
     * @param string $val
     * @return object
     */
    public static function fromString($val) {
        if ($val === null)    return null;
        return (object)[ 'type' => 'string', 'value' => $val ];
    }
    
    /**
     * Convert an array to an internal value (ie an object)
     * @param array $val
     * @return object
     */
    public static function fromArray($val) {
        if ($val === null || !is_array($val))    return null;
        $arr = [];
        foreach ($val as $v) {
            if (is_string($v))
                $arr[] = self::fromString($v);
            else if (is_numeric($v))
                $arr[] = self::fromFloat($v);
        }
        return (object)[ 'type' => 'array', 'value' => $arr ];
    }

    /**
     * Handler for misc functions
     */
    private static function basefns($fn, $argv, $param, $parser) {
        switch ($fn) {
            case 'firstof': // res = firstof(<arg1>,...)
                foreach ($argv as $arg) {
                    switch ($arg->type) {
                        case 'array':
                            if (count($arg->value) > 0)
                                return $arg;
                            breaK;
                        case 'number':
                        case 'string':
                            if ($arg->value)    return $arg;
                            break;
                    }
                }
                break;
        }
        return null;
    }
    
    /**
     * Handler for a bunch of time functions
     */
    private static function timefns($fn, $argv, $param, $parser) {
        switch ($fn) {
            case 'date': // string = date([<format>,[<timestamp>]])
                $ts = time();
                $format = 'Y-m-d H:i:s';
                if (count($argv) >= 1)   $format = self::toString($argv[0]);
                if (count($argv) >= 2)   $ts = self::toInt($argv[1]);
                if ($ts === null || $format === null)   return null;
                return self::fromString(date($format, $ts));
            case 'time': // number = time()
                return self::fromFloat(time());
            case 'strtotime': // number = strtotime(<string>)
                return self::fromFloat(strtotime(self::toString($argv[0])));
            case 'timeadjust': // number = timeadjust(<timestamp>, <string>)
                $t = date('Y-m-d H:i:s', self::toInt($argv[0]));
                $t = $parser->dateadjust($t, self::toString($argv[1]));
                return self::fromFloat(strtotime($t));
            case 'dateadjust': // string = dateadjust(<date>, <string>)
                $hastime = strlen(self::toString($argv[0])) == 19;
                $t = $parser->dateadjust(self::toString($argv[0]), self::toString($argv[1]));
                return self::fromFloat(substr($t, 0, $hastime ? 19 : 10));
        }
        return null;
    }
    
    /**
     * Modify a date+time in various ways
     * @param string $date Date+time, Y-m-d H:i:s
     * @param string $actions List of actions
     * @return string Date+time, Y-m-d H:i:s
     */
    private function dateadjust($date, $actions) {
        $weekdays = [ 'mon','tue','wed','thu','fri','sat','sun'];
        $months = [ 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec' ];
        $units = [ 'yr','mo','da','wk','hr','mi','se' ];

        $ts = strtotime($date);
        foreach (explode(' ', $actions) as $action) {
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
    }
        
    /**
     * Handler for a bunch of string functions
     */
    private static function stringfns($fn, $argv, $param, $parser) {
        switch ($fn) {
            case 'implode': // string = implode(<string>,<array>)
                if ($argv[0]->type != 'string' || $argv[1]->type != 'array')   return $parser->err(7, "Invalid argument to $fn()");
                $parts = self::toStringArray($argv[1]);
                return self::fromString(implode(self::toString($argv[0]), $parts));
            case 'explode': // array = explode(<string>,<string>)
                if ($argv[0]->type != 'string' || $argv[1]->type != 'string')   return $parser->err(7, "Invalid argument to $fn()");
                $parts = explode(self::toString($argv[0]), self::toString($argv[1]));
                $res = [ 'type' => 'array', 'value' => [] ];
                for ($i = 0; $i < count($parts); $i++)   $res['value'][] = self::fromString($parts[$i]);
                return (object)$res;
            case 'length': // number = length(<string>), number = length(<array>)
                if ($argv[0]->type == 'array')
                    return self::fromFloat(count($argv[0]->value));
                else if ($argv[0]->type == 'dict')
                    return self::fromFloat(count(array_keys($argv[0]->value)));
                $arg = self::toString($argv[0]);
                if ($arg === null)    return null;
                return self::fromFloat(mb_strlen($arg));
            case 'tolower': // string = tolower(<string>)
                $arg = self::toString($argv[0]);
                if ($arg === null)    return null;
                return self::fromString(mb_strtolower($arg));
            case 'toupper': // string = toupper(<string>)
                $arg = self::toString($argv[0]);
                if ($arg === null)    return null;
                return self::fromString(mb_strtoupper($arg));
            case 'substr': // string = substr(<string>,<int>), string = substr(<string>,<int>,<int>)
                $string = self::toString($argv[0]);
                $offset = self::toInt($argv[1]);
                $len = 100000;
                if (count($argv) == 3)     $len = self::toInt($argv[2]);
                if ($offset === null || $len === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromString(mb_substr($string, $offset, $len));
            case 'sprintf': // string = sprintf(<string>,<arg1>...)
                $format = self::toString($argv[0]);
                $args = [];
                for ($i = 1; $i < count($argv); $i++)   $args[] = $argv[$i]->value;
                return self::fromString(vsprintf($format, $args));
            case 'replace': // string = replace(<from>, <to>, <string>)
                return str_replace(self::toString($argv[0]),
                                   self::toString($argv[1]),
                                   self::toString($argv[2]));
        }
        return null;
    }


    private static function miscfns($fn, $argv, $param, $parser) {
        switch ($fn) {
            case 'get_class':
                if (count($argv) != 1)   return $parser->err(7, "Invalid argument to $fn()");
                if ($argv[0]->type != 'object')   return $parser->err(7, "Invalid argument to $fn()");
                return self::fromString($argv[0]->value->cls);
                
            case 'typeof': // string = typeof(<value>)
                if (count($argv) != 1)   return $parser->err(7, "Invalid argument to $fn()");
                return self::fromString($argv[0]->type);

            case 'caseof': // value = caseof(<input>,<option1>[,<option2]...)
                $in = self::toInt($argv[0]);
                if ($in >= 1 && $in < count($argv))   return $argv[$in];
                return self::fromString(''); // return empty string if there is no match

            case 'table':
                if (count($argv) == 0)   return $parser->err(7, "Invalid argument to $fn()");
                $table = [];
                $cols = 0;
                // find max table row length
                for ($i = 0; $i < count($argv); $i++)
                    if ($argv[$i]->type != 'array')
                        return $parser->err(7, "Invalid argument to $fn()");
                    if (count($argv[$i]->value) > $cols)
                        $cols = count($argv[$i]->value);
                
                for ($i = 0; $i < count($argv); $i++) {
                    $row = $parser->toStringArray($argv[$i]);
                    // make sure all rows have the same length
                    for ($col = count($row); $col < $cols; $col++)
                        $row[] = '';
                    $table[] = $row;
                }
                return (object)[ 'type' => 'data',  'datatype' => 'table', 'value' => $table ];

            case 'pie': // object = pie(<values>[,<labels>], object = pie(<value1>[,value2>...])
            case 'bar':
                $values = [];
                $labels = [];
                if ($argv[0]->type == 'array') {
                    $vs = $argv[0];
                    foreach ($vs->value as $arg)
                        $values[] = self::toFloat($arg);
                    if (count($argv) == 2 && $argv[1]->type == 'array')
                        $labels = self::toStringArray($argv[1]);
                    foreach ($values as $key => $v)
                        if (!isset($labels[$key]))   $labels[$key] = '';
                } else {
                    for ($i = 0; $i < count($argv); $i++)
                        $values[] = self::toFloat($argv[$i]);
                }

                return (object)[ 'type' => 'data',  'datatype' => $fn, 'value' => $values, 'labels' => $labels ];
        }
        return null;
    }
    
    
    /**
     * Handler for a bunch of maths functions
     */
    private static function mathsfns($fn, $argv, $param, $parser) {
        switch ($fn) {
            case 'random': // float = random(), float = random(<max>), int = random(<min>,<max>)
                $ceil = 1000000;
                $r = rand(0, $ceil);
                // 0 args
                if (count($argv) == 0)    return self::fromFloat($r/$ceil);
                // 1 arg
                if (count($argv) == 1) {
                    $max = self::toInt($argv[0]);
                    if ($max === null)    return $parser->err(7, "Invalid argument to $fn()");
                    return self::fromFloat($max*$r/$ceil);
                }
                // 2 args
                $min = self::toInt($argv[0]);
                $max = self::toInt($argv[1]);
                if ($min === null || $max === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat((int)($min + ($max-$min)*$r/$ceil));
            
            case 'min': // number = min(<num1>, ...), number = min(<array>)
                $res = null;
                if ($argv[0]->type == 'array')
                    $argv = $argv[0]->value;
                for ($i = 0; $i < count($argv); $i++) {
                    $arg = $argv[$i];
                    if ($arg->type != 'string' && $arg->type != 'number')   continue;
                    if ($res === null || $arg->value < $res->value)   $res = $arg;
                }
                if ($res === null)   return $parser->err(7, "Invalid argument to $fn()");
                return $res;
            case 'max': // number = max(<num1>, ....), number = max(<array>)
                $res = null;
                if ($argv[0]->type == 'array')
                    $argv = $argv[0]->value;
                for ($i = 0; $i < count($argv); $i++) {
                    $arg = $argv[$i];
                    if ($arg->type != 'string' && $arg->type != 'number')   continue;
                    if ($res === null || $arg->value > $res->value)   $res = $arg;
                }
                if ($res === null)   return $parser->err(7, "Invalid argument to $fn()");
                return $res;
            case 'sum': // number = sum(<num1>, ....), number = sum(<array>)
                $res = null;
                if ($argv[0]->type == 'array')
                    $argv = $argv[0]->value;
                $sum = 0;
                for ($i = 0; $i < count($argv); $i++) {
                    $arg = $argv[$i];
                    if ($arg->type != 'string' && $arg->type != 'number')   continue;
                    $sum += self::toFloat($arg);
                }
                return self::fromFloat($sum);
        
            case 'floor': // number = floor(<number>)
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(floor($arg));
            case 'ceil': // number = ceil(<number>)
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(ceil($arg));
            case 'round': // number = round(<number>)
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(round($arg));

            case 'sin':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(sin($arg));
            case 'cos':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(cos($arg));
            case 'tan':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(tan($arg));
            case 'asin':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(asin($arg));
            case 'acos':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(acos($arg));
            case 'atan':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(atan($arg));
            case 'log':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(log($arg));
            case 'ln':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(ln($arg));
            case 'sqrt':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(sqrt($arg));
            case 'exp':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(exp($arg));
            case 'abs':
                $arg = self::toFloat($argv[0]);
                if ($arg === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(abs($arg));
            case 'pow': // number = pow(<number>, <number>)
                $arg1 = self::toFloat($argv[0]);
                $arg2 = self::toFloat($argv[1]);
                if ($arg1 === null || $arg2 === null)    return $parser->err(7, "Invalid argument to $fn()");
                return self::fromFloat(pow($arg1, $arg2));
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

        if (!$res)   return null;
        
        $match = $matches[0];
        if (!$match || !is_array($match) || !$match[1] == 0)   return null;

        $this->index += strlen($match[0]);
        return $match[0];
    }

    private $assign_ops = ['=', '+=', '-=', '*=', '|=', '&=', '^='];
    private function binOp($leftval, $op, $rightval) {
        if (in_array($op, $this->assign_ops)) {
            $res = false;
            switch ($op) {
                case '=':
                    $res = $rightval;
                    break;
                case '+=':
                case '-=':
                case '*=':
                case '/=':
                case '&=':
                case '|=':
                case '^=':
                    $res = $this->binOp($leftval, substr($op, 0, 1), $rightval);
                    break;
            }
            if ($res) { // update the object on the left side, in case it is a variable etc
                $leftval->type = $res->type;
                $leftval->value = $res->value;
            }
            return $leftval;
        }
        
        $fn = isset($this->cfg['overloadfn'][$op]) ? $this->cfg['overloadfn'][$op] : '';
        if (is_callable($fn)) {
            $arg = isset($this->cfg['overloadfn_arg'][$op]) ? $this->cfg['overloadfn_arg'][$op] : null; 
            $res = call_user_func($fn, $leftval, $op, $rightval, $arg, $this);
            // the overload fn should return false to use the builtin handler
            if ($res !== false)    return $res;
        }

        if ($op == '+' && $leftval->type == 'string' && $rightval->type == 'string') {
            // + on string
            return self::fromString($leftval->value.$rightval->value);
        }
        
        // the rest only works for numbers and strings
        if (($leftval->type  != 'number' && $leftval->type  != 'string') ||
            ($rightval->type != 'number' && $rightval->type != 'string'))
            return self::fromFloat(0);

        $l = self::toFloat($leftval);
        $r = self::toFloat($rightval);
        $li = self::toInt($leftval);
        $ri = self::toInt($rightval);
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
                if ($r == 0) {
                    // allow division by zero
                    if ($l < 0)         $l = -self::INFINITY;
                    else if ($l > 0)    $l =  self::INFINITY;
                } else
                    $l /= $r;
                break;
            case '%':
                $l %= $r;
                break;
            case ':': // <number>:<number> returns a range between the two numbers
                if ($leftval->type != 'number' || $rightval->type != 'number')   return null;
                if ($li != $l || $ri != $r)   return null;
                $res = [];
                for ($i = $li; $i <= $ri; $i++)   $res[] = (object)['type' => 'number', 'value' => $i];
                return (object)[ 'type' => 'array', 'value' => $res];

            case '&&':
                $l = ($li && $ri) ? 1:0;
                break;
            case '||':
                $l = ($li || $ri) ? 1:0;
                break;
            case '|':
                $l = $li | $ri;
                break;
            case '&':
                $l = $li & $ri;
                break;
            case '^':
                $l = $li ^ $ri;
                break;

            case '===':
            case '!==':
                if ($leftval->type == $rightval->type) {
                    // check if the values match
                    if ($leftval->type == 'number')
                        $l = (round($l,10) == round($r,10)) ? 1:0;
                    else
                        $l = ($leftval->value == $rightval->value);
                } else
                    $l = 0;
                if ($op == '!==')   $l = $l ? 0:1;
                break;
            case '==':
            case '!=':
                $l = (round($l,10) == round($r,10)) ? 1:0;
                if ($op == '!=')    $l = $l ? 0:1;
                break;
            case '>=':
                if ($leftval->type == 'number' || $rightval->type == 'number')
                    $l = ($l >= $r) ? 1:0;
                else
                    $l = $leftval->value >= $rightvalue->value;
                break;
            case '<=':
                if ($leftval->type == 'number' || $rightval->type == 'number')
                    $l = ($l <= $r) ? 1:0;
                else
                    $l = $leftval->value <= $rightvalue->value;
                break;
            case '>':
                if ($leftval->type == 'number' || $rightval->type == 'number')
                    $l = ($l > $r) ? 1:0;
                else
                    $l = $leftval->value > $rightvalue->value;
                break;
            case '<':
                if ($leftval->type == 'number' || $rightval->type == 'number')
                    $l = ($l < $r) ? 1:0;
                else
                    $l = $leftval->value < $rightvalue->value;
                break;
        }
        return self::fromFloat($l);
    }
    
    private function expr() {
        return $this->exprLOGICAL();
    }
    
    private function exprLOGICAL() {
        $leftval = $this->exprBITWISE();
        if ($leftval === null)   return null;

        $ops = ['||','&&'];

        while (true) {
            $backtrace = $this->index;
            
            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index, 2);
            $this->index += 2;
            if (in_array($op, $ops) && ($rightval = $this->exprBITWISE()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            }
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }
    
    private function exprBITWISE() {
        $leftval = $this->exprCOMPARISON();
        if ($leftval === null)   return null;

        $ops = ['|','&','^'];

        while (true) {
            $backtrace = $this->index;
            
            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index++, 1);
            if (in_array($op, $ops) && ($rightval = $this->exprCOMPARISON()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            }
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }

    /**
     * Check for comparison expression:  <expression> "==" <expression>
     * @return mixed
     */
    private function exprCOMPARISON() {
        $leftval = $this->exprPLUSMINUS();
        if ($leftval === null)   return null;

        $cmps = [ '==','!=','<','<=','>','>=', '===', '!=='];
        $cmps = array_merge($cmps, $this->assign_ops);

        while (true) {
            $backtrace = $this->index;
            
            $res = false;
            $rightval = '';
            // look for 1-char, 2-chars or 3-chars comparison operator
            $one = substr($this->input, $this->index++, 1);
            $two = $one.substr($this->input, $this->index++, 1);
            $three = $two.substr($this->input, $this->index++, 1);
            $op = $three;
            if (!in_array($op, $cmps)) {
                $op = $two;   $this->index--;
                if (!in_array($op, $cmps)) {  $op = $one;  $this->index--;  }
            }
            if ($op && in_array($op, $cmps) && ($rightval = $this->exprPLUSMINUS()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            }
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }

    /**
     * Check for +- expression:  <expression> "+" <expression>
     * @return mixed
     */
    private function exprPLUSMINUS() {
        $leftval = $this->exprMULDIV();
        if ($leftval === null)   return null;

        while (true) {
            $backtrace = $this->index;
            
            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index++, 1);
            if ($op && strpos("+-", $op) !== false && ($rightval = $this->exprMULDIV()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            }
            
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
            $backtrace = $this->index;

            $res = false;
            $rightval = '';
            $op = substr($this->input, $this->index++, 1);
            if ($op && strpos("*/%:", $op) !== false && ($rightval = $this->exprDOT()) !== null) {
                $leftval = $this->binOp($leftval, $op, $rightval);
                if ($leftval === null)   return null;
                $res = true;
            }
            
            if (!$res) {
                $this->index = $backtrace;
                return $leftval;
            }
        }
        return null;
    }
    
    /**
     * Read a value from an array or dict
     * @param object $leftval A value or type 'array' or 'dict'
     * @return object
     */
    private function accessDictArray($leftval) {
        if ($leftval === null)   return null;

        if ($leftval->type == 'array') {
            // eg. "array_variable[1+2]"
            // an array may be followed by [N] to get the Nth element
            if (substr($this->input, $this->index, 1) == '[') {
                $ar = $this->factor_array();
                if ($ar === null || $ar->type != 'array')
                    return null;
                if (count($ar->value) != 1 || $ar->value[0]->type != 'number')
                    return $this->err(11, "Bad indexing");
                $idx = self::toInt($ar->value[0]);
                if ($idx < 0 || $idx >= count($leftval->value))
                    return self::fromString("");
                return $leftval->value[$idx];
            }

        } else if ($leftval->type == 'dict') {
            // eg.  'dict_variable.somekey'   or  'dict_variable["somekey"]'
            // a dict must be followed by . and then a key
            if (substr($this->input, $this->index, 1) === '.') {
                $this->index++; // skip .
                // get the key name of the value being accessed
                $key = $this->regExp($this->regexp_prop);
                if ($key === null)
                    return null;
                if (!isset($leftval->value[$key]))
                    return $this->err(16, "Key '$key' not found in dict");
                return $leftval->value[$key];

            } else if (substr($this->input, $this->index, 1) === '[') {
                $ar = $this->factor_array();
                if ($ar === null || $ar->type != 'array')
                    return null;
                if (count($ar->value) != 1 || $ar->value[0]->type != 'string')
                    return $this->err(11, "Bad indexing");
                $key = self::toString($ar->value[0]);
                if (!isset($leftval->value[$key]))
                    return $this->err(16, "Key '$key' not found in dict");
                return $leftval->value[$key];
            }
            
        } else {
            if ($leftval->type != 'object')     return $leftval;
        }
        return $leftval;
    }
        
    /**
     * Check for property/element access:  <varname> "." <expression>  or
     *                                     <varname> "[" <expression> "]"
     * @return mixed
     */
    private function exprDOT() {
        // try if this is an object/dict name
        $leftval = $this->factor_obj();

        if ($leftval === null) {
            $leftval = $this->factor();
            
            $res = $this->accessDictArray($leftval);
            if (!$res)   return null;
            if ($res->type != 'object')    return $res;
        }

        while (true) {
            // eg.  "object_dict_variable.propname"
            // object/dict must be followed by .
            $op = substr($this->input, $this->index, 1);
            if ($op !== '.')    return $leftval;

            $backtrace = $this->index;
            $this->index++; // skip .

            $prop = $this->factor_prop($leftval);
            if ($prop !== null) {
                $leftval = $prop;
                if ($leftval->type != 'object' && $leftval->type != 'dict')
                    return $leftval;
            } else {
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
        $res = $this->factor_array();
        if ($res !== null)    return $res;
        $res = $this->factor_dict();
        if ($res !== null)    return $res;
        $res = $this->factor_var();
        if ($res !== null)    return $res;
        $res = $this->factor_expr();
        if ($res !== null)    return $res;
        return null;
    }
    
    /**
     * Check for number: <number>
     * @return object Returns an object of type 'number', or null if nothing was found
     */
    private function factor_num() {
        $str = $this->regExp($this->regexp_number);
        if ($str === null)   return null;
        return self::fromFloat((float)$str);
    }
        
    /**
     * Check for string: " <chars> "
     * @return object Returns an object of type 'string', or null if nothing was found
     */
    private function factor_str() {
        if (substr($this->input, $this->index, 1) != '"')    return null;
        $backtrace = $this->index;
        $this->index++; // skip the 2
        
        $res = '';
        while (true) {
            $c = substr($this->input, $this->index++, 1);
            if ($c == '') {
                $this->index = $backtrace;
                return null;
            }
            if ($c == '"')    return self::fromString($res);
            
            if ($c == '\\') {
                $c = substr($this->input, $this->index++, 1);
                switch ($c) {
                    case 'n':    $res .= "\n";   break;
                    case 'r':    $res .= "\r";   break;
                    case 't':    $res .= "\t";   break;
                    case 'b':    $res .= "\b";   break;
                    case '"':    $res .= "\"";   break;
                    case '\\':   $this->index--;  $res .= "\\";   break;
                    case 'u':
                        $res .= mb_chr(hexdec(substr($this->input, $this->index, 4)));
                        $this->index += 4;
                        break;
                }
            } else
                $res .= $c;
        }
        return null;
    }
    
    /**
     * Check if a string is a valid variable/constant/object name
     * @param string $name
     * @return bool
     */
    public function isValidName($name) {
        if (!$name)   return false;
        $matches = [];
        $res = preg_match($this->regexp_name, $name, $matches, PREG_OFFSET_CAPTURE);
        if (!$res)   return false;
        
        $match = $matches[0];
        if (!$match || !is_array($match) || !$match[1] == 0)   return false;
        return true;
    }

    /**
     * Check for object property or dict element, ie. something following the . operator
     * @param object $obj The object/dict
     * @return object Returns an object with the prop value, or null if nothing was found
     */
    private function factor_prop($obj) {
        $backtrace = $this->index;
        $prop = $this->regExp($this->regexp_prop);
        if ($prop === null)    return null;

        if ($obj->type == 'dict') {
            if (isset($obj->value[$prop]))
                return $obj->value[$prop];
            $this->index = $backtrace;
            return null;
        }

        // check if prop exists in object
        $proplist = false;
        if (isset($obj->value->properties))
            $proplist = $obj->value->properties;
  
        // if the object provides a properties[] array with fixed props...
        if (is_array($proplist) && isset($proplist[$prop])) {
            $propval = $proplist[$prop];
            if (is_string($propval))
                return self::fromString($propval);
            if (is_numeric($propval))
                return self::fromFloat($propval);
            if (is_callable($propval)) {
			    if (substr($this->input, $this->index, 1) != '(')
                    return null;
                $this->index++; // skip over (
				// parse args, then call fn
				$argv = $this->factor_exprlist();
				if ($argv === null)
					return null;

                $index = $this->index;
                $input = $this->input;
				$res = call_user_func($propval, $prop, $argv);
                $this->input = $input;
                $this->index = $index;
				return $res;
			}
            if (is_object($propval) && $propval->type)
                return $propval;
            return null;
        }

        // if there is no properties[] array then use gv() to read the prop from the object
        if (!is_object($obj->value) || !method_exists($obj->value, 'gv'))
            return $this->err(9, "Invalid object");

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

        $index = $this->index;
        $input = $this->input;
        $res = call_user_func($propval->fn, $prop, $argv);
        $this->input = $input;
        $this->index = $index;
        return $res;
    }

    /**
     * Check for name of existing object or dict
     * @return object Returns an object of type 'object' or 'dict', or null if nothing was found
     */
    private function factor_obj() {
        $backtrace = $this->index;
        $objname = $this->regExp($this->regexp_name);
        if (!$objname)    return null;

        $var = $this->varOp('check', $objname);
        if ($var) {
            if ($var->type == 'dict' || $var->type == 'object')
                return $var;
            $this->index = $backtrace;
            return null;
        }
        
        if (!isset($this->objects[$objname])) {
            $this->index = $backtrace;
            return null;
        }
        return (object)[ 'type' => 'object', 'value' => $this->objects[$objname] ];
    }

    /**
     * Check for variables/constants/object reference: <name>
     * @return object Returns an object with the value, or null if nothing was found
     */
    private function factor_var() {
        $backtrace = $this->index;
        $str = $this->regExp($this->regexp_name);
        if (!$str)    return null;

        // check for constants
        if (isset($this->constants[$str])) 
            return $this->constants[$str];
        $var = $this->varOp('read', $str);
        if (!$var)   $this->index = $backtrace;
        return $var;
    }

    /**
     * Check for expression: "(" <expression> ")"
     * @return object Returns null if nothing was found
     */
    private function factor_expr() {
        $val = null;
        $backtrace = $this->index;
        $res = ($this->char('(') && ($val = $this->expr()) !== null && $this->char(')')) ? $val : null;
        if (!$res)    $this->index = $backtrace;
        return $res;
    }
    
    /**
     * Check for function: <name> "(" <expression> ")"
     * @return object Returns an object with the value of the fn call, or null if nothing was found or on error
     */
    private function factor_func() {
        $fn = $this->regExp($this->regexp_func);
        if ($fn === null)    return null;

        // strip trailing (
        $fn = substr($fn, 0, strlen($fn)-1);

        if (!isset($this->functions[$fn]))
            return $this->err(3, "Unknown func: $fn");
        $fndef = $this->functions[$fn];

        // parse arguments
        $argv = $this->factor_exprlist(')');
        if ($argv === null)    return null;

        // validate arguments
        if (count($argv) < $fndef->min || count($argv) > $fndef->max)
            return $this->err(4, "Invalid arg count for fn: $fn");
        $index = $this->index;
        $input = $this->input;
        $res = call_user_func($fndef->fn, $fn, $argv, $fndef->param, $this);
        $this->index = $index;
        $this->input = $input;
        return $res;
    }

    /**
     * Check for dict:  "{" <name>: <value>, <name>: <value> "}"
     * @return object Returns an object of type 'dict', or null if nothing was found
     */
    private function factor_dict() {
        if (substr($this->input, $this->index, 1) != '{')   return null;
        
        $backtrace = $this->index;
        $this->index++;

        // parse arguments
        $argv = $this->factor_exprlist('}', true);
        if ($argv === null)
            return null;
        return (object)[ 'type' => 'dict', 'value' => $argv ];
    }
    
    /**
     * Check for array:  "[" <expression> [,<expression> ...] "]"
     * @return object Returns an object of type 'array', or null if nothing was found
     */
    private function factor_array() {
        if (substr($this->input, $this->index, 1) != '[')   return null;
        
        $backtrace = $this->index;
        $this->index++;

        // parse arguments
        $argv = $this->factor_exprlist(']');
        if ($argv === null)
            return null;
        return (object)[ 'type' => 'array', 'value' => $argv ];
    }

    /**
     * Read a list of expressions, index must point to first char after (
     * @param string $end Which char to use as terminator
     * @param bool $dict True to read a dictionary
     * @return array<object> Returns an array of objects, or null if nothing was found
     */
    private function factor_exprlist($end = ')', $dict = false) {
        $argv = [];
        while (1) {
            $next = substr($this->input, $this->index, 1);
            if ($next == $end || $next == '') {
                // end of arg list
                if ($next == $end)   $this->index++;
                return $argv;
            }
            
            $key = false;
            if ($dict) {
                // each item is key:value
                if ($next == '"') {
                    // keys is any string
                    $key = $this->factor_str();
                    if ($key)
                        $key = $key->value;
                } else {
                    // key is a number or a name
                    $key = $this->regExp($this->regexp_name);
                    if (!$key) { // not a name, try a number
                        $key = $this->factor_num();
                        if ($key)   $key = $key->value;
                    }
                }
                if (!$key)
                    return $this->err(14, "Missing name in dictionary");
                $next = substr($this->input, $this->index, 1);
                if ($next != ':')
                    return $this->err(15, "Missing : in dictionary");
                $this->index++;
            }
            
            // parse an argument
            $arg = $this->expr();
            if ($arg === null)
                return null;
    
            if ($key)
                $argv[$key] = $arg;
            else
                $argv[] = $arg;
            // check if there are more arguments
            if (substr($this->input, $this->index, 1) == ',')
                $this->index++; // skip , to next arg
        }
    }
}
 
?>
