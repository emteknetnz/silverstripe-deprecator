<?php

namespace emteknetnz\Deprecator;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use SilverStripe\Dev\BuildTask;
use PhpParser\PrettyPrinter;
use SilverStripe\Dev\Deprecation;

class DeprecationDiffTask extends BuildTask
{
    private static $segment = 'DeprecationDiffTask';

    protected $title = 'DeprecationDiffTask';

    protected $description = 'Create a diff between CMS 4 and CMS 5 for the changelog';

    private $c = 0;
    private $maxC = 200000;

    private $fileinfo = [];

    private $updatedDirs = [];

    private $currentPath = '';

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
                if ($dir != '/var/www/vendor/silverstripe/assets') {
                    continue;
                }
                foreach ([
                    'src',
                    'code',
                    //'_legacy',
                    '_graphql',
                    // 'tests',
                    // 'thirdparty'
                ] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->diff($subdir);
                    }
                }
            }
        }
        // added in cms5
        // removed from cms5
        // deprecated in both cms4 + cms5 (i.e. not removed)
        $finfo = $this->fileinfo;
        $addedInCms5 = [];
        $removedInCms5 = [];
        foreach (array_keys($finfo) as $path) {
            if (!isset($finfo[$path]['cms4'])) {
                $removedInCms5[] = [
                    'type' => 'class',
                    'path' => $path,
                    'name' => $finfo[$path]['cms4']['namespace'] . '\\' . $finfo[$path]['cms4']['class']
                ];
            }
        }
        $deprecatedInBothCms4AndCms5 = [];
        print_r($fileinfo);die;
    }

    public function diff(string $dir)
    {
        $this->currentPath = $dir;
        $branches = array_filter(
            array_map(
                function ($branch) {
                    return trim(str_replace('origin/', '', $branch));
                },
                explode("\n", shell_exec("cd $dir && git fetch && git branch -r"))
            ),
            function ($branch) {
                return preg_match('#^[1-9]$#', $branch);
            }
        );
        sort($branches);
        $branches = array_reverse($branches);
        $cms4Branch = $branches[1];
        $cms5Branch = $branches[0];
        foreach ([$cms5Branch, $cms4Branch] as $branch) {
            shell_exec("cd $dir && git checkout $branch");
            $cms = $branch == $cms4Branch ? 'cms4' : 'cms5';
            // do cms 5 branch first so we checkout back to original cms 4 branch
            $paths = explode("\n", shell_exec("find $dir | grep .php"));
            $paths = array_filter($paths, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) == 'php');
            foreach ($paths as $path) {
                $this->cTest();
                if (is_dir($path)) {
                    continue;
                }
                $code = file_get_contents($path);
                if (strpos($code, "\nenum ") !== false) {
                    continue;
                }
                $this->fileinfo[$path] = [];
                $this->fileinfo[$path][$cms] = [
                    'namespace' => '',
                    'class' => '',
                    'deprecated' => false,
                    'config' => [],
                    'properties' => [],
                    'methods' => []
                ];
                $this->extract($code, $this->fileinfo[$path][$cms]);
            }
        }
    }

    private function extract(string $code, &$finfo): string
    {
        $ast = $this->getAst($code);
        $finfo['namespace'] = $this->getNamespace($ast)->name->toString();
        $classes = $this->getClasses($ast);
        // if multiple classes in file, just use the first one (SapphireTest phpunit 9)
        if (count($classes) > 1) {
            $classes = [$classes[0]];
        }
        foreach ($classes as $class) {
            $finfo['class'] = $class->name->name;
            $finfo['deprecated'] = $this->docBlockContainsDeprecated($class);
            foreach ($this->getMethods($class) as $method) {
                $finfo['methods'][$method->name->name] = $this->docBlockContainsDeprecated($method);
            }
            foreach ($this->getConfigs($class) as $config) {
                $finfo['config'][$config->name->name] = $this->docBlockContainsDeprecated($config);
            }
            foreach ($this->getProperties($class) as $property) {
                $finfo['properties'][$property->name->name] = $this->docBlockContainsDeprecated($property);
            }
        }
        return $code;
    }

    private function docBlockContainsDeprecated($node)
    {
        $docComment = $node->getDocComment();
        $docblock = '';
        if ($docComment !== null) {
            $docblock = $docComment->getText();
        }
        return strpos($docblock, ' * @deprecated') !== false;
    }

    private function cTest()
    {
        if ($this->c++ > $this->maxC) {
            echo "MAX C\n";
            die;
        }
    }

    private function getNamespace(array $ast): ?Namespace_
    {
        return ($ast[0] ?? null) instanceof Namespace_ ? $ast[0] : null;
    }

    // + traits
    private function getClasses(array $ast): array
    {
        $ret = [];
        $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
        $ret = array_merge($ret, array_filter($a, fn($v) => $v instanceof Class_ || $v instanceof Trait_));
        // SapphireTest and other file with dual classes
        $i = array_filter($a, fn($v) => $v instanceof If_);
        foreach ($i as $if) {
            foreach ($if->stmts ?? [] as $v) {
                if ($v instanceof Class_ || $v instanceof Trait_) {
                    $ret[] = $v;
                }
            }
        }
        return $ret;
    }

    private function getConfigs(Class_|Trait_ $class): array
    {
        return array_filter(
            $class->stmts, function ($v) {
                /** @var Property $p */
                $p = $v;
                return $p instanceof Property && $p->isPrivate() && $p->isStatic();
            }
        );
    }

    // only public + protected properties
    private function getProperties(Class_|Trait_ $class): array
    {
        return array_filter(
            $class->stmts, function ($v) {
                /** @var Property $p */
                $p = $v;
                return $p instanceof Property && !$p->isPrivate();
            }
        );
    }

    // only public + protected methods
    private function getMethods(Class_|Trait_ $class): array
    {
        return array_filter($class->stmts, fn($v) => $v instanceof ClassMethod && !$v->isPrivate());
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
}
