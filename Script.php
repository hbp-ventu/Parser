<?php

class ScriptLine {
    public $lineno = 0; // lineno within the the script

    public $tokens = false; // array of tokens
    public $type = ''; // 'def' or 'if' etc etc
    public $level = 0;
    public $next = 0;
    public $data = false; // depends on the line type
    public $num_child_lines = 0; // num. lines that are at least 1 level further in
}

class ScriptIterator {
    private $start, $end, $step, $cur;
    
    public $cls = 'Iterator';
    
    public $properties = [];
    
    public function __construct($start = 0, $end = 0, $step = 1) {
        $this->start = (float)$start;
        $this->cur = (float)$start;
        $this->end = (float)$end;
        $this->step = (float)$step;
    }
    
    public function __next__() {
        if ($this->step == 0)    return Parser::fromFloat($this->start);
        if ($this->step < 0) {
            if ($this->cur < $this->end)   return false;
            $val = $this->cur;
            $this->cur += $this->step;
            return Parser::fromFloat($val);
        }
        if ($this->cur > $this->end)   return false;
        $val = $this->cur;
        $this->cur += $this->step;
        return Parser::fromFloat($val);
    }
}

class ScriptStringIterator extends ScriptIterator {
    private $text = '', $cur = 0;
    
    public function __construct($start = 0, $end = 0, $step = 1) {
        $this->text = (string)$start;
        $this->cur = 0;
    }
    
    public function __next__() {
        if ($this->cur >= strlen($this->text))    return false;
        $val = mb_substr($this->text, $this->cur++, 1);
        return Parser::fromString($val);
    }
}

class ScriptArrayIterator extends ScriptIterator {
    private $data = [], $cur = 0;
    
    public function __construct($start = 0, $end = 0, $step = 1) {
        if (!is_array($start))   return;
        $this->data = $start;
        $this->cur = 0;
    }
    
    public function __next__() {
        if ($this->cur >= count($this->data))    return false;
        $val = $this->data[$this->cur++];
        return $val;
    }
}

class ScriptDictIterator extends ScriptIterator {
    private $data = [], $cur = 0, $keys = [];
    
    public function __construct($start = 0, $end = 0, $step = 1) {
        if (!is_array($start))   return;
        $this->data = $start;
        $this->keys = array_keys($start);
        $this->cur = 0;
    }
    
    public function __next__() {
        while (1) { // try repeatedly, in case the dict has changed since initialization
            if ($this->cur >= count($this->keys))    return false;
            $key = $this->keys[$this->cur++];
            $val = $this->data[$key];
            if (is_object($val))   return $val;
        }
    }
}

class Script {
    private $lines = []; // all lines
    // execution
    private $blockstack = [];
    private $lineno = 0; // current lineno
    private $level = 0; // current block level

    // functions and parser
    private $functions = []; // lineno indexed by function name
    private $external_functions = []; // array [phpfn,min,max] indexed by fnname
    public $parser = false; // the Parser object
    
    // configuration
    private $spaces = 2; // number of spaces used for level indentation
    private $max_fn_args = 50;
    private $max_lines = 10000000; // max. number of lines to execute
    private $max_microseconds = 10000000; // prevent runaway scripts

    // profiling and stuff
    private $executed_lines = 0;
    private $starttime = 0; // milliseconds
    private $profile = []; // number of times each line has been executed, hashed by lineno

    public $infn = -1; // lineno of current function being executed, or -1
    private $returnvalue = false;

    public $errortext = '', $errorlineno = 0;
    public $stop_script = false; // used by exit() etc
   
    private $variables = []; // see this->registerVariable()
   
    const NEXT_LINE = '-1NEXTLINE';
    const ABORT = '-2ABORT'; // terminate script
    const ABORT_LOOP = '-3ABORTLOOP'; // terminate closest loop
    const FAIL = '-4FAIL';
    const END_OF_BLOCK = '-6ENDOFBLOCK';
    const END_IF = '-7ENDIF';
    const CONTINUE_LOOP = '-8CONTINUE';
    const END_OF_FN = '-9ENDFN';
   
