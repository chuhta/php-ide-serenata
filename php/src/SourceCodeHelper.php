<?php

namespace PhpIntegrator;

use UnexpectedValueException;

/**
 * Contains utility functionality for dealing with source code.
 */
class SourceCodeHelper
{
    /**
     * @param string|null $file
     * @param bool        $isStdin
     *
     * @throws UnexpectedValueException
     */
    public function getSourceCode($file, $isStdin)
    {
        $code = null;

        if ($isStdin) {
            // NOTE: This call is blocking if there is no input!
            $code = file_get_contents('php://stdin');
        } else {
            if (!$file) {
                throw new UnexpectedValueException('The specified file does not exist!');
            }

            $code = @file_get_contents($file);
        }

        $encoding = mb_detect_encoding($code, null, true);

        if (!in_array($encoding, ['UTF-8', 'ASCII'], true)) {
            $code = mb_convert_encoding($code, 'UTF-8', $encoding);
        }

        return $code;
    }

    /**
     * Calculates the 1-indexed line the specified byte offset is located at.
     *
     * @param string $source
     * @param int    $offset
     *
     * @return int
     */
    public function calculateLineByOffset($source, $offset)
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }

    /**
     * Retrieves the character offset from the specified byte offset in the specified string. The result will always be
     * smaller than or equal to the passed in value, depending on the amount of multi-byte characters encountered.
     *
     * @param int    $byteOffset
     * @param string $string
     *
     * @return int
     */
    public function getCharacterOffsetFromByteOffset($byteOffset, $string)
    {
        return mb_strlen(mb_strcut($string, 0, $byteOffset));
    }

    /**
     * Retrieves the byte offset from the specified character offset in the specified string. The result will always be
     * larger than or equal to the passed in value, depending on the amount of multi-byte characters encountered.
     *
     * @param int    $characterOffset
     * @param string $string
     *
     * @return int
     */
    public function getByteOffsetFromCharacterOffset($characterOffset, $string)
    {
        return strlen(mb_substr($string, 0, $characterOffset));
    }

    /**
     * Retrieves the start of the expression (as byte offset) that ends at the end of the specified source code string.
     *
     * @param string $code
     *
     * @return int
     */
    public function getStartOfExpression($code)
    {
        if (empty($code)) {
            return 0;
        }

        $parenthesesOpened = 0;
        $parenthesesClosed = 0;
        $squareBracketsOpened = 0;
        $squareBracketsClosed = 0;
        $squiggleBracketsOpened = 0;
        $squiggleBracketsClosed = 0;

        $didStartInsideString = null;
        $startedStaticClassName = false;

        $token = null;
        $tokens = token_get_all($code);
        $currentTokenIndex = count($tokens);
        $tokenStartOffset = strlen($code);

        $expressionBoundaryTokens = $this->getExpressionBoundaryTokens();

        for ($i = strlen($code) - 1; $i >= 0; --$i) {
            if ($i < $tokenStartOffset) {
                $token = $tokens[--$currentTokenIndex];

                $tokenString = is_array($token) ? $token[1] : $token;
                $tokenStartOffset = ($i + 1) - strlen($tokenString);

                $token = [
                    'type' => is_array($token) ? $token[0] : null,
                    'text' => $tokenString
                ];

                if ($didStartInsideString === null) {
                    $didStartInsideString = ($token['type'] === T_STRING);
                }
            }

            if (
                $token['type'] === T_COMMENT ||
                $token['type'] === T_DOC_COMMENT || ($didStartInsideString && $token['type'] === T_STRING)
            ) {
                // Do nothing, we just keep parsing. (Comments can occur inside call stacks.)
            } elseif ($code[$i] === '(') {
                ++$parenthesesOpened;

                // Ticket #164 - We're walking backwards, if we find an opening paranthesis that hasn't been closed
                // anywhere, we know we must stop.
                if ($parenthesesOpened > $parenthesesClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === ')') {
                ++$parenthesesClosed;
            }

            elseif ($code[$i] === '[') {
                ++$squareBracketsOpened;

                // Same as above.
                if ($squareBracketsOpened > $squareBracketsClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === ']') {
                ++$squareBracketsClosed;
            } elseif ($code[$i] === '{') {
                ++$squiggleBracketsOpened;

                // Same as above.
                if ($squiggleBracketsOpened > $squiggleBracketsClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === '}') {
                ++$squiggleBracketsClosed;

                if ($parenthesesOpened === $parenthesesClosed) {
                    $nextToken = $currentTokenIndex > 0 ? $tokens[$currentTokenIndex - 1] : null;
                    $nextTokenType = is_array($nextToken) ? $nextToken[0] : null;

                    // Subscopes can only exist when e.g. a closure is embedded as an argument to a function call,
                    // in which case they will be inside parentheses. If we find a subscope outside parentheses, it
                    // means we've moved beyond the call stack to e.g. the end of an if statement.
                    if ($nextTokenType !== T_VARIABLE) {
                        return ++$i;
                    }
                }
            } elseif (
                $parenthesesOpened === $parenthesesClosed &&
                $squareBracketsOpened === $squareBracketsClosed &&
                $squiggleBracketsOpened === $squiggleBracketsClosed
            ) {
                // NOTE: We may have entered a closure.
                if (
                    in_array($code[$i], ['.', ',', '?', ';'], true) ||
                    in_array($token['type'], $expressionBoundaryTokens) ||
                    ($code[$i] === ':' && $token['type'] !== T_DOUBLE_COLON)
                ) {
                    return ++$i;
                } elseif ($token['type'] === T_DOUBLE_COLON) {
                    // For static class names and things like the self and parent keywords, we won't know when to stop.
                    // These always appear the start of the call stack, so we know we can stop if we find them.
                    $startedStaticClassName = true;
                }
            }

            if ($startedStaticClassName && !in_array($token['type'], [T_DOUBLE_COLON, T_STRING, T_NS_SEPARATOR])) {
                return ++$i;
            }
        }

        return $i;
    }

    /**
     * @param string $source
     *
     * @return string[]
     */
    public function retrieveSanitizedCallStackAt($source)
    {
        $boundary = $this->getStartOfExpression($source);

        $expression = substr($source, $boundary);

        return $this->retrieveSanitizedCallStack($expression);
    }

    /**
     * @param string $text
     *
     * @return string[]
     */
    protected function retrieveSanitizedCallStack($text)
    {
        $text = trim($text);
        $text = preg_replace('/\/\/.*\n/', '', $text);         // Remove singe line comments.
        $text = preg_replace('/\/\*(.|\n)*?\*\//', '', $text); // Remove multi-line comments.

        // The start of the call stack may be wrapped in parentheses, e.g. ""(new Foo())->test", unwrap them. Note that
        // "($this)->" is invalid (at least in PHP 5.6).
        $text = preg_replace_callback('/^\(new\s+(.|\n)+?\)/', function ($match) {
            return mb_substr($match[0], 1, -1);
        }, $text);

        if (preg_match('/function\s+([A-Za-z0-9_]\s*)?\(/', $text) === 1) {
            $text = $this->stripPairContent($text, '{', '}');
        }

        // Remove content inside parantheses (including nested parantheses).
        $text = $this->stripPairContent($text, '(', ')');

        $newElements = [];

        if ($text) {
            foreach (explode('->', $text) as $part) {
                foreach (explode('::', $part) as $subPart) {
                    $newElements[] = trim($subPart);
                }
            }
        }

        return $newElements;
    }

    /**
     * @param string $text
     * @param string $openCharacter
     * @param string $closeCharacter
     *
     * @return string
     */
    protected function stripPairContent($text, $openCharacter, $closeCharacter)
    {
        $openCount = 0;
        $closeCount = 0;
        $startIndex = -1;
        $length = mb_strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            if ($text[$i] === $openCharacter) {
                ++$openCount;

                if ($openCount === 1) {
                    $startIndex = $i;
                }
            } elseif ($text[$i] === $closeCharacter) {
                ++$closeCount;

                if ($closeCount === $openCount) {
                    $originalLength = mb_strlen($text);
                    $text = mb_substr($text, 0, $startIndex + 1) . mb_substr($text, $i);

                    $length = mb_strlen($text);
                    $i -= ($originalLength - $length);

                    $openCount = 0;
                    $closeCount = 0;
                }
            }
        }

        return $text;
    }

    /**
     * @see https://secure.php.net/manual/en/tokens.php
     *
     * @return int[]
     */
    protected function getExpressionBoundaryTokens()
    {
        $expressionBoundaryTokens = [
            T_ABSTRACT, T_AND_EQUAL, T_AS, T_BOOLEAN_AND, T_BOOLEAN_OR, T_BREAK, T_CALLABLE, T_CASE, T_CATCH, T_CLASS,
            T_CLONE, T_CLOSE_TAG, T_CONCAT_EQUAL, T_CONST, T_CONTINUE, T_DEC, T_DECLARE, T_DEFAULT, T_DIV_EQUAL, T_DO,
            T_DOUBLE_ARROW, T_ECHO, T_ELSE, T_ELSEIF, T_ENDDECLARE, T_ENDFOR, T_ENDFOREACH, T_ENDIF, T_ENDSWITCH,
            T_ENDWHILE, T_END_HEREDOC, T_EXIT, T_EXTENDS, T_FINAL, T_FOR, T_FOREACH, T_FUNCTION, T_GLOBAL, T_GOTO, T_IF,
            T_IMPLEMENTS, T_INC, T_INCLUDE, T_INCLUDE_ONCE, T_INSTANCEOF, T_INSTEADOF, T_INTERFACE, T_IS_EQUAL,
            T_IS_GREATER_OR_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL,
            T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_MINUS_EQUAL, T_MOD_EQUAL, T_MUL_EQUAL, T_NAMESPACE, T_NEW,
            T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_OR_EQUAL, T_PLUS_EQUAL, T_PRINT, T_PRIVATE, T_PUBLIC, T_PROTECTED,
            T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_SL, T_SL_EQUAL, T_SR, T_SR_EQUAL, T_START_HEREDOC, T_SWITCH,
            T_THROW, T_TRAIT, T_TRY, T_USE, T_VAR, T_WHILE, T_XOR_EQUAL
        ];

        // PHP >= 5.5
        if (defined('T_FINALLY')) {
            $expressionBoundaryTokens[] = T_FINALLY;
        }

        if (defined('T_YIELD')) {
            $expressionBoundaryTokens[] = T_YIELD;
        }

        // PHP >= 5.6
        if (defined('T_ELLIPSIS')) {
            $expressionBoundaryTokens[] = T_ELLIPSIS;
        }

        if (defined('T_POW')) {
            $expressionBoundaryTokens[] = T_POW;
        }

        if (defined('T_POW_EQUAL')) {
            $expressionBoundaryTokens[] = T_POW_EQUAL;
        }

        // PHP >= 7.0
        if (defined('T_SPACESHIP')) {
            $expressionBoundaryTokens[] = T_SPACESHIP;
        }

        return $expressionBoundaryTokens;
    }
}