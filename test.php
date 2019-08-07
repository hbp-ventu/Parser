<?php
ini_set('display_errors', 'On');


include_once("Parser.php");


/**
 * Function that will be called to read variables 
 */
function lookup_variable_fn($name) {
    if ($name == "myvariable")   return (object)[ 'type' => 'number', 'value' => 1.234 ];
    return null;
}

$p = new Parser('lookup_variable_fn');
$p->registerConstant('randmax', 10);
$p->enableMathsFunctions();
$p->enableTimeFunctions();
$p->enableStringFunctions();


/**
 * Register an object with 3 properties; 'myfn', 'mynumber' and 'myobject'
 */
$obj_this = new class {
    public function gv($prop, $argv = []) {
        switch ($prop) {
            case 'myfn':
                 return (object)[ 'type' => 'function',
                                  'min' => 1,
                                  'max' => 1,
                                  'fn' => function($fn, $argv) {
                                        return (object)['type' => 'string','value' => 'input was '.$argv[0]->value];
                                      }
                                ];
            case 'mynumber':
                return (object)[ 'type' => 'number', 'value' => 44 ];
            case 'myobject':
                $myobject = new class() {
                    public $properties = [ 'a' => 123 ];
                };
                return (object)[ 'type' => 'object', 'value' => $myobject ];
        }
        return null;
    }
    public function getType($prop) {
        switch ($prop) {
            case 'myfn':       return 'function';
            case 'mynumber':   return 'number';
            case 'myobject':   return 'object';
        }
        return null;
    }
};
$p->registerObject('this', $obj_this);


/**
 * Register a function which returns an object,
 */
function myfn($fnname, $argv) {
    global $obj_this;
    return (object)[ 'type' => 'object', 'value' => $obj_this ];
}
$p->registerFunction("getthis", "myfn", $min = 0, $max = 1);


// Test suite

$test = [
    '5*4-3*2' => 14,
    '5*(4-3)*2' => 10,
    'min(10,11,12*4,13,-4-7,15)' => -11,
    'sin(PI/4)' => 0.70710678,
    'asin(sin(PI/10))' => 0.314159,
    'myvariable*2' => 2.468,
    'sprintf("%.2f", 5/3)' => 1.67,
    'date("Y-m-d H:i:s")' => date('Y-m-d H:i:s'),
    'substr("--Str"+"ing--", 2,6)' => 'String',
    'this.myfn(10)' => 'input was 10',
    'getthis()' => 'object',
    'getthis().mynumber' => 44,
    'getthis().myfn' => 'function'
];

echo "<table>\n";
echo  "<tr>\n";
echo   "<th style='text-align: left;'>Expression</th><th style='text-align: left;'>Expected</th><th style='text-align: left;'>Result</th>";
echo  "</tr>\n";
foreach ($test as $t => $v) {
    $r = $p->parse($t);
    echo "<tr>\n";
    echo   "<td>$t</td>";
    echo   "<td>$v</td>";
    $v = $r->value;
    if (!is_scalar($v))   $v = "(non-scalar)";
    echo   "<td>(".$r->type.") ".$v."</td>"; 
    echo "</tr>\n";
}
echo "</table>\n";

 
?>
