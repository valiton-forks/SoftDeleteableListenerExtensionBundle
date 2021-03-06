<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Annotation;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDeleteSuccessor;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    use ContainerAwareTrait;

    /**
     * @param LifecycleEventArgs $args
     *
     * @throws OnSoftDeleteUnknownTypeException
     * @throws \Exception
     */
    public function preSoftDelete(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $entity = $args->getEntity();

        $entityReflection = new \ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        $reader = new AnnotationReader();
        foreach ($namespaces as $namespace) {
            $reflectionClass = new \ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                /** @var onSoftDelete $onDelete */
                if ($onDelete = $reader->getPropertyAnnotation($property, onSoftDelete::class)) {
                    $objects = null;
                    $manyToMany = null;
                    $manyToOne = null;
                    $oneToOne = null;
                    if (
                        ($manyToOne = $reader->getPropertyAnnotation($property, ManyToOne::class)) ||
                        ($manyToMany = $reader->getPropertyAnnotation($property, ManyToMany::class)) ||
                        ($oneToOne = $reader->getPropertyAnnotation($property, OneToOne::class))
                    ) {
                        /** @var OneToOne|OneToMany|ManyToMany $relationship */
                        $relationship = $manyToOne ?: $manyToMany ?: $oneToOne;

                        $ns = null;
                        $nsOriginal = $relationship->targetEntity;
                        $nsFromRelativeToAbsolute = $entityReflection->getNamespaceName().'\\'.$relationship->targetEntity;
                        $nsFromRoot = '\\'.$relationship->targetEntity;
                        if(class_exists($nsOriginal)){
                            $ns = $nsOriginal;
                        }
                        elseif(class_exists($nsFromRoot)){
                            $ns = $nsFromRoot;
                        }
                        elseif(class_exists($nsFromRelativeToAbsolute)){
                            $ns = $nsFromRelativeToAbsolute;
                        }

                        if (!$this->isOnDeleteTypeSupported($onDelete, $relationship)) {
                            throw new \Exception(sprintf('%s is not supported for %s relationships', $onDelete->type, get_class($relationship)));
                        }

                        if (($manyToOne || $oneToOne) && $ns && $entity instanceof $ns) {
                            $objects = $em->getRepository($namespace)->findBy(array(
                                $property->name => $entity,
                            ));
                        }
                        elseif($manyToMany) {

                            // For ManyToMany relations, we only delete the relationship between
                            // two entities. This can be done on both side of the relation.
                            $allowMappedSide = get_class($entity) === $namespace;
                            $allowInversedSide = ($ns && $entity instanceof $ns);
                            if ($allowMappedSide || $allowInversedSide) {
                                try {
                                    $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                    $collection = $propertyAccessor->getValue($entity, $property->name);
                                    $collection->clear();
                                    continue;
                                } catch (\Exception $e) {
                                    throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($entity)));
                                }
                            }
                        }
                    }

                    if ($objects) {
                        $factory = $em->getMetadataFactory();
                        $cacheDriver = $factory->getCacheDriver();
                        $cacheId = ExtensionMetadataFactory::getCacheId($namespace, 'Gedmo\SoftDeleteable');
                        $softDelete = false;
                        if (($config = $cacheDriver->fetch($cacheId)) !== false) {
                            $softDelete = isset($config['softDeleteable']) && $config['softDeleteable'];
                        }
                        foreach ($objects as $object) {
                            $this->processOnDeleteOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param LifecycleEventArgs $args
     * @param $config
     * @throws OnSoftDeleteUnknownTypeException
     */
    protected function processOnDeleteOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        LifecycleEventArgs $args,
        $config
    ) {
        if (strtoupper($onDelete->type) === 'SET NULL') {
            $this->processOnDeleteSetNullOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } elseif (strtoupper($onDelete->type) === 'CASCADE') {
            $this->processOnDeleteCascadeOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } elseif (strtoupper($onDelete->type) === 'SUCCESSOR') {
            $this->processOnDeleteSuccessorOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } else {
            throw new OnSoftDeleteUnknownTypeException($onDelete->type);
        }
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param LifecycleEventArgs $args
     * @param $config
     */
    protected function processOnDeleteSetNullOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        LifecycleEventArgs $args,
        $config
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reflProp->setValue($object, null);
        $args->getEntityManager()->persist($object);

        $args->getEntityManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, null);
        $args->getEntityManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, null),
        ));
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param LifecycleEventArgs $args
     * @param $config
     * @throws \Exception
     */
    protected function processOnDeleteSuccessorOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        LifecycleEventArgs $args,
        $config
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reader = new AnnotationReader();
        $reflectionClass = new \ReflectionClass(ClassUtils::getClass($oldValue));
        $successors = [];
        foreach ($reflectionClass->getProperties() as $propertyOfOldValueObject) {
            if ($reader->getPropertyAnnotation($propertyOfOldValueObject, onSoftDeleteSuccessor::class)) {
                $successors[] = $propertyOfOldValueObject;
            }
        }

        if (count($successors) > 1) {
            throw new \Exception('Only one property of deleted entity can be marked as successor.');
        } elseif (empty($successors)) {
            throw new \Exception('One property of deleted entity must be marked as successor.');
        }

        $successors[0]->setAccessible(true);

        if ($oldValue instanceof Proxy) {
            $oldValue->__load();
        }

        $newValue = $successors[0]->getValue($oldValue);
        $reflProp->setValue($object, $newValue);
        $args->getEntityManager()->persist($object);

        $args->getEntityManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, $newValue);
        $args->getEntityManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, $newValue),
        ));
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param LifecycleEventArgs $args
     * @param $config
     */
    protected function processOnDeleteCascadeOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        LifecycleEventArgs $args,
        $config
    ) {
        if ($softDelete) {
            $this->softDeleteCascade($args->getEntityManager(), $config, $object);
        } else {
            $args->getEntityManager()->remove($object);
        }
    }

    /**
     * @param EntityManager $em
     * @param $config
     * @param $object
     */
    protected function softDeleteCascade($em, $config, $object)
    {
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof \Datetime) {
            return;
        }

        //check next level
        $args = new LifecycleEventArgs($object, $em);
        $this->preSoftDelete($args);

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $uow = $em->getUnitOfWork();
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
        $uow->scheduleExtraUpdate($object, array(
            $config['fieldName'] => array($oldValue, $date),
        ));
    }

    /**
     * @param onSoftDelete $onDelete
     * @param Annotation $relationship
     * @return bool
     */
    protected function isOnDeleteTypeSupported(onSoftDelete $onDelete, Annotation $relationship)
    {
        if (strtoupper($onDelete->type) === 'SET NULL' && $relationship instanceof ManyToMany) {
            return false;
        }

        return true;
    }
}