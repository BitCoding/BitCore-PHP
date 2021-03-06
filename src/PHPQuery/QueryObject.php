<?php
/**
 * BitCore-PHP:  Rapid Development Framework (https://phpcore.bitcoding.eu)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://phpcore.bitcoding.eu BitCore-PHP Project
 * @since         0.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Bit\PHPQuery;

use Bit\Core\Exception\Exception;
use Bit\PHPQuery\Exception\MissingElementException;
use \IteratorAggregate;
use \Countable;
use \ArrayAccess;

/**
 * Class QueryObject
 * @package Bit\PHPQuery
 * @method self|QueryObject clone()  Clone Object
 */
class QueryObject implements Countable, IteratorAggregate, ArrayAccess
{
    /**
     * The current URI
     * @var string
     */
    protected $uri;

    /**
     * The default namespace prefix to be used with XPath and CSS expressions
     * @var string
     */
    private $defaultNamespacePrefix = 'default';

    /**
     * A map of manually registered namespaces
     * @var array
     */
    private $namespaces = array();

    /**
     * The base href value
     * @var string
     */
    private $baseHref;

    /**
     * The base href value
     * @var string
     */
    private $charset;

    /**
     * The Document
     * @var \DOMDocument|null
     */
    private $document;

    /**
     * Selected Nodes
     *
     * @var \DOMElement[]
     */
    private $nodes = array();

    /**
     * Whether the Crawler contains HTML or XML content (used when converting CSS to XPath).
     *
     * @var bool
     */
    private $isHtml = true;

    /**
     * QueryObject constructor.
     *
     * @param null $node A Node to use as the base for the crawling
     * @param null $currentUri The current URI
     * @param null $baseHref The base href value
     * @param string $charset
     */
    public function __construct($node = null, $currentUri = null, $baseHref = null,$charset = 'UTF-8')
    {
        $this->uri = $currentUri;
        $this->baseHref = $baseHref ?: $currentUri;
        $this->charset  = $charset ?: null;

        $this->add($node);
    }

    /**
     * Hash Document
     * @return string
     */
    public function getDocumentID(){
        return spl_object_hash($this->document);
    }

    /**
     * Returns base href.
     *
     * @return string
     */
    public function getBaseHref()
    {
        return $this->baseHref;
    }

    /**
     * Can add nodes
     * or return all Nodes
     *
     * @param null $nodes
     * @return QueryObject|\DOMElement[]|\DOMNode[]
     */
    public function nodes($nodes = null)
    {
        if ($nodes === null) {
            return $this->nodes;
        }
        return $this->add($nodes);
    }

    /**
     * Removes all the nodes.
     */
    public function clear()
    {
        $this->nodes = array();
        $this->document = null;
    }

    /**
     * Adds a node to the current list of nodes.
     *
     * This method uses the appropriate specialized add*() method based
     * on the type of the argument.
     *
     * @param \DOMNodeList|\DOMNode|array|string|null $node A node
     *
     * @throws \InvalidArgumentException When node is not the expected type.
     *
     * @return $this
     */
    protected function add($node)
    {
        if ($node instanceof \DOMNodeList) {
            $this->addNodeList($node);
        } elseif ($node instanceof \DOMNode) {
            $this->addNode($node);
        } elseif (is_array($node)) {
            $this->addNodes($node);
        } elseif (is_string($node)) {
            $this->addContent($node);
        } elseif (null !== $node) {
            throw new \InvalidArgumentException(sprintf('Expecting a DOMNodeList or DOMNode instance, an array, a string, or null, but got "%s".', is_object($node) ? get_class($node) : gettype($node)));
        }
        return $this;
    }


    /**
     * Adds HTML/XML content.
     *
     * If the charset is not set via the content type, it is assumed
     * to be ISO-8859-1, which is the default charset defined by the
     * HTTP 1.1 specification.
     *
     * @param string $content A string to parse as HTML/XML
     * @param null|string $type The content type of the string
     */
    protected function addContent($content, $type = null)
    {
        if (empty($type)) {
            $type = 0 === strpos($content, '<?xml') ? 'application/xml' : 'text/html';
        }

        // DOM only for HTML/XML content
        if (!preg_match('/(x|ht)ml/i', $type, $xmlMatches)) {
            return;
        }

        $charset = null;
        if (false !== $pos = stripos($type, 'charset=')) {
            $charset = substr($type, $pos + 8);
            if (false !== $pos = strpos($charset, ';')) {
                $charset = substr($charset, 0, $pos);
            }
        }

        // http://www.w3.org/TR/encoding/#encodings
        // http://www.w3.org/TR/REC-xml/#NT-EncName
        if (null === $charset &&
            preg_match('/\<meta[^\>]+charset *= *["\']?([a-zA-Z\-0-9_:.]+)/i', $content, $matches)
        ) {
            $this->charset = $matches[1];
        }

        if (null === $this->charset) {
            $charset = 'UTF-8';
        }else{
            $charset = $this->charset;
        }

        if ('x' === $xmlMatches[1]) {
            $this->addXmlContent($content, $charset);
        } else {
            $this->addHtmlContent($content, $charset);
        }
    }

