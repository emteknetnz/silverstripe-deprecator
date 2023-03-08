<?php

namespace emteknetnz\Deprecator;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use SilverStripe\Dev\BuildTask;
use PhpParser\Node\Stmt\Property;

class DeprecatedListTask extends BuildTask
{
    private static $segment = 'DeprecatedListTask';

    protected $title = 'DeprecatedListTask';

    protected $description = 'List deprecations in Silverstripe CMS 4';

    private const NEXT_MINOR = '4.13';

    // things to ignore cos they clutter results
    private const IGNORE_KEYS = [
        // 'SearchQuery::filter()',
        // 'SearchQuery::exclude()',
        // 'SearchQuery::filter()',
        // 'SearchQuery::limit()',
        // 'SearchQuery::start()',
        // 'SearchQuery::page()',
        // 'SearchQuery_Range::start()',
        // 'SearchQuery_Range::end()',
        // 'Email::debug()',
        // 'Email::render()',
        // 'Member::logOut()',
        // 'Member::currentUser()',
        // 'Config_ForClass::update()',
        // 'MemoryConfigCollection::update()',
        // 'SSViewer_BasicIteratorSupport::First()',
        // 'SSViewer_BasicIteratorSupport::Last()',
        // 'Comment::getParent()',
        // 'class Handler',
        // 'SiteTreeFileFormFactoryExtension::updateFormFields()',
    ];

    private $output = [];

    private $deprecatedSearchTerms = [];

    private function scanDir(string $dir): array
    {
        return array_diff(scanDir($dir), ['.', '..']);
    }

