<?php

namespace Mpociot\ApiDoc\Extracting\Strategies\BodyParameters;

use Dingo\Api\Http\FormRequest as DingoFormRequest;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Illuminate\Routing\Route;
use Mpociot\ApiDoc\Extracting\ParamHelpers;
use Mpociot\ApiDoc\Extracting\RouteDocBlocker;
use Mpociot\ApiDoc\Extracting\Strategies\Strategy;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use ReflectionClass;
use ReflectionMethod;

use IgnitionWolf\API\Services\RequestValidator;

class GetFromBodyParamTag extends Strategy
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
                throw new \Exception('No form request found.    ');
            }

            $formRequestDocBlock = new DocBlock($formRequest->getDocComment());
            $bodyParametersFromDocBlock = $this->getBodyParametersFromDocBlock($formRequestDocBlock->getTags());

            if (count($bodyParametersFromDocBlock)) {
                return $bodyParametersFromDocBlock;
            }
        }

        /** @var DocBlock $methodDocBlock */
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($route)['method'];

        return $this->getBodyParametersFromDocBlock($methodDocBlock->getTags());
    }

    private function getBodyParametersFromDocBlock($tags)
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'bodyParam';
            })
            ->mapWithKeys(function (Tag $tag) {
                // Format:
                // @bodyParam <name> <type> <"required" (optional)> <description>
                // Examples:
                // @bodyParam text string required The text.
                // @bodyParam user_id integer The ID of the user.
                preg_match('/(.+?)\s+(.+?)\s+(required\s+)?(.*)/', $tag->getContent(), $content);
                $content = preg_replace('/\s?No-example.?/', '', $content);
                if (empty($content)) {
                    // this means only name and type were supplied
                    list($name, $type) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $type, $required, $description) = $content;
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                $type = $this->normalizeParameterType($type);
                list($description, $example) = $this->parseParamDescription($description, $type);
                $value = is_null($example) && ! $this->shouldExcludeExample($tag->getContent())
                    ? $this->generateDummyValue($type)
                    : $example;

                return [$name => compact('type', 'description', 'required', 'value')];
            })->toArray();

        return $parameters;
    }
}
