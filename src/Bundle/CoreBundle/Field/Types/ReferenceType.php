<?php


namespace UniteCMS\CoreBundle\Field\Types;

use GraphQL\Type\Definition\Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use UniteCMS\CoreBundle\Content\ContentFilterInput;
use UniteCMS\CoreBundle\Content\ContentInterface;
use UniteCMS\CoreBundle\Content\FieldData;
use UniteCMS\CoreBundle\Content\FieldDataList;
use UniteCMS\CoreBundle\ContentType\ContentTypeField;
use UniteCMS\CoreBundle\Domain\DomainManager;

class ReferenceType extends AbstractFieldType
{
    const TYPE = 'reference';
    const GRAPHQL_INPUT_TYPE = Type::ID;

    /**
     * @var \UniteCMS\CoreBundle\Domain\DomainManager $domainManager
     */
    protected $domainManager;

    public function __construct(DomainManager $domainManager)
    {
        $this->domainManager = $domainManager;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(ContentTypeField $field, ExecutionContextInterface $context) : void {

        // Validate return type.
        $returnTypes = empty($field->getUnionTypes()) ? [$field->getReturnType()] : array_keys($field->getUnionTypes());
        foreach($returnTypes as $returnType) {
            if(!$this->domainManager->current()->getContentTypeManager()->getContentType($returnType)) {
                $context
                    ->buildViolation('Invalid GraphQL return type "{{ return_type }}" for field of type "{{ type }}". Please use a GraphQL type (or an union of types) that implements UniteContent.')
                    ->setParameter('{{ type }}', static::getType())
                    ->setParameter('{{ return_type }}', $field->getReturnType())
                    ->addViolation();
            }
        }

        // Validate settings.
        $this->validateSettings($field, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveField(ContentInterface $content, ContentTypeField $field, FieldData $fieldData) {

        $domain = $this->domainManager->current();
        $contentManager = $domain->getContentManager();

        if($fieldData instanceof FieldDataList) {
            $rowIds = [];
            foreach($fieldData->rows() as $rowData) {
                $rowIds[] = $rowData->getData();
            }

            // Find all content objects by id.
            $result = $contentManager->find($domain, $field->getReturnType(), ContentFilterInput::fromInput(['id' => array_unique($rowIds)]));
            $resultById = [];

            // The result will not include duplicates. We need to transform it for an exact result.
            foreach($result->getResult() as $content) {
                $resultById[$content->getId()] = $content;
            }

            $resolvedContent = [];
            foreach($rowIds as $rowId) {
                $resolvedContent[] = array_key_exists($rowId, $resultById) ? $resultById[$rowId] : null;
            }
            return $resolvedContent;
        }

        return $contentManager->get($domain, $field->getReturnType(), $fieldData->getData());
    }
}
