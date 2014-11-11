<?php

namespace Oro\Bundle\CalendarBundle\Tests\Unit\Provider;

use Doctrine\ORM\Mapping\ClassMetadata;
use Oro\Bundle\CalendarBundle\Provider\CalendarPropertyProvider;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Extend\FieldTypeHelper;

class CalendarPropertyProviderTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $doctrineHelper;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $configManager;

    /** @var FieldTypeHelper */
    protected $fieldTypeHelper;

    /** @var CalendarPropertyProvider */
    protected $provider;

    protected function setUp()
    {
        $this->doctrineHelper  = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\DoctrineHelper')
            ->disableOriginalConstructor()
            ->getMock();
        $this->configManager   = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->fieldTypeHelper = new FieldTypeHelper(['enum' => 'manyToOne', 'multiEnum' => 'manyToMany']);

        $this->provider = new CalendarPropertyProvider(
            $this->doctrineHelper,
            $this->configManager,
            $this->fieldTypeHelper
        );
    }

    public function testGetFields()
    {
        $fieldConfigs = [
            $this->getFieldConfig('id', 'integer'),
            $this->getFieldConfig('targetCalendar', 'ref-one'),
            $this->getFieldConfig('visible', 'boolean'),
            $this->getFieldConfig('many2one', 'manyToOne'),
            $this->getFieldConfig('many2many', 'manyToMany'),
            $this->getFieldConfig('one2many', 'oneToMany'),
            $this->getFieldConfig('enum', 'enum'),
            $this->getFieldConfig('multiEnum', 'multiEnum'),
            $this->getFieldConfig('new', 'string', ['state' => ExtendScope::STATE_NEW]),
            $this->getFieldConfig('deleted', 'string', ['is_deleted' => true]),
        ];

        $this->configManager->expects($this->once())
            ->method('getConfigs')
            ->with('extend', CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($fieldConfigs));

        $result = $this->provider->getFields();
        $this->assertEquals(
            [
                'id'             => 'integer',
                'targetCalendar' => 'ref-one',
                'visible'        => 'boolean',
                'many2one'       => 'manyToOne',
                'enum'           => 'enum',
            ],
            $result
        );
    }

    public function testGetDefaultValues()
    {
        $fieldConfigs = [
            $this->getFieldConfig('id', 'integer'),
            $this->getFieldConfig('targetCalendar', 'ref-one'),
            $this->getFieldConfig('visible', 'boolean'),
            $this->getFieldConfig('enum', 'enum'),
        ];

        $this->configManager->expects($this->once())
            ->method('getConfigs')
            ->with('extend', CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($fieldConfigs));

        $metadata = $this->getMockBuilder('Doctrine\ORM\Mapping\ClassMetadata')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper->expects($this->once())
            ->method('getEntityMetadata')
            ->with(CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($metadata));

        $metadata->expects($this->exactly(count($fieldConfigs)))
            ->method('hasField')
            ->will(
                $this->returnValueMap(
                    [
                        ['id', true],
                        ['targetCalendar', false],
                        ['visible', true],
                        ['enum', false],
                    ]
                )
            );
        $metadata->expects($this->exactly(2))
            ->method('getFieldMapping')
            ->will(
                $this->returnValueMap(
                    [
                        ['id', []],
                        ['visible', ['options' => ['default' => true]]],
                    ]
                )
            );

        $result = $this->provider->getDefaultValues();
        $this->assertEquals(
            [
                'id'             => null,
                'targetCalendar' => null,
                'visible'        => true,
                'enum'           => [$this->provider, 'getEnumDefaultValue'],
            ],
            $result
        );
    }

    /**
     * @dataProvider getEnumDefaultValueProvider
     */
    public function testGetEnumDefaultValue($defaults, $expected)
    {
        $fieldName   = 'test_enum';
        $fieldConfig = $this->getFieldConfig($fieldName, 'enum', ['target_entity' => 'Test\Enum']);

        $this->configManager->expects($this->once())
            ->method('getConfig')
            ->with($fieldConfig->getId())
            ->will($this->returnValue($fieldConfig));

        $repo = $this->getMockBuilder('Doctrine\ORM\EntityRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper->expects($this->once())
            ->method('getEntityRepository')
            ->with('Test\Enum')
            ->will($this->returnValue($repo));
        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $repo->expects($this->once())
            ->method('createQueryBuilder')
            ->with('e')
            ->will($this->returnValue($qb));
        $qb->expects($this->once())
            ->method('select')
            ->with('e.id')
            ->will($this->returnSelf());
        $qb->expects($this->once())
            ->method('where')
            ->with('e.default = true')
            ->will($this->returnSelf());
        $query = $this->getMockBuilder('Doctrine\ORM\AbstractQuery')
            ->disableOriginalConstructor()
            ->setMethods(['getArrayResult'])
            ->getMockForAbstractClass();
        $qb->expects($this->once())
            ->method('getQuery')
            ->will($this->returnValue($query));
        $query->expects($this->once())
            ->method('getArrayResult')
            ->will($this->returnValue($defaults));

        $this->assertSame(
            $expected,
            $this->provider->getEnumDefaultValue($fieldName)
        );
    }

    public function getEnumDefaultValueProvider()
    {
        return [
            [
                'defaults' => [],
                'expected' => null
            ],
            [
                'defaults' => [['id' => 'opt1']],
                'expected' => 'opt1'
            ],
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testGetItems()
    {
        $calendarId = 123;

        $fieldConfigs = [
            $this->getFieldConfig('id', 'integer'),
            $this->getFieldConfig('targetCalendar', 'ref-one'),
            $this->getFieldConfig('visible', 'boolean'),
            $this->getFieldConfig('enum', 'enum'),
        ];

        $items = [
            [
                'id'             => 1,
                'targetCalendar' => '123',
                'visible'        => true,
                'enum'           => 'opt1',
            ]
        ];

        $this->configManager->expects($this->once())
            ->method('getConfigs')
            ->with('extend', CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($fieldConfigs));

        $metadata = $this->getMockBuilder('Doctrine\ORM\Mapping\ClassMetadata')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper->expects($this->once())
            ->method('getEntityMetadata')
            ->with(CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($metadata));

        $metadata->expects($this->exactly(2))
            ->method('hasAssociation')
            ->will(
                $this->returnValueMap(
                    [
                        ['targetCalendar', true],
                        ['enum', true],
                    ]
                )
            );
        $metadata->expects($this->exactly(2))
            ->method('getAssociationTargetClass')
            ->will(
                $this->returnValueMap(
                    [
                        ['targetCalendar', 'Oro\Bundle\CalendarBundle\Entity\Calendar'],
                        ['enum', 'Test\Enum'],
                    ]
                )
            );
        $this->doctrineHelper->expects($this->exactly(2))
            ->method('getSingleEntityIdentifierFieldType')
            ->will(
                $this->returnValueMap(
                    [
                        ['Oro\Bundle\CalendarBundle\Entity\Calendar', false, 'integer'],
                        ['Test\Enum', false, 'string'],
                    ]
                )
            );

        $repo = $this->getMockBuilder('Doctrine\ORM\EntityRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper->expects($this->once())
            ->method('getEntityRepository')
            ->with(CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS)
            ->will($this->returnValue($repo));
        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $repo->expects($this->once())
            ->method('createQueryBuilder')
            ->with('o')
            ->will($this->returnValue($qb));
        $qb->expects($this->once())
            ->method('select')
            ->with('o.id,IDENTITY(o.targetCalendar) AS targetCalendar,o.visible,IDENTITY(o.enum) AS enum')
            ->will($this->returnSelf());
        $qb->expects($this->once())
            ->method('where')
            ->with('o.targetCalendar = :calendar_id')
            ->will($this->returnSelf());
        $qb->expects($this->once())
            ->method('setParameter')
            ->with('calendar_id', $calendarId)
            ->will($this->returnSelf());
        $qb->expects($this->once())
            ->method('orderBy')
            ->with('o.id')
            ->will($this->returnSelf());
        $query = $this->getMockBuilder('Doctrine\ORM\AbstractQuery')
            ->disableOriginalConstructor()
            ->setMethods(['getArrayResult'])
            ->getMockForAbstractClass();
        $qb->expects($this->once())
            ->method('getQuery')
            ->will($this->returnValue($query));
        $query->expects($this->once())
            ->method('getArrayResult')
            ->will($this->returnValue($items));

        $result = $this->provider->getItems($calendarId);
        $this->assertSame(
            [
                [
                    'id'             => 1,
                    'targetCalendar' => 123,
                    'visible'        => true,
                    'enum'           => 'opt1',
                ]
            ],
            $result
        );
    }

    protected function getFieldConfig($fieldName, $fieldType, $values = [])
    {
        $fieldConfigId = new FieldConfigId(
            'extend',
            CalendarPropertyProvider::CALENDAR_PROPERTY_CLASS,
            $fieldName,
            $fieldType
        );
        $fieldConfig   = new Config($fieldConfigId);
        $fieldConfig->setValues($values);

        return $fieldConfig;
    }
}
