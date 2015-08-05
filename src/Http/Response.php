<?php

namespace Dingo\Api\Http;

use Closure;
use ArrayObject;
use UnexpectedValueException;
use Dingo\Api\Transformer\Binding;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\HttpFoundation\Cookie;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Dingo\Api\Transformer\Factory as TransformerFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

class Response extends IlluminateResponse
{
    /**
     * Transformer binding instance.
     *
     * @var \Dingo\Api\Transformer\Binding
     */
    protected $binding;

    /**
     * Array of registered formatters.
     *
     * @var array
     */
    protected static $formatters = [];

    /**
     * Transformer factory instance.
     *
     * @var \Dingo\Api\Transformer\TransformerFactory
     */
    protected static $transformer;

    /**
     * Array of user-defined morphing callbacks.
     *
     * @var array
     */
    protected static $morphingCallbacks = [];

    /**
     * Array of user-defined morphed callbacks.
     *
     * @var array
     */
    protected static $morphedCallbacks = [];

    /**
     * Create a new response instance.
     *
     * @param mixed                          $content
     * @param int                            $status
     * @param array                          $headers
     * @param \Dingo\Api\Transformer\Binding $binding
     *
     * @return void
     */
    public function __construct($content, $status = 200, $headers = [], Binding $binding = null)
    {
        parent::__construct($content, $status, $headers);

        $this->binding = $binding;
    }

    /**
     * Make an API response from an existing Illuminate response.
     *
     * @param \Illuminate\Http\Response $old
     *
     * @return \Dingo\Api\Http\Response
     */
    public static function makeFromExisting(IlluminateResponse $old)
    {
        $new = static::create($old->getOriginalContent(), $old->getStatusCode());

        $new->headers = $old->headers;

        return $new;
    }

    /**
     * Morph the API response to the appropriate format.
     *
     * @param string $format
     *
     * @return \Dingo\Api\Http\Response
     */
    public function morph($format = 'json')
    {
        $this->content = $this->getOriginalContent();

        $this->fireMorphingCallbacks();

        if (isset(static::$transformer) && static::$transformer->transformableResponse($this->content)) {
            $this->content = static::$transformer->transform($this->content);
        }

        $formatter = static::getFormatter($format);

        $defaultContentType = $this->headers->get('content-type');

        $this->headers->set('content-type', $formatter->getContentType());

        $this->fireMorphedCallbacks();

        if ($this->content instanceof EloquentModel) {
            $this->content = $formatter->formatEloquentModel($this->content);
        } elseif ($this->content instanceof EloquentCollection) {
            $this->content = $formatter->formatEloquentCollection($this->content);
        } elseif (is_array($this->content) || $this->content instanceof ArrayObject || $this->content instanceof Arrayable) {
            $this->content = $formatter->formatArray($this->content);
        } else {
            $this->headers->set('content-type', $defaultContentType);
        }

        return $this;
    }

    /**
     * Fire the morphed callbacks.
     *
     * @return void
     */
    protected function fireMorphedCallbacks()
    {
        $this->fireCallbacks(static::$morphedCallbacks);
    }

    /**
     * Fire the morphing callbacks.
     *
     * @return void
     */
    protected function fireMorphingCallbacks()
    {
        $this->fireCallbacks(static::$morphingCallbacks);
    }

    /**
     * Fire the callbacks with the content and response instance as parameters.
     *
     * @param array $callbacks
     *
     * @return void
     */
    protected function fireCallbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            if ($response = call_user_func($callback, $this->content, $this)) {
                $this->content = $response;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setContent($content)
    {
        // Attempt to set the content string, if we encounter an unexpected value
        // then we most likely have an object that cannot be type cast. In that
        // case we'll simply leave the content as null and set the original
        // content value and continue.
        try {
            return parent::setContent($content);
        } catch (UnexpectedValueException $exception) {
            $this->original = $content;

            return $this;
        }
    }

    /**
     * Add a callback for when the response is about to be morphed.
     *
     * @param \Closure $callback
     *
     * @return void
     */
    public static function morphing(Closure $callback)
    {
        static::$morphingCallbacks[] = $callback;
    }

    /**
     * Add a callback for when the response has been morphed.
     *
     * @param \Closure $callback
     *
     * @return void
     */
    public static function morphed(Closure $callback)
    {
        static::$morphedCallbacks[] = $callback;
    }

    /**
     * Get the formatter based on the requested format type.
     *
     * @param string $format
     *
     * @throws \RuntimeException
     *
     * @return \Dingo\Api\Http\Response\Format\Format
     */
    public static function getFormatter($format)
    {
        if (! static::hasFormatter($format)) {
            throw new NotAcceptableHttpException('Unable to format response according to Accept header.');
        }

        return static::$formatters[$format];
    }

    /**
     * Determine if a response formatter has been registered.
     *
     * @param string $format
     *
     * @return bool
     */
    public static function hasFormatter($format)
    {
        return isset(static::$formatters[$format]);
    }

    /**
     * Set the response formatters.
     *
     * @param array $formatters
     *
     * @return void
     */
    public static function setFormatters(array $formatters)
    {
        static::$formatters = $formatters;
    }

    /**
     * Add a response formatter.
     *
     * @param string                                 $key
     * @param \Dingo\Api\Http\Response\Format\Format $formatter
     *
     * @return void
     */
    public static function addFormatter($key, $formatter)
    {
        static::$formatters[$key] = $formatter;
    }

    /**
     * Set the transformer factory instance.
     *
     * @param \Dingo\Api\Transformer\Factory $transformer
     *
     * @return void
     */
    public static function setTransformer(TransformerFactory $transformer)
    {
        static::$transformer = $transformer;
    }

    /**
     * Get the transformer instance.
     *
     * @return \Dingo\Api\Transformer\Factory
     */
    public static function getTransformer()
    {
        return static::$transformer;
    }

    /**
     * Add a meta key and value pair.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return \Dingo\Api\Http\Response
     */
    public function addMeta($key, $value)
    {
        $this->binding->addMeta($key, $value);

        return $this;
    }

    /**
     * Add a meta key and value pair.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return \Dingo\Api\Http\Response
     */
    public function meta($key, $value)
    {
        return $this->addMeta($key, $value);
    }

    /**
     * Set the meta data for the response.
     *
     * @param array $meta
     *
     * @return \Dingo\Api\Http\Response
     */
    public function setMeta(array $meta)
    {
        $this->binding->setMeta($meta);

        return $this;
    }

    /**
     * Get the meta data for the response.
     *
     * @return array
     */
    public function getMeta()
    {
        return $this->binding->getMeta();
    }

    /**
     * Add a cookie to the response.
     *
     * @param \Symfony\Component\HttpFoundation\Cookie $cookie
     *
     * @return \Dingo\Api\Http\Response
     */
    public function cookie(Cookie $cookie)
    {
        return $this->withCookie($cookie);
    }

    /**
     * Add a header to the response.
     *
     * @param string $key
     * @param string $value
     * @param bool   $replace
     *
     * @return \Dingo\Api\Http\Response
     */
    public function withHeader($key, $value, $replace = true)
    {
        return $this->header($key, $value, $replace);
    }

    /**
     * Set the response status code.
     *
     * @param int $statusCode
     *
     * @return \Dingo\Api\Http\Response
     */
    public function statusCode($statusCode)
    {
        return $this->setStatusCode($statusCode);
    }
}
