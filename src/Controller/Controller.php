<?php
namespace Bit\Controller;

use Bit\Controller\Exception\MissingActionException;

use Bit\Core\Bit;
use Bit\LessPHP\Less;
use Bit\LessPHP\LessMethod;
use Bit\Event\Event;
use Bit\Event\EventDispatcherInterface;
use Bit\Event\EventDispatcherTrait;
use Bit\Event\EventListenerInterface;
use Bit\LessPHP\Traits\LessPHP;
use Bit\LessPHP\Interfaces\Less as LessInterface;
use Bit\Log\LogTrait;
use Bit\Network\Request;
use Bit\Network\Response;
use Bit\PHPQuery\QueryObject;
use Bit\Routing\RequestActionTrait;
use Bit\Routing\Router;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;


/**
 * Application controller class for organization of business logic.
 * Provides basic functionality, such as rendering views inside layouts,
 * automatic model availability, redirection, callbacks, and more.
 *
 * Controllers should provide a number of 'action' methods. These are public
 * methods on a controller that are not inherited from `Controller`.
 * Each action serves as an endpoint for performing a specific action on a
 * resource or collection of resources. For example adding or editing a new
 * object, or listing a set of objects.
 *
 * You can access request parameters, using `$this->request`. The request object
 * contains all the POST, GET and FILES that were part of the request.
 *
 * After performing the required action, controllers are responsible for
 * creating a response. This usually takes the form of a generated `View`, or
 * possibly a redirection to another URL. In either case `$this->response`
 * allows you to manipulate all aspects of the response.
 *
 * Controllers are created by `Dispatcher` based on request parameters and
 * routing. By default controllers and actions use conventional names.
 * For example `/posts/index` maps to `PostsController::index()`. You can re-map
 * URLs using Router::connect() or RouterBuilder::connect().
 *
 * ### Life cycle callbacks
 *
 * BitPHP fires a number of life cycle callbacks during each request.
 * By implementing a method you can receive the related events. The available
 * callbacks are:
 *
 * - `beforeFilter(Event $event)`
 *   Called before each action. This is a good place to do general logic that
 *   applies to all actions.
 * - `beforeRender(Event $event)`
 *   Called before the view is rendered.
 * - `beforeRedirect(Event $event, $url, Response $response)`
 *    Called before a redirect is done.
 * - `afterFilter(Event $event)`
 *   Called after each action is complete and after the view is rendered.
 *
 */
class Controller implements \ArrayAccess, EventListenerInterface, EventDispatcherInterface, LessInterface
{
    CONST APPEND = 'body';
    CONST APPEND_FUNC = 'append';


    //use LocatorAwareTrait;

    use EventDispatcherTrait;
    use LogTrait;
    use RequestActionTrait;

    use QueryTrait;

    /**
     * The File off Main Html
     * @var string
     */
    public $template = 'main';

    /**
     * The name of this controller. Controller names are plural, named after the model they manipulate.
     *
     * Set automatically using conventions in Controller::__construct().
     *
     * @var string
     */
    public $name = null;


    /**
     * An instance of a Bit\Network\Request object that contains information about the current request.
     * This object contains all the information about a request and several methods for reading
     * additional information about the request.
     *
     * @var \Bit\Network\Request
     */
    public $request;

    /**
     * An instance of a Response object that contains information about the impending response
     *
     * @var \Bit\Network\Response
     */
    public $response;

    /**
     * The class name to use for creating the response object.
     *
     * @var string
     */
    protected $_responseClass = 'Bit\Network\Response';

    /**
     * Set to true to automatically render the view
     * after action logic.
     *
     * @var bool
     */
    public $autoRender = true;


    /**
     * Holds all passed params.
     *
     * @var array
     * @deprecated 3.1.0 Use `$this->request->params['pass']` instead.
     */
    public $passedArgs = [];

    public $vars = [];


    /**
     * Constructor.
     *
     * Sets a number of properties based on conventions if they are empty. To override the
     * conventions BitPHP uses you can define properties in your class declaration.
     *
     * @param \Bit\Network\Request|null $request Request object for this controller. Can be null for testing,
     *   but expect that features that use the request parameters will not work.
     * @param \Bit\Network\Response|null $response Response object for this controller.
     * @param string|null $name Override the name useful in testing when using mocks.
     * @param \Bit\Event\EventManager|null $eventManager The event manager. Defaults to a new instance.
     */
    public function __construct(Request $request = null, Response $response = null, $name = null, $eventManager = null)
    {
        if ($name !== null) {
            $this->name = $name;
        }

        if ($this->name === null && isset($request->params['controller'])) {
            $this->name = $request->params['controller'];
        }

        if ($this->name === null) {
            list(, $name) = namespaceSplit(get_class($this));
            $this->name = substr($name, 0, -10);
        }

        $this->setRequest($request !== null ? $request : new Request);
        $this->response = $response !== null ? $response : new Response;

        if ($eventManager !== null) {
            $this->eventManager($eventManager);
        }


        $this->initialize();

        $this->eventManager()->on($this);
    }

