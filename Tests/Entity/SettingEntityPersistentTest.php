<?php

namespace UniteCMS\CoreBundle\Tests\Entity;

use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use UniteCMS\CoreBundle\Entity\FieldableField;
use UniteCMS\CoreBundle\Entity\Setting;
use UniteCMS\CoreBundle\Entity\SettingType;
use UniteCMS\CoreBundle\Entity\SettingTypeField;
use UniteCMS\CoreBundle\Field\FieldType;
use UniteCMS\CoreBundle\Tests\DatabaseAwareTestCase;

class SettingEntityPersistentTest extends DatabaseAwareTestCase
{

    public function testValidateSetting()
    {

        // Try to validate empty Setting.
        $setting = new Setting();
        $errors = static::$container->get('validator')->validate($setting);
        $this->assertCount(1, $errors);

        $this->assertEquals('settingType', $errors->get(0)->getPropertyPath());
        $this->assertEquals('not_blank', $errors->get(0)->getMessageTemplate());
    }

    public function testValidateAdditionalContentData()
    {
        // 1. Create Setting Type with 1 Field
        $st = new SettingType();
        $field = new SettingTypeField();
        $field->setType('text')->setIdentifier('title')->setTitle('Title');
        $st->setTitle('St1')->setIdentifier('st1')->addField($field);

        // 2. Create Setting1 with the same field. => VALID
        $setting = new Setting();
        $setting->setSettingType($st)->setData(['title' => 'Title']);
        $this->assertCount(0, static::$container->get('validator')->validate($setting));

        // 3. Create Setting2 with the same field and another field. => INVALID
        $setting->setData(array_merge($setting->getData(), ['other' => "Other"]));
        $errors = static::$container->get('validator')->validate($setting);
        $this->assertCount(1, $errors);
        $this->assertEquals('data', $errors->get(0)->getPropertyPath());
        $this->assertEquals('additional_data', $errors->get(0)->getMessageTemplate());

        // 4. Create Setting2 with only another field. => INVALID
        $setting->setData(['other' => 'Other']);
        $errors = static::$container->get('validator')->validate($setting);
        $this->assertCount(1, $errors);
        $this->assertEquals('data', $errors->get(0)->getPropertyPath());
        $this->assertEquals('additional_data', $errors->get(0)->getMessageTemplate());

        // 5. SettingType have more fields than setting. => VALID
        $field2 = new SettingTypeField();
        $field2->setType('text')->setIdentifier('title2')->setTitle('Title2');
        $st->addField($field);
        $setting->setSettingType($st)->setData(['title' => 'Title']);
        $this->assertCount(0, static::$container->get('validator')->validate($setting));
    }

    public function testValidateContentDataValidation()
    {

        // 1. Create Content Type with 1 mocked FieldType
        $mockedFieldType = new Class extends FieldType
        {
            const TYPE = "setting_entity_test_mocked_field";

            function validateData(FieldableField $field, $data, ExecutionContextInterface $context)
            {
                if ($data) {
                    $context->buildViolation('mocked_message')->atPath('invalid')->addViolation();
                }
            }
        };

        // Inject the field type
        static::$container->get('unite.cms.field_type_manager')->registerFieldType($mockedFieldType);

        $st = new SettingType();
        $field = new SettingTypeField();
        $field->setType('setting_entity_test_mocked_field')->setIdentifier('invalid')->setTitle('Title');
        $st->setTitle('St1')->setIdentifier('st1')->addField($field);


        // 2. Create Setting that is invalid with FieldType. => INVALID (at path)
        $setting = new Setting();
        $setting->setSettingType($st)->setData(['invalid' => true]);
        $errors = static::$container->get('validator')->validate($setting);
        $this->assertCount(1, $errors);
        $this->assertEquals('data.invalid', $errors->get(0)->getPropertyPath());
        $this->assertEquals('mocked_message', $errors->get(0)->getMessageTemplate());

        // 3. Create Setting that is valid with FieldType. => VALID
        $setting->setData(['invalid' => false]);
        $this->assertCount(0, static::$container->get('validator')->validate($setting));
    }

}