    private static $reserved = ['def','for','in','while','return','if','else','elseif','break','continue',
                                'float','int','array','string','object','const','var','global','class',
                                'new','include'];
    private static $needblock = ['def','for','while','if','else','elseif'];
   
   
    public function __construct() {
        $this->addFunction('range', function($fnname, $argv, $param, $parser) {
            $step = 1;
            if (count($argv) == 1) {
                $from = 0;
                $to = Parser::toFloat($argv[0]);
            } else {
                $from = Parser::toFloat($argv[0]);
                $to = Parser::toFloat($argv[1]);
                if (count($argv) == 3)
                    $step = Parser::toFloat($argv[3]);
            } 
            return (object)['type' => 'object', 'value' => new ScriptIterator($from, $to, $step)];
        }, 1, 3);
    }
   
    /**
     * Add a function to the parser
     * @param string $fn The name of the function
     * @param function $callback The function to call
     * @return bool
     */
    public function addFunction($fn, $callback, $min = 0, $max = false) {
        if (!is_callable($callback))    return false;
        if ($max === false)   $max = $this->max_fn_args;
        $this->external_functions[$fn] = ['phpfn' => $callback, 'min' => (int)$min, 'max' => (int)$max];
        return true;
    }
   
    /**
     * Generate error
     * @param string $txt
     * @param int $lineno
     * @return bool Always returns false
     */
    private function err($txt = false, $lineno = false) {
        if ($txt)     $this->errortext = $txt;
        if ($lineno)  $this->errorlineno = $lineno;
        echo $this->errortext." at line ".($this->errorlineno+1)."\n";
        return false;
    }

    /**
     * Add a line to the current block
     * @param string $line
     * @param int level The indentation
     * @return mixed Either true or an error string
     */
    private function addLine($linetext, $level) {
        $tokens = $this->tokenize($linetext);
        if (is_string($tokens))   return $tokens; // error

        if (count($tokens) == 0) {
            $tokens[] = '';
            if (count($this->lines))
                $level = $this->lines[count($this->lines)-1]->level;
        }
        
        $last = $tokens[count($tokens)-1];
        
        $line = new ScriptLine();
        $line->lineno = count($this->lines);
        $line->tokens = $tokens;
        $line->level = $level;
        $line->type = $tokens[0];
        if (count($tokens) > 0 && $tokens[0] == 'def')
            if ($level != 0)    return 'function def must not be indented';

        $this->lines[] = $line;
        $this->level = $level;
        return true;
    }
    
    /**
     * Check if a name is a valid function/variable name
     * @param string $name
     * @return bool
     */
    public function isValidName($name) {
        if (in_array($name, self::$reserved))   return false;
        return preg_match("/^[A-Za-z][A-Za-z0-9_]*$/", $name) == 1;
    }
   
    /**
     * Load a script in the form of string, lines are terminated by \n
     * @param string $s
     * @return bool 
     */
    public function loadString($s) {
        $level = 0;
        if (!$this->parser)
            $this->parser = new Parser();

        $indent = str_repeat(' ', $this->spaces);
        $this->level = 0;
        foreach (explode("\n", $s) as $lno => $line) {
          // either:
          //   if <expr>
          //   elseif <expr>
          //   else
          //   while <expr>
          //   break
          //   continue
          //   for <var> in <expr>
          //   def <fn>([<varlist>])
          //   return <expr>
          //   <fn>([<exprlist>])
          //   <expr>
          //   // comment
          //   global <varlist>
          
            // remove most whitespace on right side
            while (in_array(substr($line, -1), ["\r","\n","\t"]))
                $line = substr($line, 0, strlen($line)-1);
            // indentation is always a multiple of $this->spaces
            $level = 0;
            while (substr($line, 0, $this->spaces) == $indent) {
                $level++;
                $line = substr($line, $this->spaces);
            }
            if (substr($line, 0, 1) == ' ')    return $this->err('Bad indentation', $lno);

            $rval = $this->addLine($line, $level);
            if (is_string($rval))   return $this->err($rval, $lno);
        }

        // for each line determine how many lines follow that are >= 1 level in;
        // empty lines are assumed to be at same level as the previous line
        foreach ($this->lines as $lineno => $line) {
            $level = $line->level;

            $n = 0;
            while (1) {
                $lno = $lineno+$n+1;
                if ($lno >= count($this->lines))    break;
                if ($this->lines[$lno]->level <= $level)   break;
                $n++;
            }
            $line->num_child_lines = $n;
        }
        // determine where the function defs are
        $rval = $this->scanForFunctions();
        if (!$rval)    return false;

        // validate
        foreach ($this->lines as $lineno => $line) {
            // 1) if/else/elseif/while/for/def must be followed by a block one level in
            $tok = $line->type;
            if (in_array($tok, self::$needblock)) {
                if ($lineno == count($this->lines)-1 ||
                    $this->lines[$lineno+1]->level !== $line->level+1)
                        return $this->err("'$tok' must be followed by a new block", $line->lineno);
            }
            if ($line->level == 0)   $this->infn = -1;

            // 2) validate specific types of lines
            $rval = true;
            switch ($tok) {
                case 'while':
                case 'continue':
                case 'break';
                case 'return':
                    break;
                case 'def':
                    $this->infn = $lineno;
                    break;
                case 'if':
                    $rval = $this->validate_if($line);
                    break;
                case 'for':
                    $rval = $this->validate_for($line);
                    break;
                case 'global':
                    $rval = $this->validate_global($line);
                    break;
            }
            if ($rval == false)   return false;
        }

        return true;
    }
    
