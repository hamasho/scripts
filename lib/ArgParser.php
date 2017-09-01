<?php

/**
 * Command line argument parser
 *
 * Parse given command line arguments and return the result.
 * The arguments can be short (i.e. -s) or long (i.e. --long-option).
 *
 * Option config must be supplied as an array in the following format;
 * $optConfig = array(
 *     array(
 *         'short' => 'o',
 *         'long'  => 'option',
 *         'value' => 'int',
 *         'vhelp' => '<number>',
 *         'help'  => 'an option taking numerical value',
 *     ),
 *     array(
 *         'short' => 's',
 *         'value' => 'string',
 *         'help'  => 'short option taking string',
 *     ),
 *     array(
 *         'long'  => 'long-option',
 *         'help'  => 'very long option name',
 *     ),
 *       :
 * );
 * where each key specify the following values;
 *     'short' : Short style of the option.
 *     'long'  : Long style of option.
 *               * You must supply at least short or long option *
 *     'value' : If option needs its value, specify the type of the value.
 *               (i.e. -f foo.txt or --log-file=/var/log/bar.log)
 *               Value can be 'int', 'string'.
 *               If 'value' is unset, this option doesn't require the value.
 *     'help'  : Used to create an auto generated help message.
 *               If 'exit_on_error' in $parserConfig is true, when parser fails to
 *               parse the value of this option, print this help message and exit.
 *     'vhelp' : Printing string in help documentation like '-o, --option=<desc>'.
 *               If omitted, 'value' is used.
 *     'dup'   : Allow duplication, otherwise override by final option.
 *               If this option doesn't require value, the return value is an interger
 *               of coutn, otherwise an array of all values.
 *               (i.e. $options[$opt] = 3 or $optons[$opt] = array('foo', 'bar'))
 *
 * You can also control the parser configuration by an array $parserConfig.
 * $parserConfig = array(
 *     'exit_on_error' => true,
 * );
 * where each key specify the following values;
 *     'exit_on_error' : Exit if error occurs in parsing, otherwise return false.
 *
 * Parsing finishes if one of the following condition is met:
 *   - encounting an argument which doesn't start with '-' or '--',
 *   - encounting the double dash '--',
 *   - parsing all arguments.
 * Then parse() method returns an array containing 'options', an associative array
 * including option name and it's value, and 'args', an array including remaining
 * arguments.
 *
 * For example, if an input command is like this:
 * $ command -s hello --option=100 foo.txt bar.php
 * the return value of parse() method is an array of the following format;
 * $tokens = array(
 *     'options' => array(
 *         'o'           => '100',
 *         'option'      => '100',
 *         's'           => 'hello',
 *         'long-option' => null,
 *     );
 *     'args' => array('foo.txt', 'bar.php')
 * );
 * Note that if you have short and long format for same option, then both are
 * included the 'options' array and have the same vale.
 * If an error occurs while parsing, return false.
 *
 * You can also create a help documentation by getHelpString() method.
 * The format of help documentation is like this;
 * ```
 * -o, --option=<int>
 *     an option taking numerical value
 *
 * -s
 *     short option name
 *
 * --long-option
 *     very long option name
 *
 * ```
 */