    public function run($request)
    {
        if (file_exists(BASE_PATH . "/_output")) {
            foreach ($this->scanDir(BASE_PATH . "/_output") as $txt) {
                unlink(BASE_PATH . "/_output/$txt");
            }
        } else {
            mkdir(BASE_PATH . "/_output");
        }
        $vendorDirs = [
            BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            BASE_PATH . '/vendor/symbiote',
            BASE_PATH . '/vendor/bringyourownideas',
            BASE_PATH . '/vendor/colymba',
            BASE_PATH . '/vendor/cwp',
            BASE_PATH . '/vendor/tractorcow',
        ];
        $deprecations = [];
        // list @deprecated classes/config/methods
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach ($this->scanDir($vendorDir) as $subdir) {
                $dir = "$vendorDir/$subdir";
                $module = str_replace(BASE_PATH . '/vendor/', '', $dir);
                if (!array_key_exists($module, $deprecations)) {
                    $deprecations[$module] = [];
                }
                $this->output = [];
                // if ($dir != '/var/www/vendor/silverstripe/assets') {
                //     continue;
                // }
                foreach ([
                    'src',
                    'code',
                    // '_legacy', // don't list this, graphql3 has already been taken care of
                    '_graphql',
                    // 'tests',
                    'thirdparty',
                ] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->listDeprecated($subdir);
                    }
                }
                // OUTPUT TO FILES
                if (empty($this->output)) {
                    continue;
                }

                $deprecations[$module] = array_merge_recursive($deprecations[$module], $this->output);
            }
        }

        ksort($deprecations);
        $path = BASE_PATH . "/_output/deprecations.txt";
        foreach ($deprecations as $module => $stuff) {
            if (empty($stuff)) {
                continue;
            }
            file_put_contents($path, "\n\n$module:\n", FILE_APPEND);
            foreach ($stuff as $type => $actual) {
                $str = "Deprecated $type ";
                file_put_contents($path, $str . implode("\n$str", $actual) . "\n", FILE_APPEND);
            }
        }
        echo "Wrote to $path\n";
        // var_dump($this->deprecatedSearchTerms);
        // // search for deprecated terms
        // foreach ($vendorDirs as $vendorDir) {
        //     if (!file_exists($vendorDir)) {
        //         continue;
        //     }
        //     foreach ($this->scanDir($vendorDir) as $subdir) {
        //         $this->output = [];
        //         $dir = "$vendorDir/$subdir";
        //         foreach ([
        //             'src',
        //             'code',
        //             '_graphql',
        //             'tests',
        //             'thirdparty',
        //         ] as $d) {
        //             $subdir = "$dir/$d";
        //             if (file_exists($subdir)) {
        //                 $this->stringSearchDeprecated($subdir);
        //             }
        //         }
        //         if (empty($this->output)) {
        //             continue;
        //         }
        //         $s = str_replace('/', '-', str_replace('/var/www/vendor/', '', $dir));
        //         $path = BASE_PATH . "/_output/$s.txt";
        //         file_put_contents($path, implode("\n", $this->output));
        //         echo "Wrote to $path\n";
        //     }
        // }
    }

    public function listDeprecated(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
        foreach ($paths as $path) {
            if (is_dir($path)) {
                continue;
            }
            //
            // if (!str_contains($path, 'File.php')) { continue; }
            //
            $code = file_get_contents($path);
            if (strpos($code, "\nenum ") !== false) {
                continue;
            }
            echo "Listing deprecated in $path\n";
            $this->scan($code, $dir);
        }
    }

    // public function stringSearchDeprecated(string $dir)
    // {
    //     $paths = explode("\n", shell_exec("find $dir | grep .php"));
    //     $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
    //     foreach ($paths as $path) {
    //         if (is_dir($path)) {
    //             continue;
    //         }
    //         $code = file_get_contents($path);
    //         if (strpos($code, "\nenum ") !== false) {
    //             continue;
    //         }
    //         $matches = [];
    //         echo "Search for deprecated stings in $path\n";
    //         foreach ($this->deprecatedSearchTerms as $key => $searchTerms) {
    //             foreach ($searchTerms as $searchTerm) {
    //                 $lines = explode("\n", $code);
    //                 foreach ($lines as $num => $line) {
    //                     // offset num to account for <?php
    //                     $num++;
    //                     if (!str_contains($line, $searchTerm)) {
    //                         continue;
    //                     }
    //                     $matches[] = '';
    //                     $matches[] = "\"$key\"";
    //                     $matches[] = "$num: $line";
    //                 }
    //             }
    //         }
    //         if (empty($matches)) {
    //             continue;
    //         }
    //         $this->output[] = "\n\n## $path:";
    //         foreach ($matches as $match) {
    //             $this->output[] = "$match";
    //         }
    //     }
    // }

    private function scan(string $code): void
    {
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $this->scanDeprecated($class, 'class', $class->namespacedName, $class);
            $configs = $this->getConfigs($class);
            foreach ($configs as $config) {
                $name = $config->props[0]->name;
                if (!method_exists($name, 'isFullyQualified') || !$name->isFullyQualified()) {
                    $name = $class->namespacedName . '.' . $name;
                }
                $this->scanDeprecated($config, 'config', $class->namespacedName . '.' . $config->props[0]->name->name, $class);
            }
            $methods = $this->getMethods($class);
            foreach ($methods as $method) {
                if ($method->name->name === '__construct') {
                    continue;
                }
                $this->scanDeprecated($method, 'method', $class->namespacedName . '::' . $method->name->name . '()', $class);
            }
        }
    }

    private function scanDeprecated(Node $node, string $type, string $key, \PhpParser\Node\Stmt\Class_ $class): void
    {
        if (in_array($key, self::IGNORE_KEYS)) {
            return;
        }
        $docComment = $node->getDocComment();
        if (!$docComment) {
            return;
        }
        $docblock = $docComment->getText();
        if (!$docblock) {
            return;
        }
        if (str_contains($docblock, '@deprecated')) {
            $ignoreThese = [
                'oldSyntax' => '/@deprecated \d+\.\d+\.\.\d+\.\d+/',
                'just wrong' => '/@param[\h\w\$]*?@deprecated/'
            ];
            foreach ($ignoreThese as $rx) {
                if (preg_match($rx, $docblock)) {
                    return;
                }
            }
            // $regex = [
            //     'newSyntax' => ,
            //     'forgotVersionNumber' => '/@deprecated Will be renamed /',
            // ];
            if (preg_match('/@deprecated (\d+\.\d+(\.\d+)?)/', $docblock, $match)) {
                if (!str_starts_with($match[1], self::NEXT_MINOR)) {
                    return;
                }
            }
            if (!preg_match('/@deprecated (\d+\.\d+(\.\d+)?) (.*)($|\n)/', $docblock, $match)) {
                var_dump("NO REASON GIVEN for $key");
                var_dump($docblock);
                die(1);
            }
            $reason = $match[3];
            $name = $type == 'config' ? $node->props[0]->name->name : $node->name->name;
            $this->output[$type][] = "[`$key`](api:$key) $reason";
            // // all types can have this
            // // methods can have it via call_user_func()
            // $this->deprecatedSearchTerms[$key] = [];
            // $this->deprecatedSearchTerms[$key][] = "'" . $name . "'";
            // $this->deprecatedSearchTerms[$key][] = '"' . $name . '"';
            // if ($type == 'class') {
            //     $this->deprecatedSearchTerms[$key][] = 'new ' . $name;
            //     $this->deprecatedSearchTerms[$key][] = $name . '::create(';
            // } else if ($type == 'config') {
            //     // already covered by surronding strings that all types have
            // } else if ($type == 'method') {
            //     $this->deprecatedSearchTerms[$key][] = '->' . $name . '(';
            //     /** @var ClassMethod $node */
            //     $method = $node;
            //     if ($method->isStatic()) {
            //         $this->deprecatedSearchTerms[$key][] = $class->namespacedName . '::' . $name . '(';
            //     }
            // }
        }
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
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            die;
        }

        $nameResolver = new \PhpParser\NodeVisitor\NameResolver;
        $nodeTraverser = new \PhpParser\NodeTraverser;
        $nodeTraverser->addVisitor($nameResolver);

        // Resolve names
        $ast = $nodeTraverser->traverse($ast);

        return $ast;
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


    private function getConfigs(Class_ $class): array
    {
        return array_filter(
            $class->stmts, function ($v) {
                /** @var Property $p */
                $p = $v;
                return $p instanceof Property && $p->isPrivate() && $p->isStatic();
            }
        );
    }
}
