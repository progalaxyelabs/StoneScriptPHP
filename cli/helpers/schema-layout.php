<?php
/**
 * StoneScriptPHP CLI Helper - where generators write main-database schema files.
 *
 * The gateway commands (gateway:register-main, gateway:migrate-main, schema:export, ...) only
 * read the nested layout:
 *
 *     src/postgresql/main/postgresql/{tables,functions,migrations,...}
 *
 * Generators that emit SQL must write there or the output is never deployed. The older flat layout
 * (src/postgresql/{tables,functions} + ROOT/migrations) is kept ONLY for a project that already
 * uses it, so existing scaffolds keep working; a project with no schema files yet gets the nested
 * layout.
 */

/**
 * @param string $srcPath Absolute path of the project's src/ directory, trailing separator included.
 */
function sspUseNestedMainLayout(string $srcPath): bool
{
    $postgresql = $srcPath . 'postgresql' . DIRECTORY_SEPARATOR;
    if (is_dir($postgresql . 'main' . DIRECTORY_SEPARATOR . 'postgresql')) {
        return true;
    }
    foreach (['tables', 'functions'] as $sub) {
        $flat = $postgresql . $sub;
        if (is_dir($flat) && (glob($flat . DIRECTORY_SEPARATOR . '*.{sql,pgsql,pssql}', GLOB_BRACE) ?: []) !== []) {
            return false;
        }
    }
    return true;
}
