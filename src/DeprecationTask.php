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
use PhpParser\NodeDumper;
use SilverStripe\Dev\BuildTask;
use PhpParser\PrettyPrinter;
use SilverStripe\Dev\Deprecation;

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
                foreach ([
                    'src',
                    'code',
                    // 'tests',
                    // 'thirdparty'
                ] as $d) {
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
            //
            // if (!preg_match('#SapphireTest.php#', $path)) {
            //     continue;
            // }
            //
            $originalCode = file_get_contents($path);
            if (strpos($originalCode, "\nenum ") !== false) {
                continue;
            }
            $newCode = $this->rewriteCode($originalCode, $path);
            if ($originalCode != $newCode) {
                // file_put_contents($path, $newCode);
                echo "Updated code in $path\n";
            } else {
                # echo "No changes made in $path\n";
            }
        }
    }

    private function rewriteCode(string $code, string $path): string
    {
        file_put_contents(BASE_PATH . '/out-01.php', $code);
        //$code = $this->updateMethods($code);
        $code = $this->updateClass($code);
        file_put_contents(BASE_PATH . '/out-02.php', $code);
        return $code;
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

    private function updateClass(string $code): string
    {
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $docComment = $class->getDocComment();
            $docblock = '';
            if ($docComment !== null) {
                $hasDocblock = true;
                $docblock = $docComment->getText();
            }
            if (strpos($docblock, ' * @deprecated') === false) {
                continue;
            }
            list (
                $cleanDeprecatedFromDocblock,
                $docblockFrom,
                $messageFromDocblock,
                $newDocblock
            ) = $this->extractFromDocblock($docblock, '');

            // Update constructor with Deprecation::notice SCOPE_CLASS
            $methods = $this->getMethods($class);
            $hasConstructor = false;
            foreach ($methods as $method) {
                if ($method->name->name === '__construct') {
                    $hasConstructor = true;
                    break;
                }
            }
            if ($hasConstructor) {
                foreach ($methods as $method) {
                    if ($method->name->name === '__construct') {
                        $len = $method->getEndFilePos() - $method->getStartFilePos() + 1;
                        // note: method body includes brackets and indentation
                        $methodBody = substr($code, $method->getStartFilePos(), $len);
                        $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock, $docblockFrom, Deprecation::SCOPE_CLASS);
                    }
                }
            } else {
                $s = str_replace("'", "\\'", ucfirst($messageFromDocblock));
                $s2 = $s ? "'$docblockFrom', '$s'" : "'$docblockFrom'";
                $s2 .= ", Deprecation::SCOPE_CLASS";
                if (count($methods)) {
                    foreach ($methods as $method) {
                        $code = implode('', [
                            substr($code, 0, $method->getStartFilePos()),
                            implode("\n", [
                                'public function __construct(): void',
                                '    {',
                                "        Deprecation::notice($s2);",
                                '    }',
                                '',
                                ''
                            ]),
                            '    ' . substr($code, $method->getStartFilePos()),
                        ]);
                        break;
                    }
                } else {
                    // deprecated class with no methods edge case, probably don't exist
                    var_dump('EDGE CASE');die;
                }
            }

            // standardise the @deprecated
            $code = implode('', [
                substr($code, 0, $docComment->getStartFilePos()),
                $newDocblock,
                substr($code, $docComment->getEndFilePos() + 1),
            ]);
        }
        return $code;
    }

    private function updateMethods(string $code): string
    {
        $importDeprecationClass = false;
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $methods = $this->getMethods($class);
            // reverse methods so 'updating from the bottom' so that character offests remain correct
            $methods = array_reverse($methods);
            foreach ($methods as $method) {
                if ($method->name->name === '__construct') {
                    continue;
                }
                $hasDocblock = false; // @see NodeAbstract::setDocComment() .. maybe easier that string adding to file_put_contents (or maybe worse)
                $docComment = $method->getDocComment(); // this contains the file offset info
                $docblock = '';
                if ($docComment !== null) {
                    $hasDocblock = true;
                    $docblock = $docComment->getText();
                }
                $docblockHasDeprecated = strpos($docblock, '* @deprecated') !== false;
                $len = $method->getEndFilePos() - $method->getStartFilePos() + 1;
                // note: method body includes brackets and indentation
                $methodBody = substr($code, $method->getStartFilePos(), $len);
                // spaces before Deprecation::notice( are important, as we only want deprecated
                // methods, not deprecated param types
                $methodBodyHasNotice = strpos($methodBody, "\n        Deprecation::notice(") !== false;
                if (!$docblockHasDeprecated && !$methodBodyHasNotice) {
                    continue;
                }
                $from = null;
                $cleanDeprecatedFromDocblock = '';
                $docblockFrom = '';
                $messageFromDocblock = '';
                $newDocblock = $docblock;
                $messageFromNotice = '';
                $noticeFrom = '';
                if ($docblockHasDeprecated) {
                    list (
                        $cleanDeprecatedFromDocblock,
                        $docblockFrom,
                        $messageFromDocblock,
                        $newDocblock
                    ) = $this->extractFromDocblock($docblock);
                }
                if ($methodBodyHasNotice) {
                    list (
                        $messageFromNotice,
                        $noticeFrom
                    ) = $this->extractFromMethodBody($methodBody);
                }
                // process method body first so as to 'update from the bottom'
                if ($methodBodyHasNotice) {
                    // do nothing - do not bother standardising
                } else if (!$methodBodyHasNotice) {
                    // add a standardised Deprecation::notice() to method body
                    $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock, $docblockFrom);
                    $importDeprecationClass = true;
                }
                if ($hasDocblock) {
                    // standardise the @deprecated
                    $code = implode('', [
                        substr($code, 0, $docComment->getStartFilePos()),
                        $newDocblock,
                        substr($code, $docComment->getEndFilePos() + 1),
                    ]);
                } elseif (!$hasDocblock) {
                    // add a standardised @deprecated
                    $code = implode("\n", [
                        substr($code, 0, $method->getStartFilePos()) . "/**",
                        "     * @deprecated $messageFromNotice",
                        "     */",
                        "    " . substr($code, $method->getStartFilePos()),
                    ]);
                }
            }
        }
        if ($importDeprecationClass && strpos($code, 'use SilverStripe\\Dev\\Deprecation;') === false) {
            $rx = "#(\nnamespace [\\a-zA-Z0-9]+?;\n\n)#";
            if (preg_match($rx, $code, $m)) {
                $code = preg_replace($rx, '$1' . "use SilverStripe\\Dev\\Deprecation;\n", $code, 1);
            } else {
                $code = str_replace("<?php\n\n", "<?php\n\nuse SilverStripe\\Dev\\Deprecation;\n", $code);
            }
        }
        return $code;
    }

    private function addNoticeToMethod($methodBody, $method, &$code, $messageFromDocblock, $docblockFrom, $scope = Deprecation::SCOPE_METHOD)
    {
        $bodyArr = explode("\n", $methodBody);
        for ($i = 0; $i < count($bodyArr); $i++) {
            $v = $bodyArr[$i];
            if (trim($v) == '{') {
                $s = str_replace("'", "\\'", ucfirst($messageFromDocblock));
                $s2 = $s ? "'$docblockFrom', '$s'" : "'$docblockFrom'";
                if ($scope == Deprecation::SCOPE_CLASS) {
                    $s2 .= ", Deprecation::SCOPE_CLASS";
                }
                $bodyArr = array_merge(
                    array_slice($bodyArr, 0, $i + 1),
                    ["        Deprecation::notice($s2);"],
                    array_slice($bodyArr, $i + 1),
                );
                break;
            }
        }
        $code = implode("", [
            substr($code, 0, $method->getStartFilePos()),
            implode("\n", $bodyArr),
            substr($code, $method->getEndFilePos() + 1),
        ]);
    }

    private function extractFromDocblock(string $docblock, $indent = '    '): array
    {
        $start = strpos($docblock, '@deprecated');
        // handle multiline deprecations
        $end5Newline = strpos($docblock, "\n$indent *\n", $start);
        $end5Other = strpos($docblock, "\n$indent * @", $start);
        $end5End = strpos($docblock, "\n$indent */", $start);
        $end7Newline = strpos($docblock, "\n$indent   *\n", $start);
        $end7Other = strpos($docblock, "\n$indent   * @", $start);
        $end7End = strpos($docblock, "\n$indent   */", $start);
        $end9Newline = strpos($docblock, "\n$indent     *\n", $start);
        $end9Other = strpos($docblock, "\n$indent     * @", $start);
        $end9End = strpos($docblock, "\n$indent     */", $start);
        $arr = array_filter([
            $end5Newline,
            $end5Other,
            $end5End,
            $end7Newline,
            $end7Other,
            $end7End,
            $end9Newline,
            $end9Other,
            $end9End,
        ]);
        $end = min($arr);
        $deprecated = substr($docblock, $start, $end - $start);
        $deprecated = str_replace('     * ', '', $deprecated);
        $deprecated = str_replace("\n", ' ', $deprecated);
        $deprecated = trim($deprecated);
        $deprecated = preg_replace('#@deprecated ([0-9])\.\.([0-9])+#', '@deprecated $1:$2', $deprecated);
        $from = null;
        $rx = '#@deprecated ([0-9\.\:]+)#';
        if (preg_match($rx, $deprecated, $m)) {
            $from = $m[1];
            $pos = strpos($from, ':');
            if ($pos === false) {
                $pos = strpos($from, '..');
            }
            if ($pos !== false) {
                $from = substr($from, 0, $pos);
                $deprecated = preg_replace($rx, "@deprecated $from", $deprecated);
            }
        }
        $origFrom = $from;
        if (preg_match('#^[0-9]+\.[0-9]+$#', $from ?? '')) {
            $from = "$from.0";
        }
        // convert for use in Deprecation::notice()
        if ($from === null || $from === '4.0.0' || $from === '5.0.0') {
            $from = '4.12.0';
        }
        $message = trim(str_replace(["@deprecated $origFrom"], '', $deprecated));
        $newDocblock = implode('', [
            substr($docblock, 0, $start),
            $deprecated,
            substr($docblock, $end),
        ]);
        return [$deprecated, $from, $message, $newDocblock];
    }

    private function extractFromMethodBody(string $methodBody): array
    {
        $find = 'Deprecation::notice(';
        $start = strpos($methodBody, $find);
        $end = strpos($methodBody, ");\n");
        if (!$end) {
            echo $methodBody;
            echo __FUNCTION__ . " - No end \n";
            die;
        }
        $str = substr($methodBody, $start, $end - $start + 2);
        $s = "<?php\nclass C{\nfunction F(){\n$str\n}\n}";
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        try {
            $ast = $parser->parse($s);
        } catch (Error $error) {
            echo "Parse error in " . __FUNCTION__ . ": {$error->getMessage()}\n";
            die;
        }
        $args = $ast[0]->stmts[0]->stmts[0]->expr->args;
        $from = $args[0]->value->value ?? 'UNKNOWN FROM';
        $prettyPrinter = new PrettyPrinter\Standard;
        $cleanMessage = isset($args[1]) ? $prettyPrinter->prettyPrint([$args[1]]) : '';
        // remove brackets
        if (strlen($cleanMessage)) {
            $cleanMessage = substr($cleanMessage, 1, strlen($cleanMessage) - 2);
        }
        return [$cleanMessage, $from];
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