    /**
     * Adds an HTML content to the list of nodes.
     *
     * The libxml errors are disabled when the content is parsed.
     *
     * If you want to get parsing errors, be sure to enable
     * internal errors via libxml_use_internal_errors(true)
     * and then, get the errors via libxml_get_errors(). Be
     * sure to clear errors with libxml_clear_errors() afterward.
     *
     * @param string $content The HTML content
     * @param string $charset The charset
     */
    protected function addHtmlContent($content, $charset = 'UTF-8')
    {
        $internalErrors = libxml_use_internal_errors(true);
        $disableEntities = libxml_disable_entity_loader(true);

        $dom = new \DOMDocument('1.0', $charset);
        $dom->validateOnParse = true;

        set_error_handler(function () {
            throw new \Exception();
        });

        try {
            // Convert charset to HTML-entities to work around bugs in DOMDocument::loadHTML()
            $content = mb_convert_encoding($content, 'HTML-ENTITIES', $charset);
        } catch (\Exception $e) {
        }

        restore_error_handler();

        if ('' !== trim($content)) {
            @$dom->loadHTML($content,LIBXML_HTML_NOIMPLIED);
        }

        libxml_use_internal_errors($internalErrors);
        libxml_disable_entity_loader($disableEntities);

        $this->addDocument($dom);

        $base = $this->filterRelativeXPath('descendant-or-self::base')->extract(array('href'));

        $baseHref = current($base);
        if (count($base) && !empty($baseHref)) {
            if ($this->baseHref) {
                $linkNode = $dom->createElement('a');
                $linkNode->setAttribute('href', $baseHref);
                $link = new Link($linkNode, $this->baseHref);
                $this->baseHref = $link->getUri();
            } else {
                $this->baseHref = $baseHref;
            }
        }
    }

    /**
     * Adds an XML content to the list of nodes.
     *
     * The libxml errors are disabled when the content is parsed.
     *
     * If you want to get parsing errors, be sure to enable
     * internal errors via libxml_use_internal_errors(true)
     * and then, get the errors via libxml_get_errors(). Be
     * sure to clear errors with libxml_clear_errors() afterward.
     *
     * @param string $content The XML content
     * @param string $charset The charset
     * @param int $options Bitwise OR of the libxml option constants
     *                        LIBXML_PARSEHUGE is dangerous, see
     *                        http://symfony.com/blog/security-release-symfony-2-0-17-released
     */
    protected function addXmlContent($content, $charset = 'UTF-8', $options = LIBXML_NONET)
    {
        // remove the default namespace if it's the only namespace to make XPath expressions simpler
        if (!preg_match('/xmlns:/', $content)) {
            $content = str_replace('xmlns', 'ns', $content);
        }

        $internalErrors = libxml_use_internal_errors(true);
        $disableEntities = libxml_disable_entity_loader(true);

        $dom = new \DOMDocument('1.0', $charset);
        $dom->validateOnParse = true;

        if ('' !== trim($content)) {
            @$dom->loadXML($content, $options);
        }

        libxml_use_internal_errors($internalErrors);
        libxml_disable_entity_loader($disableEntities);

        $this->addDocument($dom);

        $this->isHtml = false;
    }

    /**
     * TODO
     * Adds a \DOMDocument to the list of nodes.
     *
     * @param \DOMDocument $dom A \DOMDocument instance
     */
    protected function addDocument(\DOMDocument $dom)
    {
        if(!$dom->documentElement)
            throw new MissingElementException([

            ]);

        if ($dom->documentElement->tagName === 'html') {
            $this->addNode($dom->documentElement);
        }
        else
            $this->addNodeList($dom->childNodes);
    }

    /**
     * Adds a \DOMNodeList to the list of nodes.
     *
     * @param \DOMNodeList $nodes A \DOMNodeList instance
     */
    protected function addNodeList(\DOMNodeList $nodes)
    {
        foreach ($nodes as $node) {
            if ($node instanceof \DOMNode) {
                $this->addNode($node);
            }
        }
    }

    /**
     * Adds an array of \DOMNode instances to the list of nodes.
     *
     * @param \DOMNode[] $nodes An array of \DOMNode instances
     */
    protected function addNodes(array $nodes)
    {
        foreach ($nodes as $node) {
            $this->add($node);
        }
    }