    /**
     * Initialization hook method.
     *
     * Implement this method to avoid having to overwrite
     * the constructor and call parent.
     *
     * @return void
     */
    public function initialize()
    {
    }

    /**
     * Sets the request objects and configures a number of controller properties
     * based on the contents of the request. Controller acts as a proxy for certain View variables
     * which must also be updated here. The properties that get set are:
     *
     * - $this->request - To the $request parameter
     * - $this->plugin - To the $request->params['plugin']
     * - $this->passedArgs - Same as $request->params['pass]
     * - View::$plugin - $this->plugin
     *
     * @param \Bit\Network\Request $request Request instance.
     * @return void
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        $this->plugin = isset($request->params['plugin']) ? $request->params['plugin'] : null;

        if (isset($request->params['pass'])) {
            $this->passedArgs = $request->params['pass'];
        }
    }

    /**
     * Dispatches the controller action. Checks that the action
     * exists and isn't private.
     *
     * @return mixed The resulting response.
     * @throws \LogicException When request is not set.
     * @throws \Bit\Controller\Exception\MissingActionException When actions are not defined or inaccessible.
     */
    public function invokeAction()
    {
        $request = $this->request;
        if (!isset($request)) {
            throw new LogicException('No Request object configured. Cannot invoke action');
        }
        if (!$this->isAction($request->params['action'])) {
            throw new MissingActionException([
                'controller' => $this->name . "Controller",
                'action' => $request->params['action'],
                'prefix' => isset($request->params['prefix']) ? $request->params['prefix'] : '',
                'plugin' => $request->params['plugin'],
            ]);
        }

        return $this->_runAction($request->params['action'], $request->params['pass']);
    }

    /**
     * @param string $action
     * @param array $params
     * @return Response|mixed The resulting response.
     */
    protected function _runAction(string $action, array $params)
    {
        $this->page = $this->getTemplate($this->template);
        $this->method = $this->less()->getMethod($action);

        array_map(function ($params) {
            call_user_func_array([$this, "loadTemplate"], $params);
        }, $this->method->getTag('template'));

        $event = $this->dispatchEvent('Controller.beforeRunAction');
        if ($event->result instanceof Response) {
            return $event->result;
        }

        return call_user_func_array([$this, $action], $params);
    }


    /**
     * Returns a list of all events that will fire in the controller during its lifecycle.
     * You can override this function to add your own listener callbacks
     *
     * @return array
     */
    public function implementedEvents()
    {
        return [
            'Controller.initialize' => 'beforeFilter',
            'Controller.beforeRunAction' => 'beforeRunAction',
            'Controller.beforeRender' => 'beforeRender',
            'Controller.beforeRedirect' => 'beforeRedirect',
            'Controller.shutdown' => 'afterFilter',
        ];
    }


    /**
     * Perform the startup process for this controller.
     * Fire the Components and Controller callbacks in the correct order.
     *
     * - Initializes components, which fires their `initialize` callback
     * - Calls the controller `beforeFilter`.
     * - triggers Component `startup` methods.
     *
     * @return \Bit\Network\Response|null
     */
    public function startupProcess()
    {
        $event = $this->dispatchEvent('Controller.initialize');
        if ($event->result instanceof Response) {
            return $event->result;
        }
        $event = $this->dispatchEvent('Controller.startup');
        if ($event->result instanceof Response) {
            return $event->result;
        }
    }

    /**
     * Perform the various shutdown processes for this controller.
     * Fire the Components and Controller callbacks in the correct order.
     *
     * - triggers the component `shutdown` callback.
     * - calls the Controller's `afterFilter` method.
     *
     * @return \Bit\Network\Response|null
     */
    public function shutdownProcess()
    {
        $event = $this->dispatchEvent('Controller.shutdown');
        if ($event->result instanceof Response) {
            return $event->result;
        }
    }

