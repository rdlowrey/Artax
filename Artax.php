<?php

/**
 * Artax Bootstrap File
 * 
 * PHP version 5.4
 * 
 * ### QUICK START
 * 
 * You need to do two things to fire up an Artax application:
 * 
 * 1. Specify the application-wide debug level;
 * 2. Require the the **Artax.php** bootstrap file.
 * 
 *     define('AX_DEBUG', 1); // acceptable values: 0, 1, 2
 *     require '/hard/path/to/Artax.php';
 * 
 * That's it. From there it's a simple matter of pushing event listeners onto
 * the event mediator (`$mediator`) and (optionally) adding dependency definitions
 * (if necessary) to the dependency injection container (`$provider`).
 * 
 * ### Concerning AX_DEBUG levels
 * 
 * Artax applications have three different debug output levels:
 * 
 *     - `define('AX_DEBUG', 0); // production`
 *     - `define('AX_DEBUG', 1); // development`
 *     - `define('AX_DEBUG', 2); // debug nested fatal errors in development`
 * 
 * Production apps should always run in debug level 0. Level 1 results in
 * formatted output that correctly represents exceptions and fatal errors in
 * all but the most extreme cases. Finally, debug level 2 is necessary *only*
 * when debugging a fatal E_ERROR that occurs *inside an exception handler
 * that is already handling a fatal error*. An example of such a situation
 * would be an E_PARSE error in a class used by your exception event listener.
 * 
 * Artax goes to great lengths to turn fatal E_ERRORs into exceptions so
 * that applications can handle these situations like any other uncaught
 * exception. However, there's nothing to be done if your exception handler
 * triggers a fatal E_ERROR while already handling a fatal error. If you 
 * can't figure out why your app keeps breaking, try switching into debug 
 * level 2, as this will give your more information about the problem.
 * 
 * ### More information
 * 
 * Examples to get you started are available in the {%ARTAX_DIR%}/examples
 * directory. For more detailed discussion checkout the [github wiki][wiki].
 * 
 * [wiki]: https://rdlowrey.github.com/Artax/
 * 
 * 
 * @category Artax
 * @package  Core
 * @author   Daniel Lowrey <rdlowrey@gmail.com>
 * @author   Levi Morrison <lm@php.net>
 */

use Artax\Core\Provider,
    Artax\Core\Mediator,
    Artax\Core\Handlers;

/*
 * --------------------------------------------------------------------
 * CHECK FOR 5.4+ & DEFINE AX_DEBUG/AX_SYSDIR CONSTANTS
 * --------------------------------------------------------------------
 */

if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50400) {
    die('Artax requires PHP 5.4 or higher' . PHP_EOL);
}

define('AX_SYSDIR', __DIR__);

/*
 * --------------------------------------------------------------------
 * SET ERROR REPORTING LEVELS
 * --------------------------------------------------------------------
 * 
 * The built-in Artax error handler turns all PHP errors into
 * `ErrorException` objects which are passed to listeners assigned to the 
 * system `error` event. Handling error events is up to you. If no `error`
 * listeners are attached, non-fatal PHP errors will simply be ignored. This
 * is not recommended, obviously, and you should specify an event listener to
 * handle PHP errors. 
 * 
 * Your error listener(s) might simply throw the `ErrorException` object to
 * treat all PHP errors as exceptions. `error` listeners are also passed the
 * application-wide debug level as a parameter. This allows your handlers
 * to behave differently in development and production environments.
 * 
 * ### Why is E_ERROR ignored?
 * 
 * It may seem counter-intuitive to disable reporting of `E_ERROR` output
 * at any time. However, a fatal error is always fatal, regardless of
 * whether it is reported or not. You can't actually suppress it. Just because
 * a fatal E_ERROR *shouldn't* occur in production code doesn't mean it won't.
 * Ignoring "impossible" conditions is how space shuttles blow up.
 * 
 * Consider for example, a fatal "out of memory" error. In such cases we 
 * still need to prevent ugly error messages from being shown to end users.
 * Setting `display_errors = Off` will not prevent raw error output in the 
 * case of a memory error. Instead, we must also use the `error_reporting` 
 * directive to prevent its display. Artax transforms the fatal error into
 * a `FatalErrorException` which can be handled like any other uncaught 
 * exception using listeners attached to the system `exception` event. This
 * allows applications to treat fatals as if they are run-of-the-mill uncaught 
 * exceptions and terminate gracefully (and perform necessary logging) when
 * unexpected fatals occur.
 */

if (!defined('AX_DEBUG')) {
    define('AX_DEBUG', 1);
}

if (AX_DEBUG === 2) {
    error_reporting(E_ALL);
    ini_set('display_errors', TRUE);
} elseif (AX_DEBUG === 1) {
    error_reporting(E_ALL & ~ E_ERROR);
    ini_set('display_errors', FALSE);
} elseif (AX_DEBUG === 0) {
    error_reporting(E_ALL & ~ E_ERROR);
    ini_set('display_errors', FALSE);
} else {
    throw new RuntimeException(
        'Invalid DEBUG level: 0, 1 or 2 expected; '.AX_DEBUG.' specified'
    );
}

ini_set('html_errors', FALSE);

/*
 * --------------------------------------------------------------------
 * LOAD REQUIRED ARTAX LIBS
 * --------------------------------------------------------------------
 */

require AX_SYSDIR . '/src/Artax/Core/ProviderDefinitionException.php';
require AX_SYSDIR . '/src/Artax/Core/ProviderInterface.php';
require AX_SYSDIR . '/src/Artax/Core/Provider.php';
require AX_SYSDIR . '/src/Artax/Core/MediatorInterface.php';
require AX_SYSDIR . '/src/Artax/Core/Mediator.php';
require AX_SYSDIR . '/src/Artax/Core/FatalErrorException.php';
require AX_SYSDIR . '/src/Artax/Core/ScriptHaltException.php';
require AX_SYSDIR . '/src/Artax/Core/HandlersInterface.php';
require AX_SYSDIR . '/src/Artax/Core/Handlers.php';

/*
 * --------------------------------------------------------------------
 * BOOT THE EVENT MEDIATOR & DEPENDENCY PROVIDER
 * --------------------------------------------------------------------
 */

$provider = new Provider;
$mediator = new Mediator($provider);
$provider->share('Artax\\Core\\Mediator', $mediator);

/*
 * --------------------------------------------------------------------
 * REGISTER ERROR, EXCEPTION & SHUTDOWN HANDLERS
 * --------------------------------------------------------------------
 */

(new Handlers(AX_DEBUG, $mediator))->register();
