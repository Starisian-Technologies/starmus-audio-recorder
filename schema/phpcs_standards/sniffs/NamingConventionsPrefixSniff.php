<?php
namespace MyProject\Sniffs\NamingConventions;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

class GlobalVariablePrefixSniff implements Sniff
{
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array<int, int>
     */
    public function register()
    {
        return [T_VARIABLE];
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
        $varName = $tokens[$stackPtr]['content'];

        // Check if it's a global variable declaration.
        // This is a simplified check. A more robust sniff might look for "global $" keyword.
        // For simplicity, this sniff will check all variables unless specifically excluded.
        // You might need to refine this to specifically target *global* variables,
        // perhaps by checking for the 'global' keyword or by looking for variable usage outside of functions/classes.

        // For now, let's just check variables that might represent globals.
        // If it's a global variable (e.g., accessed without $this-> or function parameters)
        // this sniff would trigger.
        // A better approach for true "global variables" might involve detecting `global $var;` or
        // variables defined in the global scope. For simplicity here, we'll check any variable
        // that doesn't start with $this-> (which would be an object property).

        // Let's assume for this example, we're checking for non-class properties
        // or variables likely intended as globals. This is a tricky area as PHPCS
        // mostly deals with syntax.
        // A simpler interpretation for this sniff might be: "All variables in the global scope
        // OR *any* variable that could be mistaken for a global if not prefixed".

        // For a more precise "global variable" detection:
        // We'd need to trace scope, which is more complex.
        // Let's just assume variables starting with $ (not $this) at the top level or
        // declared with 'global' keyword.

        // Simplistic approach for demonstration: If it's not a property, check prefix.
        // This will apply to *all* local variables too unless scope is deeply analyzed.
        // To only target *true* global variables, you'd need more complex scope analysis within PHPCS.

        // For now, let's consider any variable not part of an object.
        $isProperty = false;
        $prev = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, null, true);
        if ($tokens[$prev]['code'] === T_OBJECT_OPERATOR) {
            $isProperty = true;
        }

        if (!$isProperty && !preg_match('/^\$STAR_|^$star_/', $varName)) {
            $error = 'Global variable "%s" must be prefixed with STAR_ or star_.';
            $phpcsFile->addError($error, $stackPtr, 'MissingPrefix', [$varName]);
        }
    }
}