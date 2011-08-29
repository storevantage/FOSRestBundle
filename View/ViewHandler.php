<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\View;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Component\HttpFoundation\Response,
    Symfony\Component\HttpFoundation\Request,
    Symfony\Component\DependencyInjection\ContainerAware,
    Symfony\Component\Serializer\SerializerInterface,
    Symfony\Component\Form\FormInterface,
    Symfony\Bundle\FrameworkBundle\Templating\TemplateReference;

use FOS\RestBundle\Response\Codes;

/**
 * View may be used in controllers to build up a response in a format agnostic way
 * The View class takes care of encoding your data in json, xml, or renders a
 * template for html via the Serializer component.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Lukas K. Smith <smith@pooteeweet.org>
 */
class ViewHandler extends ContainerAware implements ViewHandlerInterface
{
    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var array key format, value a callable that returns a Response instance
     */
    protected $customHandlers = array();

    /**
     * @var array the supported formats as keys and if the given formats uses templating is denoted by a true value
     */
    protected $formats;

    /**
     * @param int HTTP response status code for a failed validation
     */
    protected $failedValidationCode;

    /**
     * @var array if to force a redirect for the given key format, with value being the status code to use
     */
    protected $forceRedirects;

    /**
     * @var string default engine (twig, php ..)
     */
    protected $defaultEngine;

    /**
     * Constructor
     *
     * @param array $formats the supported formats as keys and if the given formats uses templating is denoted by a true value
     * @param int $failedValidationCode The HTTP response status code for a failed validation
     * @param string $defaultFormKey    The default parameter form key
     * @param array $forceRedirects     If to force a redirect for the given key format, with value being the status code to use
     * @param string $defaultEngine default engine (twig, php ..)
     */
    public function __construct(array $formats = null, $failedValidationCode = Codes::HTTP_BAD_REQUEST, array $forceRedirects = null, $defaultEngine = 'twig')
    {
        $this->formats = (array)$formats;
        $this->failedValidationCode = $failedValidationCode;
        $this->forceRedirects = (array)$forceRedirects;
        $this->defaultEngine = $defaultEngine;
    }

    /**
     * Verifies whether the given format is supported by this view
     *
     * @param string $format format name
     *
     * @return Boolean
     */
    public function supports($format)
    {
        return isset($this->customHandlers[$format]) || isset($this->formats[$format]);
    }

    /**
     * Registers a custom handler
     *
     * The handler must have the following signature: handler($viewObject, $request, $response)
     * It can use the public methods of this class to retrieve the needed data and return a
     * Response object ready to be sent.
     *
     * @param string $format the format that is handled
     * @param callable $callable callable that can handle the given format
     */
    public function registerHandler($format, $callable)
    {
        if (!is_callable($callable)) {
            throw new \InvalidArgumentException('Registered view callback must be callable.');
        }

        $this->customHandlers[$format] = $callable;
    }

    /**
     * Gets a response HTTP status code
     *
     * By default it will return 200, however for the first form instance in the top level of the parameters it will
     * - set the status code to the failed_validation configuration is the form instance has errors
     * - set inValidFormKey so that the form instance can be replaced with createView() if the format uses templating
     *
     * @param View $view view instance
     * @return int HTTP status code
     */
    private function getStatusCodeFromView(View $view)
    {
        if (null !== $code = $view->getStatusCode()) {
            return $code;
        }

        $data = $view->getData();
        if (!is_array($data) || empty($data['form']) || !($data['form'] instanceof FormInterface)) {
            return Codes::HTTP_OK;
        }

        return $data['form']->isBound() && !$data['form']->isValid()
            ? $this->failedValidationCode : Codes::HTTP_OK;
    }

    /**
     * If the given format uses the templating system for rendering
     *
     * @param string $format
     * @return bool
     */
    public function isFormatTemplating($format)
    {
        return !empty($this->formats[$format]);
    }

    /**
     * Handles a request with the proper handler
     *
     * Decides on which handler to use based on the request format
     *
     * @param View $view
     * @param Request $request Request object
     *
     * @return Response
     */
    public function handle(View $view, Request $request = null)
    {
        if (null === $request) {
            $request = $this->container->get('request');
        }

        $format = $view->getFormat() ?: $request->getRequestFormat();

        if (isset($this->customHandlers[$format])) {
            return call_user_func($this->customHandlers[$format], $view, $request);
        }

        if ($this->supports($format)) {
            return $this->createResponse($view, $request, $format);
        }

        return new Response("Format '$format' not supported, handler must be implemented", Codes::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    /**
     * Create the Response from the view
     *
     * @param View $view
     * @param string $location
     * @param string $format
     * 
     * @return Response
     */
    protected function createRedirectResponse(View $view, $location, $format)
    {
        $headers['Location'] = $location;

        $code = isset($this->forceRedirects[$format])
            ? $this->forceRedirects[$format] : $this->getStatusCodeFromView($view);

        if ('html' === $format) {
            $response = new RedirectResponse($headers['Location'], $code);
            $response->headers->replace($headers);

            return $response;
        }

        return new Response('', $code, $headers);
    }

    /**
     * Render the view data with the given template
     * 
     * @param View $view
     * @param string $format
     *
     * @return string
     */
    protected function renderTemplate(View $view, $format)
    {
        $data = $view->getData();
        if (null === $data) {
            $data = array();
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'data must be an array if you allow a templating-aware format (%s).',
                $format
            ));
        }

        if (isset($data['form']) && $data['form'] instanceof FormInterface) {
            $data['form'] = $data['form']->createView();
        }

        $template = $view->getTemplate();
        if ($template instanceOf TemplateReference) {
            if (null === $template->get('format')) {
                $template->set('format', $format);
            }

            if (null === $template->get('engine')) {
                $engine = $view->getEngine() ?: $this->defaultEngine;
                $template->set('engine', $engine);
            }
        }

        return $this->container->get('templating')->render($template, $data);
    }
    /**
     * Handles creation of a Response using either redirection or the templating/serializer service
     *
     * @param View $view
     * @param Request $request
     * @param string $format
     *
     * @return Response
     */
    protected function createResponse(View $view, Request $request, $format)
    {
        $headers = $view->getHeaders();
        $headers['Content-Type'] = $request->getMimeType($format);

        $location = $view->getLocation();
        if (!$location && ($route = $view->getRoute())) {
            $location = $this->container->get('router')->generate($route, (array)$view->getData(), true);
        }

        if ($location) {
            return $this->createRedirectResponse($view, $location, $format);
        }

        if ($this->isFormatTemplating($format)) {
            $content = $this->renderTemplate($view, $format);
        } else {
            $content = $this->container->get('serializer')->serialize($view->getData(), $format);
        }

        return new Response($content, $this->getStatusCodeFromView($view), $headers);
    }
}