    /**
     * Validate for-in; syntax is: "for" <variable> "in" <expression>
     * @param ScriptLine $line
     * @return bool Returns false on error
     */
    private function validate_for($line) {
        if ($line->num_child_lines == 0)   return $this->err("Empty 'for'", $line->lineno);
        if (count($line->tokens) < 4)   return $this->err("Bad 'for'", $line->lineno);
        if ($line->tokens[2] !== 'in')   return $this->err("Missing 'in'", $line->lineno);
    
        $var = $line->tokens[1];
        if (!$this->parser->isValidName($var))   return $this->err("Invalid variable name '$var'", $line->lineno);
        return true;
    }

    private function validate_global($line) {
        if ($this->infn == -1)   return $this->err("'global' is only allowed inside functions", $line->lineno);
        $n = count($line->tokens);
        if ($n == 1)  return $this->err("Empty variable list", $line->lineno);
        if ($n & 1)  return $this->err("Invalid variable list", $line->lineno);
        $vars = [];
        for ($i = 1; $i < $n; $i += 2) {
            $var = $line->tokens[$i];
            if (!$this->isValidName($var))
                return $this->err('Invalid name in global: '.$var, $line->lineno);
            if ($i+1 < $n && $line->tokens[$i+1] != ',')
                return $this->err("Missing , in variable list", $line->lineno);
            $vars[] = $var;
        }
        $line->data = $vars;
        return true;
    }

    /**
     * Validate if-elseif-else; consists of pairs of
     *     1) if/elseif/else and
     *     2) a conditional 
     * @param ScriptLine $line
     * @return bool Returns false on error
     */
    private function validate_if($line) {
        $parts = []; // keep track of which of if/elseif/else have been used
        $first = true;
        $lineno = $line->lineno;
        $lastlineno = $lineno+$line->num_child_lines-1;
        while ($lineno < count($this->lines)) {
            $lif = $this->lines[$lineno]; // the if/elseif/else

            $tok = $lif->type;
            if (!$first && !in_array($tok, ['elseif','else'])) // end of if/elseif/else
                break;
            $first = false;

            if ($tok == 'elseif' && in_array('else', $parts))
                return $this->err("Bad if-elseif-else, 'elseif' after 'else'", $lif->lineno);

            // build a list of the lines that hold the if-elseif-else
            $parts[$lineno] = $tok;

            $lineno++;
            $lineno += $lif->num_child_lines;
            if ($lineno >= count($this->lines)-1)
                break;
        }

        return true;
    }
                