    /**
     * Adds a \DOMNode instance to the list of nodes.
     *
     * @param \DOMNode $node A \DOMNode instance
     */
    protected function addNode(\DOMNode $node)
    {
        if ($node instanceof \DOMDocument) {
            $node = $node->documentElement;
        }

        if (null !== $this->document && $this->document !== $node->ownerDocument) {
            throw new \InvalidArgumentException('Attaching DOM nodes from multiple documents in the same crawler is forbidden.');
        }

        if (null === $this->document) {
            $this->document = $node->ownerDocument;
        }

        // Don't add duplicate nodes in the Crawler
        if (in_array($node, $this->nodes, true)) {
            return;
        }

        $this->nodes[] = $node;
    }


    /**
     * Extracts information from the list of nodes.
     *
     * You can extract attributes or/and the node value (_text).
     *
     * Example:
     * ```
     * $crawler->filter('h1 a')->extract(array('_text', 'href'));
     * ```
     * @param array $attributes An array of attributes
     *
     * @return array An array of extracted values
     */
    public function extract($attributes)
    {
        $attributes = (array)$attributes;
        $count = count($attributes);

        $data = array();
        foreach ($this->nodes as $node) {
            $elements = array();
            foreach ($attributes as $attribute) {
                if ('_text' === $attribute) {
                    $elements[] = $node->nodeValue;
                } else {
                    $elements[] = $node->getAttribute($attribute);
                }
            }

            $data[] = $count > 1 ? $elements : $elements[0];
        }

        return $data;
    }

    /**
     * Filters the list of nodes with a CSS selector.
     *
     * This method only works if you have installed the CssSelector Symfony Component.
     *
     * @param string $selector A CSS selector
     *
     * @return self|QueryObject A new instance of QueryObject with the filtered list of nodes
     *
     * @throws \RuntimeException if the CssSelector Component is not available
     */
    public function find($selector)
    {
        // The CssSelector already prefixes the selector with descendant-or-self::
        return $this->filterRelativeXPath(PHPQueryFactory::toXPath($selector));
    }

    /**
     * Filters the list of nodes with an XPath expression.
     *
     * The XPath expression should already be processed to apply it in the context of each node.
     *
     * @param string $xpath
     *
     * @return self|QueryObject
     */
    public function filterRelativeXPath($xpath)
    {
        $prefixes = $this->findNamespacePrefixes($xpath);

        $crawler = $this->createSub(null);

        foreach ($this->nodes as $node) {
            $domxpath = $this->createDOMXPath($node->ownerDocument, $prefixes);
            $crawler->add($domxpath->query($xpath, $node));
        }

        return $crawler;
    }


    /**
     * Calls an anonymous function on each node of the list.
     *
     * The anonymous function receives the position and the node wrapped
     * in a Crawler instance as arguments.
     *
     * Example:
     *
     *     $crawler->filter('h1')->each(function ($node, $i) {
     *         return $node->text();
     *     });
     *
     * @param \Closure $closure An anonymous function
     *
     * @return array An array of values returned by the anonymous function
     */
    public function each(\Closure $closure)
    {
        $data = array();
        foreach ($this->nodes as $i => $node) {
            $data[] = $closure($this->createSub($node), $i);
        }

        return $data;
    }


    /**
     * Calls an anonymous function on this.
     *
     * The anonymous function receives the position and the node wrapped
     * in a Crawler instance as arguments.
     *
     * Example:
     *
     *     $crawler->filter('h1')->excecute(function (QueryObject $node) {
     *         $node->text('Test');
     *     });
     *
     * @param \Closure $closure An anonymous function
     *
     * @return self|QueryObject
     */
    public function excecute(\Closure $closure)
    {
        $closure($this);

        return $this;
    }

    /**
     * Slices the list of nodes by $offset and $length.
     *
     * @param int $offset
     * @param int $length
     *
     * @return self|QueryObject A Crawler instance with the sliced nodes
     */
    public function slice($offset = 0, $length = null)
    {
        return $this->createSub(array_slice($this->nodes, $offset, $length));
    }

    /**
     * Reduces the list of nodes by calling an anonymous function.
     *
     * To remove a node from the list, the anonymous function must return false.
     *
     * @param \Closure $closure An anonymous function
     *
     * @return self|QueryObject A Crawler instance with the selected nodes.
     */
    public function reduce(\Closure $closure)
    {
        $nodes = array();
        foreach ($this->nodes as $i => $node) {
            if (false !== $closure($this->createSub($node), $i)) {
                $nodes[] = $node;
            }
        }

        return $this->createSub($nodes);
    }

