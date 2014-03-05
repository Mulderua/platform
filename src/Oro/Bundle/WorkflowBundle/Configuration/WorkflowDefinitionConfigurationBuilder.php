<?php

namespace Oro\Bundle\WorkflowBundle\Configuration;

use Oro\Bundle\WorkflowBundle\Entity\WorkflowDefinition;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowEntityAcl;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowStep;
use Oro\Bundle\WorkflowBundle\Model\Workflow;
use Oro\Bundle\WorkflowBundle\Model\WorkflowAssembler;

class WorkflowDefinitionConfigurationBuilder extends AbstractConfigurationBuilder
{
    /**
     * @var WorkflowAssembler
     */
    protected $workflowAssembler;

    /**
     * @param WorkflowAssembler $workflowAssembler
     */
    public function __construct(WorkflowAssembler $workflowAssembler)
    {
        $this->workflowAssembler = $workflowAssembler;
    }

    /**
     * @param array $configurationData
     * @return WorkflowDefinition[]
     */
    public function buildFromConfiguration(array $configurationData)
    {
        $workflowDefinitions = array();
        foreach ($configurationData as $workflowName => $workflowConfiguration) {
            $workflowDefinitions[] = $this->buildOneFromConfiguration($workflowName, $workflowConfiguration);
        }

        return $workflowDefinitions;
    }

    /**
     * @param string $name
     * @param array $configuration
     * @return WorkflowDefinition
     */
    public function buildOneFromConfiguration($name, array $configuration)
    {
        $this->assertConfigurationOptions($configuration, array('label', 'entity'));

        $enabled = $this->getConfigurationOption($configuration, 'enabled', true);
        $system = $this->getConfigurationOption($configuration, 'is_system', false);
        $startStepName = $this->getConfigurationOption($configuration, 'start_step', null);
        $entityAttributeName = $this->getConfigurationOption(
            $configuration,
            'entity_attribute',
            WorkflowConfiguration::DEFAULT_ENTITY_ATTRIBUTE
        );
        $stepsDisplayOrdered = $this->getConfigurationOption(
            $configuration,
            'steps_display_ordered',
            false
        );

        $workflowDefinition = new WorkflowDefinition();
        $workflowDefinition
            ->setName($name)
            ->setLabel($configuration['label'])
            ->setRelatedEntity($configuration['entity'])
            ->setStepsDisplayOrdered($stepsDisplayOrdered)
            ->setEnabled($enabled)
            ->setSystem($system)
            ->setEntityAttributeName($entityAttributeName)
            ->setConfiguration($configuration);

        $workflow = $this->workflowAssembler->assemble($workflowDefinition);

        $this->setSteps($workflowDefinition, $workflow);
        $workflowDefinition->setStartStep($workflowDefinition->getStepByName($startStepName));

        $this->setEntityAcls($workflowDefinition, $workflow);

        return $workflowDefinition;
    }

    /**
     * @param WorkflowDefinition $workflowDefinition
     * @param Workflow $workflow
     */
    protected function setSteps(WorkflowDefinition $workflowDefinition, Workflow $workflow)
    {
        $workflowSteps = array();
        foreach ($workflow->getStepManager()->getSteps() as $step) {
            $workflowStep = new WorkflowStep();
            $workflowStep
                ->setName($step->getName())
                ->setLabel($step->getLabel())
                ->setStepOrder($step->getOrder())
                ->setFinal($step->isFinal());

            $workflowSteps[] = $workflowStep;
        }

        $workflowDefinition->setSteps($workflowSteps);
    }

    protected function setEntityAcls(WorkflowDefinition $workflowDefinition, Workflow $workflow)
    {
        $entityAcls = array();
        foreach ($workflow->getAttributeManager()->getEntityAttributes() as $attribute) {
            foreach ($workflow->getStepManager()->getSteps() as $step) {
                $updatable = $attribute->isEntityUpdateAllowed()
                    && $step->isEntityUpdateAllowed($attribute->getName());
                $deletable = $attribute->isEntityDeleteAllowed()
                    && $step->isEntityDeleteAllowed($attribute->getName());

                if (!$updatable || !$deletable) {
                    $entityAcl = new WorkflowEntityAcl();
                    $entityAcl
                        ->setAttribute($attribute->getName())
                        ->setStep($workflowDefinition->getStepByName($step->getName()))
                        ->setEntityClass($attribute->getOption('class'))
                        ->setUpdatable($updatable)
                        ->setDeletable($deletable);
                    $entityAcls[] = $entityAcl;
                }
            }
        }

        $workflowDefinition->setEntityAcls($entityAcls);
    }
}
