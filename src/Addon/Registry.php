<?php
/**
 * One instance per add-on, found by class name.
 *
 * The registry used to be a static property on Controller with `new static()`
 * inside a static method, which is the shape PHPStan calls
 * `new.staticInAbstractClassStaticMethod`, and it is right to: written that
 * way, `WP2Static\Addon\Controller::boot()` — the abstract class, called
 * directly — reaches `new static()` on an abstract class and dies with
 * "Cannot instantiate abstract class", which says nothing about what the caller
 * did wrong.
 *
 * Here the class being instantiated is a parameter, so the mistake can be
 * named. The check costs a ReflectionClass on the miss path only: once per
 * add-on per request, and never again.
 *
 * @package WP2Static
 */

namespace WP2Static\Addon;

final class Registry {

    /**
     * @var array<class-string<Controller>, Controller> One per add-on.
     */
    private static $instances = [];

    /**
     * The one instance of an add-on's Controller.
     *
     * @template T of Controller
     * @param class-string<T> $class The add-on's concrete Controller.
     * @return T
     * @throws \WP2Static\WP2StaticException When asked for a class that cannot be instantiated.
     */
    public static function get( string $class ) : Controller {
        if ( ! isset( self::$instances[ $class ] ) ) {
            // esc_html() on an exception message is not decoration: with
            // WP_DEBUG_DISPLAY on, an uncaught exception's message is printed
            // as HTML, and the class name here came from the caller.
            if ( ! class_exists( $class ) ) {
                throw new \WP2Static\WP2StaticException(
                    'No such add-on Controller: ' . esc_html( $class )
                );
            }

            $reflection = new \ReflectionClass( $class );

            if ( $reflection->isAbstract() ) {
                throw new \WP2Static\WP2StaticException(
                    esc_html( $class ) . ' is abstract. An add-on boots its own ' .
                    'Controller, not WP2Static\Addon\Controller.'
                );
            }

            self::$instances[ $class ] = new $class();
        }

        /** @var T $instance */
        $instance = self::$instances[ $class ];

        return $instance;
    }

    /**
     * Forget everything. For tests, which boot several add-ons in one process.
     */
    public static function reset() : void {
        self::$instances = [];
    }
}