    /**
     * Returns a node given its position in the node list.
     *
     * @param int $position The position
     *
     * @return self|QueryObject A new instance of the Crawler with the selected node, or an empty Crawler if it does not exist.
     */
    public function eq($position)
    {
        if (isset($this->nodes[$position])) {
            return $this->createSub($this->nodes[$position]);
        }

        return $this->createSub(null);
    }

    /**
     * Magic method
     *
     * Factory to load plugins
     *
     * @access private
     *
     * @param $method
     * @param $args
     * @return self|QueryObject|Plugin|array|string
     */
    public function __call($method, $args)
    {
        if($method == 'clone')
        {
            $return = [];
            foreach ($this as $node) {
                if($node instanceof \DOMDocumentType)
                    continue;
                $return[] = $node->cloneNode(true);
            }

            return $this->createSub($return);
        }

        if ($_cls = PHPQueryFactory::plugin($method)) {
            return $_cls->invoke($this,$args);
        }
        //var_dump(static::plugins()->Translate);

        if ($call = PHPQueryFactory::method($method)) {
            return $call($this, $args);
        }

        throw new \InvalidArgumentException('The method '.$method.' not exist.');
    }

    /**
     * The `remove()` method takes elements out of the DOM.
     * Use `remove()` when you want to remove the element itself, as well as
     * everything inside it. In addition to the elements
     * themselves, all bound events and jQuery data associated
     * with the elements are removed.
     *
     * ### Example:
     *
     * Consider the following HTML:
     * ```
     * <div class="container">
     *  <div class="hello">Hello</div>
     *  <div class="goodbye">Goodbye</div>
     * </div>
     * ```
     *
     * We can target any element for removal:
     * ```
     * $node->find('.hello')->remove();
     * ```
     *
     * @param null|string $selector
     * @return $this|self|QueryObject
     */
    public function remove($selector = null)
    {
        $loop = $selector
            ? $this->filter($selector)
            : $this;

        $size = $loop->count();
        if (!$size) {
            return $this;
        }

        foreach ($loop->nodes() as $key=>$node) {
            if (!$node->parentNode)
                continue;

            $node->parentNode->removeChild($node);
        }
        return $this;
    }

    /**
     * The `attr()` method gets the attribute value for only the first element
     * in the matched set. To get the value for each element individually, use a looping
     * construct such as jQuery's `each()`.
     *
     * Using jQuery's `attr()` method to get the value of an element's
     * attribute has two main benefits:
     *
     * @param null $attr
     * @param null $value
     * @return array|QueryObject|null|string
     */
    public function attr($attr = null, $value = null)
    {
        $size = $this->count();
        if (!$size) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $vNull = is_null($value);
        $isArray = is_array($attr);
        $set = !$isArray && $attr === '*';

        if ($size === 1) {
            $node = $this->getNode(0);
            if (!$isArray && $vNull) {

                if ($set) {
                    foreach (($node->attributes) as $n => $v)
                        $return[$n] = $v->value;
                    return (object)$return;
                }
                return $node->hasAttribute($attr) ? $node->getAttribute($attr) : null;
            } else {
                if ($isArray) {
                    foreach ($attr as $k => $v)
                        @$node->setAttribute($k, is_array($v) ? json_encode($v) : $v);
                } else if ($set)
                    foreach (($this->getNodeAttrs($node)) as $a)
                        @$node->setAttribute($a, is_array($value) ? json_encode($value) : $value);
                else
                    @$node->setAttribute($attr, is_array($value) ? json_encode($value) : $value);
                return $this;
            }
        }
        $results = [];
        foreach ($this->nodes() as $key => $node) {
            if ($vNull) {
                if ($isArray || $set) {
                    foreach (($node->attributes) as $n => $v)
                        if ($set || in_array($n, $attr))
                            $results[$key][$n] = $v->value;
                    $results[$key] = (object)$results[$key];
                    continue;
                }
                if ($node->hasAttribute($attr))
                    $results[$key] = $node->getAttribute($attr);
            } else if ($isArray)
                foreach ($attr as $k => $v)
                    @$node->setAttribute($k, is_array($v) ? json_encode($v) : $v);
            else if ($set)
                foreach ($node->attributes as $a)
                    @$node->setAttribute($a, is_array($value) ? json_encode($value) : $value);
            else
                @$node->setAttribute($attr, is_array($value) ? json_encode($value) : $value);
        }
        return $vNull ? $results : $this;
    }

    /**
     * Remove Attributes
     *
     * @param $attr
     * @return $this
     */
    public function removeAttr($attr) {
        foreach($this->nodes() as $node) {
            $loop = $attr == '*' ? $node->attributes : [$attr=>0];

            foreach($loop as $key=>$value) {
                $node->removeAttribute($key);
            }
        }
        return $this;
    }

