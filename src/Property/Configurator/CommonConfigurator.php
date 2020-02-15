<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Property\Configurator;

use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Property\PropertyConfigInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Property\PropertyConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CommonConfigurator implements PropertyConfiguratorInterface
{
    private $applicationContextProvider;
    private $translator;
    private $propertyAccessor;

    public function __construct(ApplicationContextProvider $applicationContextProvider, TranslatorInterface $translator, PropertyAccessorInterface $propertyAccessor)
    {
        $this->applicationContextProvider = $applicationContextProvider;
        $this->translator = $translator;
        $this->propertyAccessor = $propertyAccessor;
    }

    public function supports(PropertyConfigInterface $propertyConfig, EntityDto $entityDto): bool
    {
        // this configurator applies to all kinds of properties
        return true;
    }

    public function configure(string $action, PropertyConfigInterface $propertyConfig, EntityDto $entityDto): void
    {
        $applicationContext = $this->applicationContextProvider->getContext();
        $translationDomain = $applicationContext->getI18n()->getTranslationDomain();

        $value = $this->buildValueProperty($propertyConfig, $entityDto);

        $propertyConfig
            ->setValue($value)
            ->setFormattedValue($value)
            ->setLabel($this->buildLabelOption($propertyConfig, $translationDomain))
            ->setSortable($this->buildSortableOption($propertyConfig, $entityDto))
            ->setVirtual($this->buildVirtualOption($propertyConfig, $entityDto))
            ->setTemplatePath($this->buildTemplatePathOption($applicationContext, $propertyConfig, $entityDto, $value))
            ->setRequired($this->buildRequiredOption($propertyConfig, $entityDto));

        if (null !== $propertyConfig->getHelp()) {
            $propertyConfig->setHelp($this->buildHelpProperty($propertyConfig, $translationDomain));
        }
    }

    private function buildHelpProperty(PropertyConfigInterface $propertyConfig, string $translationDomain): ?string
    {
        if ((null === $help = $propertyConfig->getHelp()) || empty($help)) {
            return $help;
        }

        return $this->translator->trans($help, $propertyConfig->getTranslationParams(), $translationDomain);
    }

    private function buildLabelOption(PropertyConfigInterface $propertyConfig, string $translationDomain): string
    {
        // it field doesn't define its label explicitly, generate an automatic
        // label based on the field's property name
        if (null === $label = $propertyConfig->getLabel()) {
            $label = $this->humanizeString($propertyConfig->getName());
        }

        if (empty($label)) {
            return $label;
        }

        return $this->translator->trans($label, $propertyConfig->getTranslationParams(), $translationDomain);
    }

    private function buildSortableOption(PropertyConfigInterface $propertyConfig, EntityDto $entityDto): bool
    {
        if (null !== $isSortable = $propertyConfig->isSortable()) {
            return $isSortable;
        }

        return $entityDto->hasProperty($propertyConfig->getName());
    }

    private function buildVirtualOption(PropertyConfigInterface $propertyConfig, EntityDto $entityDto): bool
    {
        return !$entityDto->hasProperty($propertyConfig->getName());
    }

    private function buildValueProperty(PropertyConfigInterface $propertyConfig, EntityDto $entityDto)
    {
        $entityInstance = $entityDto->getInstance();
        $propertyName = $propertyConfig->getName();

        if ($this->propertyAccessor->isReadable($entityInstance, $propertyName)) {
            return $this->propertyAccessor->getValue($entityInstance, $propertyName);
        }

        return null;
    }

    private function buildTemplatePathOption(ApplicationContext $applicationContext, PropertyConfigInterface $propertyConfig, EntityDto $entityDto, $propertyValue): string
    {
        if (null !== $templatePath = $propertyConfig->getTemplatePath()) {
            return $templatePath;
        }

        $isPropertyReadable = $this->propertyAccessor->isReadable($entityDto->getInstance(), $propertyConfig->getName());
        if (!$isPropertyReadable) {
            return $applicationContext->getTemplatePath('label/inaccessible');
        }

        if (null === $propertyValue) {
            return $applicationContext->getTemplatePath('label/null');
        }

        // TODO: move this condition to each property class
        if (empty($propertyValue) && \in_array($propertyConfig->getType(), ['image', 'file', 'array', 'simple_array'])) {
            return $applicationContext->getTemplatePath('label/empty');
        }

        if (null === $templateName = $propertyConfig->getTemplateName()) {
            throw new \RuntimeException(sprintf('Properties must define either their templateName or their templatePath. None give for "%s" property.', $propertyConfig->getName()));
        }

        return $applicationContext->getTemplatePath($templateName);
    }

    private function buildRequiredOption(PropertyConfigInterface $propertyConfig, EntityDto $entityDto): bool
    {
        if (null !== $isRequired = $propertyConfig->isRequired()) {
            return $isRequired;
        }

        // consider that virtual properties are not required
        if (!$entityDto->hasProperty($propertyConfig->getName())) {
            return false;
        }

        return !$entityDto->getPropertyMetadata($propertyConfig->getName())['nullable'];
    }

    // copied from Symfony\Component\Form\FormRenderer::humanize()
    // (author: Bernhard Schussek <bschussek@gmail.com>).
    private function humanizeString(string $string): string
    {
        return ucfirst(mb_strtolower(trim(preg_replace(['/([A-Z])/', '/[_\s]+/'], ['_$1', ' '], $string))));
    }
}