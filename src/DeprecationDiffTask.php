<?php

namespace emteknetnz\Deprecator;

use phpDocumentor\Reflection\Types\Intersection;
use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Trait_;
use SilverStripe\Dev\BuildTask;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;

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

    private $changelog = [];

    private $toDeprecateInCms4 = [];

    // hardcoded list of silverstripe/framework Email.php methods that no longer exists
    // on Email.php, are instead defined on parent class in symfony/email and have a different
    // param and/or return type signature
    private $frameworkEmailMethods = [
        'getFrom',
        'addFrom',
        'getSender',
        'getBody',
        'getReturnPath',
        'getTo',
        'addTo',
        'getCC',
        'addCC',
        'getBCC',
        'addBCC',
        'getReplyTo',
        'addReplyTo',
        'getSubject',
        'getPriority'
    ];

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
                $this->output($dir);
                $this->fileinfo = [];
            }
        }
        $f = BASE_PATH . '/_output/_changelog.txt';
        file_put_contents($f, implode("\n", $this->changelog));
        echo "\n\nWrote to $f\n";
        //
        $f = BASE_PATH . '/_output/_to_deprecate_in_cms4.txt';
        ob_start();
        print_r($this->toDeprecateInCms4);
        $s = ob_get_clean();
        file_put_contents($f, $s);
        echo "Wrote to $f\n\n";
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
        $paramsDiff = [];
        $returnTypesDiff = [];
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
            foreach (['methods', 'config', 'properties', 'constants'] as $k) {
                $type = '';
                if ($k == 'methods') $type = 'method';
                if ($k == 'properties') $type = 'property';
                if ($k == 'config') $type = 'config';
                if ($k == 'constants') $type = 'constant';
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
                if ($k == 'methods') {
                    foreach (array_keys($f['cms4'][$k]) as $name) {
                        for ($i = 0; $i < count($f['cms4'][$k][$name]['params'] ?? []); $i++) {
                            // skip if the method doesn't exist in cms5
                            if (!isset($f['cms5'][$k][$name])) {
                                continue;
                            }
                            $key = "$path--$type-$name.$i";
                            $cms4_param_type = $f['cms4'][$k][$name]['params'][$i]['type'] ?? '';
                            $cms4_param_name = $f['cms4'][$k][$name]['params'][$i]['name'] ?? '';
                            $cms5_param_type = $f['cms5'][$k][$name]['params'][$i]['type'] ?? '';
                            $cms5_param_name = $f['cms5'][$k][$name]['params'][$i]['name'] ?? '';
                            if ($cms4_param_name != $cms5_param_name || $cms4_param_type != $cms5_param_type) {
                                $paramsDiff[$key] = [
                                    'type' => 'param',
                                    'name' => "$name",
                                    'class' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                                    'path' => $path,
                                    'cms4_param_name' => $cms4_param_name,
                                    'cms5_param_name' => $cms5_param_name,
                                    'cms4_param_type' => $cms4_param_type,
                                    'cms5_param_type' => $cms5_param_type,
                                ];
                            }
                        }
                        if (isset($f['cms4'][$k][$name]['returnType']) && isset($f['cms5'][$k][$name]['returnType'])) {
                            if ($f['cms4'][$k][$name]['returnType'] != $f['cms5'][$k][$name]['returnType']) {
                                $key = "$path--$type-$name.returnType";
                                $returnTypesDiff[$key] = [
                                    'type' => 'returnType',
                                    'name' => $name,
                                    'class' => $f['cms4']['namespace'] . '\\' . $f['cms4']['name'],
                                    'path' => $path,
                                    'cms4_returnType' => $f['cms4'][$k][$name]['returnType'],
                                    'cms5_returnType' => $f['cms5'][$k][$name]['returnType'],
                                ];
                            }
                        }
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
                // remove constants.php
                if (preg_match('#/[a-z0-9\-\.]+$#', $key)) {
                    continue;
                }
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
        echo "\n\nTO ACTION\n\n";
        $removedInCms5ButNotDeprecatedInCms4 = [];
        foreach ($removedInCms5 as $key => $a) {
            if (isset($deprecatedInCms4[$key])) {
                continue;
            }
            if ($this->isFrameworkEmailMethod($key)) {
                continue;
            }
            $removedInCms5ButNotDeprecatedInCms4[$key] = $a;
        }
        $toDeprecateInCms4 = $cleanThing($removedInCms5ButNotDeprecatedInCms4, $deprecatedInCms4);
        print_r([
            'Removed in CMS 5 but not deprecated in CMS 4' => $toDeprecateInCms4
        ]);
        $this->toDeprecateInCms4 = array_merge($this->toDeprecateInCms4, ['', $dir,''], $toDeprecateInCms4);
        echo "\n===================\n";
        echo "\n\nRAW DATA\n\n";
        print_r([
            'addedInCms5' => $addedInCms5,
            'removedInCms5' => $cleanThing($removedInCms5),
            'deprecatedInCms4' => $deprecatedInCms4,
            'deprecatedInCms5' => $deprecatedInCms5,
            'deprecatedInBothCms4AndCms5' => $deprecatedInBothCms4AndCms5,
            'paramsDiff' => $paramsDiff,
            'returnTypesDiff' => $returnTypesDiff,
        ]);
        $s = ob_get_clean();
        $f = BASE_PATH . '/_output/' . $iden . '.txt';
        file_put_contents($f, $s);
        echo "Wrote to $f\n";

        $depr = [];
        foreach ($removedInCms5 as $key => $a) {
            if (strpos($key, '--') === false) {
                if (preg_match('#/[a-z0-9\-\.]+$#', $a['path'])) {
                    // lowercase file name e.g. consts.php
                    continue;
                }
                $depr[] = "- Removed deprecated {$a['type']} `{$a['name']}`";
            }
        }
        foreach ($removedInCms5 as $key => $a) {
            if (strpos($key, '--') !== false) {
                $classKey = explode('--', $key)[0];
                $type = explode('-', explode('--', $key)[1])[0];
                if (isset($removedInCms5[$classKey])) {
                    continue;
                }
                if ($this->isFrameworkEmailMethod($key)) {
                    // note: the API docs DO NOT contain details for API inherited from vendor classes
                    // e.g. https://api.silverstripe.org/5/SilverStripe/Dev/SapphireTest.html which extends
                    // phpunit TestCase does NOT contain info about assertSame()
                    $depr[] = "- Method {$this->getChangelogThing($a, $type)} is now defined in `Symfony\Component\Mime\Email` with a different method signature";
                } else {
                    $depr[] = "- Removed deprecated $type {$this->getChangelogThing($a, $type)}";
                }
            }
        }
        $jointDiff = array_merge($paramsDiff, $returnTypesDiff);
        foreach ($jointDiff as $key => $a) {
            $classKey = explode('--', $key)[0];
            if (isset($removedInCms5[$classKey])) {
                continue;
            }
            $untyped = 'dynamic';
            if (str_contains($key, 'returnType')) {
                $cms4_returnType = $a['cms4_returnType']
                    ? $this->styleType($a['cms4_returnType'])
                    : $untyped;
                $cms5_returnType = $a['cms5_returnType']
                    ? $this->styleType($a['cms5_returnType'])
                    : $untyped;
                $depr[] = "- Changed return type for {$this->getChangelogMethodApiLink($a)} from $cms4_returnType to $cms5_returnType";
            } else {
                $cms4_param_name = $a['cms4_param_name'];
                $cms5_param_name = $a['cms5_param_name'];
                $cms4_param_type = $a['cms4_param_type']
                    ? $this->styleType($a['cms4_param_type'])
                    : $untyped;
                $cms5_param_type = $a['cms5_param_type']
                    ? $this->styleType($a['cms5_param_type'])
                    : $untyped;
                if (empty($cms5_param_name)) {
                    $depr[] = "- Removed parameter in {$this->getChangelogMethodApiLink($a)} named `\${$cms4_param_name}`";
                } else {
                    if ($cms4_param_name != $cms5_param_name) {
                        $depr[] = "- Changed parameter name in {$this->getChangelogMethodApiLink($a)} from `\${$cms4_param_name}` to `\${$cms5_param_name}`";
                    }
                    if ($cms4_param_type != $cms5_param_type) {
                        $depr[] = "- Changed parameter type in {$this->getChangelogMethodApiLink($a)} for `\${$cms5_param_name}` from {$cms4_param_type} to {$cms5_param_type}";
                    }
                }
            }
        }
        usort($depr, function($a, $b) {
            // non api link
            $rx = "#\`([A-Za-z0-9_]+\\\[^`]+)`#";
            $rx2 = "#\[([A-Za-z0-9_]+\\\[^\]]+)#";
            if (!preg_match($rx, $a, $ma)) {
                if (!preg_match($rx2, $a, $ma)) {
                    var_dump($a);
                    die;
                }
            }
            if (!preg_match($rx, $b, $mb)) {
                if (!preg_match($rx2, $b, $mb)) {
                    var_dump($b);
                    die;
                }
            }
            $p = [
                'Removed deprecated class',
                'Removed deprecated method',
                'Removed deprecated config',
                'Removed deprecated constant',
                'Removed deprecated property',
                'Changed return type',
                'Changed parameter type'
            ];
            $ap = 99;
            $bp = 99;
            for ($i = 0; $i < count($p); $i++) {
                $s = $p[$i];
                if (str_contains($a, $s)) {
                    $ap = $i;
                }
                if (str_contains($b, $s)) {
                    $bp = $i;
                }
            }
            if ($ap == $bp) {
                return strtolower($ma[1]) <=> strtolower($mb[1]);
            } else {
                return $ap <=> $bp;
            }
        });
        if (!empty($depr)) {
            $this->changelog[] = '';
            $this->changelog[] = '#### ' . $this->getComposerName($dir);
            $this->changelog[] = '';
            foreach ($depr as $line) {
                $this->changelog[] = $line;
            }
        }
    }

    private function styleType($t)
    {
        $r = [];
        foreach (explode('|', $t) as $s) {
            if (strpos($s, 'SilverStripe\\') === 0) {
                $r[] = "[`{$s}`](api:{$s})";
            } else {
                $r[] = "`$s`";
            }
        }
        return implode('|', $r);
    }

    private function getChangelogMethodApiLink($a)
    {
        return "[`{$a['class']}::{$a['name']}()`](api:{$a['class']}::{$a['name']}())";
    }

    private function getChangelogThing($a, $type)
    {
        if ($type == 'method') {
            return "`{$a['class']}::{$a['name']}()`";
        } elseif ($type == 'constant') {
            return "`{$a['class']}::{$a['name']}`";
        } elseif ($type == 'config') {
            return "`{$a['class']}.{$a['name']}`";
        } else {
            // property
            return "`{$a['class']}::\${$a['name']}`";
        }
    }

    private function isFrameworkEmailMethod($key)
    {
        if (str_contains($key, '/Email/Email.php')) {
            if (str_contains($key, '--')) {
                $method = explode('-', explode('--', $key)[1])[1];
                if (in_array($method, $this->frameworkEmailMethods)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getComposerName($dir)
    {
        return json_decode(file_get_contents("$dir/composer.json"))->name;
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
                if (strpos($code, "\nclass ") === false &&
                    strpos($code, "\nabstract class ") === false &&
                    strpos($code, "\ninterface ") === false &&
                    strpos($code, "\ntrait ") === false &&
                    strpos($code, "\nabstract trait ") === false
                ) {
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
                        'methods' => [],
                        'constants' => [],
                    ];
                }
                $this->extract($code, $this->fileinfo[$path][$cms]);
            }
        }
    }

    private function extract(string $code, &$finfo): string
    {
        $ast = $this->getAst($code);
        $useStatements = $this->getUseStatements($ast);
        $imports = array_combine(
            array_map(fn(Use_ $use) => end($use->uses[0]->name->parts), $useStatements),
            array_map(fn(Use_ $use) => (string) $use->uses[0]->name, $useStatements),
        );
        $namespace = $this->getNamespace($ast);
        $ns = $namespace ? (string) $namespace->name : '';
        $finfo['namespace'] = $namespace ? $namespace->name->toString() : '';
        $classes = $this->getClasses($ast);
        // if multiple classes in file, just use the first one (SapphireTest phpunit 9)
        if (count($classes) > 1) {
            $classes = [$classes[0]];
        }
        foreach ($classes as $class) {
            $finfo['type'] = $class instanceof Class_ ? 'class' : ($class instanceof Trait_ ? 'trait' : 'interface');
            $finfo['name'] = $class->name->name;
            $finfo['deprecated'] = $this->docBlockContainsDeprecated($class);
            foreach ($this->getMethods($class) as $method) {
                $finfo['methods'][$method->name->name] = [
                    'deprecated' => $this->docBlockContainsDeprecated($method),
                    'params' => $this->getParamsData($imports, $ns, $method), // ['name' => $name, 'type' => $type]
                    'returnType' => $this->getReturnType($imports, $ns, $method)
                ];
            }
            foreach ($this->getConfigs($class) as $config) {
                $finfo['config'][$config->props[0]->name->name] = [
                    'deprecated' => $this->docBlockContainsDeprecated($config)
                ];
            }
            foreach ($this->getProperties($class) as $property) {
                $finfo['properties'][$property->props[0]->name->name] = [
                    'deprecated' => $this->docBlockContainsDeprecated($property)
                ];
            }
            foreach ($this->getConstants($class) as $constant) {
                $finfo['constants'][$constant->consts[0]->name->name] = [
                    'deprecated' => $this->docBlockContainsDeprecated($constant)
                ];
            }
        }
        return $code;
    }

    private function getParamsData(array $imports, string $ns, ClassMethod $method)
    {
        return array_map(
            function (Param $param) use ($imports, $ns) {
                $types = [$param->type];
                if ($param->type instanceof UnionType) {
                    $types = $param->type->types;
                } elseif ($param->type instanceof IntersectionType) {
                    // this is probably technically wrong for IntersectionType
                    $types = $param->type->types;
                }
                return [
                    'name' => $param->var->name,
                    'type' => $this->formatTypes($types, $imports, $ns),
                ];
            },
            $method->getParams()
        );
    }

    private function getReturnType(array $imports, string $ns, ClassMethod $method): string
    {
        $returnType = $method->getReturnType();
        $types = [$returnType];
        if ($returnType instanceof UnionType) {
            $types = $returnType->types;
        }
        if ($returnType instanceof IntersectionType) {
            // this is probably technically wrong for IntersectionType
            $types = $returnType->types;
        }
        return $this->formatTypes($types, $imports, $ns);
    }

    private function formatTypes(array $types, array $imports, string $ns): string
    {
        $tys = [];
        $isNullable = false;
        foreach ($types as $type) {
            if ($type instanceof NullableType) {
                $isNullable = true;
                $type = $type->type;
            }
            $cn = (string) $type;
            if (strtolower($cn) == $cn) {
                $tys[] = $cn;
            } elseif (class_exists($cn) || interface_exists($cn) || trait_exists($cn)) {
                $tys[] = $cn;
            } elseif (isset($imports[$cn])) {
                $tys[] = $imports[$cn];
            } else {
                $tys[] = "$ns\\$cn";
            }
        }
        if ($isNullable) {
            $tys[] = 'null';
        }
        return implode('|', $tys);
    }

    private function docBlockContainsDeprecated($node): bool
    {
        $docComment = $node->getDocComment();
        $docblock = '';
        if ($docComment !== null) {
            $docblock = $docComment->getText();
        }
        return strpos($docblock, '* @deprecated') !== false;
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

    private function getUseStatements(array $ast): array
    {
        $ret = [];
        $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
        return array_merge($ret, array_filter($a, fn($v) => $v instanceof Use_));
        return $ret;
    }

    // + traits
    private function getClasses(array $ast): array
    {
        $ret = [];
        $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
        $ret = array_merge($ret, array_filter($a, fn($v) => $v instanceof Class_ || $v instanceof Trait_ || $v instanceof Interface_));
        // SapphireTest and other file with dual classes
        $i = array_filter($a, fn($v) => $v instanceof If_);
        foreach ($i as $if) {
            foreach ($if->stmts ?? [] as $v) {
                if ($v instanceof Class_ || $v instanceof Trait_ || $v instanceof Interface_) {
                    $ret[] = $v;
                }
            }
        }
        return $ret;
    }

    private function getConfigs(Class_|Trait_|Interface_ $class): array
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
    private function getProperties(Class_|Trait_|Interface_ $class): array
    {
        return array_filter(
            $class->stmts, function ($v) {
                /** @var Property $p */
                $p = $v;
                return ($p instanceof Property) && !$p->isPrivate();
            }
        );
    }

    // only public + protected constants
    private function getConstants(Class_|Trait_|Interface_ $class): array
    {
        return array_filter(
            $class->stmts, function ($v) {
                /** @var ConClassConststant $p */
                $c = $v;
                return ($c instanceof ClassConst) && !$c->isPrivate();
            }
        );
    }

    // only public + protected methods
    private function getMethods(Class_|Trait_|Interface_ $class): array
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
            $ast = $parser->parse(str_replace('declare(strict_types=1);', '', $code));
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            die;
        }
        return $ast;
    }
}