    /**
     * In an HTML document, html() can be used to get the contents of
     * any element. If the selector expression matches more than one element,
     * only the first match will have its HTML content returned. Consider this code:
     *
     * ### Example:
     *
     * ```
     * $html = $node->html();
     * ```
     *
     * When .html() is used to set an element's content,
     * any content that was in that element is completely replaced by the new
     * content. Additionally, jQuery removes other constructs such as data and
     * event handlers from child elements before replacing those elements with
     * the new content.
     *
     * ### Example:
     *
     * ```
     * $node->html('<a href="#">Test</a>');
     * ```
     *
     * @param string|QueryObject|callable $html
     * @param boolean $innerMarkup
     * @return string|phpQuery|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
     * @TODO force html result
     */
    public function html($html = null, $innerMarkup = false)
    {
        $size = $this->count();
        if (!$size) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        if ($html) {
            if($html instanceof QueryObject)
                $nodes = $html;
            else{
                $html = trim(($html instanceof \Closure) ? $html() : $html);
                $unwrap = !isset($html[0]) || $html[0] != '<';
                if($unwrap)
                    $html = '<text>'.$html.'</text>';
                $nodes = new static($html);

                if($unwrap)
                    $nodes = new static($nodes->find('text')->nodes()[0]->childNodes);
            }
            foreach($this->nodes as $alreadyAdded => $node) {
                $node->nodeValue = '';
                foreach ($nodes->nodes() as $newNode) {
                    $newNode = $this->document->importNode($newNode, true);
                    if(!$newNode)
                        continue;
                    $node->appendChild($newNode);
                }
            }
            return $this;
        }
        $html = '';
        foreach ($this->nodes() as $node) {
            if ($innerMarkup && $node->childNodes)
                foreach($node->childNodes as $child)
                    $html .= $child->ownerDocument->saveHTML($child);
            else
                $html .= $node->ownerDocument->saveHTML($node);
        }

        return $html;
    }

    /**
     * Return joined text content.
     *
     * @param null $text
     * @return $this|string
     */
    public function text($text = null)
    {
        if (!is_null($text)) {
            $test = $this->document->createTextNode($text);
            foreach ($this->nodes() as $node) {
                $node->nodeValue = '';
                $node->appendChild($test->cloneNode(true));
            }
            return $this;
        }
        $return = '';
        $i = count($this);
        foreach ($this->nodes() as $node) {
            $txt = trim($node->textContent);
            if ($i > 1 && $txt)
                $txt .= "\n";
            elseif($node->nodeName === 'br')
                $txt .= "\n";
            $return .= $txt;
        }
        return $return;
    }

    /**
     * Clean the Text
     * @return QueryObject
     */
    public function cleanText(){
        return $this->filterRelativeXPath('/html/body//text()[not(ancestor::script) and not(normalize-space(.) = "")] | /html/body//br');
    }

    /**
     * Given a QueryObject that represents a set of DOM elements, the parent()
     * method traverses to the immediate parent of each of these elements in the
     * DOM tree and constructs a new jQuery object from the matching elements.
     *
     * @param null $selector
     * @return self|QueryObject
     */
    public function parent($selector = null){
        $stack = array();
        foreach($this->nodes() as $node )
            if ( $node->parentNode && ! $this->stackContainsNode($node->parentNode, $stack) )
                $stack[] = $node->parentNode;

        $new = $this->createSub($stack);
        return ( $selector ) ? $new->filter($selector, true) : $new;
    }


    /**
     * Returns the first node of the current selection.
     *
     * @return self|QueryObject A Crawler instance with the first selected node
     */
    public function first()
    {
        return $this->eq(0);
    }

    /**
     * Returns the last node of the current selection.
     *
     * @return self|QueryObject A Crawler instance with the last selected node
     */
    public function last()
    {
        return $this->eq(count($this->nodes) - 1);
    }

    /**
     * Creates a QueryObject for some subnodes.
     *
     * @param \DOMElement|\DOMElement[]|\DOMNodeList|null $nodes
     *
     * @return static
     */
    private function createSub($nodes)
    {
        $crawler = new static($nodes,null,null,$this->charset);
        $crawler->document = $this->document;

        return $crawler;
    }

    /**
     * Return node on Specific position
     *
     * @param int $position
     *
     * @return \DOMElement|null
     */
    public function getNode($position)
    {
        if (isset($this->nodes[$position])) {
            return $this->nodes[$position];
        }
        return null;
    }

    /**
     * Nodes As Iterator
     *
     * @return \ArrayIterator|self[]|QueryObject[]
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->nodes);
    }

    /**
     * Count node elements
     * @return mixed
     */
    public function count()
    {
        return count($this->nodes);
    }

