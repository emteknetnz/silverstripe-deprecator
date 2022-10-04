<?php

namespace emteknetnz\Deprecator;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use SilverStripe\Dev\BuildTask;

class DeprecationTask extends BuildTask
{
    private static $segment = 'DeprecationTask';

    protected $title = 'DeprecationTask';

    protected $description = 'Used to assist deprecations in Silverstripe CMS 4';

    public function run($request)
    {
        $vendorDirs = [
            // BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            // BASE_PATH . '/vendor/symbiote',
            // BASE_PATH . '/vendor/bringyourownideas',
            // BASE_PATH . '/vendor/colymba',
            // BASE_PATH . '/vendor/cwp',
            // BASE_PATH . '/vendor/tractorcow',
        ];
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach (scandir($vendorDir) as $subdir) {
                if (in_array($subdir, ['.', '..'])) {
                    continue;
                }
                $dir = "$vendorDir/$subdir";
                if ($dir != '/var/www/vendor/silverstripe/framework') {
                    continue;
                }
                foreach (['src', 'code', 'tests', 'thirdparty'] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->update($subdir);
                    }
                }
            }
        }
    }

    public function update(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
        foreach ($paths as $path) {
            if (is_dir($path)) {
                continue;
            }
            $originalCode = file_get_contents($path);
            if (strpos($originalCode, "\nenum ") !== false) {
                continue;
            }
            $newCode = $this->rewriteCode($originalCode, $path);
            if ($originalCode != $newCode) {
                # file_put_contents($path, $newCode);
                # echo "Updated code in $path\n";
            } else {
                // echo "No changes made in $path\n";
            }
        }
    }

    private function rewriteCode(string $code, string $path): string
    {
        file_put_contents(BASE_PATH . '/out-01.php', $code);
        $code = $this->updateMethods($code);
        file_put_contents(BASE_PATH . '/out-02.php', $code);
        return '';
    }

    private function getAst(string $code): array
    {
        $lexer = new Lexer([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                //'startTokenPos',
                //'endTokenPos',
                'startFilePos',
                'endFilePos'
            ]
        ]);
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            die;
        }
        return $ast;
    }

    private function updateMethods(string $code): string
    {
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        foreach ($classes as $class) {
            $methods = $this->getMethods($class);
            // reverse methods so 'updating from the bottom' so that character offests remain correct
            $methods = array_reverse($methods);
            foreach ($methods as $method) {
                $hasDocblock = false; // @see NodeAbstract::setDocComment() .. may be easier that string adding to file_put_contents (or maybe worse)
                $docComment = $method->getDocComment(); // this contains the file offset info
                $docblock = '';
                if ($docComment !== null) {
                    $hasDocblock = true;
                    $docblock = $docComment->getText();
                }
                $docblockHasDeprecated = strpos($docblock, '@deprecated') !== false;
                $len = $method->getEndFilePos() - $method->getStartFilePos() + 1;
                // note: method body includes brackets and indentation
                $methodBody = substr($code, $method->getStartFilePos(), $len);
                $methodBodyHasDeprecated = strpos($methodBody, 'Deprecation::notice(') !== false;
                if (!$docblockHasDeprecated && !$methodBodyHasDeprecated) {
                    continue;
                }
                $from = null;
                $deprecationFromDocblock = '';
                if ($docblockHasDeprecated) {
                    list ($deprecationFromDocblock, $from) = $this->extractCleanDeprecationFromDocblock($docblock);
                }
                print_r([
                    $deprecationFromDocblock,
                    $from,
                    $methodBodyHasDeprecated
                ]);
                continue;
                // use @deprecated as the source of truth

                // process method body first so as to 'update from the bottom'
                if ($methodBodyHasDeprecated) {
                    // standardise the Deprecation::notice()
                } else if (!$methodBodyHasDeprecated) {
                    // add a standardised Deprecation::notice()
                }
                if ($docblockHasDeprecated) {
                    // standardise the @deprecated
                } elseif (!$docblockHasDeprecated) {
                    // add a standardised @deprecated
                }
                var_dump([
                    $docblock,
                    $methodBody
                ]);
                die;
                // $code = implode('', [
                //     substr($code, 0, $method->getStartFilePos()),
                //     "#[\ReturnTypeWillChange]\n    ",
                //     substr($code, $method->getStartFilePos()),
                // ]);
            }
        }
        return $code;
    }

    /**
     * @return array - [$cleanDocblock, $from]
     */
    private function extractCleanDeprecationFromDocblock(string $docblock): array
    {
        $start = strpos($docblock, '@deprecated');
        // handle multiline deprecations
        $endNewline = strpos($docblock, "*\n", $start);
        $endOther = strpos($docblock, "* @", $start);
        $endEnd = strpos($docblock, "*/", $start); // lol
        $arr = array_filter([$endNewline, $endOther, $endEnd]);
        $end = min($arr);
        $str = substr($docblock, $start, $end - $start);
        $str = str_replace('     * ', '', $str);
        $str = str_replace("\n", ' ', $str);
        $str = trim($str);
        $str = preg_replace('#@deprecated ([0-9])\.\.([0-9])+#', '@deprecated $1:$2', $str);
        $from = null;
        $rx = '#@deprecated ([0-9\.:]+)#';
        if (preg_match($rx, $str, $m)) {
            $from = $m[1];
            $pos = strpos($from, ':');
            if ($pos !== false) {
                $from = substr($from, 0, $pos);
                preg_replace($rx, "@deprecated $from", $docblock);
            }
        }
        if (preg_match('#^[0-9]+\.[0-9]+$#', $from)) {
            $from = "$from.0";
        }
        // convert for use in Deprecation::notice()
        if ($from === null || $from === '4.0.0' || $from === '5.0.0') {
            $from = '4.12.0';
        }
        return [$str, $from];
    }

    private function getNamespace(array $ast): ?Namespace_
    {
        return ($ast[0] ?? null) instanceof Namespace_ ? $ast[0] : null;
    }

    private function getClasses(array $ast): array
    {
        $ret = [];
        $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
        $ret = array_merge($ret, array_filter($a, fn($v) => $v instanceof Class_));
        // SapphireTest and other file with dual classes
        $i = array_filter($a, fn($v) => $v instanceof If_);
        foreach ($i as $if) {
            foreach ($if->stmts ?? [] as $v) {
                if ($v instanceof Class_) {
                    $ret[] = $v;
                }
            }
        }
        return $ret;
    }

    private function getMethods(Class_ $class): array
    {
        return array_filter($class->stmts, fn($v) => $v instanceof ClassMethod);
    }
}
