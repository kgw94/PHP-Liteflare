<?php

namespace System\Core;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use Exception;
use InvalidArgumentException;

/**
 * Class Container
 * 
 * A simple dependency injection container with support for auto-wiring, 
 * singleton management, lazy loading, and circular dependency detection.
 */
class Container
{
    /**
     * @var array Holds the registered instances or closures
     */
    protected array $instances = [];

    /**
     * @var array Holds singletons
     */
    protected array $singletons = [];

    /**
     * @var array Keeps track of resolving classes to detect circular dependencies
     */
    protected array $resolving = [];

    /**
     * Register a binding in the container.
     *
     * @param string $abstract
     * @param mixed|null $concrete
     */
    public function set(string $abstract, mixed $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }
        $this->instances[$abstract] = $concrete;
    }

    /**
     * Register a singleton in the container.
     *
     * @param string $abstract
     * @param mixed|null $concrete
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->set($abstract, $concrete);
        $this->singletons[$abstract] = true;
    }

    /**
     * Retrieve an instance from the container.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function get(string $abstract, array $parameters = []): mixed
    {
        // Check for circular dependency
        if (in_array($abstract, $this->resolving)) {
            throw new Exception("Circular dependency detected while resolving {$abstract}");
        }

        $this->resolving[] = $abstract;

        // If we don't have it, register it automatically
        if (!isset($this->instances[$abstract])) {
            $this->set($abstract);
        }

        // Resolve the instance
        $object = $this->resolve($this->instances[$abstract], $parameters);

        // Handle singleton instances
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        // Remove from resolving list
        array_pop($this->resolving);

        return $object;
    }

    /**
     * Resolve a given concrete instance or class name.
     *
     * @param mixed $concrete
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function resolve(mixed $concrete, array $parameters = []): mixed
    {
        // If $concrete is a closure, execute it
        if ($concrete instanceof Closure) {
            return $concrete($this, ...$parameters);
        }

        // If $concrete is a string and already registered, resolve it
        if (is_string($concrete) && isset($this->instances[$concrete])) {
            $instance = $this->instances[$concrete];
            return is_callable($instance) ? $instance() : $instance;
        }

        // Use reflection to instantiate the class
        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new Exception("Class {$concrete} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return $reflector->newInstance();
        }

        // Resolve dependencies for the constructor
        $dependencies = $this->getDependencies($constructor->getParameters());

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve all dependencies of a given set of parameters.
     *
     * @param ReflectionParameter[] $parameters
     * @return array
     * @throws Exception
     */
    public function getDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getType() && !$parameter->getType()->isBuiltin()
                ? new ReflectionClass($parameter->getType()->getName())
                : null;

            if ($dependency === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    // Use the default value if available
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve class dependency {$parameter->name}");
                }
            } else {
                // Recursively resolve the dependency
                $dependencies[] = $this->get($dependency->getName());
            }
        }

        return $dependencies;
    }
}
