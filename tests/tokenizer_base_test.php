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
 * Unit tests for mod_vpl\tokenizer\tokenizer_base
 *
 * @package mod_vpl
 * @copyright David Parreño Barbuzano
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author  David Parreño Barbuzano <david.parreno101@alu.ulpgc.es>
 */
namespace mod_vpl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/vpl/tests/base_test.php');

/**
 * Unit tests for \mod_vpl\tokenizer\tokenizer_base class.
 *
 * @group mod_vpl
 * @group mod_vpl_tokenizer
 * @group mod_vpl_tokenizer_base
 * @covers \mod_vpl\tokenizer\tokenizer_base
 */
class tokenizer_base_test extends \advanced_testcase {
    // Test cases for tokenizer::remove_capturing_groups
    //
    // - key   => input value to test
    // - value => expected result.
    private static array $testcasesrcg;

    // Test cases for tokenizer::create_splitter_regex
    //
    // - key   => input value to test
    // - value => expected result.
    private static array $testcasescsr;

    // Test cases for tokenizer::check_type
    //
    // - key   => available data type
    // - value => [ true => [ list_of_valid_values ], false => [ list_of_invalid_values ] ].
    private static array $testcasesckt;

    // Test cases for tokenizer::check_token
    //
    // - key   => expected value to get
    // - value => list of input tokens to test.
    private static array $testcasesctk;

    // State to use to test tokenizer::contains_rule.
    private static array $statetosearchrules;

    // Available tokens to use to test tokenizer::check_token.
    private const AVAILABLETOKENS = [
        "text",
        "comment"  => [ "line" ],
        "constant" => [ "character" => [ "escape" ] ],
        "storage"  => [ "type" ]
    ];

    /**
     * Prepare test cases before the execution
     */
    public static function setUpBeforeClass(): void {
        self::$testcasesrcg = [
            "()"           => "()",
            "(a)"          => "(?:a)",
            "(ab)"         => "(?:ab)",
            "(a)(b)"       => "(?:a)(?:b)",
            "(ab)(d)"      => "(?:ab)(?:d)",
            "(ab)(d)()"    => "(?:ab)(?:d)()",
            "(ax(by))[()]" => "(?:ax(?:by))[()]"
        ];

        self::$testcasescsr = [
            "(a)(b)(?=[x)(])"           => "^(a)(b)$",
            "xc(?=([x)(]))"             => "^xc$",
            "(xc(?=([x)(])))"           => "^(xc)$",
            "(?=r)[(?=)](?=([x)(]))"    => "^(?=r)[(?=)]$",
            "(?=r)[(?=)](\\?=t)"        => "^(?=r)[(?=)](\\?=t)$",
            "[(?=)](\\?=t)"             => "^[(?=)](\\?=t)$"
        ];

        self::$testcasesckt = [
            "number"       => [ true => [ 10, 30.5, 0 ], false => [ true, "not_a_number" ] ],
            "bool"         => [ true => [ true, false ], false => [ 10, "not_a_bool" ] ],
            "string"       => [ true => [ "", "example" ], false => [ 10, true ] ],
            "array"        => [ true => [ [], [ 10, 20, 30 ] ], false => [ 10, true, "not_an_array" ] ],
            "object"       => [ true => [ (object)["attr" => 10], (object)["attr" => ""] ], false => [ "not_an_object", 20 ] ],
            "array_number" => [ true => [ [ 10, 20, 30], [10] ], false => [ [ 10, "", 30 ], 10 ] ],
            "array_bool"   => [ true => [ [ true, false, true], [ false ] ], false => [ [ true, "", false ], true ] ],
            "array_string" => [ true => [ [ "example", "", "10"], [ "test" ] ], false => [ [ "10", "", 30 ], "10" ] ],
            "array_array"  => [ true => [ [ [ 10, 20, 30 ] ], [[10]] ], false => [ [ 10, "", 30 ], [10] ] ],
            "array_object" => [ true => [ [ (object)["h" => 10] ], [(object)[]] ], false => [ [ 10, "", 30 ], 10 ] ]
        ];

        self::$testcasesctk = [
            true  => [
                "text", "comment", "comment.line", "constant", "constant.character",
                "constant.character.escape", "storage", "storage.type",
                [ "text" ], [ "text", "comment" ], [ "text", "comment.line", "constant.character" ]
            ],
            false => [
                "", "hello", "comment.multiple", "constant.regex", "variable",
                [], [ "text.line"], [ "text", "comment.multiple", "character" ]
            ]
        ];

        self::$statetosearchrules = [
            (object)["token" => "string.double", "regex" => "\".*\""],
            (object)["token" => "comment", "regex" => "\\/\\/", "next" => "start"],
            (object)["default_token" => "comment"]
        ];
    }

    /**
     * Method to test tokenizer::remove_capturing_groups
     *
     * Test cases based on Ace Editor unit tests:
     * (https://github.com/ajaxorg/ace/blob/master/lib/ace/tokenizer_test.js)
     */
    public function test_remove_capturing_groups() {
        foreach (self::$testcasesrcg as $src => $expectedregex) {
            $regex = testable_tokenizer_base::remove_capturing_groups($src);
            $this->assertSame($expectedregex, $regex);
        }
    }

    /**
     * Method to test tokenizer::create_splitter_regex
     *
     * Test cases based on Ace Editor unit tests:
     * (https://github.com/ajaxorg/ace/blob/master/lib/ace/tokenizer_test.js)
     */
    public function test_create_splitter_regex() {
        foreach (self::$testcasescsr as $src => $expectedregex) {
            $regex = testable_tokenizer_base::create_splitter_regex($src);
            $this->assertSame($expectedregex, $regex);
        }
    }

    /**
     * Method to test tokenizer::check_type
     */
    public function test_check_type() {
        foreach (self::$testcasesckt as $type => $values) {
            foreach ($values[true] as $validvalue) {
                $cond = testable_tokenizer_base::check_type($validvalue, $type);
                $this->assertTrue($cond);
            }

            foreach ($values[false] as $invalidvalue) {
                $cond = testable_tokenizer_base::check_type($invalidvalue, $type);

                if (is_bool($cond) === true) {
                    $this->assertFalse($cond);
                } else {
                    $this->assertTrue(is_numeric($cond));
                    $this->assertTrue($cond >= 0 && $cond < count($invalidvalue));
                    $this->assertFalse(testable_tokenizer_base::check_type($invalidvalue[$cond], $type));
                }
            }
        }
    }

    /**
     * Method to test tokenizer::check_token
     *
     * Naming conventions are inspired in TextMate manual,
     * see https://macromates.com/manual/en/language_grammars#naming-conventions
     */
    public function test_check_token() {
        foreach (self::$testcasesctk as $expectedvalue => $tokens) {
            foreach ($tokens as $token) {
                $result = testable_tokenizer_base::check_token($token, self::AVAILABLETOKENS);
                $this->assertSame(boolval($expectedvalue), $result);
            }
        }
    }

    /**
     * Method to test tokenizer::contains_rule
     */
    public function test_contains_rule() {
        foreach (self::$statetosearchrules as $rule) {
            $cond = testable_tokenizer_base::contains_rule(self::$statetosearchrules, $rule);
            $this->assertTrue($cond);

            $invalidrule = clone $rule;
            $invalidrule->dump = "this_change_makes_current_rule_invalid";
            $cond = testable_tokenizer_base::contains_rule(self::$statetosearchrules, $invalidrule);
            $this->assertFalse($cond);
        }
    }
}