    /**
     * Helper
     *
     * @param \DOMDocument $document
     * @param array $prefixes
     *
     * @return \DOMXPath
     *
     * @throws \InvalidArgumentException
     */
    private function createDOMXPath(\DOMDocument $document, array $prefixes = array())
    {
        $domxpath = new \DOMXPath($document);

        foreach ($prefixes as $prefix) {
            $namespace = $this->discoverNamespace($domxpath, $prefix);
            if (null !== $namespace) {
                $domxpath->registerNamespace($prefix, $namespace);
            }
        }

        return $domxpath;
    }

    /**
     * Helper
     *
     * @param string $xpath
     *
     * @return array
     */
    private function findNamespacePrefixes($xpath)
    {
        if (preg_match_all('/(?P<prefix>[a-z_][a-z_0-9\-\.]*+):[^"\/:]/i', $xpath, $matches)) {
            return array_unique($matches['prefix']);
        }

        return array();
    }

    /**
     * conbert object to html()
     *
     * @return QueryObject|string
     */
    public function __toString() {
        return count($this->find('html')) ? '<!DOCTYPE html>'.PHP_EOL.$this->html() : (count($this) ? $this->html()  :'' ) ;
    }


    /**
     *
     * OLD stackContainsNode
     *
     * @param $nodeToCheck
     * @param null $elementsStack
     * @return bool
     */
    protected function stackContainsNode($nodeToCheck, $elementsStack = null) {
        $loop = $elementsStack ?: $this->nodes();
        foreach($loop as $node) {
            if ($node->isSameNode($nodeToCheck))
                return true;
        }
        return false;
    }


    // === PHPQuery

    /**
     * It's important to note that this method does not replace a class.
     * It simply adds the class, appending it to any which may already be assigned to the elements.
     *
     * Example:
     * ```
     *     $node->find('h1')->addClass("black");
     * ```
     *
     * @param    string $className
     * @return QueryObject|self|$this
     */
    public function addClass($className)
    {
        if (!$this->count() || !$className)
            return $this;

        foreach ($this->nodes() as $node) {
            $cls = $node->getAttribute('class');
            if ($cls == '' || self::_matchClasses($cls, $className) == "0")
                $node->setAttribute(
                    'class',
                    trim($cls . ' ' . $className)
                );
        }
        return $this;
    }

    /**
     * Determine whether any of the matched elements are assigned the given class.
     *
     * Example:
     * ```
     *     $isActive = $node->find('h1')->hasClass("active");
     * ```
     * @param    string $className
     * @return    bool
     */
    public function hasClass($className)
    {
        foreach ($this->nodes() as $node) {
            if ($this->is(".$className", $node))
                return true;
        }
        return false;
    }

    /**
     * If a class name is included as a parameter, then only that class will be removed
     * from the set of matched elements. If no class names are specified in the parameter,
     * all classes will be removed.
     *
     * Example:
     * ```
     *      $node->find('h1')->removeClass("active");
     * ```
     * @param $className
     * @return QueryObject|$this
     */
    public function removeClass($className)
    {
        foreach ($this->nodes() as $node) {
            $classes = explode(' ', $node->getAttribute('class'));
            if (in_array($className, $classes)) {
                $classes = array_diff($classes, array($className));
                if ($classes)
                    $node->setAttribute('class', implode(' ', $classes));
                else
                    $node->removeAttribute('class');
            }
        }
        return $this;
    }

    /**
     *
     * Add or remove one or more classes from each element in the set of matched elements,
     * depending on either the class's presence or the value of the state argument.
     *
     * This method takes one or more class names as its parameter.
     * In the first version, if an element in the matched set of elements already
     * has the class, then it is removed; if an element does not have the class,
     * then it is added. For example, we can apply `toggleClass()` to a simple `<div>`:
     *
     * @param $className
     * @return $this|QueryObject
     */
    public function toggleClass($className)
    {
        foreach ($this->nodes() as $node) {
            if ($this->is($node, '.' . $className))
                $this->removeClass($className);
            else
                $this->addClass($className);
        }
        return $this;
    }

    /**
     * Unlike other filtering methods, `is()` does not create a new jQuery object.
     * Instead, it allows you to test the contents of a jQuery object without
     * modification. This is often useful inside callbacks, such as event handlers.
     *
     * @param $selector
     * @param null $nodes
     * @return array|bool|null
     */
    public function is($selector, $nodes = null)
    {
        if (!$selector)
            return false;

        $sub = $this;

        if ($nodes && is_array($nodes)) {
            $sub = is_array($nodes) ? $this->createSub($nodes) : $this->createSub([$nodes]);
        }

        $sub = $sub->filter($selector);

        return (bool)count($sub);
    }

