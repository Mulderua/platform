<?php

namespace Oro\Bundle\EntityBundle\Form\Type;

use Doctrine\Common\Util\Inflector;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigIdInterface;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

use Oro\Bundle\EntityExtendBundle\Tools\Generator\Generator;

class CustomEntityType extends AbstractType
{
    /**
     * @var ConfigManager
     */
    protected $configManager;

    protected $typeMap = array(
        'string'   => 'text',
        'integer'  => 'integer',
        'smallint' => 'integer',
        'bigint'   => 'integer',
        'boolean'  => 'choice',
        'decimal'  => 'number',
        'date'     => 'date',
        'time'     => 'time',
        'datetime' => 'datetime',
        'text'     => 'textarea',
        'float'    => 'number',
    );

    /**
     * @param ConfigManager $configManager
     */
    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $className = $options['className'];

        /** @var ConfigProvider $formConfigProvider */
        $formConfigProvider = $this->configManager->getProvider('form');
        $formConfigs        = $formConfigProvider->getConfigs($className);

        /** @var ConfigProvider $entityConfigProvider */
        $entityConfigProvider = $this->configManager->getProvider('entity');

        /** @var ConfigProvider $extendConfigProvider */
        $extendConfigProvider = $this->configManager->getProvider('extend');


        foreach ($formConfigs as $formConfig) {
            $extendConfig = $extendConfigProvider->getConfig($className, $formConfig->getId()->getFieldName());
            if ($formConfig->get('is_enabled')
                && !$extendConfig->get('is_deleted')
            ) {
                /** @var FieldConfigIdInterface $fieldConfigId */
                $fieldConfigId = $formConfig->getId();

                $entityConfig = $entityConfigProvider->getConfig(
                    $fieldConfigId->getClassName(),
                    $fieldConfigId->getFieldName()
                );

                $options = array(
                    'label'    => $entityConfig->get('label'),
                    'required' => false,
                    'block'    => 'general',
                );

                if ($fieldConfigId->getFieldType() == 'boolean') {
                    $options['empty_value'] = false;
                    $options['choices']     = array('No', 'Yes');
                }

                $builder->add(
                    Inflector::camelize(Generator::PREFIX . $fieldConfigId->getFieldName()),
                    $this->typeMap[$fieldConfigId->getFieldType()],
                    $options
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setRequired(array('className'));
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'custom_entity_type';
    }
}