    /**
     * Find all function definitions and validate them and register them 
     * @return bool
     */
    private function scanForFunctions() {
        foreach ($this->lines as $lineno => $line) {
            if ($line->type != 'def')   continue; // not a function def

            $tokens = $line->tokens;
            // there must be at least 4 tokens:  "def", FUNCTIONNAME, "(" and ")"
            if (count($tokens) < 4              ||
                !$this->isValidName($tokens[1]) ||
                $tokens[2] != '('               ||
                $tokens[count($tokens)-1] != ')')
                return $this->err('Invalid def of '.$tokens[1].'()', $lineno);
            if (in_array($tokens[1], self::$reserved))
                return $this->err($tokens[1].' is a reserved keyword', $lineno);

            // anything between () is a list of argument names seperated by ,       
            $args = [];
            for ($i = 3; $i < count($tokens)-1; $i++) {
                $argname = $tokens[$i];
                if (!$this->isValidName($argname))
                    return $this->err('Invalid arg name: '.$argname, $lineno);
                if (in_array($argname, $args))
                    return $this->err("Duplicate arg name ($argname)", $lineno);
                $args[] = $argname;
                
                // check for , between args
                if ($i < count($tokens)-2 && $tokens[$i+1] != ',')   return $this->err('Invalid arg list', $lineno);
                $i++; // skip the ,
            }
            $line->data = $args;
            self::log("Found def ".$tokens[1]."(".implode(',',$args).")");

          //  foreach ($block->lines as $lineidx => $line) {
        // TODO further checking...
           // }
            // register the function
            $this->functions[$tokens[1]] = $lineno;
        }
        return true;
    }

    /**
     * Convert a line into a list of tokens; escaping (eg. \\X) is retained
     * Tokens are:
     *   1) keywords
     *   2) one of either (  ) or ,
     *   3) a comma-seperated list
     *   4) text inside "" or '' (the token includes the "" or '')
     * @param string $line
     * @return array|string Returns error message or an array of tokens
     */ 
    public function tokenize($line) {
        if (substr($line, 0, 2) == '//')   return [''];

        $words = [];
        $len = strlen($line);
        $word = '';
        $inbr = false; // inside brackets ()
        $inquote = false; // inside quotes "" or ''
        for ($i = 0; $i < $len; $i++) {
            $c = mb_substr($line, $i, 1);
            if (!$inquote && ($c == '"' || $c == "'")) {
                $inquote = $c;
                $word .= $c;
            } else if ($inquote && $c == $inquote) {
                $inquote = false;
                $word .= $c;

            } else if (!$inquote && !$inbr && $c == '/' && mb_substr($line, $i+1, 1) == '/') {
                // rest of line is a comment
                if ($word)   $words[] = $word;
                return $words;

            } else if (!$inquote && $inbr && $c == ')') {
                $inbr =  false;
                if ($word !== '')   $words[] = $word;
                $word = '';
                $words[] = ')';
            } else if (!$inquote && !$inbr && $c == '(') {
                $inbr = true;
                if ($word !== '')   $words[] = $word;
                $word = '';
                $words[] = '(';

            } else if (!$inquote && $c == ',') {
                if ($word !== '')   $words[] = $word;
                $word = '';
                $words[] = ',';

            } else if ($c == ' ' && !$inbr && !$inquote) {
                if ($word !== '')   $words[] = $word;
                $word = '';
            } else if ($c == '\\') {
                $word .= '\\'.mb_substr($line, ++$i, 1);
            } else
                $word .= $c;
        }
        if ($inquote)    return "Quote mismatch";
        if ($word !== '')   $words[] = $word;
       // echo "TOKENS : ".implode("   ", $words)."\n";
        return $words;
    }
    
    /**
     * This is called by the parser to execute a function defined by the script
     * @param string $fnname
     * @param array $argv
     * @param Script $script
     * @param Parser $parser
     * @return mixed Either null on error, or an object with type,value
     */
    public static function executeFunction($fnname, $argv, $script, $parser) {
        $script->log("*** execute $fnname");
        if (!isset($script->functions[$fnname]))    return null;
        $lineno = $script->functions[$fnname];
        $line = $script->lines[$lineno];
        
        // copy args to the stack
//TODO COPY THE VALUE NOT THE ENTIRE VARIABLE
        $script->stack('def');
        //print_r($argv);
        //print_r($line->data);
        $stack = $script->blockstack[count($script->blockstack)-1];
        foreach ($line->data as $idx => $argname)
            $stack->variables[$argname] = $argv[$idx];

        // execute the block
        $script->returnvalue = Parser::fromFloat(0);
        $old_infn = $script->infn;
        $script->infn = $lineno;
        $rval = $script->executeBlock($lineno+1);
        $script->infn = $old_infn;
        $script->unstack();
        
        $script->log("*** executed $fnname $rval -> ".json_encode($script->returnvalue));
        switch ($rval) {
            case self::ABORT:
                break;
        }
        if (!is_object($script->returnvalue))
            return null;
        return $script->returnvalue;
    } 

