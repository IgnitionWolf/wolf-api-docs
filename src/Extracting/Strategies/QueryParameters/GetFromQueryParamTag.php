<?php

namespace Mpociot\ApiDoc\Extracting\Strategies\QueryParameters;

use Dingo\Api\Http\FormRequest as DingoFormRequest;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Mpociot\ApiDoc\Extracting\ParamHelpers;
use Mpociot\ApiDoc\Extracting\RouteDocBlocker;
use Mpociot\ApiDoc\Extracting\Strategies\Strategy;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use ReflectionClass;
use ReflectionMethod;

use IgnitionWolf\API\Services\RequestValidator;

class GetFromQueryParamTag extends Strategy
{
    use ParamHelpers;

    public function __invoke(Route $route, ReflectionClass $controller, ReflectionMethod $method, array $routeRules, array $context = [])
    {
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType === null) {
                continue;
            }

            $parameterClassName = $paramType->getName();

            try {
                $parameterClass = new ReflectionClass($parameterClassName);
            } catch (\ReflectionException $e) {
                continue;
            }

            /**
             * @var null|FormRequest
             */
            $formRequest = null;

            if ($controller->isSubclassOf('IgnitionWolf\API\Controllers\EntityController')) {
                $replace = ['store' => 'create', 'destroy' => 'delete', 'index' => 'list', 'show' => 'read'];
                $methodName = $method->name;
                $entity = $controller->getStaticProperties()['entity'];
                $namespace = RequestValidator::getNamespace($entity);

                if (isset($replace[$methodName])) {
                    $methodName = $replace[$methodName];
                }

                $entity = explode('\\', $entity);
                $options = RequestValidator::getPossibleRequests($namespace, end($entity), ucfirst($methodName));

                foreach ($options as $option) {
                    if (class_exists($option)) {
                        $formRequest = new ReflectionClass($option);
                        break;
                    }
                }
            }
            
            if (!$formRequest) {
                // If there's a FormRequest, we check there for @urlParam tags.
                if (class_exists(LaravelFormRequest::class) && $parameterClass->isSubclassOf(LaravelFormRequest::class)
                    || class_exists(DingoFormRequest::class) && $parameterClass->isSubclassOf(DingoFormRequest::class)) {
                    
                    $formRequest = $parameterClass;
                }
            }

            if (!$formRequest) {
                throw new \Exception('No form request found.');
            }

            $formRequestDocBlock = new DocBlock($formRequest->getDocComment());
            $queryParametersFromDocBlock = $this->getQueryParametersFromDocBlock($formRequestDocBlock->getTags());

            if (count($queryParametersFromDocBlock)) {
                return $queryParametersFromDocBlock;
            }
        }

        /** @var DocBlock $methodDocBlock */
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($route)['method'];

        return $this->getQueryParametersFromDocBlock($methodDocBlock->getTags());
    }

    private function getQueryParametersFromDocBlock($tags)
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'queryParam';
            })
            ->mapWithKeys(function (Tag $tag) {
                // Format:
                // @queryParam <name> <"required" (optional)> <description>
                // Examples:
                // @queryParam text string required The text.
                // @queryParam user_id The ID of the user.
                preg_match('/(.+?)\s+(required\s+)?(.*)/', $tag->getContent(), $content);
                $content = preg_replace('/\s?No-example.?/', '', $content);
                if (empty($content)) {
                    // this means only name was supplied
                    list($name) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $required, $description) = $content;
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                list($description, $value) = $this->parseParamDescription($description, 'string');
                if (is_null($value) && ! $this->shouldExcludeExample($tag->getContent())) {
                    $value = Str::contains($description, ['number', 'count', 'page'])
                        ? $this->generateDummyValue('integer')
                        : $this->generateDummyValue('string');
                }

                return [$name => compact('description', 'required', 'value')];
            })->toArray();

        return $parameters;
    }
}
