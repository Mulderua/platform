<?php

namespace Oro\Bundle\SecurityBundle\Acl\Extension;

use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Exception\InvalidDomainObjectException;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Util\ClassUtils;

use Oro\Bundle\EntityBundle\Exception\InvalidEntityException;
use Oro\Bundle\EntityBundle\ORM\EntityClassResolver;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdAccessor;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdentityFactory;
use Oro\Bundle\SecurityBundle\Acl\Exception\InvalidAclMaskException;
use Oro\Bundle\SecurityBundle\Annotation\Acl as AclAnnotation;
use Oro\Bundle\SecurityBundle\Authentication\Token\OrganizationContextTokenInterface;
use Oro\Bundle\SecurityBundle\Metadata\EntitySecurityMetadataProvider;
use Oro\Bundle\SecurityBundle\Owner\EntityOwnerAccessor;
use Oro\Bundle\SecurityBundle\Owner\Metadata\MetadataProviderInterface;
use Oro\Bundle\SecurityBundle\Owner\Metadata\OwnershipMetadataInterface;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class EntityAclExtension extends AbstractAclExtension
{
    /**
     * @var ObjectIdAccessor
     */
    protected $objectIdAccessor;

    /**
     * @var EntityClassResolver
     */
    protected $entityClassResolver;

    /**
     * @var MetadataProviderInterface
     */
    protected $metadataProvider;

    /**
     * @var EntitySecurityMetadataProvider
     */
    protected $entityMetadataProvider;

    /**
     * @var AccessLevelOwnershipDecisionMakerInterface
     */
    protected $decisionMaker;

    /**
     * @var EntityOwnerAccessor
     */
    protected $entityOwnerAccessor;

    /**
     * key = Permission
     * value = The identity of a permission mask builder
     *
     * @var int[]
     */
    protected $permissionToMaskBuilderIdentity = array();

    /**
     * key = The identity of a permission mask builder
     * value = The full class name of a permission mask builder
     *
     * @var string[]
     */
    protected $maskBuilderClassNames = array();

    /**
     * Constructor
     *
     * @param ObjectIdAccessor                $objectIdAccessor
     * @param EntityClassResolver             $entityClassResolver
     * @param EntitySecurityMetadataProvider  $entityMetadataProvider
     * @param MetadataProviderInterface       $metadataProvider
     * @param AccessLevelOwnershipDecisionMakerInterface $decisionMaker
     */
    public function __construct(
        ObjectIdAccessor $objectIdAccessor,
        EntityClassResolver $entityClassResolver,
        EntitySecurityMetadataProvider $entityMetadataProvider,
        MetadataProviderInterface $metadataProvider,
        AccessLevelOwnershipDecisionMakerInterface $decisionMaker
    ) {
        $this->objectIdAccessor       = $objectIdAccessor;
        $this->entityClassResolver    = $entityClassResolver;
        $this->entityMetadataProvider = $entityMetadataProvider;
        $this->metadataProvider       = $metadataProvider;
        $this->decisionMaker          = $decisionMaker;

        $this->maskBuilderClassNames[EntityMaskBuilder::IDENTITY]
            = 'Oro\Bundle\SecurityBundle\Acl\Extension\EntityMaskBuilder';

        $this->permissionToMaskBuilderIdentity['VIEW']   = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['CREATE'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['EDIT']   = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['DELETE'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['ASSIGN'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['SHARE']  = EntityMaskBuilder::IDENTITY;

        $this->map = array(
            'VIEW'   => array(
                EntityMaskBuilder::MASK_VIEW_BASIC,
                EntityMaskBuilder::MASK_VIEW_LOCAL,
                EntityMaskBuilder::MASK_VIEW_DEEP,
                EntityMaskBuilder::MASK_VIEW_GLOBAL,
                EntityMaskBuilder::MASK_VIEW_SYSTEM,
            ),
            'CREATE' => array(
                EntityMaskBuilder::MASK_CREATE_BASIC,
                EntityMaskBuilder::MASK_CREATE_LOCAL,
                EntityMaskBuilder::MASK_CREATE_DEEP,
                EntityMaskBuilder::MASK_CREATE_GLOBAL,
                EntityMaskBuilder::MASK_CREATE_SYSTEM,
            ),
            'EDIT'   => array(
                EntityMaskBuilder::MASK_EDIT_BASIC,
                EntityMaskBuilder::MASK_EDIT_LOCAL,
                EntityMaskBuilder::MASK_EDIT_DEEP,
                EntityMaskBuilder::MASK_EDIT_GLOBAL,
                EntityMaskBuilder::MASK_EDIT_SYSTEM,
            ),
            'DELETE' => array(
                EntityMaskBuilder::MASK_DELETE_BASIC,
                EntityMaskBuilder::MASK_DELETE_LOCAL,
                EntityMaskBuilder::MASK_DELETE_DEEP,
                EntityMaskBuilder::MASK_DELETE_GLOBAL,
                EntityMaskBuilder::MASK_DELETE_SYSTEM,
            ),
            'ASSIGN' => array(
                EntityMaskBuilder::MASK_ASSIGN_BASIC,
                EntityMaskBuilder::MASK_ASSIGN_LOCAL,
                EntityMaskBuilder::MASK_ASSIGN_DEEP,
                EntityMaskBuilder::MASK_ASSIGN_GLOBAL,
                EntityMaskBuilder::MASK_ASSIGN_SYSTEM,
            ),
            'SHARE'  => array(
                EntityMaskBuilder::MASK_SHARE_BASIC,
                EntityMaskBuilder::MASK_SHARE_LOCAL,
                EntityMaskBuilder::MASK_SHARE_DEEP,
                EntityMaskBuilder::MASK_SHARE_GLOBAL,
                EntityMaskBuilder::MASK_SHARE_SYSTEM,
            ),
        );
    }

    /**
     * @param EntityOwnerAccessor $entityOwnerAccessor
     */
    public function setEntityOwnerAccessor(EntityOwnerAccessor $entityOwnerAccessor)
    {
        $this->entityOwnerAccessor = $entityOwnerAccessor;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessLevelNames($object)
    {
        if ($this->getObjectClassName($object) === ObjectIdentityFactory::ROOT_IDENTITY_TYPE) {
            /**
             * In community version root entity should not have GLOBAL(Organization) access level
             */
            return AccessLevel::getAccessLevelNames(
                AccessLevel::BASIC_LEVEL,
                AccessLevel::SYSTEM_LEVEL,
                [AccessLevel::GLOBAL_LEVEL]
            );
        } else {
            return $this->getMetadata($object)->getAccessLevelNames();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($type, $id)
    {
        if ($type === ObjectIdentityFactory::ROOT_IDENTITY_TYPE && $id === $this->getExtensionKey()) {
            return true;
        }

        $delim = strpos($type, '@');
        if ($delim !== false) {
            $type = ltrim(substr($type, $delim + 1), ' ');
        }

        if ($id === $this->getExtensionKey()) {
            $type = $this->entityClassResolver->getEntityClass(ClassUtils::getRealClass($type));
        } else {
            $type = ClassUtils::getRealClass($type);
        }

        return $this->entityClassResolver->isEntity($type);
    }

    /**
     * {@inheritdoc}
     */
    public function getExtensionKey()
    {
        return 'entity';
    }

    /**
     * {@inheritdoc}
     */
    public function validateMask($mask, $object, $permission = null)
    {
        if (0 === $this->removeServiceBits($mask)) {
            // zero mask
            return;
        }

        $permissions = $permission === null
            ? $this->getPermissions($mask, true)
            : array($permission);

        foreach ($permissions as $permission) {
            $validMasks = $this->getValidMasks($permission, $object);
            if (($mask | $validMasks) === $validMasks) {
                $identity = $this->permissionToMaskBuilderIdentity[$permission];
                foreach ($this->permissionToMaskBuilderIdentity as $p => $i) {
                    if ($identity === $i) {
                        $this->validateMaskAccessLevel($p, $mask, $object);
                    }
                }

                return;
            }
        }

        throw $this->createInvalidAclMaskException($mask, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectIdentity($val)
    {
        if (is_string($val)) {
            return $this->fromDescriptor($val);
        } elseif ($val instanceof AclAnnotation) {
            $class = $this->entityClassResolver->getEntityClass($val->getClass());
            $group = $val->getGroup();

            return new ObjectIdentity($val->getType(), !empty($group) ? $group . '@' . $class : $class);
        }

        return $this->fromDomainObject($val);
    }

    /**
     * {@inheritdoc}
     */
    public function getMaskBuilder($permission)
    {
        if (empty($permission)) {
            $permission = 'VIEW';
        }

        $identity             = $this->permissionToMaskBuilderIdentity[$permission];
        $maskBuilderClassName = $this->maskBuilderClassNames[$identity];

        return new $maskBuilderClassName();
    }

    /**
     * {@inheritdoc}
     */
    public function getAllMaskBuilders()
    {
        $result = array();
        foreach ($this->maskBuilderClassNames as $maskBuilderClassName) {
            $result[] = new $maskBuilderClassName();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaskPattern($mask)
    {
        $maskBuilderClassName = $this->maskBuilderClassNames[$this->getServiceBits($mask)];

        return $maskBuilderClassName::getPatternFor($mask);
    }

    /**
     * {@inheritdoc}
     */
    public function adaptRootMask($rootMask, $object)
    {
        $permissions = $this->getPermissions($rootMask, true);
        if (!empty($permissions)) {
            $metadata = $this->getMetadata($object);
            $identity = $this->getServiceBits($rootMask);
            foreach ($permissions as $permission) {
                $permissionMask = $this->getMaskBuilderConst($identity, 'GROUP_' . $permission);
                $mask           = $rootMask & $permissionMask;
                $accessLevel    = $this->getAccessLevel($mask);
                if (!$metadata->hasOwner()) {
                    if ($identity === EntityMaskBuilder::IDENTITY
                        && ($permission === 'ASSIGN' || $permission === 'SHARE')
                    ) {
                        $rootMask &= ~$this->removeServiceBits($mask);
                    } elseif ($accessLevel < AccessLevel::SYSTEM_LEVEL) {
                        $rootMask &= ~$this->removeServiceBits($mask);
                        $rootMask |= $this->getMaskBuilderConst($identity, 'MASK_' . $permission . '_SYSTEM');
                    }
                } elseif ($metadata->isGlobalLevelOwned()) {
                    if ($accessLevel < AccessLevel::GLOBAL_LEVEL) {
                        $rootMask &= ~$this->removeServiceBits($mask);
                        $rootMask |= $this->getMaskBuilderConst($identity, 'MASK_' . $permission . '_GLOBAL');
                    }
                } elseif ($metadata->isLocalLevelOwned()) {
                    if ($accessLevel < AccessLevel::LOCAL_LEVEL) {
                        $rootMask &= ~$this->removeServiceBits($mask);
                        $rootMask |= $this->getMaskBuilderConst($identity, 'MASK_' . $permission . '_LOCAL');
                    }
                }
            }
        }

        return $rootMask;
    }

    /**
     * {@inheritdoc}
     */
    public function getServiceBits($mask)
    {
        return $mask & BaseEntityMaskBuilder::SERVICE_BITS;
    }

    /**
     * {@inheritdoc}
     */
    public function removeServiceBits($mask)
    {
        return $mask & BaseEntityMaskBuilder::REMOVE_SERVICE_BITS;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessLevel($mask, $permission = null, $object = null)
    {
        if (0 === $this->removeServiceBits($mask)) {
            return AccessLevel::NONE_LEVEL;
        }

        $identity = $this->getServiceBits($mask);
        if ($permission !== null) {
            $permissionMask = $this->getMaskBuilderConst($identity, 'GROUP_' . $permission);
            $mask           = $mask & $permissionMask;
        }

        $result = AccessLevel::NONE_LEVEL;
        foreach (AccessLevel::$allAccessLevelNames as $accessLevel) {
            if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'GROUP_' . $accessLevel))) {
                $result = AccessLevel::getConst($accessLevel . '_LEVEL');
            }
        }

        return $this->metadataProvider->getMaxAccessLevel($result, $this->getObjectClassName($object));
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissions($mask = null, $setOnly = false)
    {
        if ($mask === null) {
            return array_keys($this->permissionToMaskBuilderIdentity);
        }

        $result = array();
        if (!$setOnly) {
            $identity = $this->getServiceBits($mask);
            foreach ($this->permissionToMaskBuilderIdentity as $permission => $id) {
                if ($id === $identity) {
                    $result[] = $permission;
                }
            }
        } elseif (0 !== $this->removeServiceBits($mask)) {
            $identity = $this->getServiceBits($mask);
            foreach ($this->permissionToMaskBuilderIdentity as $permission => $id) {
                if ($id === $identity) {
                    if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'GROUP_' . $permission))) {
                        $result[] = $permission;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPermissions(ObjectIdentity $oid)
    {
        if ($oid->getType() === ObjectIdentityFactory::ROOT_IDENTITY_TYPE) {
            $result = array_keys($this->permissionToMaskBuilderIdentity);
        } else {
            $config = $this->entityMetadataProvider->getMetadata($oid->getType());
            $result = $config->getPermissions();
            if (empty($result)) {
                $result = array_keys($this->map);
            }

            $metadata = $this->getMetadata($oid);
            if (!$metadata->hasOwner()) {
                foreach ($result as $key => $value) {
                    if (in_array($value, array('ASSIGN', 'SHARE'))) {
                        unset($result[$key]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getClasses()
    {
        return $this->entityMetadataProvider->getEntities();
    }

    /**
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * {@inheritdoc}
     */
    public function decideIsGranting($triggeredMask, $object, TokenInterface $securityToken)
    {
        // check whether we check permissions for a domain object
        if ($object === null || !is_object($object) || $object instanceof ObjectIdentityInterface) {
            return true;
        }

        $organization = null;
        if ($securityToken instanceof OrganizationContextTokenInterface) {
            if ($this->isAccessDeniedByOrganizationContext($object, $securityToken)) {
                return false;
            }
            $organization = $securityToken->getOrganizationContext();
        }

        $accessLevel = $this->getAccessLevel($triggeredMask);
        if (AccessLevel::SYSTEM_LEVEL === $accessLevel) {
            return true;
        }

        $metadata = $this->getMetadata($object);
        if (!$metadata->hasOwner()) {
            return true;
        }

        $result = false;
        if (AccessLevel::BASIC_LEVEL === $accessLevel) {
            $result = $this->decisionMaker->isAssociatedWithBasicLevelEntity(
                $securityToken->getUser(),
                $object,
                $organization
            );
        } else {
            if ($metadata->isBasicLevelOwned()) {
                $result = $this->decisionMaker->isAssociatedWithBasicLevelEntity(
                    $securityToken->getUser(),
                    $object,
                    $organization
                );
            }
            if (!$result) {
                if (AccessLevel::LOCAL_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithLocalLevelEntity(
                        $securityToken->getUser(),
                        $object,
                        false,
                        $organization
                    );
                } elseif (AccessLevel::DEEP_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithLocalLevelEntity(
                        $securityToken->getUser(),
                        $object,
                        true,
                        $organization
                    );
                } elseif (AccessLevel::GLOBAL_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithGlobalLevelEntity(
                        $securityToken->getUser(),
                        $object,
                        $organization
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @param int   $accessLevel Current object access level
     * @param mixed $object      Object for test
     *
     * @return int
     *
     * @deprecated since 1.8, use MetadataProviderInterface::getMaxAccessLevel instead
     */
    protected function fixMaxAccessLevel($accessLevel, $object)
    {
        return $this->metadataProvider->getMaxAccessLevel($accessLevel, $this->getObjectClassName($object));
    }

    /**
     * Constructs an ObjectIdentity for the given domain object
     *
     * @param string $descriptor
     *
     * @return ObjectIdentity
     * @throws \InvalidArgumentException
     */
    protected function fromDescriptor($descriptor)
    {
        $type = $id = $group = null;
        $this->parseDescriptor($descriptor, $type, $id, $group);

        $type = $this->entityClassResolver->getEntityClass(ClassUtils::getRealClass($type));

        if ($id === $this->getExtensionKey()) {
            return new ObjectIdentity($id, !empty($group) ? $group . '@' . $type : $type);
        }

        throw new \InvalidArgumentException(
            sprintf('Unsupported object identity descriptor: %s.', $descriptor)
        );
    }

    /**
     * Constructs an ObjectIdentity for the given domain object
     *
     * @param object $domainObject
     *
     * @return ObjectIdentity
     * @throws InvalidDomainObjectException
     */
    protected function fromDomainObject($domainObject)
    {
        if (!is_object($domainObject)) {
            throw new InvalidDomainObjectException('$domainObject must be an object.');
        }

        try {
            return new ObjectIdentity(
                $this->objectIdAccessor->getId($domainObject),
                ClassUtils::getRealClass($domainObject)
            );
        } catch (\InvalidArgumentException $invalid) {
            throw new InvalidDomainObjectException($invalid->getMessage(), 0, $invalid);
        }
    }

    /**
     * Checks that the given mask represents only one access level
     *
     * @param string $permission
     * @param int    $mask
     * @param mixed  $object
     *
     * @throws InvalidAclMaskException
     */
    protected function validateMaskAccessLevel($permission, $mask, $object)
    {
        $identity = $this->permissionToMaskBuilderIdentity[$permission];
        if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'GROUP_' . $permission))) {
            $maskAccessLevels = array();
            foreach (AccessLevel::$allAccessLevelNames as $accessLevel) {
                if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'MASK_' . $permission . '_' . $accessLevel))) {
                    $maskAccessLevels[] = $accessLevel;
                }
            }
            if (count($maskAccessLevels) > 1) {
                $msg = sprintf(
                    'The %s mask must be in one access level only, but it is in %s access levels.',
                    $permission,
                    implode(', ', $maskAccessLevels)
                );
                throw $this->createInvalidAclMaskException($mask, $object, $msg);
            }
        }
    }

    /**
     * Gets all valid bitmasks for the given object
     *
     * @param string $permission
     * @param mixed  $object
     *
     * @return int
     */
    protected function getValidMasks($permission, $object)
    {
        if ($object instanceof ObjectIdentity && $object->getType() === ObjectIdentityFactory::ROOT_IDENTITY_TYPE) {
            $identity = $this->permissionToMaskBuilderIdentity[$permission];

            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_DEEP')
                | $this->getMaskBuilderConst($identity, 'GROUP_LOCAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_BASIC');
        }

        $metadata = $this->getMetadata($object);
        if (!$metadata->hasOwner()) {
            if ($this->permissionToMaskBuilderIdentity[$permission] === EntityMaskBuilder::IDENTITY) {
                return EntityMaskBuilder::GROUP_CRUD_SYSTEM;
            }

            return $this->permissionToMaskBuilderIdentity[$permission];
        }

        $identity = $this->permissionToMaskBuilderIdentity[$permission];
        if ($metadata->isGlobalLevelOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL');
        } elseif ($metadata->isLocalLevelOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_DEEP')
                | $this->getMaskBuilderConst($identity, 'GROUP_LOCAL');
        } elseif ($metadata->isBasicLevelOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_DEEP')
                | $this->getMaskBuilderConst($identity, 'GROUP_LOCAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_BASIC');
        }

        return $this->permissionToMaskBuilderIdentity[$permission];
    }

    /**
     * Gets metadata for the given object
     *
     * @param mixed $object
     *
     * @return OwnershipMetadataInterface
     */
    protected function getMetadata($object)
    {
        return $this->metadataProvider->getMetadata($this->getObjectClassName($object));
    }

    /**
     * Gets class name for given object
     *
     * @param $object
     *
     * @return string
     */
    protected function getObjectClassName($object)
    {
        if ($object instanceof ObjectIdentity) {
            $className = $object->getType();
        } elseif (is_string($object)) {
            $className = $id = $group = null;
            $this->parseDescriptor($object, $className, $id, $group);
        } else {
            $className = ClassUtils::getRealClass($object);
        }

        return $className;
    }

    /**
     * Gets the constant value defined in the given permission mask builder
     *
     * @param int    $maskBuilderIdentity The permission mask builder identity
     * @param string $constName
     *
     * @return int
     */
    protected function getMaskBuilderConst($maskBuilderIdentity, $constName)
    {
        $maskBuilderClassName = $this->maskBuilderClassNames[$maskBuilderIdentity];

        return $maskBuilderClassName::getConst($constName);
    }

    /**
     * Check organization. If user try to access entity what was created in organization this user do not have access -
     *  deny access. We should check organization for all the entities what have ownership
     *  (USER, BUSINESS_UNIT, ORGANIZATION ownership types)
     *
     * @param mixed $object
     * @param OrganizationContextTokenInterface $securityToken
     * @return bool
     */
    protected function isAccessDeniedByOrganizationContext($object, OrganizationContextTokenInterface $securityToken)
    {
        try {
            // try to get entity organization value
            $objectOrganization = $this->entityOwnerAccessor->getOrganization($object);

            // check entity organization with current organization
            if ($objectOrganization
                && $objectOrganization->getId() !== $securityToken->getOrganizationContext()->getId()
            ) {
                return true;
            }
        } catch (InvalidEntityException $e) {
            // in case if entity has no organization field (none ownership type)
        }

        return false;
    }
}