class ArgParser
{
    public function __construct($optConfig, $parserConfig = null)
    {
        if ($parserConfig !== null)
            $this->parserConfig = $parserConfig;
        else
            $this->parserConfig = array();
        $this->optConfig = $optConfig;

        $this->allOptions = array();
        foreach ($optConfig as $config)
        {
            if ( ! (isset($config['short']) or isset($config['long'])) )
                throw new Exception('no option key: ('.implode(',', $config).')');
            if ( isset($config['short']) )
                $this->allOptions[$config['short']] = $config;
            if ( isset($config['long']) )
                $this->allOptions[$config['long']] = $config;
        }
    }
    /**
     * Parse the arguments.
     */
    public function parse($args)
    {
        if ( ! is_array($args))
            $args = explode(' ', preg_replace('!\s+!', ' ', $args));
        $options = array();

        try {
            for ($idx = 0; $idx < count($args); $idx++)
            {
                $token = $args[$idx];

                if (substr($token, 0, 2) === '--') {
                    $t = explode('=', substr($token, 2), 2);
                    if ($t[0] === '')
                        return array(
                            'options' => $options,
                            'args' => array_slice($args, $idx + 1),
                        );
                    if ( ! isset($this->allOptions[$t[0]]))
                        throw new Exception("invalid option: --{$t[0]}");
                    $opt = $t[0];
                    $optConf = $this->allOptions[$opt];
                    if (isset($optConf['value'])) {
                        if ( ! isset($t[1]))
                            throw new Exception("option --{$opt} requires a value");
                        $val = $t[1];
                        if ( ! self::_validate($val, $optConf['value']))
                            throw new Exception("value {$val} of option --{$opt} is invalid");
                    } else {
                        if (isset($t[1]))
                            throw new Exception("option --{$opt} need not take value");
                        $val = true;
                    }
                    self::_setValue($options[$opt], $val, $optConf);
                    if (isset($optConf['short']))
                        self::_setValue($options[$optConf['short']], $val, $optConf);

                } else if (substr($token, 0, 1) === '-') {
                    $s = substr($token, 1);
                    $opts = str_split($s);
                    for ($i = 0; $i < count($opts); $i++) {
                        $opt = $opts[$i];
                        if ( ! isset($this->allOptions[$opt]))
                            throw new Exception("invalid option: -{$opt}");
                        $optConf = $this->allOptions[$opt];
                        if (isset($optConf['value'])) {
                            if (isset($opts[$i+1]) or (! isset($args[$idx+1])))
                                throw new Exception("option -{$opt} requires a value");
                            $val = $args[$idx+1];
                            if ( ! self::_validate($val, $optConf['value']))
                                throw new Exception("value {$val} of option -{$opt} is invalid");
                            $idx++;
                        } else {
                            $val = true;
                        }
                        self::_setValue($options[$opt], $val, $optConf);
                        if (isset($optConf['long']))
                            self::_setValue($options[$optConf['long']], $val, $optConf);
                    }
                } else {
                    return array(
                        'options' => $options,
                        'args' => array_slice($args, $idx),
                    );
                }
            }

            return array(
                'options' => $options,
                'args' => array(),
            );
        }
        catch (Exception $ex)
        {
            if (isset($this->parserConfig['exit_on_error'])) {
                fwrite(STDERR, 'error: '.$ex->getMessage()."\n");
                exit(1);
            }
            return $ex->getMessage();
        }
    }
    /**
     * Format the help string from $optConfig's 'help' value.
     */
    public function getHelpString()
    {
        $result = '';
        foreach ($this->optConfig as $config) {
            if (isset($config['value'])) {
                $vhelp = (isset($config['vhelp']) ? $config['vhelp'] : $config['value']);
                if (isset($config['short'])) {
                    $result .= "-{$config['short']}";
                    if (isset($config['long']))
                        $result .= ", --{$config['long']}={$vhelp}";
                    else
                        $result .= " {$vhelp}";
                } else {
                    $result .= "--{$config['long']}={$vhelp}";
                }
            } else {
                if (isset($config['short'])) {
                    $result .= "-{$config['short']}";
                    if (isset($config['long']))
                        $result .= ", --{$config['long']}";
                } else {
                    $result .= "--{$config['long']}";
                }
            }
            $result .= "\n    {$config['help']}\n";
        }
        return $result;
    }

    private static function _validate($value, $type)
    {
        switch ($type)
        {
        case 'int':
            if ( ! is_numeric($value))
                return false;
        }
        return true;
    }
    private static function _setValue(&$var, $val, $optConf)
    {
        if (isset($optConf['dup'])) {
            if (isset($var)) {
                if (isset($optConf['value']))
                    $var[] = $val;
                else
                    $var++;
            } else {
                if (isset($optConf['value']))
                    $var = array($val);
                else
                    $var = 1;
            }
        } else {
            $var = $val;
        }
    }
}
