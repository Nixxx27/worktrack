<?php

use Livewire\Component;
use Symfony\Component\Finder\Finder;

/**
 * One invariant, and it exists because breaking it is invisible to every other test
 * in this suite.
 *
 * Livewire's $wire proxy resolves a name against component STATE before it falls back
 * to calling a server action:
 *
 *     } else if (property in state)  return state[property]
 *     } else if (...)                return getFallback(component)(property)
 *
 * So a public property that shares its name with a public method turns
 * `$wire.thatName(...)` into "string is not a function" in the browser, while
 * `Livewire::test(...)->call('thatName')` keeps passing on the server — PHP has no
 * such ambiguity. The board shipped exactly this bug: a `$openProject` URL property
 * added beside the long-standing `openProject()` action silently killed every route
 * into the card drawer, and the whole feature suite stayed green.
 */
it('never names a public property the same as a public action', function () {
    $components = collect(Finder::create()->files()->in(app_path('Livewire'))->name('*.php'))
        ->map(fn ($file) => 'App\\Livewire\\'.$file->getBasename('.php'))
        ->filter(fn ($class) => class_exists($class) && is_subclass_of($class, Component::class));

    expect($components)->not->toBeEmpty();

    $collisions = [];

    foreach ($components as $class) {
        $reflection = new ReflectionClass($class);

        $mine = fn ($members) => collect($members)
            ->filter(fn ($m) => $m->class === $class && ! $m->isStatic())
            ->map->getName();

        $properties = $mine($reflection->getProperties(ReflectionProperty::IS_PUBLIC));

        // Lifecycle hooks are called by Livewire itself, never through $wire, so a
        // property named `mount` would be odd but not this bug.
        $methods = $mine($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn ($name) => in_array($name, ['mount', 'boot', 'booted', 'render', 'hydrate', 'dehydrate'], true)
                || str_starts_with($name, 'updated')
                || str_starts_with($name, 'updating'));

        foreach ($properties->intersect($methods) as $name) {
            $collisions[] = class_basename($class).'::$'.$name.' collides with '.class_basename($class).'::'.$name.'()';
        }
    }

    expect($collisions)->toBe([]);
});
