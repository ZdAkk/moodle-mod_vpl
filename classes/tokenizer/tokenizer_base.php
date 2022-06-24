<?php
// This file is part of VPL for Moodle - http://vpl.dis.ulpgc.es/
//
// VPL for Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// VPL for Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with VPL for Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Basic utilities for tokenizer
 *
 * @package mod_vpl
 * @copyright 2022 David Parreño Barbuzano
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author David Parreño Barbuzano <losedavidpb@gmail.com>
 */
namespace mod_vpl\tokenizer;

/**
 * @internal
 *
 * This class was not designed to be used as a real tokenizer,
 * since it does not implements tokenizer interface.
 *
 * Tokenizers which could be useful for real applications must
 * implement the methods defined at tokenizer and must be an
 * extension of this class in order to use its methods
 */
class tokenizer_base {
    protected array $states;
    protected array $matchmappings;
    protected array $regexprs;

    /**
     * Empty constructor
     */
    public function __construct() {

    }

    /**
     * Get states for current tokenizer
     *
     * @return array
     */
    protected function get_states(): array {
        return $this->states;
    }

    /**
     * Get matching map for current tokenizer
     *
     * @return array
     */
    protected function get_matchmappings(): array {
        return $this->matchmappings;
    }

    /**
     * Get regex of each state for current tokenizer
     *
     * @return array
     */
    protected function get_regexprs(): array {
        return $this->regexprs;
    }

    /**
     * Check if passed value has an available data type
     *
     * @param $value value to check
     * @param string $typename data type which must have $value
     * @return bool|int
     */
    protected static function check_type($value, string $typename) {
        $condtypes = array(
            "number" => function ($val) {
                return is_numeric($val);
            },
            "string" => function ($val) {
                return is_string($val);
            },
            "object" => function ($val) {
                return is_object($val);
            },
            "bool"   => function ($val) {
                return is_bool($val);
            },
            "array"  => function ($val) {
                return is_array($val);
            }
        );

        if (str_starts_with($typename, "array_") && is_array($value)) {
            $typearray = substr($typename, 6);

            foreach ($value as $indexval => $val) {
                if (isset($condtypes[$typearray])) {
                    if ($condtypes[$typearray]($val) !== true) {
                        return $indexval;
                    }
                } else {
                    return $indexval;
                }
            }

            return true;
        } else if (!str_starts_with($typename, "array_")) {
            if (isset($condtypes[$typename])) {
                return $condtypes[$typename]($value);
            }
        }

        return false;
    }

    /**
     * Check if passed state contains passed rule
     *
     * @param array $state state that could have $rule
     * @param object $rule rule to search
     * @return bool
     */
    protected static function contains_rule(array $state, object $rule): bool {
        $result = false;

        foreach ($state as $ruleorig) {
            $tokensorig = array_keys(get_object_vars($ruleorig));
            $tokensrule = array_keys(get_object_vars($rule));
            $result = false;

            if (count($tokensorig) == count($tokensrule)) {
                foreach ($tokensorig as $name) {
                    $result = false;

                    if (isset($rule->$name) && isset($ruleorig->$name)) {
                        if ($rule->$name === $ruleorig->$name) {
                            $result = true;
                        }
                    }
                }

                if ($result == true) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Check data type of token based on TextMate
     *
     * @param string|array $token value for a token
     * @param array $availabletokens list of available tokens
     * @return bool
     */
    protected static function check_token($token, array $availabletokens): bool {
        $token = is_string($token) ? [$token] : $token;

        if (is_array($token) && count($token) == 0) {
            return false;
        }

        foreach ($token as $value) {
            $splitvalues = explode(".", $value);
            $splitvalues = count($splitvalues) <= 0 ? $value : $splitvalues;
            $arraytoken = $availabletokens;

            foreach ($splitvalues as $svalue) {
                if (isset($arraytoken) === true) {
                    if (isset($arraytoken[$svalue])) {
                        $arraytoken = $arraytoken[$svalue];
                    } else if (in_array($svalue, $arraytoken)) {
                        unset($arraytoken);
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove Capturing Groups based on Ace Editor tokenizer.js
     * (https://github.com/ajaxorg/ace/blob/master/lib/ace/tokenizer.js).
     *
     * @param string $src source string with capturing groups
     * @return string
     */
    protected static function remove_capturing_groups(string $src): string {
        $regex = preg_replace_callback("/\\.|\[(?:\\.|[^\\\]])*|\(\?[:=!<]|(\()/", function ($value) {
            return strcmp($value[0], "(") == 0 ? "(?:" : $value[0];
        }, $src);

        return preg_replace("/\(\?:\)/", "()", $regex);
    }

    /**
     * Create Splitter Regex based on Ace Editor tokenizer.js
     * (https://github.com/ajaxorg/ace/blob/master/lib/ace/tokenizer.js).
     *
     * @param string $src source string with capturing groups
     * @return string
     */
    protected static function create_splitter_regexp(string $src): string {
        if (strpos($src, "(?=") !== false) {
            $stack = 0;
            $inchclass = false;
            $lastcapture = [];

            $regex = "/(\\.)|(\((?:\?[=!])?)|(\))|([\[\]])/";
            preg_match_all($regex, $src, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $value) {
                $valuematch = $value[0];
                $index = $value[1];

                if ($inchclass === true) {
                    $inchclass = strcmp($valuematch, ']') != 0;
                } else if (preg_match("/([\[\]])/", $valuematch) == 1) {
                    $inchclass = true;
                } else if (preg_match("/(\))/", $valuematch) == 1) {
                    if (isset($lastcapture['stack'])) {
                        if ($stack == $lastcapture['stack']) {
                            $lastcapture['end'] = $index + 1;
                            $lastcapture['stack'] = -1;
                        }

                        $stack--;
                    }
                } else if (preg_match("/(\((?:\?[=!])?)/", $valuematch, $parenmatches) == 1) {
                    $stack++;

                    if (strlen($parenmatches[0]) != 1) {
                        $lastcapture['stack'] = $stack;
                        $lastcapture['start'] = $index;
                    }
                }
            }

            if (isset($lastcapture['end'])) {
                if (preg_match("/^\)*$/", substr($src, $lastcapture['end'])) == 1) {
                    $src = substr($src, 0, $lastcapture['start']) . substr($src, $lastcapture['end']);
                }
            }
        }

        $src = strcmp($src[0], "^") != 0 ? "^" . $src : $src;
        $src = strcmp($src[strlen($src) - 1], "$") != 0 ? $src . "$" : $src;

        return $src;
    }
}