    /**
     *
     * print_r for QueryObject
     *
     * @param $var
     * @return QueryObject
     */
    public function print_r($var){
        ob_start();
        var_dump($var);
        $result = ob_get_clean();
        $this->append(sprintf('<pre class="pr">%s</pre>', $result));
        return $this;
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     * @return QueryObject
     */
    public function append($content)
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     * @return QueryObject
     */
    public function prepend($content)
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     * @return QueryObject
     */
    public function before($content)
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     * @return QueryObject
     */
    public function after($content)
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     * @return QueryObject
     */
    public function appendTo($seletor)
    {
        return $this->insert($seletor, __FUNCTION__);
    }

    /**
     * Enter description here...
     * @param String|QueryObject
     *
     * @return QueryObject
     */
    public function prependTo($seletor)
    {
        return $this->insert($seletor, __FUNCTION__);
    }

    /**
     * Enter description here...
     *
     * @param String|QueryObject
     * @return QueryObject
     */
    public function insertBefore($seletor)
    {
        return $this->insert($seletor, __FUNCTION__);
    }

    /**
     * Enter description here...
     *
     * @param $seletor
     * @return QueryObject
     * @throws \Exception
     */
    public function insertAfter($seletor)
    {
        return $this->insert($seletor, __FUNCTION__);
    }

    /**
     * Description TODO
     *
     * @param $source
     * @param $sourceCharset
     * @return array Array of imported nodes.
     */
    public function import($source, $sourceCharset = null)
    {

        // TODO charset conversions
        $return = array();

        if (!($source instanceof QueryObject)) {
            $source = (new self($source, null, null, $sourceCharset));
        }
        if ($source->getDocumentID() === $this->getDocumentID()) {

            var_dump($source->getDocumentID(), $this->getDocumentID());
            die('TT');

            return $source;
        }

        foreach ($source as $node) {
            if($node instanceof \DOMDocumentType)
                continue;
            $return[] = $this->document->importNode($node, true);
        }

        return $this->createSub($return);
    }

    /**
     * Internal insert method. Don't use it.
     *
     * @param String|QueryObject $_target
     * @param String $type
     * @return QueryObject
     * @access private
     */
    private function insert($_target, $type)
    {
        $insertFrom = $insertTo = array();
        $to = in_array($type,['appendTo','prependTo','insertBefore','insertAfter']);

        if ($_target instanceof self)
            $target = $_target;
        else if(!$to || static::isMarkup($_target))
            $target = $this->import($_target);
        else if ($to && is_string($_target))
            $target = $this->find($_target);

        list($to,$from) =$to ? [$target,$this] : [$this,$target];

        $insertTo = $to;
        $insertFrom = $to->getDocumentID() === $from->getDocumentID()
            ? $from
            : $insertTo->import($from);


        foreach ($insertTo as $insertNumber => $toNode) {
            switch ($type) {
                case 'prependTo':
                case 'prepend':
                    $firstChild = $toNode->firstChild;
                    break;
                case 'insertAfter':
                case 'after':
                    $nextSibling = $toNode->nextSibling;
                    break;
            }

            foreach ($insertFrom as $fromNode) {
                // clone if inserted already before
                $insert = $insertNumber
                    ? $fromNode->cloneNode(true)
                    : $fromNode;

                switch ($type) {
                    case 'appendTo':
                    case 'append':
                        $toNode->appendChild($insert);
                        $eventTarget = $insert;
                        break;
                    case 'prependTo':
                    case 'prepend':
                        $toNode->insertBefore(
                            $insert,
                            $firstChild
                        );
                        break;
                    case 'insertBefore':
                    case 'before':
                        if (!$toNode->parentNode)
                            throw new \Exception("No parentNode, can't do {$type}()");
                        else
                            $toNode->parentNode->insertBefore(
                                $insert,
                                $toNode
                            );
                        break;
                    case 'insertAfter':
                    case 'after':
                        if (!$toNode->parentNode)
                            throw new \Exception("No parentNode, can't do {$type}()");
                        else
                            $toNode->parentNode->insertBefore(
                                $insert,
                                $nextSibling
                            );
                        break;
                }
            }
        }

        return $this;
    }

    /**
     * Explode class string
     *
     * @var array
     */
    static private $explodeCache = array();

    /**
     * Enter description here...
     *
     * In the future, when PHP will support XLS 2.0, then we would do that this way:
     * contains(tokenize(@class, '\s'), "something")
     * @param string $classes
     * @param string $class
     * @return boolean
     * @access private
     */
    static function _matchClasses($classes, $class)
    {
        if ($classes == '')
            return "0";

        if (!isset(self::$explodeCache[$class . $classes])) {

            if (!isset(self::$explodeCache[$class]))
                self::$explodeCache[$class] = (strpos($class, " ") === false) ? [substr($class, 1)] : explode('.', substr($class, 1));

            if (!isset(self::$explodeCache[$classes]))
                self::$explodeCache[$classes] = (strpos($classes, " ") === false) ? [$classes] : explode(' ', $classes);

            $a = !isset(self::$explodeCache[$class][1]);
            $b = !isset(self::$explodeCache[$classes][1]);

            if ($a && $b) {
                $ret = self::$explodeCache[$class][0] == self::$explodeCache[$classes][0] ? 0 : 1;
                self::$explodeCache[$class . $classes] = $ret;
            } else if ($a && !$b) {
                $tmp = array_flip(self::$explodeCache[$classes]);
                $ret = isset($tmp[self::$explodeCache[$class][0]]) ? 0 : 1;
                self::$explodeCache[$class . $classes] = $ret;
            } else if ($a) {
                $ret = (strpos(" " . $classes . " ", " " . self::$explodeCache[$class][0] . " ") === false) ? 1 : 0;
                self::$explodeCache[$class . $classes] = $ret;
            } else
                self::$explodeCache[$class . $classes] = count(array_diff(
                    self::$explodeCache[$class],
                    self::$explodeCache[$classes]
                ));
        }
        return (!self::$explodeCache[$class . $classes]) ? "1" : "0";
    }

    /**
     * Given a QueryObject that represents a set of DOM elements, the `children()`
     * method allows us to search through the children of these elements in the DOM
     * tree and construct a new jQuery object from the matching elements.
     * The `children()` method differs from  `find()` in that `children()`
     * only travels a single level down the DOM tree while `find()` can traverse
     * down multiple levels to select descendant elements (grandchildren, etc.) as well.
     * Note also that like most jQuery methods, `children()` does not return text nodes;
     * to get all children including text and comment nodes, use `contents()`.
     *
     * The `children()` method optionally accepts a selector expression of the same
     * type that we can pass to the $() function. If the selector is supplied,
     * the elements will be filtered by testing whether they match it.
     *
     * @param null $selector
     * @return QueryObject
     */
    public function children($selector = null)
    {
        $stack  = [];
        foreach ($this as $node) {
            foreach ($node->childNodes as $newNode) {
                if ($newNode->nodeType != 1)
                    continue;
                if ($selector && !$this->is($selector, $newNode))
                    continue;
                $stack[] = $newNode;
            }
        }
        return $this->createSub($stack);
    }


    /**
     * Array Access Helper
     *
     * @param mixed $offset
     * @return bool|int
     */
    public function offsetExists($offset)
    {
        return is_int($offset) ? (isset($this->nodes[$offset]) ? 1 : 0) : $this->find($offset)->size() > 0;
    }


    /**
     * Array Access Helper
     *
     * @param mixed $offset
     * @return self|QueryObject
     */
    public function offsetGet($offset)
    {
        return is_int($offset) ? ($this->createSub([$this->nodes[$offset]])) : $this->find($offset);
    }

    /**
     * Array Access Helper
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
//		$this->find($offset)->replaceWith($value);
        throw new Exception("Todo");
        //return is_int($offset) ? (isset($this->createSub($this->nodes[$offset])) ? $new : null) : $this->find($offset);
        //$this->find($offset)->html($value);
    }

    /**
     * Array Access Helper
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        // empty
        throw new Exception("Can't do unset, use array interface only for calling queries and replacing HTML.");
    }

    /**
     * Todo Description
     *
     * @param \DOMXPath $domxpath
     * @param string    $prefix
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function discoverNamespace(\DOMXPath $domxpath, $prefix)
    {
        if (isset($this->namespaces[$prefix])) {
            return $this->namespaces[$prefix];
        }

        // ask for one namespace, otherwise we'd get a collection with an item for each node
        $namespaces = $domxpath->query(sprintf('(//namespace::*[name()="%s"])[last()]', $this->defaultNamespacePrefix === $prefix ? '' : $prefix));

        if ($node = $namespaces->item(0)) {
            return $node->nodeValue;
        }
    }

    /**
     * return TagName
     *
     * @return string
     */
    function getTagName(){
        $size = $this->count();
        if($size > 1 || !$size)
            throw new Exception("Todo");
        return $this->nodes[0]->tagName;
    }

    /**
     * TODO description
     * @param string $prefix
     * @param string $namespace
     */
    public function registerNamespace($prefix, $namespace)
    {
        $this->namespaces[$prefix] = $namespace;
    }

    /**
     * DebugInfo QueryObject
     *
     * @return array
     */
    public function __debugInfo()
    {
        return [
            'documentID' => $this->getDocumentID(),
            'namespaces' => $this->namespaces,
            'charset' => $this->charset,
            'nodes' => $this->nodes,
            'isHtml' => $this->isHtml
        ];
    }
}
