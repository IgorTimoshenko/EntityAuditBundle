<?php
/*
 * (c) 2011 SimpleThings GmbH
 *
 * @package SimpleThings\EntityAudit
 * @author Benjamin Eberlei <eberlei@simplethings.de>
 * @link http://www.simplethings.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

namespace SimpleThings\EntityAudit;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use SimpleThings\EntityAudit\Collection\AuditedCollection;
use SimpleThings\EntityAudit\Exception\DeletedException;
use SimpleThings\EntityAudit\Exception\InvalidRevisionException;
use SimpleThings\EntityAudit\Exception\NoRevisionFoundException;
use SimpleThings\EntityAudit\Exception\NotAuditedException;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;

class AuditReader
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var AuditConfiguration
     */
    private $config;

    /**
     * @var MetadataFactory
     */
    private $metadataFactory;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var QuoteStrategy
     */
    private $quoteStrategy;

    /**
     * Entity cache to prevent circular references
     * @var array
     */
    private $entityCache;

    /**
     * Decides if audited ToMany collections are loaded
     * @var bool
     */
    private $loadAuditedCollections = true;

    /**
     * Decides if audited ToOne collections are loaded
     * @var bool
     */
    private $loadAuditedEntities = true;

    /**
     * Decides if native (not audited) ToMany collections are loaded
     * @var bool
     */
    private $loadNativeCollections = true;

    /**
     * Decides if native (not audited) ToOne collections are loaded
     * @var bool
     */
    private $loadNativeEntities = true;

    /**
     * @return boolean
     */
    public function isLoadAuditedCollections()
    {
        return $this->loadAuditedCollections;
    }

    /**
     * @param boolean $loadAuditedCollections
     */
    public function setLoadAuditedCollections($loadAuditedCollections)
    {
        $this->loadAuditedCollections = $loadAuditedCollections;
    }

    /**
     * @return boolean
     */
    public function isLoadAuditedEntities()
    {
        return $this->loadAuditedEntities;
    }

    /**
     * @param boolean $loadAuditedEntities
     */
    public function setLoadAuditedEntities($loadAuditedEntities)
    {
        $this->loadAuditedEntities = $loadAuditedEntities;
    }

    /**
     * @return boolean
     */
    public function isLoadNativeCollections()
    {
        return $this->loadNativeCollections;
    }

    /**
     * @param boolean $loadNativeCollections
     */
    public function setLoadNativeCollections($loadNativeCollections)
    {
        $this->loadNativeCollections = $loadNativeCollections;
    }

    /**
     * @return boolean
     */
    public function isLoadNativeEntities()
    {
        return $this->loadNativeEntities;
    }

    /**
     * @param boolean $loadNativeEntities
     */
    public function setLoadNativeEntities($loadNativeEntities)
    {
        $this->loadNativeEntities = $loadNativeEntities;
    }

    /**
     * @param EntityManagerInterface $em
     * @param AuditConfiguration $config
     * @param MetadataFactory $factory
     */
    public function __construct(EntityManagerInterface $em, AuditConfiguration $config, MetadataFactory $factory)
    {
        $this->em = $em;
        $this->config = $config;
        $this->metadataFactory = $factory;
        $this->platform = $this->em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->em->getConnection();
    }

    /**
     * @return AuditConfiguration
     */
    public function getConfiguration()
    {
        return $this->config;
    }

    /**
     * Clears entity cache. Call this if you are fetching subsequent revisions using same AuditManager.
     */
    public function clearEntityCache()
    {
        $this->entityCache = array();
    }

    /**
     * Find a class at the specific revision.
     *
     * This method does not require the revision to be exact but it also searches for an earlier revision
     * of this entity and always returns the latest revision below or equal the given revision. Commonly, it
     * returns last revision INCLUDING "DEL" revision. If you want to throw exception instead, set
     * $threatDeletionAsException to true.
     *
     * @param string $className
     * @param mixed  $id
     * @param int    $revision
     * @param array  $options
     *
     * @return object
     *
     * @throws DeletedException
     * @throws NoRevisionFoundException
     * @throws NotAuditedException
     * @throws \Doctrine\DBAL\DBALException
     */
    public function find($className, $id, $revision, array $options = array())
    {
        $options = array_merge(array('threatDeletionsAsExceptions' => false), $options);

        if (! $this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        /** @var ClassMetadataInfo|ClassMetadata $class */
        $class = $this->em->getClassMetadata($className);
        $connection = $this->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->from($tableName = $this->config->getTableName($class), 'e');
        $queryBuilder->where(sprintf('e.%s <= ?', $this->config->getRevisionFieldName()));

        foreach ($class->identifier AS $idField) {
            if (is_array($id) && count($id) > 0) {
                $idKeys = array_keys($id);
                $columnName = $idKeys[0];
            } else if (isset($class->fieldMappings[$idField])) {
                $columnName = $class->fieldMappings[$idField]['columnName'];
            } elseif (isset($class->associationMappings[$idField])) {
                $columnName = $class->associationMappings[$idField]['joinColumns'][0]['name'];
            } else {
                throw new \RuntimeException('column name not found  for' . $idField);
            }

            $queryBuilder->andWhere(sprintf('e.%s = ?', $columnName));
        }

        if (!is_array($id)) {
            $id = array($class->identifier[0] => $id);
        }

        $queryBuilder->addSelect('e.' . $this->config->getRevisionTypeFieldName());
        $columnMap = array();

        foreach ($class->fieldNames as $columnName => $field) {
            $tableAlias = $class->isInheritanceTypeJoined() && $class->isInheritedField($field) && !$class->isIdentifier($field)
                ? 're' // root entity
                : 'e';

            $queryBuilder->addSelect(sprintf(
                '%s.%s AS %s',
                $tableAlias,
                $this->quoteStrategy->getColumnName($field, $class, $this->platform),
                $this->platform->quoteSingleIdentifier($field)
            ));
            $columnMap[$field] = $this->platform->getSQLResultCasing($columnName);
        }

        foreach ($class->associationMappings AS $assoc) {
            if (($assoc['type'] & ClassMetadata::TO_ONE) == 0 || ! $assoc['isOwningSide']) {
                continue;
            }

            foreach ($assoc['joinColumnFieldNames'] as $sourceCol) {
                $tableAlias = $class->isInheritanceTypeJoined() &&
                    $class->isInheritedAssociation($assoc['fieldName']) &&
                    !$class->isIdentifier($assoc['fieldName'])
                    ? 're' // root entity
                    : 'e';
                $queryBuilder->addSelect($tableAlias . '.' . $sourceCol);
                $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
            }
        }

        if ($class->isInheritanceTypeJoined() && $class->name != $class->rootEntityName) {
            /** @var ClassMetadataInfo|ClassMetadata $rootClass */
            $rootClass = $this->em->getClassMetadata($class->rootEntityName);
            $rootTableName = $this->config->getTableName($rootClass);

            $condition = ['re.rev = e.rev'];
            foreach ($class->getIdentifierColumnNames() as $name) {
                $condition[] = "re.$name = e.$name";
            }

            $queryBuilder->innerJoin('e', $rootTableName, 're', implode(' AND ', $condition));
        }

        if (! $class->isInheritanceTypeNone()) {
            $queryBuilder->addSelect($class->discriminatorColumn['name']);

            if ($class->isInheritanceTypeSingleTable() && $class->discriminatorValue !== null) {
                // Support for single table inheritance sub-classes
                $allDiscrValues = array_flip($class->discriminatorMap);

                $queriedDiscrValues = array($connection->quote($class->discriminatorValue));
                foreach ($class->subClasses as $subclassName) {
                    $queriedDiscrValues[] = $connection->quote($allDiscrValues[$subclassName]);
                }

                $queryBuilder->andWhere(sprintf(
                    '%s IN (%s)',
                    $class->discriminatorColumn['name'],
                    implode(', ', $queriedDiscrValues)
                ));
            }
        }

        $queryBuilder->setParameters(array_merge(array($revision), array_values($id)));
        $queryBuilder->orderBy('e.rev', 'DESC');

        $row = $queryBuilder->execute()->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new NoRevisionFoundException($class->name, $id, $revision);
        }

        if ($options['threatDeletionsAsExceptions'] && $row[$this->config->getRevisionTypeFieldName()] == 'DEL') {
            throw new DeletedException($class->name, $id, $revision);
        }

        unset($row[$this->config->getRevisionTypeFieldName()]);

        return $this->createEntity($class->name, $columnMap, $row, $revision);
    }

    /**
     * Simplified and stolen code from UnitOfWork::createEntity.
     *
     * @param string $className
     * @param array $columnMap
     * @param array $data
     * @param $revision
     * @throws DeletedException
     * @throws NoRevisionFoundException
     * @throws NotAuditedException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Exception
     * @return object
     */
    private function createEntity($className, array $columnMap, array $data, $revision)
    {
        /** @var ClassMetadataInfo|ClassMetadata $class */
        $class = $this->em->getClassMetadata($className);

        //lookup revisioned entity cache
        $keyParts = array();

        foreach($class->getIdentifierFieldNames() as $name) {
            if ($class->hasAssociation($name)) {
                if ($class->isSingleValuedAssociation($name)) {
                    $name = $class->getSingleAssociationJoinColumnName($name);
                } else {
                    // Doctrine should throw a mapping exception if an identifier
                    // that is an association is not single valued, but just in case.
                    throw new \RuntimeException('Multiple valued association identifiers not supported');
                }
            }
            $keyParts[] = $data[$name];
        }

        $key = implode(':', $keyParts);

        if (isset($this->entityCache[$className]) &&
            isset($this->entityCache[$className][$key]) &&
            isset($this->entityCache[$className][$key][$revision])
        ) {
            return $this->entityCache[$className][$key][$revision];
        }

        if (!$class->isInheritanceTypeNone()) {
            if (!isset($data[$class->discriminatorColumn['name']])) {
                throw new \RuntimeException('Expecting discriminator value in data set.');
            }
            $discriminator = $data[$class->discriminatorColumn['name']];
            if (!isset($class->discriminatorMap[$discriminator])) {
                throw new \RuntimeException("No mapping found for [{$discriminator}].");
            }

            if ($class->discriminatorValue) {
                $entity = $this->em->getClassMetadata($class->discriminatorMap[$discriminator])->newInstance();
            } else {
                //a complex case when ToOne binding is against AbstractEntity having no discriminator
                $pk = array();

                foreach ($class->identifier as $field) {
                    $pk[$class->getColumnName($field)] = $data[$field];
                }

                return $this->find($class->discriminatorMap[$discriminator], $pk, $revision);
            }
        } else {
            $entity = $class->newInstance();
        }

        //cache the entity to prevent circular references
        $this->entityCache[$className][$key][$revision] = $entity;

        $connection = $this->getConnection();
        foreach ($data as $field => $value) {
            if (isset($class->fieldMappings[$field])) {
                $value = $connection->convertToPHPValue($value, $class->fieldMappings[$field]['type']);
                $class->reflFields[$field]->setValue($entity, $value);
            }
        }

        foreach ($class->associationMappings as $field => $assoc) {
            // Check if the association is not among the fetch-joined associations already.
            if (isset($hints['fetched'][$className][$field])) {
                continue;
            }

            /** @var ClassMetadataInfo|ClassMetadata $targetClass */
            $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);

            if ($assoc['type'] & ClassMetadata::TO_ONE) {
                //print_r($targetClass->discriminatorMap);
                if ($this->metadataFactory->isAudited($assoc['targetEntity'])) {
                    if ($this->loadAuditedEntities) {
                        // Primary Key. Used for audit tables queries.
                        $pk = array();
                        // Primary Field. Used when fallback to Doctrine finder.
                        $pf = array();

                        if ($assoc['isOwningSide']) {
                            foreach ($assoc['targetToSourceKeyColumns'] as $foreign => $local) {
                                $pk[$foreign] = $pf[$foreign] = $data[$columnMap[$local]];
                            }
                        } else {
                            /** @var ClassMetadataInfo|ClassMetadata $otherEntityMeta */
                            $otherEntityAssoc = $this->em->getClassMetadata($assoc['targetEntity'])->associationMappings[$assoc['mappedBy']];

                            foreach ($otherEntityAssoc['targetToSourceKeyColumns'] as $local => $foreign) {
                                $pk[$foreign] = $pf[$otherEntityAssoc['fieldName']] = $data[$class->getFieldName($local)];
                            }
                        }

                        $pk = array_filter($pk, function ($value) {
                            return !is_null($value);
                        });

                        if (!$pk) {
                            $class->reflFields[$field]->setValue($entity, null);
                        } else {
                            try {
                                $value = $this->find($targetClass->name, $pk, $revision, array('threatDeletionsAsExceptions' => true));
                            } catch (DeletedException $e) {
                                $value = null;
                            } catch (NoRevisionFoundException $e) {
                                // The entity does not have any revision yet. So let's get the actual state of it.
                                $value = $this->em->getRepository($targetClass->name)->findOneBy($pf);
                            }

                            $class->reflFields[$field]->setValue($entity, $value);
                        }
                    } else {
                        $class->reflFields[$field]->setValue($entity, null);
                    }
                } else {
                    if ($this->loadNativeEntities) {
                        if ($assoc['isOwningSide']) {
                            $associatedId = array();
                            foreach ($assoc['targetToSourceKeyColumns'] as $targetColumn => $srcColumn) {
                                $joinColumnValue = isset($data[$columnMap[$srcColumn]]) ? $data[$columnMap[$srcColumn]] : null;
                                if ($joinColumnValue !== null) {
                                    $associatedId[$targetClass->fieldNames[$targetColumn]] = $joinColumnValue;
                                }
                            }
                            if (!$associatedId) {
                                // Foreign key is NULL
                                $class->reflFields[$field]->setValue($entity, null);
                            } else {
                                $associatedEntity = $this->em->getReference($targetClass->name, $associatedId);
                                $class->reflFields[$field]->setValue($entity, $associatedEntity);
                            }
                        } else {
                            // Inverse side of x-to-one can never be lazy
                            $class->reflFields[$field]->setValue($entity, $this->getEntityPersister($assoc['targetEntity'])
                                ->loadOneToOneEntity($assoc, $entity));
                        }
                    } else {
                        $class->reflFields[$field]->setValue($entity, null);
                    }
                }
            } elseif ($assoc['type'] & ClassMetadata::ONE_TO_MANY) {
                if ($this->metadataFactory->isAudited($assoc['targetEntity'])) {
                    if ($this->loadAuditedCollections) {
                        $foreignKeys = array();
                        foreach ($targetClass->associationMappings[$assoc['mappedBy']]['sourceToTargetKeyColumns'] as $local => $foreign) {
                            $field = $class->getFieldForColumn($foreign);
                            $foreignKeys[$local] = $class->reflFields[$field]->getValue($entity);
                        }

                        $collection = new AuditedCollection($this, $targetClass->name, $targetClass, $assoc, $foreignKeys, $revision);

                        $class->reflFields[$assoc['fieldName']]->setValue($entity, $collection);
                    } else {
                        $class->reflFields[$assoc['fieldName']]->setValue($entity, new ArrayCollection());
                    }
                } else {
                    if ($this->loadNativeCollections) {
                        $collection = new PersistentCollection($this->em, $targetClass, new ArrayCollection());

                        $this->getEntityPersister($assoc['targetEntity'])
                            ->loadOneToManyCollection($assoc, $entity, $collection);

                        $class->reflFields[$assoc['fieldName']]->setValue($entity, $collection);
                    } else {
                        $class->reflFields[$assoc['fieldName']]->setValue($entity, new ArrayCollection());
                    }
                }
            } else {
                // Inject collection
                $reflField = $class->reflFields[$field];
                $reflField->setValue($entity, new ArrayCollection);
            }
        }

        return $entity;
    }

    /**
     * Return a list of all revisions.
     *
     * @param int $limit
     * @param int $offset
     * @return Revision[]
     */
    public function findRevisionHistory($limit = 20, $offset = 0)
    {
        $revisionsData = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->config->getRevisionTableName())
            ->orderBy('id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->execute()
            ->fetchAll();

        $revisions = array();
        foreach ($revisionsData AS $row) {
            $revisions[] = $this->createRevision($row);
        }
        return $revisions;
    }

    /**
     * Return a list of ChangedEntity instances created at the given revision.
     *
     * @param int $revision
     *
     * @return ChangedEntity[]
     */
    public function findEntitiesChangedAtRevision($revision)
    {
        $auditedEntities = $this->metadataFactory->getAllClassNames();
        $connection = $this->getConnection();

        $changedEntities = array();
        foreach ($auditedEntities AS $className) {
            /** @var ClassMetadataInfo|ClassMetadata $class */
            $class = $this->em->getClassMetadata($className);

            if ($class->isInheritanceTypeSingleTable() && count($class->subClasses) > 0) {
                continue;
            }

            $queryBuilder = $connection->createQueryBuilder()
                ->select('e.' . $this->config->getRevisionTypeFieldName())
                ->from($this->config->getTableName($class), 'e');

            $queryBuilder->where(sprintf(
                'e.%s = %s',
                $this->config->getRevisionFieldName(),
                $queryBuilder->createPositionalParameter($revision)
            ));

            $columnMap = array();

            foreach ($class->fieldNames as $columnName => $field) {
                $tableAlias = $class->isInheritanceTypeJoined() && $class->isInheritedField($field)	&& ! $class->isIdentifier($field)
                    ? 're' // root entity
                    : 'e';

                $queryBuilder->addSelect(sprintf(
                    '%s.%s AS %s',
                    $tableAlias,
                    $this->quoteStrategy->getColumnName($field, $class, $this->platform),
                    $this->platform->quoteSingleIdentifier($field)
                ));
                $columnMap[$field] = $this->platform->getSQLResultCasing($columnName);
            }

            foreach ($class->associationMappings AS $assoc) {
                if (($assoc['type'] & ClassMetadata::TO_ONE) > 0 && $assoc['isOwningSide']) {
                    foreach ($assoc['targetToSourceKeyColumns'] as $sourceCol) {
                        $queryBuilder->addSelect($sourceCol);
                        $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
                    }
                }
            }

            if ($class->isInheritanceTypeSingleTable()) {
                $queryBuilder->addSelect('e.' . $class->discriminatorColumn['name']);
                $queryBuilder->andWhere(sprintf(
                    'e.%s = %s',
                    $class->discriminatorColumn['fieldName'],
                    $queryBuilder->createPositionalParameter($class->discriminatorValue)
                ));
            } elseif ($class->isInheritanceTypeJoined() && $class->rootEntityName != $class->name) {
                /** @var ClassMetadataInfo|ClassMetadata $rootClass */
                $rootClass = $this->em->getClassMetadata($class->rootEntityName);
                $rootTableName = $this->config->getTableName($rootClass);

                $condition  = ['re.rev = e.rev'];
                foreach ($class->getIdentifierColumnNames() as $name) {
                    $condition[] = "re.$name = e.$name";
                }

                $queryBuilder->addSelect('re.' . $class->discriminatorColumn['name']);
                $queryBuilder->innerJoin('e', $rootTableName, 're', implode(' AND ', $condition));
            }

            $revisionsData = $queryBuilder->execute()->fetchAll();

            foreach ($revisionsData AS $row) {
                $id = array();
                foreach ($class->identifier AS $idField) {
                    $id[$idField] = $row[$idField];
                }

                $entity = $this->createEntity($className, $columnMap, $row, $revision);
                $changedEntities[] = new ChangedEntity(
                    $className,
                    $id,
                    $row[$this->config->getRevisionTypeFieldName()],
                    $entity
                );
            }
        }

        return $changedEntities;
    }

    /**
     * Return the revision object for a particular revision.
     *
     * @param  int $rev
     *
     * @return Revision
     *
     * @throws InvalidRevisionException
     */
    public function findRevision($rev)
    {
        $revisionsData = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->config->getRevisionTableName(), 'r')
            ->where('r.id = :id')
            ->setParameter('id', $rev)
            ->execute()
            ->fetchAll();

        if (count($revisionsData) === 1) {
            return $this->createRevision($revisionsData[0]);
        }

        throw new InvalidRevisionException($rev);
    }

    /**
     * Find all revisions that were made of entity class with given id.
     *
     * @param string $className
     * @param mixed $id
     * @throws NotAuditedException
     * @return Revision[]
     */
    public function findRevisions($className, $id)
    {
        if (! $this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        /** @var ClassMetadataInfo|ClassMetadata $class */
        $class = $this->em->getClassMetadata($className);

        $connection = $this->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('r.*')
            ->from($this->config->getRevisionTableName(), 'r')
            ->innerJoin(
                'r',
                $this->config->getTableName($class),
                'e',
                'r.id = e.' . $this->config->getRevisionFieldName()
            )
            ->orderBy('r.id', 'DESC');

        if (! is_array($id)) {
            $id = array($class->identifier[0] => $id);
        }
        $queryBuilder->setParameters(array_values($id));

        foreach ($class->identifier AS $idField) {
            if (isset($class->fieldMappings[$idField])) {
                $queryBuilder->andWhere(sprintf(
                    'e.%s = ?',
                    $class->fieldMappings[$idField]['columnName']
                ));
            } else if (isset($class->associationMappings[$idField])) {
                $queryBuilder->andWhere(sprintf(
                    'e.%s = ?',
                    $class->associationMappings[$idField]['joinColumns'][0]['name']
                ));
            }
        }

        $revisionsData = $queryBuilder->execute()->fetchAll();

        $revisions = array();
        foreach ($revisionsData AS $row) {
            $revisions[] = $this->createRevision($row);
        }

        return $revisions;
    }

    /**
     * Gets the current revision of the entity with given ID.
     *
     * @param string $className
     * @param mixed $id
     * @throws NotAuditedException
     * @return integer
     */
    public function getCurrentRevision($className, $id)
    {
        if (!$this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        /** @var ClassMetadataInfo|ClassMetadata $class */
        $class = $this->em->getClassMetadata($className);

        $queryBuilder = $this->getConnection()->createQueryBuilder()
            ->select('e.' . $this->config->getRevisionFieldName())
            ->from($this->config->getTableName($class), 'e')
            ->orderBy('e.' . $this->config->getRevisionFieldName(), 'DESC');

        if (! is_array($id)) {
            $id = array($class->identifier[0] => $id);
        }
        $queryBuilder->setParameters(array_values($id));

        foreach ($class->identifier AS $idField) {
            if (isset($class->fieldMappings[$idField])) {
                $queryBuilder->andWhere(sprintf('e.%s = ?', $class->fieldMappings[$idField]['columnName']));
            } elseif (isset($class->associationMappings[$idField])) {
                $queryBuilder->andWhere(sprintf('e.%s = ?', $class->associationMappings[$idField]['joinColumns'][0]['name']));
            }
        }

        return $queryBuilder->execute()->fetchColumn();
    }

    /**
     * @param object $entity
     *
     * @return \Doctrine\ORM\Persisters\Entity\EntityPersister
     */
    protected function getEntityPersister($entity)
    {
        return $this->em->getUnitOfWork()->getEntityPersister($entity);
    }

    /**
     * Get an array with the differences of between two specific revisions of
     * an object with a given id.
     *
     * @param string $className
     * @param int    $id
     * @param int    $oldRevision
     * @param int    $newRevision
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \SimpleThings\EntityAudit\Exception\NotAuditedException
     * @throws \SimpleThings\EntityAudit\Exception\NoRevisionFoundException
     * @throws \SimpleThings\EntityAudit\Exception\DeletedException
     */
    public function diff($className, $id, $oldRevision, $newRevision)
    {
        $oldObject = $this->find($className, $id, $oldRevision);
        $newObject = $this->find($className, $id, $newRevision);

        $oldValues = $this->getEntityValues($className, $oldObject);
        $newValues = $this->getEntityValues($className, $newObject);

        $diff = array();

        $metadataFactory = $this->em->getMetadataFactory();
        $valueToCompare = function ($value) use ($metadataFactory) {
            // If the value is an associated entity, we have to compare the identifiers.
            if (is_object($value) && $metadataFactory->hasMetadataFor(ClassUtils::getClass($value))) {
                return $metadataFactory->getMetadataFor(ClassUtils::getClass($value))
                    ->getIdentifierValues($value);
            }

            return $value;
        };

        $keys = array_keys($oldValues + $newValues);
        foreach ($keys as $field) {
            $old = array_key_exists($field, $oldValues) ? $oldValues[$field] : null;
            $new = array_key_exists($field, $newValues) ? $newValues[$field] : null;

            if ($valueToCompare($old) == $valueToCompare($new)) {
                $row = array('old' => '', 'new' => '', 'same' => $old);
            } else {
                $row = array('old' => $old, 'new' => $new, 'same' => '');
            }

            $diff[$field] = $row;
        }

        return $diff;
    }

    /**
     * Get the values for a specific entity as an associative array
     *
     * @param string $className
     * @param object $entity
     * @return array
     */
    public function getEntityValues($className, $entity)
    {
        /** @var ClassMetadataInfo|ClassMetadata $metadata */
        $metadata = $this->em->getClassMetadata($className);

        $values = array();

        // Fetch simple fields values
        foreach ($metadata->getFieldNames() as $fieldName) {
            $values[$fieldName] = $metadata->getFieldValue($entity, $fieldName);
        }

        // Fetch associations identifiers values
        foreach ($metadata->getAssociationNames() as $associationName) {
            // Do not get OneToMany or ManyToMany collections because not relevant to the revision.
            if ($metadata->getAssociationMapping($associationName)['isOwningSide']) {
                $values[$associationName] = $metadata->getFieldValue($entity, $associationName);
            }
        }

        return $values;
    }

    /**
     * @param string $className
     * @param mixed $id
     *
     * @return array
     *
     * @throws NotAuditedException
     */
    public function getEntityHistory($className, $id)
    {
        if (! $this->metadataFactory->isAudited($className)) {
            throw new NotAuditedException($className);
        }

        /** @var ClassMetadataInfo|ClassMetadata $class */
        $class = $this->em->getClassMetadata($className);

        $revisionFieldName = $this->config->getRevisionFieldName();
        $queryBuilder = $this->getConnection()->createQueryBuilder()
            ->select($revisionFieldName)
            ->from($this->config->getTableName($class), 'e')
            ->orderBy('e.rev', 'DESC');

        if (!is_array($id)) {
            $id = array($class->identifier[0] => $id);
        }
        $queryBuilder->setParameters(array_values($id));

        foreach ($class->identifier AS $idField) {
            if (isset($class->fieldMappings[$idField])) {
                $queryBuilder->andWhere($class->fieldMappings[$idField]['columnName'] . ' = ?');
            } elseif (isset($class->associationMappings[$idField])) {
                $queryBuilder->andWhere($class->associationMappings[$idField]['joinColumns'][0]['name'] . ' = ?');
            }
        }

        $columnMap  = array();

        foreach ($class->fieldNames as $columnName => $field) {
            $queryBuilder->addSelect(sprintf(
                '%s AS %s',
                $this->quoteStrategy->getColumnName($field, $class, $this->platform),
                $this->platform->quoteSingleIdentifier($field)
            ));
            $columnMap[$field] = $this->platform->getSQLResultCasing($columnName);
        }

        foreach ($class->associationMappings AS $assoc) {
            if (($assoc['type'] & ClassMetadata::TO_ONE) == 0 || ! $assoc['isOwningSide']) {
                continue;
            }

            foreach ($assoc['targetToSourceKeyColumns'] as $sourceCol) {
                $queryBuilder->addSelect($sourceCol);
                $columnMap[$sourceCol] = $this->platform->getSQLResultCasing($sourceCol);
            }
        }


        $stmt = $queryBuilder->execute();

        $result = array();
        while ($row = $stmt->fetch(Query::HYDRATE_ARRAY)) {
            $rev = $row[$revisionFieldName];
            unset($row[$revisionFieldName]);

            $result[] = $this->createEntity($class->name, $columnMap, $row, $rev);
        }

        return $result;
    }

    /**
     * @param array $row
     *
     * @return Revision
     */
    private function createRevision(array $row)
    {
        return new Revision(
            $row['id'],
            \DateTime::createFromFormat($this->platform->getDateTimeFormatString(), $row['timestamp']),
            $row['username']
        );
    }
}