    /**
     * Lookup a variable, callback from Parser
     * @param string $op Either 'read' or 'set' or 'check'
     * @param string $varname
     * @param Script $script
     * @param Parser $parser
     * @return mixed Either an object with {type,value} or null
     */
    public static function parserVars($op, $varname, $value, $script, $parser) {
        if ($op == 'read')   return $script->readVar($varname);
        if ($op == 'set')   return $script->setVar($varname, $value);
        if ($op == 'check')   return $script->checkVar($varname);
        return null;
    }

    /**
     * Should really only be called from this->parserVars()
     */
    public function readVar($varname) {
        for ($i = count($this->blockstack)-1; $i >= 0; $i--) {
            $stack = $this->blockstack[$i];
            if (isset($stack->variables[$varname]))
                return $stack->variables[$varname];
            // when we reach a function we can go no further unless the variable is global
            if ($stack->type == 'def' && !in_array($varname, $stack->globals))
                break;
        }
        // create variable
        $stack = $this->blockstack[count($this->blockstack)-1];
        $stack->variables[$varname] = Parser::fromFloat(0);
        $stack->variables[$varname]->variable_name = $varname;
        return $stack->variables[$varname];
    }
    /**
     * Should really only be called from this->parserVars()
     */
    public function checkVar($varname) {
        for ($i = count($this->blockstack)-1; $i >= 0; $i--) {
            $stack = $this->blockstack[$i];
            if (isset($stack->variables[$varname]))
                return $stack->variables[$varname];
            // when we reach a function we can go no further unless the variable is global
            if ($stack->type == 'def' && !in_array($varname, $stack->globals))
                return null;
        }
        return null;
    }
    /**
     * Should really only be called from this->parserVars()
     */
    public function setVar($varname, $value) {
        // look for it on the stack
        $var = $this->readVar($varname);
        if ($var) {
            $var->type = $value->type;
            $var->value = $value->value;
            return true;
        }
        // create new variable
        $stack = $this->blockstack[count($this->blockstack)-1];
        $stack->variables[$varname] = $value;
        return true;
    }


    public function registerVariable($name, $value) {
        if (!$this->isValidName($name))   return false;
        if (!is_object($value))    return false;
        $this->variables[$name] = $value;
        return true;
    }

    /**
     * Set the Parser to use
     * @param mixed $parser Either a Parser object, or false to use default
     */
    public function setParser($parser) {
        $this->parser = $parser;
        $this->initParser();
    }
    
    public function initParser() {
        if (!$this->parser) {
            $cfg = [
                'enablemathsfns' => true,
                'enablemiscfns' => true,
                'enabletimefns' => true,
                'enablestringfns' => true,
                'disabledfns' => [],
                'variablefn' => 'Script::parserVars',
                'variablefn_arg' => $this,
            ];
            $this->parser = new Parser($cfg);
        }
    }
    
    public function run() {
        $this->initParser();

        // reset stuff
        $this->starttime = microtime(true);
        $this->executed_lines = 0;
        $this->profile = [];
        $this->infn = -1;
        $this->stop_script = false;
        for ($i = 0; $i < count($this->lines); $i++) {
            $line = $this->lines[$i];
            if ($line->data instanceof ScriptIterator)
                $line->data = false;
        }
        // register functions defined in the script
        foreach ($this->functions as $fnname => $lineno)
            $this->parser->registerFunction($fnname, 'Script::executeFunction', 0, $this->max_fn_args, $this);
        // register external functions
        foreach ($this->external_functions as $fnname => $fn)
            $this->parser->registerFunction($fnname, $fn['phpfn'], $fn['min'], $fn['max'], $this);
        
        $this->blockstack = [];
        if (count($this->variables)) {
            $this->stack();
            $stack = $this->blockstack[0];
            $stack->variables = $this->variables;
        }
        $this->lineno = 0;
        $this->stack();
        $rval = $this->executeBlock(0);
        $this->unstack();
        $this->log("RETURN VALUE $rval");
        
        return true;
    }
    
