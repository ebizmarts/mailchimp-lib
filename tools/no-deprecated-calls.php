<?php
/**
 * Fails when a deprecated PHP call appears unguarded in src/.
 *
 * This library declares php >=5.2.0 and means it, so a modernisation ruleset
 * is the wrong tool: most of its rules would demand the PHP 7/8 syntax this
 * code must not emit. What is wanted is narrower — a deny-list of calls that
 * are deprecated on new PHP, flagged only where nothing guards them.
 *
 * It tokenises rather than greps, for two reasons that are both real in this
 * repository today:
 *
 *   - `curl_close()` is called on purpose, inside `if (PHP_VERSION_ID < 80000)`,
 *     because on 5.x the handle is a resource and that call is what frees it.
 *   - `'money_format'` appears as an array key in EcommerceStores.php.
 *
 * A grep-based gate reports both and is red the day it lands, which is how a
 * gate gets switched off.
 *
 * Usage: php tools/no-deprecated-calls.php [path ...]
 * Exit:  0 clean, 1 findings, 2 usage error.
 */

$DENIED_CALLS = array(
    'curl_close',
    'utf8_encode',
    'utf8_decode',
    'each',
    'create_function',
    'money_format',
    'strftime',
    'get_magic_quotes_gpc',
);

$DENIED_CONSTANTS = array(
    'FILTER_SANITIZE_STRING',
);

$paths = array_slice($argv, 1);
if (!$paths) {
    $paths = array(dirname(__DIR__) . '/src');
}

/**
 * Every .php file under the given paths.
 */
function collect(array $paths)
{
    $files = array();
    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;
            continue;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "not a file or directory: $path\n");
            exit(2);
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $files[] = $f->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

/**
 * Index of the next token that is not whitespace or a comment.
 */
function nextSignificant(array $tokens, $i)
{
    for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue;
        }
        return $j;
    }
    return -1;
}

/**
 * Index of the previous token that is not whitespace or a comment.
 */
function prevSignificant(array $tokens, $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue;
        }
        return $j;
    }
    return -1;
}

$findings = array();
$scanned  = 0;

foreach (collect($paths) as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $scanned++;

    $depth   = 0;
    $guards  = array();   // brace depth => opened by a PHP_VERSION guard
    $pending = null;      // guard verdict for the block about to open

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && ($token[0] === T_IF || $token[0] === T_ELSEIF)) {
            $open = nextSignificant($tokens, $i);
            if ($open >= 0 && $tokens[$open] === '(') {
                $level = 0;
                $cond  = '';
                for ($j = $open; $j < $n; $j++) {
                    $t = $tokens[$j];
                    $text = is_array($t) ? $t[1] : $t;
                    $cond .= $text;
                    if ($t === '(') { $level++; }
                    if ($t === ')') { $level--; if ($level === 0) { break; } }
                }
                // A version test is what makes a deprecated call deliberate.
                $pending = (strpos($cond, 'PHP_VERSION') !== false);
                $i = $j;
            }
            continue;
        }

        if (!is_array($token)) {
            if ($token === '{') {
                $depth++;
                $guards[$depth] = ($pending === true);
                $pending = null;
                continue;
            }
            if ($token === '}') {
                unset($guards[$depth]);
                $depth--;
                continue;
            }
            if ($token === ';') {
                // `if (...) foo();` with no braces — the verdict does not carry.
                $pending = null;
            }
            continue;
        }

        if ($token[0] !== T_STRING) {
            continue;
        }

        $name = $token[1];
        $isCall     = in_array(strtolower($name), array_map('strtolower', $DENIED_CALLS), true);
        $isConstant = in_array($name, $DENIED_CONSTANTS, true);
        if (!$isCall && !$isConstant) {
            continue;
        }

        // A method or a declaration of the same name is not the call we mean.
        $p = prevSignificant($tokens, $i);
        if ($p >= 0 && is_array($tokens[$p])
            && in_array($tokens[$p][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW), true)) {
            continue;
        }

        $s = nextSignificant($tokens, $i);
        $followedByParen = ($s >= 0 && $tokens[$s] === '(');

        if ($isCall && !$followedByParen) {
            continue;   // a bare word that happens to match, not an invocation
        }
        if ($isConstant && $followedByParen) {
            continue;
        }

        if (in_array(true, $guards, true)) {
            continue;   // deliberate: inside a PHP_VERSION guard
        }

        $findings[] = array($file, $token[2], $name);
    }
}

foreach ($findings as $f) {
    printf("%s:%d  %s is deprecated and is not inside a PHP_VERSION guard\n", $f[0], $f[1], $f[2]);
}

printf("\nscanned %d file(s), %d finding(s)\n", $scanned, count($findings));

exit($findings ? 1 : 0);
