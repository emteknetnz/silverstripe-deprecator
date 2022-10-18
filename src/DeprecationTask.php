<?php

namespace emteknetnz\Deprecator;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use SilverStripe\Dev\BuildTask;
use PhpParser\PrettyPrinter;
use SilverStripe\Dev\Deprecation;

class DeprecationTask extends BuildTask
{
    private static $segment = 'DeprecationTask';

    protected $title = 'DeprecationTask';

    protected $description = 'Used to assist deprecations in Silverstripe CMS 4';

    private $c = 0;
    private $maxC = 200000;

    private $updatedDirs = [];

    public function run($request)
    {
        $vendorDirs = [
            BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            BASE_PATH . '/vendor/symbiote',
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
                if ($dir != '/var/www/vendor/silverstripe/cms') {
                    continue;
                }
                /*
                D admin
                D asset-admin
                W assets
                D behat-extension
                D campaign-admin
                D cms
                D config
                D framework`
                D recipe-plugin
                D vendor-plugin
                D versioned
                D versioned-admin
                D dnadesign/silverstripe-elemental
                None symbiote/silvestripe-gridfieldextensions
                */
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
        echo "Update in dirs:\n";
        print_r($this->updatedDirs);
    }


    public function update(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
        foreach ($paths as $path) {
            $this->cTest();
            if (is_dir($path)) {
                continue;
            }
            // these files have messed up indendation, do manually
            if (strpos($path, 'SapphireTest.php') !== false || strpos($path, 'FunctionalTest.php') !== false) {
                continue;
            }
            $originalCode = file_get_contents($path);
            if (strpos($originalCode, "\nenum ") !== false) {
                continue;
            }
            $newCode = $this->rewriteCode($originalCode, $path);
            if ($originalCode != $newCode) {
                file_put_contents($path, $newCode);
                echo "Updated code in $path\n";
                $this->updatedDirs[$dir] = true;
            } else {
                // echo "No changes made in $path\n";
            }
        }
    }

    private function rewriteCode(string $code, string $path): string
    {
        file_put_contents(BASE_PATH . '/out-01.php', $code);
        $code = $this->updateMethods($code);
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
        $importDeprecationClass = false;
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $this->cTest();
            $docComment = $class->getDocComment();
            $docblock = '';
            if ($docComment !== null) {
                $docblock = $docComment->getText();
            }
            if (strpos($docblock, ' * @deprecated') === false) {
                continue;
            }
            list (
                $docblockFrom,
                $messageFromDocblock,
                $newDocblock
            ) = $this->extractFromDocblock($docblock, '', '', '');

            // Update constructor with Deprecation::notice SCOPE_CLASS
            $methods = $this->getMethods($class);
            $hasConstructor = false;
            foreach ($methods as $method) {
                $this->cTest();
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
                        if (strpos($methodBody, 'Deprecation::notice(') === false) {
                            $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock, $docblockFrom, Deprecation::SCOPE_CLASS);
                            $importDeprecationClass = true;
                        } else {
                            $methodBody = preg_replace("#\s+Deprecation::notice.+?\);#s", '', $methodBody);
                            $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock, $docblockFrom, Deprecation::SCOPE_CLASS);
                        }
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
                                'public function __construct()',
                                '    {',
                                "        Deprecation::notice($s2);",
                                '    }',
                                '',
                                ''
                            ]),
                            '    ' . substr($code, $method->getStartFilePos()),
                        ]);
                        $importDeprecationClass = true;
                        break;
                    }
                } else {
                    $lines = explode("\n", $code);
                    $i = -1;
                    foreach ($lines as $line) {
                        $i++;
                        if (str_contains($line, 'class')) {
                            break;
                        }
                    }
                    $lines = array_merge(
                        array_slice($lines, 0, $i + 2),
                        [implode("\n", [
                            '    public function __construct()',
                            '    {',
                            "        Deprecation::notice($s2);",
                            '    }',
                        ])],
                        array_slice($lines, $i + 2),
                    );
                    $code = implode("\n", $lines);
                    $importDeprecationClass = true;
                }
            }

            // standardise the @deprecated
            $code = implode('', [
                substr($code, 0, $docComment->getStartFilePos()),
                $newDocblock,
                substr($code, $docComment->getEndFilePos() + 1),
            ]);
        }
        if ($importDeprecationClass) {
            $this->addImport($code);
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
            $this->cTest();
            $methods = $this->getMethods($class);
            // reverse methods so 'updating from the bottom' so that character offests remain correct
            $methods = array_reverse($methods);
            foreach ($methods as $method) {
                $this->cTest();
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
                $noticeFrom = '';
                $messageFromNotice = '';
                $docblockFrom = '';
                $messageFromDocblock = '';
                $newDocblock = $docblock;
                if ($methodBodyHasNotice) {
                    list (
                        $noticeFrom,
                        $messageFromNotice,
                    ) = $this->extractFromMethodBody($methodBody, $docblockFrom, $messageFromNotice);
                }
                if ($docblockHasDeprecated) {
                    list (
                        $docblockFrom,
                        $messageFromDocblock,
                        $newDocblock
                    ) = $this->extractFromDocblock($docblock, $noticeFrom, $messageFromNotice);
                }
                // process method body first so as to 'update from the bottom'
                if ($methodBodyHasNotice) {
                    $methodBody = preg_replace("#\s+Deprecation::notice.+?\);#s", '', $methodBody);
                    $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock ?: $messageFromNotice, $docblockFrom ?: $noticeFrom);
                } else if (!$methodBodyHasNotice) {
                    // add a standardised Deprecation::notice() to method body
                    $this->addNoticeToMethod($methodBody, $method, $code, $messageFromDocblock, $docblockFrom);
                    $importDeprecationClass = true;
                }
                if ($hasDocblock) {
                    if (!$docblockHasDeprecated) {
                        $newDocblock = str_replace(
                            "     */",
                            "     * @deprecated $noticeFrom $messageFromNotice\n     */",
                            $newDocblock
                        );
                    }
                    $code = implode('', [
                        substr($code, 0, $docComment->getStartFilePos()),
                        $newDocblock,
                        substr($code, $docComment->getEndFilePos() + 1),
                    ]);
                } elseif (!$hasDocblock) {
                    // add a standardised @deprecated
                    $code = implode("\n", [
                        substr($code, 0, $method->getStartFilePos()) . "/**",
                        "     * @deprecated $noticeFrom $messageFromNotice",
                        "     */",
                        "    " . substr($code, $method->getStartFilePos()),
                    ]);
                }
            }
        }
        if ($importDeprecationClass) {
            $this->addImport($code);
        }
        return $code;
    }

    private function addImport(&$code)
    {
        if (strpos($code, 'use SilverStripe\\Dev\\Deprecation;') !== false) {
            return;
        }
        $rx = "#(\nnamespace [\\a-zA-Z0-9]+?;\n\n)#";
        if (preg_match($rx, $code, $m)) {
            $code = preg_replace($rx, '$1' . "use SilverStripe\\Dev\\Deprecation;\n", $code, 1);
        } else {
            $code = str_replace("<?php\n\n", "<?php\n\nuse SilverStripe\\Dev\\Deprecation;\n", $code);
        }
    }

    private function cTest()
    {
        if ($this->c++ > $this->maxC) {
            echo "MAX C\n";
            die;
        }
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

    private function extractFromDocblock($docblock, $noticeFrom = '', $messageFromNotice = '', $indent = '    '): array
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
        $from = $from ?: $noticeFrom;
        $from = $this->cleanFrom($from);
        $message = trim(str_replace(["@deprecated $origFrom"], '', $deprecated));
        if (!$message && $messageFromNotice) {
            $deprecated = $deprecated . ' ' . $messageFromNotice;
        }
        if (preg_match('#@deprecated [a-zA-Z]#', $deprecated)) {
            $deprecated = str_replace('@deprecated', "@deprecated $from", $deprecated);
        }
        $rx = '#(@deprecated [0-9\.]+ )([a-z])#';
        if (preg_match($rx, $deprecated, $m)) {
            $deprecated = preg_replace($rx, '$1' . strtoupper($m[2]), $deprecated);
        }
        $newDocblock = implode('', [
            substr($docblock, 0, $start),
            str_replace($origFrom, $from, $deprecated),
            substr($docblock, $end),
        ]);
        return [$from, $message, $newDocblock];
    }

    private function cleanFrom($from)
    {
        if (preg_match('#^[0-9]+\.[0-9]+$#', $from ?? '')) {
            $from = "$from.0";
        }
        // convert for use in Deprecation::notice()
        if (!$from || $from === '5.0.0') {
            $from = '4.12.0';
        }
        if ($from === '4.0.0') {
            $from = '4.0.1';
        }
        return $from;
    }

    private function extractFromMethodBody(string $methodBody): array
    {
        $find = 'Deprecation::notice(';
        $start = strpos($methodBody, $find);
        $end = strpos($methodBody, ");\n", $start);
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
        } catch (Error|\Exception $error) {
            echo "$s\n";
            echo "Parse error in " . __FUNCTION__ . ": {$error->getMessage()}\n";
            die;
        }
        $args = $ast[0]->stmts[0]->stmts[0]->expr->args;
        $from = $args[0]->value->value ?? '4.12.0';
        $from = $this->cleanFrom($from);
        $prettyPrinter = new PrettyPrinter\Standard;
        $cleanMessage = isset($args[1]) ? $prettyPrinter->prettyPrint([$args[1]]) : '';
        // remove brackets
        if (strlen($cleanMessage)) {
            $cleanMessage = substr($cleanMessage, 1, strlen($cleanMessage) - 2);
        }
        return [$from, $cleanMessage];
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
