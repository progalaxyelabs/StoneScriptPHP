<?php

declare(strict_types=1);

namespace StoneScriptPHP\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Advisory PHPStan rule: nudge raw config/secret access onto the central
 * StoneScriptPHP\Env path.
 *
 * Flags, when used OUTSIDE the framework's Env class:
 *   - getenv(...)                         function call
 *   - putenv(...)                         function call
 *   - $_ENV[...]                          superglobal array access
 *   - $_SERVER[...]                       superglobal array access
 *
 * The intent is advisory (non-fatal). It carries a stable error identifier so
 * legitimate exceptions can be silenced with a phpstan-ignore annotation or via
 * `ignoreErrors` in a platform's phpstan.neon, and it points the developer at
 * `Env::secret('X')`, which reads /run/secrets/X and <X>_FILE natively and is
 * immune to PHP-FPM clear_env.
 *
 * @implements Rule<Node>
 */
final class RawEnvAccessRule implements Rule
{
    /**
     * Stable identifier so the advisory is silenceable via a phpstan-ignore
     * annotation and reportUnmatchedIgnoredErrors-friendly.
     */
    public const IDENTIFIER = 'stonescriptphp.rawEnvAccess';

    /**
     * The Env class is the one place that is allowed to touch the
     * environment / secrets directly — it owns the resolution chain.
     */
    private const ALLOWED_CLASS = \StoneScriptPHP\Env::class;

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Never flag access made from inside the Env class itself.
        $classReflection = $scope->getClassReflection();
        if ($classReflection !== null) {
            $name = $classReflection->getName();
            if ($name === self::ALLOWED_CLASS || $name === ltrim(self::ALLOWED_CLASS, '\\')) {
                return [];
            }
        }

        if ($node instanceof FuncCall) {
            return $this->processFuncCall($node);
        }

        if ($node instanceof ArrayDimFetch) {
            return $this->processArrayDimFetch($node);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processFuncCall(FuncCall $node): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $fn = strtolower($node->name->toString());
        if ($fn !== 'getenv' && $fn !== 'putenv') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw %s() bypasses StoneScriptPHP\\Env config/secret resolution.',
                $fn
            ))
                ->identifier(self::IDENTIFIER)
                ->tip("prefer Env::secret('X') — reads /run/secrets/X and <X>_FILE natively (immune to PHP-FPM clear_env)")
                ->build(),
        ];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processArrayDimFetch(ArrayDimFetch $node): array
    {
        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return [];
        }

        $varName = $node->var->name;
        if ($varName !== '_ENV' && $varName !== '_SERVER') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw $%s[] superglobal access bypasses StoneScriptPHP\\Env config/secret resolution.',
                $varName
            ))
                ->identifier(self::IDENTIFIER)
                ->tip("prefer Env::secret('X') — reads /run/secrets/X and <X>_FILE natively (immune to PHP-FPM clear_env)")
                ->build(),
        ];
    }
}
