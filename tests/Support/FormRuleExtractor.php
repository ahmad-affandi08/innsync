<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;

/**
 * Reads, from the controllers' own source, which request fields each route requires
 * (`required` and not `sometimes`; nested keys such as `lines.*.quantity` are kept as written), so the screens can mark those fields without a second list
 * to keep in step. Static on purpose: nothing is executed. A rule it cannot read (built from a
 * constant, merged arrays) counts as not required, so a star is never shown by mistake.
 */
final class FormRuleExtractor
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string, list<Node\Stmt>> */
    private array $trees = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /** @return array<string, array{required: list<string>, declared: list<string>}> keyed by "METHOD /uri" */
    public function extract(Router $router): array
    {
        $out = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! is_string($action)) {
                continue;
            }

            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            $calls = $this->ruleSets($class, $method);

            if ($calls === []) {
                continue;
            }

            $declared = [];
            $required = [];

            foreach ($calls as $set) {
                foreach ($set as $field => $isRequired) {
                    $declared[$field] = true;
                    $required[$field] = ($required[$field] ?? true) && $isRequired;
                }
            }

            ksort($declared);

            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $out[$verb.' /'.ltrim($route->uri(), '/')] = [
                    'required' => array_keys(array_filter($required)),
                    'declared' => array_keys($declared),
                ];
            }
        }

        ksort($out);

        foreach ($out as &$entry) {
            sort($entry['required']);
        }

        return $out;
    }

    /** @return list<array<string, bool>> one entry per validation call that runs for this action: field => required */
    private function ruleSets(string $class, string $method): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            return [];
        }

        $sets = [];
        $seen = [];
        $this->collect($file, $method, $sets, $seen, 0);

        return $sets;
    }

    /**
     * @param  list<array<string, bool>>  $sets
     * @param  array<string, true>  $seen
     */
    private function collect(string $file, string $method, array &$sets, array &$seen, int $depth): void
    {
        if ($depth > 3 || isset($seen[$file.'#'.$method])) {
            return;
        }

        $seen[$file.'#'.$method] = true;
        $tree = $this->tree($file);
        $node = $this->finder->findFirst($tree, fn (Node $n) => $n instanceof ClassMethod && $n->name->toString() === $method);

        if (! $node instanceof ClassMethod) {
            return;
        }

        foreach ($node->params as $param) {
            $type = $param->type;

            if ($type instanceof Node\Name && class_exists($type->toString()) && is_subclass_of($type->toString(), FormRequest::class)) {
                $requestFile = (new ReflectionClass($type->toString()))->getFileName();
                $rules = $requestFile === false ? null : $this->finder->findFirst($this->tree($requestFile), fn (Node $n) => $n instanceof ClassMethod && $n->name->toString() === 'rules');

                if ($rules instanceof ClassMethod) {
                    $return = $this->finder->findFirstInstanceOf((array) $rules->stmts, Return_::class);

                    if ($return instanceof Return_ && $return->expr instanceof Array_) {
                        $sets[] = $this->fields($return->expr);
                    }
                }
            }
        }

        foreach ($this->finder->find((array) $node->stmts, fn (Node $n) => $n instanceof MethodCall || $n instanceof StaticCall) as $call) {
            if ($call instanceof MethodCall && $call->name instanceof Node\Identifier) {
                $name = $call->name->toString();

                if (in_array($name, ['validate', 'validateWithBag'], true) && ($call->args[0]->value ?? null) instanceof Array_) {
                    $sets[] = $this->fields($call->args[0]->value);
                } elseif ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $this->collect($file, $name, $sets, $seen, $depth + 1);
                }
            }

            if ($call instanceof StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier && $call->name->toString() === 'make' && str_ends_with($call->class->toString(), 'Validator') && ($call->args[1]->value ?? null) instanceof Array_) {
                $sets[] = $this->fields($call->args[1]->value);
            }
        }
    }

    /** @return array<string, bool> */
    private function fields(Array_ $rules): array
    {
        $out = [];

        foreach ($rules->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $tokens = [];

            if ($item->value instanceof String_) {
                $tokens = explode('|', $item->value->value);
            } elseif ($item->value instanceof Array_) {
                foreach ($item->value->items as $part) {
                    if ($part->value instanceof String_) {
                        array_push($tokens, ...explode('|', $part->value->value));
                    }
                }
            }

            $required = in_array('required', $tokens, true) && ! in_array('sometimes', $tokens, true);
            $out[$item->key->value] = $required;

            // `confirmed` asks for a second field named `<field>_confirmation`, required together with it.
            if (in_array('confirmed', $tokens, true)) {
                $out[$item->key->value.'_confirmation'] = $required;
            }
        }

        return $out;
    }

    /** @return list<Node\Stmt> */
    private function tree(string $file): array
    {
        if (! isset($this->trees[$file])) {
            $tree = $this->parser->parse((string) file_get_contents($file)) ?? [];
            $traverser = new NodeTraverser(new NameResolver);
            $this->trees[$file] = $traverser->traverse($tree);
        }

        return $this->trees[$file];
    }
}
