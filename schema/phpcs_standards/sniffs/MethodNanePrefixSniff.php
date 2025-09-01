<?php
namespace MyProject\Sniffs\NamingConventions;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class MethodNamePrefixSniff implements Sniff
{
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array<int, int>
     */
    public function register()
    {
        return [T_FUNCTION];
    }

    /**
     * Processes the tokens that this sniff is interested in.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file where the token was found.
     * @param int                         $stackPtr  The position in the stack where
     *                                                 the token was found.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // Find the function name
        $functionNamePtr = $phpcsFile->findNext(T_STRING, $stackPtr);
        if ($functionNamePtr === false) {
            return;
        }
        $methodName = $tokens[$functionNamePtr]['content'];

        // Check if it's a method (i.e., inside a class or interface)
        $isMethod = false;
        $openScope = $phpcsFile->findPrevious(Tokens::$scopeOpeners, $stackPtr);
        if ($openScope !== false) {
            if ($tokens[$openScope]['code'] === T_CLASS || $tokens[$openScope]['code'] === T_INTERFACE || $tokens[$openScope]['code'] === T_TRAIT) {
                $isMethod = true;
            }
        }

        if ($isMethod && !preg_match('/^starmus_/', $methodName)) {
            $error = 'Method name "%s" must be prefixed with "starmus_".';
            $phpcsFile->addError($error, $functionNamePtr, 'MissingPrefix', [$methodName]);
        }
    }
}