    /**
     * Redirects to given $url, after turning off $this->autoRender.
     * Script execution is halted after the redirect.
     *
     * @param string|array $url A string or array-based URL pointing to another location within the app,
     *     or an absolute URL
     * @param int $status HTTP status code (eg: 301)
     * @return \Bit\Network\Response|null
     */
    public function redirect($url, $status = 302)
    {
        $this->autoRender = false;

        $response = $this->response;
        if ($status) {
            $response->statusCode($status);
        }

        $event = $this->dispatchEvent('Controller.beforeRedirect', [$url, $response]);
        if ($event->result instanceof Response) {
            return $event->result;
        }
        if ($event->isStopped()) {
            return null;
        }
        if (!$response->location()) {
            $response->location(Router::url($url, true));
        }

        return $response;
    }

    /**
     * Internally redirects one action to another. Does not perform another HTTP request unlike Controller::redirect()
     *
     * Examples:
     *
     * ```
     * setAction('another_action');
     * setAction('action_with_parameters', $parameter1);
     * ```
     *
     * @param string $action The new action to be 'redirected' to.
     *   Any other parameters passed to this method will be passed as parameters to the new action.
     * @return mixed Returns the return value of the called action
     */
    public function setAction($action)
    {
        $this->request->params['action'] = $action;
        $args = func_get_args();
        unset($args[0]);
        return $this->_runAction($action, $args);
    }

    /**
     * Instantiates the correct view class, hands it its data, and uses it to render the view output.
     *
     * @return \Bit\Network\Response A response object containing the rendered view.
     */
    public function render()
    {
        $event = $this->dispatchEvent('Controller.beforeRender');
        if ($event->result instanceof Response) {
            return $event->result;
        }
        if ($event->isStopped()) {
            return $this->response;
        }

        $this->response->body($this->page);
        return $this->response;
    }


    /**
     * Returns the referring URL for this request.
     *
     * @param string|null $default Default URL to use if HTTP_REFERER cannot be read from headers
     * @param bool $local If true, restrict referring URLs to local server
     * @return string Referring URL
     */
    public function referer($default = null, $local = false)
    {
        if (!$this->request) {
            return Router::url($default, !$local);
        }

        $referer = $this->request->referer($local);
        if ($referer === '/' && $default && $default !== $referer) {
            return Router::url($default, !$local);
        }
        return $referer;
    }


    /**
     * Method to check that an action is accessible from a URL.
     *
     * Override this method to change which controller methods can be reached.
     * The default implementation disallows access to all methods defined on Bit\Controller\Controller,
     * and allows all public methods on all subclasses of this class.
     *
     * @param string $action The action to check.
     * @return bool Whether or not the method is accessible from a URL.
     */
    public function isAction($action)
    {
        $baseClass = new ReflectionClass('Bit\Controller\Controller');
        if ($baseClass->hasMethod($action)) {
            return false;
        }
        try {
            $method = new ReflectionMethod($this, $action);
        } catch (ReflectionException $e) {
            return false;
        }

        return $method->isPublic();
    }

    /**
     * Called before the controller action. You can use this method to configure and customize components
     * or perform logic that needs to happen before each controller action.
     *
     * @param \Bit\Event\Event $event An Event instance
     * @return \Bit\Network\Response|null
     */
    public function beforeFilter(Event $event)
    {
        return null;
    }

    public function beforeRunAction(Event $event)
    {
        return null;
    }

    /**
     * Called after the controller action is run, but before the view is rendered. You can use this method
     * to perform logic or set view variables that are required on every request.
     *
     * @param \Bit\Event\Event $event An Event instance
     * @return \Bit\Network\Response|null
     */
    public function beforeRender(Event $event)
    {
        return null;
    }

    /**
     * The beforeRedirect method is invoked when the controller's redirect method is called but before any
     * further action.
     *
     * If the event is stopped the controller will not continue on to redirect the request.
     * The $url and $status variables have same meaning as for the controller's method.
     * You can set the event result to response instance or modify the redirect location
     * using controller's response instance.
     *
     * @param \Bit\Event\Event $event An Event instance
     * @param string|array $url A string or array-based URL pointing to another location within the app,
     *     or an absolute URL
     * @param \Bit\Network\Response $response The response object.
     * @return \Bit\Network\Response|null
     */
    public function beforeRedirect(Event $event, $url, Response $response)
    {
        return null;
    }

    /**
     * Called after the controller action is run and rendered.
     *
     * @param \Bit\Event\Event $event An Event instance
     * @return \Bit\Network\Response|null
     */
    public function afterFilter(Event $event)
    {
        return null;
    }
}