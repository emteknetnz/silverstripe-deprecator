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

    // things to ignore cos they clutter results
    private const IGNORE_KEYS = [
        'SearchQuery::filter()',
        'SearchQuery::exclude()',
        'SearchQuery::filter()',
        'SearchQuery::limit()',
        'SearchQuery::start()',
        'SearchQuery::page()',
        'SearchQuery_Range::start()',
        'Email::debug()',
        'Email::render()',
        'Member::logOut()',
        'Member::currentUser()',
        'Config_ForClass::update()',
        'MemoryConfigCollection::update()',
        'SSViewer_BasicIteratorSupport::First()',
        'SSViewer_BasicIteratorSupport::Last()',
    ];

    private $output = [];

    private $deprecatedSearchTerms = [];

    public function run($request)
    {
        if (file_exists(BASE_PATH . "/_output")) {
            foreach (scandir(BASE_PATH . "/_output") as $txt) {
                if ($txt == '.' || $txt == '..') {
                    continue;
                }
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
            // BASE_PATH . '/vendor/colymba',
            BASE_PATH . '/vendor/cwp',
            // BASE_PATH . '/vendor/tractorcow',
        ];
        // list @deprecated classes/config/methods
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach (scandir($vendorDir) as $subdir) {
                if (in_array($subdir, ['.', '..'])) {
                    continue;
                }
                $dir = "$vendorDir/$subdir";
                // if ($dir != '/var/www/vendor/silverstripe/assets') {
                //     continue;
                // }
                foreach ([
                    'src',
                    'code',
                    // '_legacy', // don't list this, graphql3 has already been taken care of
                    '_graphql',
                    // 'tests',
                    // 'thirdparty'
                ] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->listDeprecated($subdir);
                    }
                }
            }
        }
        // search for deprecated terms
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach (scandir($vendorDir) as $subdir) {
                if (in_array($subdir, ['.', '..'])) {
                    continue;
                }
                $this->output = [];
                $dir = "$vendorDir/$subdir";
                foreach ([
                    'src',
                    'code',
                    '_graphql',
                    'tests',
                ] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->stringSearchDeprecated($subdir);
                    }
                }
                if (empty($this->output)) {
                    continue;
                }
                $s = str_replace('/', '-', str_replace('/var/www/vendor/', '', $dir));
                $path = BASE_PATH . "/_output/$s.txt";
                file_put_contents($path, implode("\n", $this->output));
                echo "Wrote to $path\n";
            }
        }
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
            $this->scan($code);
        }
    }

    public function stringSearchDeprecated(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
        foreach ($paths as $path) {
            if (is_dir($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (strpos($code, "\nenum ") !== false) {
                continue;
            }
            $matches = [];
            echo "Search for deprecated stings in $path\n";
            foreach ($this->deprecatedSearchTerms as $key => $searchTerms) {
                foreach ($searchTerms as $searchTerm) {
                    $lines = explode("\n", $code);
                    foreach ($lines as $num => $line) {
                        // offset num to account for <?php
                        $num++;
                        if (!str_contains($line, $searchTerm)) {
                            continue;
                        }
                        $matches[] = '';
                        $matches[] = "\"$key\"";
                        $matches[] = "$num: $line";
                    }
                }
            }
            if (empty($matches)) {
                continue;
            }
            $this->output[] = "\n\n## $path:";
            foreach ($matches as $match) {
                $this->output[] = "$match";
            }
        }
    }

    private function scan(string $code): void
    {
        $ast = $this->getAst($code);
        $classes = $this->getClasses($ast);
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $this->scanDeprecated($class, 'class', "class " . $class->name->name, $class);
            $configs = $this->getConfigs($class);
            foreach ($configs as $config) {
                $this->scanDeprecated($config, 'config', $class->name->name . '.' . $config->props[0]->name->name, $class);
            }
            $methods = $this->getMethods($class);
            foreach ($methods as $method) {
                if ($method->name->name === '__construct') {
                    continue;
                }
                $this->scanDeprecated($method, 'method', $class->name->name . '::' . $method->name->name . '()', $class);
            }
        }
    }

    private function scanDeprecated(Node $node, string $type, string $key, $class): void
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
        if (str_contains($docblock, '* @deprecated')) {
            $name = $type == 'config' ? $node->props[0]->name->name : $node->name->name;
            // all types can have this
            // methods can have it via call_user_func()
            $this->deprecatedSearchTerms[$key] = [];
            $this->deprecatedSearchTerms[$key][] = "'" . $name . "'";
            $this->deprecatedSearchTerms[$key][] = '"' . $name . '"';
            if ($type == 'class') {
                $this->deprecatedSearchTerms[$key][] = 'new ' . $name;
                $this->deprecatedSearchTerms[$key][] = $name . '::create(';
            } else if ($type == 'config') {
                // already covered by surronding strings that all types have
            } else if ($type == 'method') {
                $this->deprecatedSearchTerms[$key][] = '->' . $name . '(';
                /** @var ClassMethod $node */
                $method = $node;
                if ($method->isStatic()) {
                    $this->deprecatedSearchTerms[$key][] = $class->name->name . '::' . $name . '(';
                }
            }
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
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            die;
        }
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
