<?php
class Router {
  private array $routes;
  public function __construct(array $routes){ $this->routes = $routes; }
  public function resolve(string $page): string {
    if (!isset($this->routes[$page])) {
      return reset($this->routes); 
    }
    return $this->routes[$page];
  }
}
