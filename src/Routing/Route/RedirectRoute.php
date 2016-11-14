<?php
namespace Bit\Routing\Route;

use Bit\Routing\Exception\RedirectException;
use Bit\Routing\Router;

/**
 * Redirect route will perform an immediate redirect. Redirect routes
 * are useful when you want to have Routing layer redirects occur in your
 * application, for when URLs move.
 *
 * Redirection is signalled by an exception that halts route matching and
 * defines the redirect URL and status code.
 */
class RedirectRoute extends Route
{

    /**
     * A Response object
     *
     * @var \Bit\Network\Response
     * @deprecated 3.2.0 This property is unused.
     */
    public $response = null;

    /**
     * The location to redirect to. Either a string or a BitPHP array URL.
     *
     * @var array|string
     */
    public $redirect;

    /**
     * Constructor
     *
     * @param string $template Template string with parameter placeholders
     * @param array|string $defaults Defaults for the route.
     * @param array $options Array of additional options for the Route
     */
    public function __construct($template, $defaults = [], array $options = [])
    {
        parent::__construct($template, $defaults, $options);
        if (is_array($defaults) && isset($defaults['redirect'])) {
            $defaults = $defaults['redirect'];
        }
        $this->redirect = (array)$defaults;
    }

    /**
     * Parses a string URL into an array. Parsed URLs will result in an automatic
     * redirection.
     *
     * @param string $url The URL to parse.
     * @return false|null False on failure. An exception is raised on a successful match.
     * @throws \Bit\Routing\Exception\RedirectException An exception is raised on successful match.
     *   This is used to halt route matching and signal to the middleware that a redirect should happen.
     */
    public function parse($url)
    {
        $params = parent::parse($url);
        if (!$params) {
            return false;
        }
        $redirect = $this->redirect;
        if (count($this->redirect) === 1 && !isset($this->redirect['controller'])) {
            $redirect = $this->redirect[0];
        }
        if (isset($this->options['persist']) && is_array($redirect)) {
            $redirect += ['pass' => $params['pass'], 'url' => []];
            if (is_array($this->options['persist'])) {
                foreach ($this->options['persist'] as $elem) {
                    if (isset($params[$elem])) {
                        $redirect[$elem] = $params[$elem];
                    }
                }
            }
            $redirect = Router::reverse($redirect);
        }
        $status = 301;
        if (isset($this->options['status']) && ($this->options['status'] >= 300 && $this->options['status'] < 400)) {
            $status = $this->options['status'];
        }
        throw new RedirectException(Router::url($redirect, true), $status);
    }

    /**
     * There is no reverse routing redirection routes.
     *
     * @param array $url Array of parameters to convert to a string.
     * @param array $context Array of request context parameters.
     * @return bool Always false.
     */
    public function match(array $url, array $context = [])
    {
        return false;
    }
}