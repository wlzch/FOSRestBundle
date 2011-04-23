<?php

namespace FOS\RestBundle\Request;

use Symfony\Component\HttpFoundation\ParameterBag,
    Symfony\Component\HttpKernel\Event\GetResponseEvent,
    Symfony\Component\Serializer\SerializerInterface,
    Symfony\Component\Serializer\Encoder\DecoderInterface,
    Symfony\Component\DependencyInjection\ContainerAwareInterface,
    Symfony\Component\DependencyInjection\ContainerInterface;

/*
 * This file is part of the FOS/RestBundle
 *
 * (c) Lukas Kahwe Smith <smith@pooteeweet.org>
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 * (c) Bulat Shakirzyanov <avalanche123>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * RequestListener object.
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class RequestListener implements ContainerAwareInterface
{
    protected $formats;
    protected $detectFormat;
    protected $defaultFormat;
    protected $decodeBody;
    protected $container;

    /**
     * Initialize RequestListener.
     *
     * @param   Boolean    $detectFormat        If to try and detect the format
     * @param   string     $defaultFormat       Default fallback format
     * @param   Boolean    $decodeBody          If to decode the body for parameters
     * @param   array      $formats             The supported formats as keys, encoder service id's as values
     */
    public function __construct($detectFormat, $defaultFormat, $decodeBody, array $formats = null)
    {
        $this->detectFormat = $detectFormat;
        $this->defaultFormat = $defaultFormat;
        $this->decodeBody = $decodeBody;
        $this->formats = (array)$formats;
    }

    /**
     * Sets the Container associated with this Controller.
     *
     * @param ContainerInterface $container A ContainerInterface instance
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Core request handler
     *
     * @param   GetResponseEvent   $event    The event
     */
    public function onCoreRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if ($this->detectFormat) {
            $this->detectFormat($request);
        } elseif (null !== $this->defaultFormat && null === $request->getRequestFormat(null)) {
            $request->setRequestFormat($this->defaultFormat);
        }

        if ($this->decodeBody) {
            $this->decodeBody($request);
        }
    }

    /**
     * Detect the request format in the following order:
     * 
     * - Request
     * - Accept Header
     * - Default
     *
     * @param   Request   $request    The request
     */
    protected function detectFormat($request)
    {
        $format = $request->getRequestFormat(null);
        if (null === $format) {
            $format = $this->getFormatFromAcceptHeader($request);
            if (null === $format) {
                $format = $this->defaultFormat;
            }

            $request->setRequestFormat($format);
        }
    }

    /**
     * Get the format from the Accept header
     *
     * Override this method to implement more complex Accept header negotiations
     *
     * @param   Request     $request    The request
     * @return  void|string             The format string
     */
    protected function getFormatFromAcceptHeader($request)
    {
        $formats = $request->splitHttpAcceptHeader($request->headers->get('Accept'));
        if (empty($formats)) {
            return null;
        }

        $format = key($formats);
        return $request->getFormat($format);
    }

    /**
     * Get an encoder instance for the given format
     *
     * @param    string     $format     The format string
     * @return   void|EncoderInterface  The encoder if one can be determined
     */
    protected function getEncoder($format)
    {
        if (null === $format || empty($this->formats[$format])) {
            return null;
        }

        $serializer = $this->container->get('fos_rest.serializer');
        if (!$serializer->hasEncoder($format)) {
            // TODO this kind of lazy loading of encoders should be provided by the Serializer component
            $encoder = $this->container->get($this->formats[$format]);
            // Technically not needed, but this way we have the instance for encoding later on
            $serializer->setEncoder($format, $encoder);
        } else {
            $encoder = $serializer->getEncoder($format);
        }

        return $encoder;
    }

    /**
     * Decode the request body depending on the request content type
     *
     * @param   Request   $request    The request
     */
    protected function decodeBody($request)
    {
        if (0 == count($request->request->all())
            && in_array($request->getMethod(), array('POST', 'PUT', 'DELETE'))
        ) {
            $format = $request->getFormat($request->headers->get('Content-Type'));
            $encoder = $this->getEncoder($format);

            if ($encoder && $encoder instanceof DecoderInterface) {
                $post = $encoder->decode($request->getContent(), $format);

                $request->request = new ParameterBag((array)$post);
            }
        }
    }
}