    /**
     * Parse an expression
     * @param string $expr
     * @return mixed Returns false on error, or object with type,value
     */
    public function parse($expr) {
        $rval = $this->parser->parse($expr);
        $this->log("parse($expr) = ".json_encode($rval));
        if ($rval && $rval->type != 'error') {
            return $rval;
        }
        $this->errorlineno = $this->lineno;
        if ($rval)
            $this->errortext = $rval->value." ($expr)";
        else
            $this->errortext = "Invalid expression ($expr)";
        return false;
    }

    /**
     * Check if the result from Parser->parse() should be considered true
     * @param object $val
     * @return bool
     */
    private function isTrue($val) {
        switch ($val->type) {
            case 'string':
            case 'number':  return !!$val->value;
        }
        return false;
    }
    
    /**
     * Remove the top block(s) on the stack
     * @param int $level -1 to remove top block, or >= 0 to leave this many blocks 
     */
    private function unstack($level = -1) {
        if ($level < 0)   $level = count($this->blockstack)-1;
        $this->blockstack = array_slice($this->blockstack, 0, $level);
    }

    private function stack($type = false) {
        $stack = (object)['lineno' => $this->lineno,
                          'type' => $type,
                          'globals' => [],
                          'variables' => []];
        if (!$type) {
            $line = $this->lines[$this->lineno];
            $stack->type = $line->type;
        }
        $this->blockstack[] = $stack;
    }
    
    /**
     * Check if we are in a loop
     * @return int 0:no in a loop
     *             1:in a function call or in loop but loop is not top block
     *             2:in loop, loop is top block
     */
    private function isInLoop() {
        $n = count($this->blockstack);
        for ($i = $n-1; $i >= 0; $i--) {
            $stack = $this->blockstack[$i];
            if (in_array($stack->type, ['def']))
                return 1;
            if (in_array($stack->type, ['while','for']))
                return ($i == $n-1) ? 2 : 1;
        }
        return 0;
    }
    
    /**
     * Execute all lines in a block, that is, all lines on same level
     */
    private function executeBlock($lineno) {
        $level = $this->lines[$lineno]->level;
        // find last line in this block
        $lastline = count($this->lines)-1;
        for ($i = $lineno; $i < count($this->lines); $i++)
            if ($this->lines[$i]->level < $level) {
                $lastline = $i-1;
                break;
            }
        $this->log("block ".sprintf("%03d - %03d", $lineno, $lastline).", lvl $level");
        
        while ($lineno <= $lastline) {
            $rval = $this->executeLine($lineno);
            $this->lineno = $lineno;
            $this->log("  exeblklin res=".json_encode($rval)." lvl $level");

            if ($rval === false) {
                return self::ABORT;
            }
            switch ($rval) {
                case self::ABORT:
                    return self::ABORT;

                case self::END_OF_FN:
                    // unstack until we are out of the function 
                    $stacktop = $this->blockstack[count($this->blockstack)-1];
                    if ($stacktop->type == 'def')
                        return self::END_OF_BLOCK;
                    return self::END_OF_FN;
                        
                case self::END_OF_BLOCK:
                    $this->log("ENDOFBLOCK : block ".sprintf("%03d - %03d", $lineno, $lastline));
                    $line = $this->lines[$lineno];
                    if ($line->data instanceof ScriptIterator)
                        $line->data = false;
                    if ($lastline >= count($this->lines))
                        return self::ABORT;
                    return $lastline+1;

                case self::CONTINUE_LOOP:
                    $inloop = $this->isInLoop();
                    if (!$inloop) {
                        $this->log("bad continue");
                        return self::ABORT;
                    }
                    if ($inloop == 1 || $inloop == 2) {
                        return self::CONTINUE_LOOP;
                    }
                    return self::END_OF_BLOCK;
                    
                case self::ABORT_LOOP:
                    // break out of the closest block which is a loop
                    $this->log("ABORT LOOP : block ".sprintf("%03d - %03d", $lineno, $lastline));
                    $line = $this->lines[$lineno];
                    $inloop = $this->isInLoop();
                    if (!$inloop) {
                        echo "bad break\n";
                        return self::ABORT;
                    }
                    if ($inloop == 1) {
                        return self::ABORT_LOOP;
                    }
                    if ($inloop == 2) {                         
                        $line->data = false;
                        $nextlineno = $lastline+1;
                        return $nextlineno;
                    }
                    return self::END_OF_BLOCK;

                case self::NEXT_LINE:
                    $this->log("NEXTLINE : block ".sprintf("%03d - %03d", $lineno, $lastline));
                    // next line in same block, ie. same level
                    while (true) {
                        $lineno++;
                        if ($lineno > $lastline) {
                            //if ($lineno >= count($this->lines))
                            //    return self::ABORT;
                            return self::END_OF_BLOCK;
                        }
                        if ($this->lines[$lineno]->level > $level)
                            continue; // skip lines in child blocks
                        break;
                    }
                    break;

                default:
                    $this->log("GOTO $rval : block ".sprintf("%03d - %03d", $lineno, $lastline));
                    if ($rval >= 0)   $lineno = $rval;
                    break;
            }
        }
        return self::END_OF_BLOCK;
    }
    
