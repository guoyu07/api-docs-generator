<?php

namespace Bolt\Api;

use Michelf\MarkdownExtra;
use Sami\Reflection\ClassReflection;
use Sami\Reflection\MethodReflection;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{
    protected $markdown;

    public function getFilters()
    {
        return [
            new TwigFilter(
                'desc',
                [$this, 'parseDesc'],
                ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']]
            ),
        ];
    }

    public function parseDesc(Environment $twig, array $context, $desc, ClassReflection $class)
    {
        if (!$desc) {
            return $desc;
        }

        if (null === $this->markdown) {
            $this->markdown = new MarkdownExtra();
        }

        /** @var \Sami\Renderer\TwigExtension $ext */
        $ext = $twig->getExtension(\Sami\Renderer\TwigExtension::class);

        $resolveSee = function ($match) use ($ext, $context, $class) {
            $ref = $match[1];
            $title = $match[2] ?? null;

            $resolved = $this->resolveReference($ref, $class);
            if ($resolved instanceof ClassReflection) {
                $path = $ext->pathForClass($context, $resolved);

                if (!$title) {
                    $title = $resolved->getNamespace() === $class->getNamespace()
                        ? $resolved->getShortName()
                        : $resolved
                    ;
                }
            } elseif ($resolved instanceof MethodReflection) {
                $path = $ext->pathForMethod($context, $resolved);

                if (!$title) {
                    if ($resolved->getClass()->getName() === $class->getName()) {
                        // method
                        $title = $resolved->getName();
                    } elseif ($resolved->getClass()->getNamespace() === $class->getNamespace()) {
                        // Bar::method
                        $title = $resolved->getClass()->getShortName() . '::' . $resolved->getName();
                    } else {
                        // \Foo\Bar::method
                        $title = $resolved;
                    }
                }
            } elseif (is_string($resolved)) {
                $path = $resolved;
                $title = $title ?: $ref;
            } else {
                // strip out inline tag and just display title, if given, or reference
                return $title ?: $ref;
            }

            return sprintf('<a href="%s">%s</a>', $path, $title);
        };

        $desc = preg_replace_callback('/{@see ([^ }]+) ?([^}]*)}/', $resolveSee, $desc);
        $desc = preg_replace_callback('/@see ([^ ]+)/', $resolveSee, $desc);

        $html = $this->markdown->transform($desc);

        $html = preg_replace('#<pre><code>#', '<pre><code class="language-php">', $html);

        return preg_replace(['#^<p>\s*#s', '#\s*</p>\s*$#s'], '', $html);
    }

    /**
     * @param string          $ref
     * @param ClassReflection $class
     *
     * @return ClassReflection|MethodReflection|string|null
     */
    private function resolveReference($ref, ClassReflection $class)
    {
        if (strpos($ref, 'http:') === 0 || strpos($ref, 'https:') === 0) {
            return $ref;
        }

        if ($pos = strpos($ref, '::') > 0) {
            list($cls, $method) = explode('::', $ref) + [null, null];

            $cls = $this->resolveClass($cls, $class);
            if (!$cls) {
                return null;
            }

            return $cls->getMethod($method) ?: null;
        }

        $function = $this->resolveFunction($ref, $class);
        if ($function) {
            return $function;
        }

        return $this->resolveClass($ref, $class);
    }

    /**
     * @param string          $name
     * @param ClassReflection $class
     *
     * @return ClassReflection|null
     */
    private function resolveClass($name, ClassReflection $class)
    {
        // fqns
        if ($name[0] === '\\') {
            $pos = strrpos($name, '\\');
            $ns = substr($name, 1, $pos - 1);
            $cls = substr($name, $pos + 1);
            $allClasses = $class->getProject()->getNamespaceAllClasses($ns);
            if (isset($allClasses[$ns . '\\' . $cls])) {
                return $allClasses[$ns . '\\' . $cls];
            }
        }

        // imported class
        $aliases = $class->getAliases();
        if (isset($aliases[$name])) {
            $name = $aliases[$name];

            return $class->getProject()->getClass($name);
        }

        // class same ns
        $allClasses = $class->getProject()->getNamespaceAllClasses($class->getNamespace());
        $nsName = $class->getNamespace() . '\\' . $name;
        if (isset($allClasses[$nsName])) {
            return $allClasses[$nsName];
        }

        return null;
    }

    /**
     * @param string          $name
     * @param ClassReflection $class
     *
     * @return MethodReflection|string|null
     */
    private function resolveFunction($name, ClassReflection $class)
    {
        // local method
        if ($class->getMethod($name)) {
            return $class->getMethod($name);
        }

        // parent method
        if ($method = $class->getParentMethod($name)) {
            return $method;
        }

        // imported function

        // function in same ns

        // global function
        if (function_exists($name)) {
            return '//php.net/' . $name;
        }

        return null;
    }
}
