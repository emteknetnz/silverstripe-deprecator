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
        file_put_contents(BASE_PATH . "/_output/_log.txt", '');
        $vendorDirs = [
            BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            BASE_PATH . '/vendor/symbiote',
            // BASE_PATH . '/vendor/bringyourownideas',
            // BASE_PATH . '/vendor/colymba',
            BASE_PATH . '/vendor/cwp',
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
                if ($dir != '/var/www/vendor/cwp/cwp-core') {
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
                $this->output($dir);
                $this->fileinfo = [];
            }
        }
    }

    public function output(string $dir)
    {
        // added in cms5
        // removed from cms5
        // deprecated in both cms4 + cms5 (i.e. not removed)
        $finfo = $this->fileinfo;
        $addedInCms5 = [];
        $removedInCms5 = [];
        $deprecatedInCms4 = [];
        $deprecatedInCms5 = [];
        foreach (array_keys($finfo) as $path) {
            $f = $finfo[$path];
            // classes
            $key = $path;
            if (!isset($f['cms4'])) {
                $addedInCms5[$key] = [
                    'path' => $path,
                    'type' => $f['cms5']['type'],
                    'name' => $f['cms5']['namespace'] . '\\' . $f['cms5']['name']
                ];
            } elseif (($f['cms5']['type'] ?? '') == '') {
                $removedInCms5[$key] = [
                    'path' => $path,
                    'type' => $f['cms4']['type'],
                    'name' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                    'dep4' => $f['cms4']['deprecated'] ? 'true' : 'false'
                ];
            }
            if ($f['cms4']['deprecated']) {
                $deprecatedInCms4[$key] = [
                    'type' => $f['cms4']['type'],
                    'name' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                    'path' => $path,
                ];
            }
            if ($f['cms5']['deprecated']) {
                $deprecatedInCms5[$key] = [
                    'type' => $f['cms5']['type'],
                    'name' => $f['cms5']['namespace'] . '\\' . $f['cms5']['name'],
                    'path' => $path,
                ];
            }
            foreach (['methods', 'config', 'properties'] as $k) {
                $type = $k == 'methods' ? 'method' : ($k == 'properties' ? 'property' : 'config');
                foreach (array_keys($f['cms4'][$k]) as $name) {
                    $key = "$path--$type-$name";
                    if ($f['cms4'][$k][$name]['deprecated'] ?? false) {
                        $deprecatedInCms4[$key] = [
                            'type' => $type,
                            'name' => $name,
                            'class' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                            'path' => $path,
                        ];
                    }
                    if ($f['cms5'][$k][$name]['deprecated'] ?? false) {
                        $deprecatedInCms5[$key] = [
                            'type' => $type,
                            'name' => $name,
                            'class' => $f['cms4']['namespace'] . '\\' . $f['cms5']['name'],
                            'path' => $path,
                        ];
                    }
                    if (!isset($f['cms5'][$k][$name])) {
                        $removedInCms5[$key] = [
                            'type' => $type,
                            'name' => $name,
                            'class' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                            'path' => $path
                        ];
                    }
                }
                foreach (array_keys($f['cms5'][$k]) as $name) {
                    $key = "$path--$type-$name";
                    if (!isset($f['cms4'][$k][$name])) {
                        $addedInCms5[$key] = [
                            'type' => $type,
                            'name' => $name,
                            'class' => $f['cms5']['namespace'] . '\\' . $f['cms5']['name'],
                            'path' => $path,
                        ];
                    }
                }
            }
        }
        $deprecatedInBothCms4AndCms5 = [];
        foreach ($deprecatedInCms4 as $key => $a) {
            if (isset($deprecatedInCms5[$key])) {
                $deprecatedInBothCms4AndCms5[$key] = $a;
            }
        }
        // filter out deprecated/removed methods where entire class was deprecated/removed
        // this is getting it closer to a "useful output"
        $cleanThing = function($a, $b = []) {
            $na = [];
            foreach (array_keys($a) as $key) {
                // always retain classes
                if (strpos($key, '--') === false) {
                    $na[$key] = $a[$key];
                    continue;
                }
                // keep methods/properties/config where the class wasn't itself deprecated/removed
                $classKey = explode('--', $key)[0];
                if (!isset($a[$classKey]) && !isset($b[$classKey])) {
                    $na[$key] = $a[$key];
                }
            }
            return $na;
        };
        //
        $iden = str_replace('/', '-', str_replace('/var/www/vendor/', '', $dir));
        ob_start();
        echo "\n\nRAW DATA\n\n";
        print_r([
            'addedInCms5' => $addedInCms5,
            'removedInCms5' => $cleanThing($removedInCms5),
            'deprecatedInCms4' => $deprecatedInCms4,
            'deprecatedInCms5' => $deprecatedInCms5,
            'deprecatedInBothCms4AndCms5' => $deprecatedInBothCms4AndCms5,
        ]);
        echo "\n\nTO ACTION\n\n";
        $removedInCms5ButNotDeprecatedInCms4 = [];
        foreach ($removedInCms5 as $key => $a) {
            if (!isset($deprecatedInCms4[$key])) {
                $removedInCms5ButNotDeprecatedInCms4[$key] = $a;
            }
        }
        print_r([
            'removedInCms5ButNotDeprecatedInCms4' => $cleanThing($removedInCms5ButNotDeprecatedInCms4, $deprecatedInCms4),
        ]);
        $s = ob_get_clean();
        $f = BASE_PATH . '/_output/' . $iden . '.txt';
        file_put_contents($f, $s);
        echo "Wrote to $f\n";
    }

    private function log($s)
    {
        $f = BASE_PATH . "/_output/_log.txt";
        file_put_contents($f, file_get_contents($f) . $s . "\n");
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
        if (count($branches) < 2) {
            $this->log("WARNING: Only 1 branch found for $dir");
            return;
        }
        $cms4Branch = $branches[1];
        $cms5Branch = $branches[0];
        foreach ([$cms5Branch, $cms4Branch] as $branch) {
            if (!file_exists($dir)) {
                $this->log("WARNING: dir does not exist $dir");
                continue;
            }
            shell_exec("cd $dir && git checkout $branch");
            $cms = $branch == $cms4Branch ? 'cms4' : 'cms5';
            // do cms 5 branch first so we checkout back to original cms 4 branch
            $res = shell_exec("find $dir | grep .php");
            if (!$res) {
                $this->log("INFO: No php files found for $branch in $dir, continuing");
                continue;
            }
            $paths = explode("\n", $res);
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
                $this->fileinfo[$path] ??= [];
                foreach (['cms4', 'cms5'] as $c) {
                    $this->fileinfo[$path][$c] ??= [
                        'namespace' => '',
                        'type' => '',
                        'name' => '',
                        'deprecated' => false,
                        'config' => [],
                        'properties' => [],
                        'methods' => []
                    ];
                }
                $this->extract($code, $this->fileinfo[$path][$cms]);
            }
        }
    }

    private function extract(string $code, &$finfo): string
    {
        $ast = $this->getAst($code);
        $namespace = $this->getNamespace($ast);
        $finfo['namespace'] = $namespace ? $namespace->name->toString() : '';
        $classes = $this->getClasses($ast);
        // if multiple classes in file, just use the first one (SapphireTest phpunit 9)
        if (count($classes) > 1) {
            $classes = [$classes[0]];
        }
        foreach ($classes as $class) {
            $finfo['type'] = $class instanceof Class_ ? 'class' : 'trait';
            $finfo['name'] = $class->name->name;
            $finfo['deprecated'] = $this->docBlockContainsDeprecated($class);
            foreach ($this->getMethods($class) as $method) {
                $finfo['methods'][$method->name->name] = $this->docBlockContainsDeprecated($method);
            }
            foreach ($this->getConfigs($class) as $config) {
                $finfo['config'][$config->props[0]->name->name] = $this->docBlockContainsDeprecated($config);
            }
            foreach ($this->getProperties($class) as $property) {
                $finfo['properties'][$property->props[0]->name->name] = $this->docBlockContainsDeprecated($property);
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
                return ($p instanceof Property) && $p->isPrivate() && $p->isStatic();
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
                return ($p instanceof Property) && !$p->isPrivate();
            }
        );
    }

    // only public + protected methods
    private function getMethods(Class_|Trait_ $class): array
    {
        return array_filter($class->stmts, fn($v) => ($v instanceof ClassMethod) && !$v->isPrivate());
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