    /**
     * Find next line on same level or higher
     */
    private function nextLine($lineno) {
        $level = $this->lines[$lineno]->level;
        $lineno++;
        while ($lineno < count($this->lines)) {
            $line = $this->lines[$lineno];
            if ($line->level <= $level)    return $lineno;
            $lineno++;
        }
        return self::ABORT;
    }
    
    /**
     * Execute a line
     * @param $block ScriptBlock
     * @param $line ScriptLine
     * @param int $lineno Line no within the ScriptBlock, from 0 to count($block->lines)-1
     * @return mixed Either an error object, or true (all went well), or array with flow control
     */
    private function executeLine($lineno) {
        $this->lineno = $lineno;
        $this->executed_lines++;
        if ($this->executed_lines > $this->max_lines ||
            microtime(true) - $this->starttime > $this->max_microseconds) {
        // TODO error
            return self::ABORT;
        }
        if ($this->stop_script)    return self::ABORT;
        if (!isset($this->profile[$lineno]))   $this->profile[$lineno] = 0;
        $this->profile[$lineno] += 1;
        $line = $this->lines[$lineno];

        $this->dumpLine($line, "execute : ");

        // check if this line is a block, if so just execute the block
       
        switch ($line->type) {
            case '':
                return self::NEXT_LINE;
                
            case 'global':
                if (is_array($line->data)) {
                    $stack = $this->blockstack[count($this->blockstack)-1];
                    foreach ($line->data as $varname)
                        if (!in_array($varname, $stack->globals))
                            $stack->globals[] = $varname;
                }
                break;

            case 'return': // return [<expr>]
                $tokens = $line->tokens;
                $expr = implode(' ', array_slice($tokens, 1));
                if ($expr)
                    $this->returnvalue = $this->parse($expr);
                else
                    $this->returnvalue = (object)['type' => 'number', 'value' => 0];
                // if inside function call then return the value, else exit
                if ($this->infn >= 0) {
                    $this->log("  end of function @".$this->infn);
                    return self::END_OF_FN;
                }
                $this->log("  end of script");
                return self::ABORT;

            case 'while': // while <expr>
                $tokens = $line->tokens;
                $expr = implode(' ', array_slice($tokens, 1));
                // parse expr, if true then run next block
                $rval = $this->parse($expr);
                $this->log("  'WHILE $expr' (=".json_encode($rval).")");
                if (!$rval)    return $this->err();
                
                if ($this->isTrue($rval)) {
                    // run next block, then do the 'while...' again
                    $this->stack();
                    $rval = $this->executeBlock($lineno+1);
                    //echo " endofwhile ".json_encode($rval)."\n";
                    $this->unstack();
                    //echo "WHILE returned $rval\n";
                    switch ($rval) {
                        case self::END_OF_FN:
                        case self::ABORT:
                        case self::ABORT_LOOP:    return $rval;
                        case self::CONTINUE_LOOP:  return $lineno;
                        default:
                            if ($rval > 0)   return $rval;
                            break;
                    }
                    return $lineno; // back to start of the 'while'
                }
                
                return self::NEXT_LINE;

            case 'for': // for <var> in <expr>
                $tokens = $line->tokens;
                $var = $tokens[1];
        // TODO allow an array instead of an iterator
                if (!($line->data instanceof ScriptIterator)) {
                    $expr = implode(' ', array_slice($tokens, 3));
                    $rval = $this->parse($expr);
                    if (!$rval)    return $this->err();
                    if ($rval->type == 'object' && $rval->value instanceof ScriptIterator)
                        $line->data = $rval->value;
                    else if ($rval->type == 'string')
                        $line->data= new ScriptStringIterator($rval->value);
                    else if ($rval->type == 'array')
                        $line->data = new ScriptArrayIterator($rval->value);
                    else if ($rval->type == 'dict')
                        $line->data = new ScriptDictIterator($rval->value);
                    else
                        return self::ABORT;
                }
                // get value from iterator
                $val = $line->data->__next__();
                if ($val === false) {
                    $this->log('end of for');
                    return self::NEXT_LINE;//self::ABORT_LOOP;
                }
                // assign to variable
                $this->parser->setConstant($var, $val);
                // execute block
                $this->stack();
                $rval = $this->executeBlock($lineno+1);
                $this->unstack();
                //echo " for ".json_encode($rval)."\n";
                switch ($rval) {
                    case self::END_OF_FN:
                    case self::ABORT:
                    case self::ABORT_LOOP:    return $rval;
                    case self::CONTINUE_LOOP:  return $lineno;
                    default:
                        if ($rval > 0)   return $rval;
                        break;
                }
                return $lineno; // back to start of the 'for'
    
            case 'break':
                return self::ABORT_LOOP;

            case 'continue':
                return self::CONTINUE_LOOP;
    
            case 'def': // def <fnname>([<expr>[,<expr>...])
                // simply skip the next block
                return self::END_OF_BLOCK;

            case 'if': // if <expr>
            case 'elseif': // elseif <expr>
                $tokens = $line->tokens;
                $expr = implode(' ', array_slice($tokens, 1));
                //$this->log("  'IF $expr'");
                // parse expr, if true then run next block else find next block to run
                $rval = $this->parse($expr);
                $this->log("    res=".json_encode($rval));
                if (!$rval)    return $this->err();

                if ($this->isTrue($rval)) {
                    // run next block
                    $this->stack();
                    $rval = $this->executeBlock($lineno+1);
                    $this->unstack();
                    switch ($rval) {
                        case self::END_OF_FN:
                        case self::ABORT:
                        case self::ABORT_LOOP:
                        case self::CONTINUE_LOOP:
                            return $rval;
                    }
                    return self::NEXT_LINE;
            
                } else {
                    $this->log("IF failed");
                    $res = $this->nextLine($lineno);
                    if ($res == -1)   return self::NEXT_LINE;

                    $line = $this->lines[$res];
                    if ($line->type == 'else') {
                        $this->stack();
                        $rval = $this->executeBlock($lineno+1);
                        $this->unstack();
                        switch ($rval) {
                            case self::END_OF_FN:
                            case self::ABORT:
                            case self::ABORT_LOOP:
                            case self::CONTINUE_LOOP:
                                return $rval;
                        }
                        return self::NEXT_LINE;

                    } else if ($line->tokens[0] == 'elseif') {
                        return $res;
                    } else {
                        return $res;
                    }
                }
                break;
    
            default:
                $tokens = $line->tokens;
                $this->log("     expr : ".implode(' ', $tokens));
                $rval = $this->parse(implode(' ', $tokens));
                $this->log("     rval=".json_encode($rval));
                if (!$rval)   return $this->err();
                break;
        }
        return self::NEXT_LINE;
    }
    
    /**
     * Print the script
     */
    public function dump($indent = '') {
        $spaces = str_repeat('. ', $this->spaces/2);
        foreach ($this->lines as $lineno => $line) {
            $this->lineno = $lineno;
            $this->dumpLine($line, str_repeat($spaces, $line->level));
        }
    }
    private function dumpLine($line, $indent = '', $txt = '', $tail = '') {
        $txt .= ' '.$indent.implode(" ", $line->tokens);
        if (strlen($txt) < 35)   $txt .= str_repeat(' ', 35-strlen($txt));
        $this->log($txt."          $tail");
    }
    
    private function dumpStack() {
        for ($i = count($this->blockstack)-1; $i >= 0; $i--) {
            $stack = $this->blockstack[$i];
            echo sprintf("%02d",$i)." ".$stack->type." vars=".implode(',', array_keys($stack->variables))."\n";
        }
    }
    
    public function log($txt) {
        return;
        echo sprintf('%03d ',  $this->lineno);
        if (is_string($txt))
            echo $txt."\n";
        else
            print_r($txt);
    }
}

?>
