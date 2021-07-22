<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Storage\FileStorage;
use Psalm\SymfonyPsalmPlugin\Issue\NamingConventionViolation;
use Psalm\SymfonyPsalmPlugin\Issue\PrivateService;
use Psalm\SymfonyPsalmPlugin\Issue\ServiceNotFound;
use Psalm\SymfonyPsalmPlugin\Symfony\ContainerMeta;
use Psalm\SymfonyPsalmPlugin\Symfony\Service;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class ContainerHandler implements AfterMethodCallAnalysisInterface, AfterClassLikeVisitInterface
{
    private const GET_CLASSLIKES = [
        'Psr\Container\ContainerInterface',
        'Symfony\Component\DependencyInjection\ContainerInterface',
        'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
        'Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait',
    ];

    /**
     * @var ContainerMeta|null
     */
    private static $containerMeta;

    public static function init(ContainerMeta $containerMeta): void
    {
        self::$containerMeta = $containerMeta;
    }

    /**
     * {@inheritdoc}
     */
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaring_method_id = $event->getDeclaringMethodId();
        $statements_source = $event->getStatementsSource();
        $expr = $event->getExpr();
        $codebase = $event->getCodebase();
        $context = $event->getContext();

        if (!self::isContainerMethod($declaring_method_id, 'get')) {
            if (self::isContainerMethod($declaring_method_id, 'getparameter')) {
                $argument = $expr->args[0]->value;
                if ($argument instanceof String_ && !self::followsNamingConvention($argument->value) && false === strpos($argument->value, '\\')) {
                    IssueBuffer::accepts(
                        new NamingConventionViolation(new CodeLocation($statements_source, $argument)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }

            return;
        }

        if (!self::$containerMeta) {
            if ($event->getReturnTypeCandidate() && $expr->args[0]->value instanceof ClassConstFetch) {
                $className = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
                if (!in_array($className, ['self', 'parent', 'static'])) {
                    $event->setReturnTypeCandidate(new Union([new TNamedObject($className)]));
                }
            }

            return;
        }

        if ($expr->args[0]->value instanceof String_) {
            $serviceId = $expr->args[0]->value->value;
        } elseif ($expr->args[0]->value instanceof ClassConstFetch) {
            $serviceId = (string) $expr->args[0]->value->class->getAttribute('resolvedName');
        } else {
            return;
        }

        $service = self::$containerMeta->get($serviceId);
        if ($service) {
            if (!self::followsNamingConvention($serviceId) && false === strpos($serviceId, '\\')) {
                IssueBuffer::accepts(
                    new NamingConventionViolation(new CodeLocation($statements_source, $expr->args[0]->value)),
                    $statements_source->getSuppressedIssues()
                );
            }

            $class = $service->getClassName();
            if ($class) {
                $codebase->classlikes->addFullyQualifiedClassName($class);
                $event->setReturnTypeCandidate(new Union([new TNamedObject($class)]));
            }

            if (!$service->isPublic()) {
                $isTestContainer = $context->parent && ('Symfony\Bundle\FrameworkBundle\Test\KernelTestCase' === $context->parent || is_subclass_of($context->parent, 'Symfony\Bundle\FrameworkBundle\Test\KernelTestCase'));
                if (!$isTestContainer) {
                    IssueBuffer::accepts(
                        new PrivateService($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                        $statements_source->getSuppressedIssues()
                    );
                }
            }
        } else {
            IssueBuffer::accepts(
                new ServiceNotFound($serviceId, new CodeLocation($statements_source, $expr->args[0]->value)),
                $statements_source->getSuppressedIssues()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $codebase = $event->getCodebase();
        $statements_source = $event->getStatementsSource();
        $storage = $event->getStorage();
        $stmt = $event->getStmt();

        $fileStorage = $codebase->file_storage_provider->get($statements_source->getFilePath());

        if (\in_array($storage->name, ContainerHandler::GET_CLASSLIKES)) {
            if (self::$containerMeta) {
                foreach (self::$containerMeta->getClassNames() as $className) {
                    $codebase->queueClassLikeForScanning($className);
                    $fileStorage->referenced_classlikes[strtolower($className)] = $className;
                }
            }
        }

        // see https://symfony.com/doc/current/service_container/service_subscribers_locators.html
        if (self::$containerMeta && $stmt instanceof Class_ && in_array('getsubscribedservices', array_keys($storage->methods))) {
            foreach ($stmt->stmts as $classStmt) {
                if ($classStmt instanceof ClassMethod && 'getSubscribedServices' === $classStmt->name->name && $classStmt->stmts) {
                    foreach ($classStmt->stmts as $methodStmt) {
                        if (!$methodStmt instanceof Return_) {
                            continue;
                        }

                        $return = $methodStmt->expr;
                        if ($return instanceof Expr\Array_) {
                            self::addSubscribedServicesArray($return, $codebase, $fileStorage);
                        } elseif ($return instanceof Expr\FuncCall) {
                            $funcName = $return->name;
                            if ($funcName instanceof Name && in_array('array_merge', $funcName->parts)) {
                                foreach ($return->args as $arg) {
                                    if ($arg->value instanceof Expr\Array_) {
                                        self::addSubscribedServicesArray($arg->value, $codebase, $fileStorage);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private static function addSubscribedServicesArray(Expr\Array_ $array, Codebase $codebase, FileStorage $fileStorage): void
    {
        if (!self::$containerMeta) {
            return;
        }

        foreach ($array->items as $arrayItem) {
            if ($arrayItem instanceof Expr\ArrayItem) {
                $value = $arrayItem->value;
                if (!$value instanceof Expr\ClassConstFetch) {
                    continue;
                }

                /** @var string $className */
                $className = $value->class->getAttribute('resolvedName');

                $key = $arrayItem->key;
                $serviceId = $key instanceof String_ ? $key->value : $className;

                if (null === self::$containerMeta->get($className)) {
                    $service = new Service($serviceId, $className);
                    $service->setIsPublic(true);
                    self::$containerMeta->add($service);
                }

                $codebase->queueClassLikeForScanning($className);
                $fileStorage->referenced_classlikes[strtolower($className)] = $className;
            }
        }
    }

    private static function isContainerMethod(string $declaringMethodId, string $methodName): bool
    {
        return in_array(
            $declaringMethodId,
            array_map(
                function ($c) use ($methodName) {
                    return $c.'::'.$methodName;
                },
                self::GET_CLASSLIKES
            ),
            true
        );
    }

    /**
     * @see https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions
     */
    private static function followsNamingConvention(string $name): bool
    {
        return !preg_match('/[A-Z]/', $name);
    }
}
