<?php

namespace App\Core;

/** Route table: Router::get('equipment/(:num)', 'Equipment::show', ['auth']) */
class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, string $handler, array $filters = []): void
    {
        $re = str_replace(['(:num)', '(:segment)', '(:any)'], ['(\d+)', '([^/]+)', '(.*)'], $pattern);
        $this->routes[] = [$method, '#^api/v1/' . $re . '$#', $handler, $filters];
    }

    public function get(string $p, string $h, array $f = []): void { $this->add('GET', $p, $h, $f); }
    public function post(string $p, string $h, array $f = []): void { $this->add('POST', $p, $h, $f); }
    public function put(string $p, string $h, array $f = []): void { $this->add('PUT', $p, $h, $f); }
    public function delete(string $p, string $h, array $f = []): void { $this->add('DELETE', $p, $h, $f); }

    /** @return array{0:string,1:string,2:array,3:array}|null  [Class, method, params, filters] */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as [$m, $re, $handler, $filters]) {
            if ($m === $method && preg_match($re, $path, $mm)) {
                array_shift($mm);
                [$class, $action] = explode('::', $handler, 2) + [1 => 'index'];
                $action = explode('/', $action)[0]; // "show/$1" -> "show"; captured values are passed in order

                return [$class, $action, $mm, $filters];
            }
        }

        return null;
    }
}
