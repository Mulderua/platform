<?php

namespace Oro\Bundle\LayoutBundle\Layout\Block\Type;

use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Oro\Component\Layout\Block\Type\ContainerType;
use Oro\Component\Layout\BlockView;
use Oro\Component\Layout\BlockInterface;
use Oro\Component\Layout\BlockBuilderInterface;

use Oro\Bundle\LayoutBundle\Layout\Form\FormLayoutBuilderInterface;

/**
 * This block type is responsible to build the layout for a Symfony's form object.
 * Naming convention:
 *  field id = $options['form_field_prefix'] + field path (path separator is replaced with colon (:))
 *      for example: form_firstName or form_address:city  where 'form_' is the prefix
 *  group id = $options['form_group_prefix'] + group name
 *      for example: form:group_myGroup where 'form:group_' is the prefix
 */
class FormType extends AbstractFormType
{
    const NAME = 'form';

    /** @var FormLayoutBuilderInterface */
    protected $formLayoutBuilder;

    /**
     * @param FormLayoutBuilderInterface $formLayoutBuilder
     */
    public function __construct(FormLayoutBuilderInterface $formLayoutBuilder)
    {
        $this->formLayoutBuilder = $formLayoutBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        parent::setDefaultOptions($resolver);
        $resolver->setDefaults(
            [
                // example: ['jobTitle', 'user.lastName']
                'preferred_fields'  => [],
                // example:
                // [
                //   'general'    => [
                //     'title'  => 'General Info',
                //     'fields' => ['user.firstName', 'user.lastName']
                //   ],
                //   'additional'    => [
                //     'title'   => 'Additional Info',
                //     'default' => true
                //   ]
                // ]
                'groups'            => [],
                'form_prefix'       => function (Options $options, $value) {
                    return null === $value ? $options['form_name'] : $value;
                },
                'form_field_prefix' => function (Options $options, $value) {
                    return null === $value ? $options['form_prefix'] . '_' : $value;
                },
                'form_group_prefix' => function (Options $options, $value) {
                    return null === $value ? $options['form_prefix'] . ':group_' : $value;
                }
            ]
        );
        $resolver->setAllowedTypes(
            [
                'preferred_fields'  => 'array',
                'groups'            => 'array',
                'form_prefix'       => 'string',
                'form_field_prefix' => 'string',
                'form_group_prefix' => 'string'
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function buildBlock(BlockBuilderInterface $builder, array $options)
    {
        $formAccessor = $this->getFormAccessor($builder->getContext(), $options);

        $this->formLayoutBuilder->build($formAccessor, $builder, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(BlockView $view, BlockInterface $block, array $options)
    {
        $formAccessor = $this->getFormAccessor($block->getContext(), $options);

        $view->vars['form'] = $formAccessor->getView();
    }

    /**
     * {@inheritdoc}
     */
    public function finishView(BlockView $view, BlockInterface $block, array $options)
    {
        $formAccessor = $this->getFormAccessor($block->getContext(), $options);

        // prevent form fields rendering by form_rest() method,
        // if the corresponding layout block has been removed
        $rootView = null;
        foreach ($formAccessor->getProcessedFields() as $formFieldPath => $blockId) {
            if (isset($view[$blockId])) {
                $this->checkExistingFieldView($view, $view[$blockId], $formFieldPath);
                continue;
            }
            if ($rootView === null) {
                $rootView = $view->parent !== null
                    ? $this->getRootView($view)
                    : false;
            }
            if ($rootView !== false && isset($rootView[$blockId])) {
                $this->checkExistingFieldView($view, $rootView[$blockId], $formFieldPath);
                continue;
            }

            $this->getFormFieldView($view, $formFieldPath)->setRendered();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return ContainerType::NAME;
    }

    /**
     * @param BlockView $view
     *
     * @return BlockView
     */
    protected function getRootView(BlockView $view)
    {
        $result = $view;
        while ($result->parent) {
            $result = $result->parent;
        }

        return $result;
    }

    /**
     * Returns form field view
     *
     * @param BlockView $view
     * @param string    $formFieldPath
     *
     * @return FormView
     */
    protected function getFormFieldView(BlockView $view, $formFieldPath)
    {
        /** @var FormView $form */
        $form = $view->vars['form'];
        foreach (explode('.', $formFieldPath) as $field) {
            $form = $form[$field];
        }

        return $form;
    }

    /**
     * Checks whether an existing field view is the view created in buildBlock method,
     * and if it is another view mark the corresponding form field as rendered
     *
     * @param BlockView $view
     * @param BlockView $childView
     * @param string    $formFieldPath
     */
    protected function checkExistingFieldView(BlockView $view, BlockView $childView, $formFieldPath)
    {
        if (!isset($childView->vars['form'])) {
            $this->getFormFieldView($view, $formFieldPath)->setRendered();
        } else {
            $formFieldView = $this->getFormFieldView($view, $formFieldPath);
            if ($childView->vars['form'] !== $formFieldView) {
                $formFieldView->setRendered();
            }
        }
    }
}
